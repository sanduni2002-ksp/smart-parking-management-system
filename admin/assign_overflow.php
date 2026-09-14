<?php

require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$adminId = (int) $_SESSION["admin_id"];

$errors = [];

$successMessage =
    $_SESSION["admin_success"] ?? "";

unset($_SESSION["admin_success"]);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$selectedReservationId = filter_input(
    INPUT_GET,
    "reservation_id",
    FILTER_VALIDATE_INT
);

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $selectedReservationId = filter_input(
        INPUT_POST,
        "reservation_id",
        FILTER_VALIDATE_INT
    );

    $newSlotId = filter_input(
        INPUT_POST,
        "new_slot_id",
        FILTER_VALIDATE_INT
    );

    $reason = trim(
        $_POST["reason"] ?? ""
    );

    $csrfToken =
        $_POST["csrf_token"] ?? "";

    if (
        empty($_SESSION["csrf_token"]) ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] =
            "Invalid form submission. Please try again.";
    }

    if (!$selectedReservationId) {
        $errors[] =
            "Select a valid reservation.";
    }

    if (!$newSlotId) {
        $errors[] =
            "Select a valid overflow slot.";
    }

    if (strlen($reason) < 5) {
        $errors[] =
            "Enter a reason for assigning the overflow slot.";
    }

    if (strlen($reason) > 255) {
        $errors[] =
            "The reason cannot contain more than 255 characters.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            /*
             * Lock confirmed, paid reservation.
             */
            $reservationStatement = $pdo->prepare(
                "SELECT
                    r.reservation_id,
                    r.slot_id AS old_slot_id,
                    r.start_date,
                    r.start_time,
                    r.end_date,
                    r.end_time,
                    r.status,
                    v.vehicle_number,
                    v.vehicle_type,
                    u.username,
                    u.full_name,
                    old_slot.slot_number
                        AS old_slot_number,
                    old_slot.floor_no
                        AS old_floor_no,
                    old_slot.slot_type
                        AS required_slot_type,
                    old_slot.access_type
                        AS old_access_type,
                    p.payment_id
                 FROM reservations AS r
                 INNER JOIN vehicles AS v
                    ON r.vehicle_id = v.vehicle_id
                 INNER JOIN users AS u
                    ON v.user_id = u.user_id
                 INNER JOIN parking_slots AS old_slot
                    ON r.slot_id = old_slot.slot_id
                 INNER JOIN payments AS p
                    ON r.reservation_id =
                       p.reservation_id
                 WHERE r.reservation_id =
                       :reservation_id
                 LIMIT 1
                 FOR UPDATE"
            );

            $reservationStatement->execute([
                "reservation_id" =>
                    $selectedReservationId
            ]);

            $reservation =
                $reservationStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$reservation) {
                throw new RuntimeException(
                    "The confirmed paid reservation was not found."
                );
            }

            if (
                $reservation["status"]
                !== "Confirmed"
            ) {
                throw new RuntimeException(
                    "Only confirmed reservations can be assigned to an overflow slot."
                );
            }

            if (
                $reservation["old_access_type"]
                !== "Public"
            ) {
                throw new RuntimeException(
                    "This reservation is already using an overflow slot."
                );
            }

            $reservationEnd = new DateTime(
                $reservation["end_date"]
                . " "
                . $reservation["end_time"]
            );

            if ($reservationEnd <= new DateTime()) {
                throw new RuntimeException(
                    "An expired reservation cannot be reassigned."
                );
            }

            /*
             * Lock selected overflow slot.
             */
            $slotStatement = $pdo->prepare(
                "SELECT
                    slot_id,
                    slot_number,
                    floor_no,
                    slot_type,
                    access_type,
                    status
                 FROM parking_slots
                 WHERE slot_id = :slot_id
                 LIMIT 1
                 FOR UPDATE"
            );

            $slotStatement->execute([
                "slot_id" => $newSlotId
            ]);

            $newSlot = $slotStatement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$newSlot) {
                throw new RuntimeException(
                    "The selected overflow slot was not found."
                );
            }

            if (
                $newSlot["access_type"]
                !== "AdminOnly"
            ) {
                throw new RuntimeException(
                    "Only admin-only overflow slots can be assigned here."
                );
            }

            if (
                $newSlot["status"]
                !== "Available"
            ) {
                throw new RuntimeException(
                    "This overflow slot is physically unavailable."
                );
            }

            if (
                $newSlot["slot_type"]
                !== $reservation["required_slot_type"]
            ) {
                throw new RuntimeException(
                    "The overflow slot is not suitable for this vehicle."
                );
            }

            /*
             * Recheck overflow-slot availability.
             */
            $overlapStatement = $pdo->prepare(
                "SELECT reservation_id
                 FROM reservations
                 WHERE slot_id = :slot_id
                   AND reservation_id
                       <> :reservation_id
                   AND status IN (
                       'Pending',
                       'Confirmed'
                   )
                   AND TIMESTAMP(
                       start_date,
                       start_time
                   ) < TIMESTAMP(
                       :end_date,
                       :end_time
                   )
                   AND TIMESTAMP(
                       end_date,
                       end_time
                   ) > TIMESTAMP(
                       :start_date,
                       :start_time
                   )
                 LIMIT 1
                 FOR UPDATE"
            );

            $overlapStatement->execute([
                "slot_id" => $newSlotId,
                "reservation_id" =>
                    $selectedReservationId,
                "start_date" =>
                    $reservation["start_date"],
                "start_time" =>
                    $reservation["start_time"],
                "end_date" =>
                    $reservation["end_date"],
                "end_time" =>
                    $reservation["end_time"]
            ]);

            if ($overlapStatement->fetch()) {
                throw new RuntimeException(
                    "This overflow slot is already reserved during the selected period."
                );
            }

            /*
             * Move existing reservation.
             */
            $updateStatement = $pdo->prepare(
                "UPDATE reservations
                 SET slot_id = :new_slot_id
                 WHERE reservation_id =
                       :reservation_id"
            );

            $updateStatement->execute([
                "new_slot_id" => $newSlotId,
                "reservation_id" =>
                    $selectedReservationId
            ]);

            /*
             * Save audit record.
             */
            $auditStatement = $pdo->prepare(
                "INSERT INTO
                    reservation_reassignments (
                        reservation_id,
                        old_slot_id,
                        new_slot_id,
                        admin_id,
                        reason
                    ) VALUES (
                        :reservation_id,
                        :old_slot_id,
                        :new_slot_id,
                        :admin_id,
                        :reason
                    )"
            );

            $auditStatement->execute([
                "reservation_id" =>
                    $selectedReservationId,
                "old_slot_id" =>
                    $reservation["old_slot_id"],
                "new_slot_id" => $newSlotId,
                "admin_id" => $adminId,
                "reason" => $reason
            ]);

            $pdo->commit();

            $_SESSION["admin_success"] =
                "Reservation #"
                . $selectedReservationId
                . " moved from slot "
                . $reservation["old_slot_number"]
                . " to overflow slot "
                . $newSlot["slot_number"]
                . " successfully.";

            $_SESSION["csrf_token"] = bin2hex(
                random_bytes(32)
            );

            header(
                "Location: assign_overflow.php"
            );
            exit;

        } catch (RuntimeException $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] =
                $exception->getMessage();

        } catch (PDOException $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                $exception->getMessage()
            );

            $errors[] =
                "The overflow assignment failed. Please try again.";
        }
    }
}

/*
 * Confirmed, paid reservations currently
 * assigned to public slots.
 */
$eligibleStatement = $pdo->query(
    "SELECT
        r.reservation_id,
        r.start_date,
        r.start_time,
        r.end_date,
        r.end_time,
        u.username,
        u.full_name,
        v.vehicle_number,
        v.vehicle_type,
        ps.slot_number,
        ps.floor_no,
        ps.slot_type,
        p.amount
     FROM reservations AS r
     INNER JOIN vehicles AS v
        ON r.vehicle_id = v.vehicle_id
     INNER JOIN users AS u
        ON v.user_id = u.user_id
     INNER JOIN parking_slots AS ps
        ON r.slot_id = ps.slot_id
     INNER JOIN payments AS p
        ON r.reservation_id = p.reservation_id
     WHERE r.status = 'Confirmed'
       AND ps.access_type = 'Public'
       AND TIMESTAMP(
            r.end_date,
            r.end_time
       ) > NOW()
     ORDER BY
        r.start_date,
        r.start_time"
);

$eligibleReservations =
    $eligibleStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

$selectedReservation = null;
$overflowSlots = [];

if ($selectedReservationId) {

    $selectedStatement = $pdo->prepare(
        "SELECT
            r.reservation_id,
            r.start_date,
            r.start_time,
            r.end_date,
            r.end_time,
            r.status,
            u.username,
            u.full_name,
            v.vehicle_number,
            v.vehicle_type,
            ps.slot_number AS old_slot_number,
            ps.floor_no AS old_floor_no,
            ps.slot_type AS required_slot_type,
            ps.access_type,
            p.amount
         FROM reservations AS r
         INNER JOIN vehicles AS v
            ON r.vehicle_id = v.vehicle_id
         INNER JOIN users AS u
            ON v.user_id = u.user_id
         INNER JOIN parking_slots AS ps
            ON r.slot_id = ps.slot_id
         INNER JOIN payments AS p
            ON r.reservation_id =
               p.reservation_id
         WHERE r.reservation_id =
               :reservation_id
           AND r.status = 'Confirmed'
           AND ps.access_type = 'Public'
           AND TIMESTAMP(
                r.end_date,
                r.end_time
           ) > NOW()
         LIMIT 1"
    );

    $selectedStatement->execute([
        "reservation_id" =>
            $selectedReservationId
    ]);

    $selectedReservation =
        $selectedStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if ($selectedReservation) {

        $overflowStatement = $pdo->prepare(
            "SELECT
                ps.slot_id,
                ps.slot_number,
                ps.floor_no,
                ps.slot_type
             FROM parking_slots AS ps
             WHERE ps.access_type = 'AdminOnly'
               AND ps.status = 'Available'
               AND ps.slot_type =
                   :required_slot_type
               AND NOT EXISTS (
                    SELECT 1
                    FROM reservations AS r
                    WHERE r.slot_id = ps.slot_id
                      AND r.reservation_id
                          <> :reservation_id
                      AND r.status IN (
                          'Pending',
                          'Confirmed'
                      )
                      AND TIMESTAMP(
                          r.start_date,
                          r.start_time
                      ) < TIMESTAMP(
                          :end_date,
                          :end_time
                      )
                      AND TIMESTAMP(
                          r.end_date,
                          r.end_time
                      ) > TIMESTAMP(
                          :start_date,
                          :start_time
                      )
               )
             ORDER BY
                ps.floor_no,
                ps.slot_number"
        );

        $overflowStatement->execute([
            "required_slot_type" =>
                $selectedReservation[
                    "required_slot_type"
                ],
            "reservation_id" =>
                $selectedReservationId,
            "start_date" =>
                $selectedReservation[
                    "start_date"
                ],
            "start_time" =>
                $selectedReservation[
                    "start_time"
                ],
            "end_date" =>
                $selectedReservation[
                    "end_date"
                ],
            "end_time" =>
                $selectedReservation[
                    "end_time"
                ]
        ]);

        $overflowSlots =
            $overflowStatement->fetchAll(
                PDO::FETCH_ASSOC
            );
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Assign Overflow Slot | Smart Parking
    </title>

    <link
        rel="stylesheet"
        href="/smart_parking/assets/css/theme.css?v=1"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0 !important;
            min-height: 100vh;
            font-family: Arial, sans-serif;

            background:
                radial-gradient(
                    circle at top right,
                    #174e86,
                    #0b2d55 40%,
                    #06162d
                ) fixed !important;
        }

        .sidebar {
            width: 270px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;

            display: flex;
            flex-direction: column;

            padding: 25px 18px;

            background:
                linear-gradient(
                    180deg,
                    #041126,
                    #08264a 55%,
                    #0c3766
                );

            color: white;
        }

        .brand {
            padding: 0 8px 24px;

            border-bottom:
                1px solid rgba(255, 255, 255, 0.15);
        }

        .brand h2 {
            margin: 0;
            color: white !important;
        }

        .brand small {
            color: #93c5fd !important;
        }

        .sidebar-navigation {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 24px;
        }

        .nav-link {
            padding: 13px 15px;
            border-radius: 8px;

            color: #dbeafe !important;

            font-weight: bold;
            text-decoration: none;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #2563eb;
            color: white !important;
        }

        .sidebar-footer {
            margin-top: auto;
        }

        .logout-link {
            background:
                rgba(220, 38, 38, 0.18);
        }

        .main-content {
            min-height: 100vh;
            margin-left: 270px;
            padding: 30px 4% 50px;
        }

        .page-heading h1 {
            margin: 0;
            color: white !important;
        }

        .page-heading p {
            color: #bfdbfe;
        }

        .panel {
            margin-top: 24px;
            padding: 25px;

            background: white !important;

            border-radius: 12px;

            box-shadow:
                0 15px 35px
                rgba(0, 0, 0, 0.25) !important;
        }

        .message {
            margin-bottom: 20px;
            padding: 13px;
            border-radius: 8px;
        }

        .success {
            background: #dcfce7 !important;
            color: #166534 !important;
        }

        .error {
            background: #fee2e2 !important;
            color: #991b1b !important;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            border-bottom:
                1px solid #e2e8f0;
            text-align: left;
        }

        th {
            background: #0a1f3d !important;
            color: white !important;
        }

        .select-button,
        .assign-button {
            display: inline-block;
            padding: 9px 13px;
            border: none;
            border-radius: 7px;

            background:
                linear-gradient(
                    135deg,
                    #0a1f3d,
                    #2563eb
                ) !important;

            color: white !important;

            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }

        .reservation-summary {
            padding: 18px;
            border-left: 4px solid #2563eb;
            border-radius: 8px;
            background: #eff6ff;
        }

        .slot-grid {
            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(230px, 1fr)
                );

            gap: 18px;
            margin-top: 20px;
        }

        .slot-card {
            padding: 21px;
            border-top: 4px solid #f59e0b;
            border-radius: 10px;
            background: white !important;

            box-shadow:
                0 8px 22px
                rgba(6, 21, 43, 0.12);
        }

        .slot-card input {
            width: 100%;
            margin: 10px 0 14px;
            padding: 11px;
        }

        .overflow-badge {
            display: inline-block;
            padding: 5px 9px;

            border-radius: 20px;

            background: #fef3c7;
            color: #92400e;

            font-size: 12px;
            font-weight: bold;
        }

        @media (max-width: 700px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }

            .sidebar-footer {
                margin-top: 20px;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">

    <div class="brand">
        <h2>Smart Parking Management</h2>
        <small>Administrator Panel</small>
    </div>

    <div class="sidebar-navigation">

        <a href="dashboard.php" class="nav-link">
            Dashboard
        </a>

        <a
            href="details.php?section=users"
            class="nav-link"
        >
            Registered Users
        </a>

        <a
            href="details.php?section=slots"
            class="nav-link"
        >
            Parking Slots
        </a>

        <a
            href="details.php?section=reservations"
            class="nav-link"
        >
            Reservations
        </a>

        <a
            href="add_reservation.php"
            class="nav-link"
        >
            Create Reservation
        </a>

        <a
            href="assign_overflow.php"
            class="nav-link active"
        >
            Assign Overflow Slot
        </a>

        <a
            href="details.php?section=payments"
            class="nav-link"
        >
            Payments & Revenue
        </a>

    </div>

    <div class="sidebar-footer">

        <a
            href="logout.php"
            class="nav-link logout-link"
        >
            Logout
        </a>

    </div>

</aside>

<main class="main-content">

    <header class="page-heading">

        <h1>Assign Overflow Slot</h1>

        <p>
            Move a confirmed paid reservation when its
            original slot is still occupied.
        </p>

    </header>

    <?php if ($successMessage !== ""): ?>

        <div class="panel message success">
            <?= htmlspecialchars($successMessage) ?>
        </div>

    <?php endif; ?>

    <?php if (!empty($errors)): ?>

        <div class="panel message error">
            <ul>
                <?php foreach ($errors as $error): ?>

                    <li>
                        <?= htmlspecialchars($error) ?>
                    </li>

                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <section class="panel">

        <h2>Eligible Confirmed Reservations</h2>

        <?php if (empty($eligibleReservations)): ?>

            <p>
                No confirmed paid public-slot
                reservations are currently eligible.
            </p>

        <?php else: ?>

            <div class="table-wrapper">

                <table>
                    <thead>
                    <tr>
                        <th>Reservation</th>
                        <th>User</th>
                        <th>Vehicle</th>
                        <th>Current Slot</th>
                        <th>Period</th>
                        <th>Action</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach (
                        $eligibleReservations
                        as $reservation
                    ): ?>

                        <tr>
                            <td>
                                #<?= (int) $reservation[
                                    "reservation_id"
                                ] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $reservation["full_name"]
                                ) ?>

                                <br>

                                @<?= htmlspecialchars(
                                    $reservation["username"]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $reservation[
                                        "vehicle_number"
                                    ]
                                ) ?>

                                <br>

                                <?= htmlspecialchars(
                                    $reservation[
                                        "vehicle_type"
                                    ]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $reservation[
                                        "slot_number"
                                    ]
                                ) ?>

                                – Floor
                                <?= (int) $reservation[
                                    "floor_no"
                                ] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $reservation[
                                        "start_date"
                                    ]
                                ) ?>

                                <?= htmlspecialchars(
                                    substr(
                                        $reservation[
                                            "start_time"
                                        ],
                                        0,
                                        5
                                    )
                                ) ?>

                                <br>

                                to

                                <?= htmlspecialchars(
                                    $reservation[
                                        "end_date"
                                    ]
                                ) ?>

                                <?= htmlspecialchars(
                                    substr(
                                        $reservation[
                                            "end_time"
                                        ],
                                        0,
                                        5
                                    )
                                ) ?>
                            </td>

                            <td>
                                <a
                                    href="assign_overflow.php?reservation_id=<?= (int) $reservation["reservation_id"] ?>"
                                    class="select-button"
                                >
                                    Find Overflow Slot
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

        <?php endif; ?>

    </section>

    <?php if ($selectedReservation): ?>

        <section class="panel">

            <h2>
                Assign Reservation
                #<?= (int) $selectedReservation[
                    "reservation_id"
                ] ?>
            </h2>

            <div class="reservation-summary">

                <p>
                    <strong>User:</strong>

                    <?= htmlspecialchars(
                        $selectedReservation[
                            "full_name"
                        ]
                    ) ?>

                    (@<?= htmlspecialchars(
                        $selectedReservation[
                            "username"
                        ]
                    ) ?>)
                </p>

                <p>
                    <strong>Vehicle:</strong>

                    <?= htmlspecialchars(
                        $selectedReservation[
                            "vehicle_number"
                        ]
                    ) ?>

                    –
                    <?= htmlspecialchars(
                        $selectedReservation[
                            "vehicle_type"
                        ]
                    ) ?>
                </p>

                <p>
                    <strong>Original Slot:</strong>

                    <?= htmlspecialchars(
                        $selectedReservation[
                            "old_slot_number"
                        ]
                    ) ?>

                    – Floor
                    <?= (int) $selectedReservation[
                        "old_floor_no"
                    ] ?>
                </p>

            </div>

            <?php if (empty($overflowSlots)): ?>

                <p>
                    No suitable admin-only overflow slot
                    is available for this reservation.
                </p>

            <?php else: ?>

                <div class="slot-grid">

                    <?php foreach (
                        $overflowSlots as $slot
                    ): ?>

                        <article class="slot-card">

                            <h3>
                                Overflow Slot
                                <?= htmlspecialchars(
                                    $slot["slot_number"]
                                ) ?>
                            </h3>

                            <span class="overflow-badge">
                                Admin Only
                            </span>

                            <p>
                                Floor:
                                <?= (int) $slot[
                                    "floor_no"
                                ] ?>
                            </p>

                            <p>
                                Type:
                                <?= htmlspecialchars(
                                    $slot["slot_type"]
                                ) ?>
                            </p>

                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars(
                                        $_SESSION[
                                            "csrf_token"
                                        ]
                                    ) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="reservation_id"
                                    value="<?= (int) $selectedReservation["reservation_id"] ?>"
                                >

                                <input
                                    type="hidden"
                                    name="new_slot_id"
                                    value="<?= (int) $slot["slot_id"] ?>"
                                >

                                <label for="reason_<?= (int) $slot["slot_id"] ?>">
                                    Assignment Reason
                                </label>

                                <input
                                    type="text"
                                    id="reason_<?= (int) $slot["slot_id"] ?>"
                                    name="reason"
                                    maxlength="255"
                                    value="Previous vehicle did not leave the original slot on time."
                                    required
                                >

                                <button
                                    type="submit"
                                    class="assign-button"
                                    onclick="return confirm('Move this reservation to the selected overflow slot?');"
                                >
                                    Assign This Slot
                                </button>

                            </form>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>

</body>
</html>
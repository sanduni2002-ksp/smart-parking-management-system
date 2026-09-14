<?php

require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$adminId = (int) $_SESSION["admin_id"];

$graceMinutes = 15;
$fineRatePerHour = 100.00;

$errors = [];

$successMessage = $_SESSION["success"] ?? "";
unset($_SESSION["success"]);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/*
|--------------------------------------------------------------------------
| Record vehicle exit
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $reservationId = filter_input(
        INPUT_POST,
        "reservation_id",
        FILTER_VALIDATE_INT
    );

    $csrfToken = $_POST["csrf_token"] ?? "";

    if (
        $csrfToken === "" ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Invalid form submission.";
    }

    if (!$reservationId) {
        $errors[] = "Invalid reservation selected.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            /*
            | Lock reservation to prevent double checkout
            */

            $reservationStatement = $pdo->prepare(
                "SELECT
                    r.reservation_id,
                    r.slot_id,
                    r.end_date,
                    r.end_time,
                    r.status,
                    r.actual_exit_at,
                    v.vehicle_number,
                    ps.slot_number
                 FROM reservations AS r
                 INNER JOIN vehicles AS v
                    ON r.vehicle_id = v.vehicle_id
                 INNER JOIN parking_slots AS ps
                    ON r.slot_id = ps.slot_id
                 WHERE r.reservation_id = :reservation_id
                 LIMIT 1
                 FOR UPDATE"
            );

            $reservationStatement->execute([
                "reservation_id" => $reservationId
            ]);

            $reservation = $reservationStatement->fetch();

            if (!$reservation) {
                throw new RuntimeException(
                    "Reservation was not found."
                );
            }

            if ($reservation["status"] !== "Confirmed") {
                throw new RuntimeException(
                    "Only confirmed reservations can be checked out."
                );
            }

            if ($reservation["actual_exit_at"] !== null) {
                throw new RuntimeException(
                    "This vehicle has already been checked out."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate overstay
            |--------------------------------------------------------------------------
            */

            $scheduledEnd = new DateTimeImmutable(
                $reservation["end_date"] .
                " " .
                $reservation["end_time"]
            );

            $actualExit = new DateTimeImmutable();

            $overstaySeconds = max(
                0,
                $actualExit->getTimestamp() -
                $scheduledEnd->getTimestamp()
            );

            $overstayMinutes = $overstaySeconds > 0
                ? (int) ceil($overstaySeconds / 60)
                : 0;

            $chargeableMinutes = max(
                0,
                $overstayMinutes - $graceMinutes
            );

            $fineAmount = 0.00;

            if ($chargeableMinutes > 0) {
                $chargeableHours = (int) ceil(
                    $chargeableMinutes / 60
                );

                $fineAmount =
                    $chargeableHours * $fineRatePerHour;

                /*
                | Create fine record
                */

                $fineStatement = $pdo->prepare(
                    "INSERT INTO fines (
                        reservation_id,
                        overstay_minutes,
                        grace_minutes,
                        chargeable_minutes,
                        rate_per_hour,
                        fine_amount,
                        status
                    ) VALUES (
                        :reservation_id,
                        :overstay_minutes,
                        :grace_minutes,
                        :chargeable_minutes,
                        :rate_per_hour,
                        :fine_amount,
                        'Pending'
                    )"
                );

                $fineStatement->execute([
                    "reservation_id" => $reservationId,
                    "overstay_minutes" => $overstayMinutes,
                    "grace_minutes" => $graceMinutes,
                    "chargeable_minutes" => $chargeableMinutes,
                    "rate_per_hour" => $fineRatePerHour,
                    "fine_amount" => $fineAmount
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Complete reservation
            |--------------------------------------------------------------------------
            */

            $updateReservation = $pdo->prepare(
                "UPDATE reservations
                 SET
                    actual_exit_at = :actual_exit_at,
                    checked_out_by = :checked_out_by,
                    status = 'Completed'
                 WHERE reservation_id = :reservation_id"
            );

            $updateReservation->execute([
                "actual_exit_at" =>
                    $actualExit->format("Y-m-d H:i:s"),

                "checked_out_by" => $adminId,

                "reservation_id" => $reservationId
            ]);

            /*
            |--------------------------------------------------------------------------
            | Release physical parking slot
            |--------------------------------------------------------------------------
            */

            $updateSlot = $pdo->prepare(
                "UPDATE parking_slots
                 SET status = 'Available'
                 WHERE slot_id = :slot_id"
            );

            $updateSlot->execute([
                "slot_id" => $reservation["slot_id"]
            ]);

            $pdo->commit();

            if ($fineAmount > 0) {
                $_SESSION["success"] =
                    "Vehicle " .
                    $reservation["vehicle_number"] .
                    " checked out successfully. Fine: Rs. " .
                    number_format($fineAmount, 2);
            } else {
                $_SESSION["success"] =
                    "Vehicle " .
                    $reservation["vehicle_number"] .
                    " checked out successfully. No fine was required.";
            }

            $_SESSION["csrf_token"] = bin2hex(
                random_bytes(32)
            );

            header(
                "Location: /smart_parking/admin/vehicle_exit.php"
            );
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : "Vehicle checkout failed. Please try again.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load vehicles currently inside parking
|--------------------------------------------------------------------------
*/

$activeStatement = $pdo->query(
    "SELECT
        r.reservation_id,
        r.start_date,
        r.end_date,
        r.start_time,
        r.end_time,
        r.status,
        u.full_name,
        u.username,
        v.vehicle_number,
        v.vehicle_type,
        ps.slot_number,
        ps.floor_no,
        ps.access_type
     FROM reservations AS r
     INNER JOIN vehicles AS v
        ON r.vehicle_id = v.vehicle_id
     INNER JOIN users AS u
        ON v.user_id = u.user_id
     INNER JOIN parking_slots AS ps
        ON r.slot_id = ps.slot_id
     WHERE r.status = 'Confirmed'
       AND r.actual_exit_at IS NULL
       AND TIMESTAMP(
            r.start_date,
            r.start_time
       ) <= NOW()
     ORDER BY
        r.end_date ASC,
        r.end_time ASC"
);

$activeReservations = $activeStatement->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Vehicle Exit & Fines | Smart Parking</title>

    <link
        rel="stylesheet"
        href="/smart_parking/assets/css/theme.css?v=1"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background: #edf4ff;
            color: #0f2747;
        }

        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 18px 6%;
            background: #071f42;
            color: white;
        }

        .navbar h2 {
            margin: 0;
        }

        .navbar-links {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .navbar a {
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        .container {
            width: 92%;
            max-width: 1250px;
            margin: 35px auto;
        }

        .page-header {
            margin-bottom: 25px;
            padding: 27px;
            border-left: 5px solid #2563eb;
            border-radius: 14px;
            background: white;
            box-shadow: 0 10px 28px rgba(15, 39, 71, 0.08);
        }

        .page-header h1 {
            margin: 0 0 10px;
            color: #071f42;
        }

        .page-header p {
            margin: 5px 0;
            color: #64748b;
        }

        .policy {
            display: inline-block;
            margin-top: 10px;
            padding: 9px 13px;
            border-radius: 8px;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: bold;
        }

        .message {
            margin-bottom: 20px;
            padding: 14px;
            border-radius: 9px;
        }

        .success {
            border: 1px solid #86efac;
            background: #dcfce7;
            color: #166534;
        }

        .error {
            border: 1px solid #fca5a5;
            background: #fee2e2;
            color: #991b1b;
        }

        .error ul {
            margin: 0;
            padding-left: 20px;
        }

        .table-card {
            overflow: hidden;
            border: 1px solid #d7e3f1;
            border-radius: 14px;
            background: white;
            box-shadow: 0 10px 28px rgba(15, 39, 71, 0.08);
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
            padding: 14px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #071f42;
            color: white;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        tr:hover td {
            background: #f8fbff;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: bold;
        }

        .normal {
            background: #dcfce7;
            color: #166534;
        }

        .overdue {
            background: #fee2e2;
            color: #b91c1c;
        }

        .overflow {
            background: #fef3c7;
            color: #92400e;
        }

        .checkout-button {
            padding: 9px 13px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .checkout-button:hover {
            background: #1d4ed8;
        }

        .empty-state {
            padding: 40px 25px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 760px) {
            .navbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .navbar-links {
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">

    <h2>Smart Parking Admin</h2>

    <div class="navbar-links">
        <a href="/smart_parking/admin/dashboard.php">
            Dashboard
        </a>

        <a href="/smart_parking/admin/details.php?section=reservations">
            Reservations
        </a>

        <a href="/smart_parking/admin/logout.php">
            Logout
        </a>
    </div>

</nav>

<main class="container">

    <section class="page-header">

        <h1>Vehicle Exit & Overstay Fines</h1>

        <p>
            Record the vehicle's actual exit and automatically
            calculate any applicable fine.
        </p>

        <div class="policy">
            15-minute grace period · Rs.
            <?= number_format($fineRatePerHour, 2) ?>
            per started additional hour
        </div>

    </section>

    <?php if ($successMessage !== ""): ?>

        <div class="message success">
            <?= htmlspecialchars($successMessage) ?>
        </div>

    <?php endif; ?>

    <?php if (!empty($errors)): ?>

        <div class="message error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

    <?php endif; ?>

    <section class="table-card">

        <?php if (empty($activeReservations)): ?>

            <div class="empty-state">
                <h3>No vehicles are currently awaiting checkout.</h3>

                <p>
                    Confirmed reservations will appear here after
                    their reservation start time.
                </p>
            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table>

                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Vehicle</th>
                        <th>Parking Slot</th>
                        <th>Reserved Period</th>
                        <th>Current Timing</th>
                        <th>Estimated Fine</th>
                        <th>Action</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($activeReservations as $reservation): ?>

                        <?php
                        $scheduledEnd = new DateTimeImmutable(
                            $reservation["end_date"] .
                            " " .
                            $reservation["end_time"]
                        );

                        $now = new DateTimeImmutable();

                        $overstaySeconds = max(
                            0,
                            $now->getTimestamp() -
                            $scheduledEnd->getTimestamp()
                        );

                        $overstayMinutes = $overstaySeconds > 0
                            ? (int) ceil($overstaySeconds / 60)
                            : 0;

                        $chargeableMinutes = max(
                            0,
                            $overstayMinutes - $graceMinutes
                        );

                        $estimatedFine =
                            $chargeableMinutes > 0
                                ? ceil(
                                    $chargeableMinutes / 60
                                ) * $fineRatePerHour
                                : 0;
                        ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars(
                                    $reservation["full_name"]
                                ) ?>
                                <br>

                                <small>
                                    @<?= htmlspecialchars(
                                        $reservation["username"]
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <strong>
                                    <?= htmlspecialchars(
                                        $reservation["vehicle_number"]
                                    ) ?>
                                </strong>
                                <br>

                                <small>
                                    <?= htmlspecialchars(
                                        $reservation["vehicle_type"]
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $reservation["slot_number"]
                                ) ?>

                                <br>

                                <small>
                                    Floor
                                    <?= (int) $reservation["floor_no"] ?>
                                </small>

                                <?php if (
                                    $reservation["access_type"]
                                    === "AdminOnly"
                                ): ?>
                                    <br>
                                    <span class="badge overflow">
                                        Overflow
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $reservation["start_date"]
                                ) ?>

                                <?= htmlspecialchars(
                                    substr(
                                        $reservation["start_time"],
                                        0,
                                        5
                                    )
                                ) ?>

                                <br>

                                to

                                <?= htmlspecialchars(
                                    $reservation["end_date"]
                                ) ?>

                                <?= htmlspecialchars(
                                    substr(
                                        $reservation["end_time"],
                                        0,
                                        5
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?php if ($overstayMinutes > 0): ?>

                                    <span class="badge overdue">
                                        Overdue by
                                        <?= $overstayMinutes ?>
                                        minutes
                                    </span>

                                <?php else: ?>

                                    <span class="badge normal">
                                        Within reserved time
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <strong>
                                    Rs.
                                    <?= number_format(
                                        $estimatedFine,
                                        2
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <form
                                    method="POST"
                                    onsubmit="return confirm(
                                        'Confirm that this vehicle has exited the parking area?'
                                    );"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars(
                                            $_SESSION["csrf_token"]
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="reservation_id"
                                        value="<?= (int) $reservation[
                                            "reservation_id"
                                        ] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="checkout-button"
                                    >
                                        Record Exit
                                    </button>
                                </form>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
<?php

require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$errors = [];
$availableSlots = [];
$searchCompleted = false;

$successMessage =
    $_SESSION["admin_success"] ?? "";

unset($_SESSION["admin_success"]);

$vehicleId = "";
$startDate = date("Y-m-d", strtotime("+1 day"));
$startTime = "08:00";
$endDate = date("Y-m-d", strtotime("+1 day"));
$endTime = "09:00";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/*
 * Load all registered vehicles with owner details.
 */
$vehicleStatement = $pdo->query(
    "SELECT
        v.vehicle_id,
        v.vehicle_number,
        v.vehicle_type,
        v.vehicle_brand,
        u.user_id,
        u.username,
        u.full_name
     FROM vehicles AS v
     INNER JOIN users AS u
        ON v.user_id = u.user_id
     ORDER BY
        u.full_name,
        v.vehicle_number"
);

$vehicles = $vehicleStatement->fetchAll(
    PDO::FETCH_ASSOC
);

/*
 * Get matching slot type.
 */
function getRequiredSlotType(
    string $vehicleType
): string {
    return match ($vehicleType) {
        "Motorcycle" => "Motorcycle",
        "Van" => "Van",
        default => "Car"
    };
}

/*
 * Search all slots.
 * Admin can see Public and AdminOnly slots.
 */
function searchAdminSlots(
    PDO $pdo,
    string $slotType,
    string $startDate,
    string $startTime,
    string $endDate,
    string $endTime
): array {
    $statement = $pdo->prepare(
        "SELECT
            ps.slot_id,
            ps.slot_number,
            ps.floor_no,
            ps.slot_type,
            ps.access_type,
            ps.status
         FROM parking_slots AS ps
         WHERE ps.status = 'Available'
           AND ps.slot_type = :slot_type
           AND NOT EXISTS (
                SELECT 1
                FROM reservations AS r
                WHERE r.slot_id = ps.slot_id
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
            CASE
                WHEN ps.access_type = 'AdminOnly'
                THEN 1
                ELSE 0
            END,
            ps.floor_no,
            ps.slot_number"
    );

    $statement->execute([
        "slot_type" => $slotType,
        "start_date" => $startDate,
        "start_time" => $startTime,
        "end_date" => $endDate,
        "end_time" => $endTime
    ]);

    return $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "search";

    $vehicleId = filter_input(
        INPUT_POST,
        "vehicle_id",
        FILTER_VALIDATE_INT
    );

    $startDate = trim(
        $_POST["start_date"] ?? ""
    );

    $startTime = trim(
        $_POST["start_time"] ?? ""
    );

    $endDate = trim(
        $_POST["end_date"] ?? ""
    );

    $endTime = trim(
        $_POST["end_time"] ?? ""
    );

    $csrfToken =
        $_POST["csrf_token"] ?? "";

    /*
     * CSRF validation.
     */
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

    /*
     * Validate vehicle.
     */
    $selectedVehicle = null;

    if (!$vehicleId) {
        $errors[] =
            "Select a registered vehicle.";
    } else {
        $selectedVehicleStatement = $pdo->prepare(
            "SELECT
                v.vehicle_id,
                v.vehicle_number,
                v.vehicle_type,
                u.user_id,
                u.username,
                u.full_name
             FROM vehicles AS v
             INNER JOIN users AS u
                ON v.user_id = u.user_id
             WHERE v.vehicle_id = :vehicle_id
             LIMIT 1"
        );

        $selectedVehicleStatement->execute([
            "vehicle_id" => $vehicleId
        ]);

        $selectedVehicle =
            $selectedVehicleStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$selectedVehicle) {
            $errors[] =
                "The selected vehicle was not found.";
        }
    }

    /*
     * Validate reservation dates.
     */
    try {
        $startDateTime = new DateTime(
            $startDate . " " . $startTime
        );

        $endDateTime = new DateTime(
            $endDate . " " . $endTime
        );

        $currentDateTime = new DateTime();

        if ($startDateTime <= $currentDateTime) {
            $errors[] =
                "The reservation must start in the future.";
        }

        if ($endDateTime <= $startDateTime) {
            $errors[] =
                "The end date and time must be after the start date and time.";
        }

    } catch (Exception $exception) {
        $errors[] =
            "Enter a valid reservation date and time.";
    }

    /*
     * Search available slots.
     */
    if (
        empty($errors) &&
        $selectedVehicle &&
        $action === "search"
    ) {
        $requiredSlotType = getRequiredSlotType(
            $selectedVehicle["vehicle_type"]
        );

        $availableSlots = searchAdminSlots(
            $pdo,
            $requiredSlotType,
            $startDate,
            $startTime,
            $endDate,
            $endTime
        );

        $searchCompleted = true;
    }

    /*
     * Create reservation.
     */
    if (
        empty($errors) &&
        $selectedVehicle &&
        $action === "reserve"
    ) {
        $slotId = filter_input(
            INPUT_POST,
            "slot_id",
            FILTER_VALIDATE_INT
        );

        if (!$slotId) {
            $errors[] =
                "Select a valid parking slot.";
        } else {
            try {
                $pdo->beginTransaction();

                /*
                 * Lock vehicle.
                 */
                $lockedVehicleStatement = $pdo->prepare(
                    "SELECT
                        vehicle_id,
                        vehicle_number,
                        vehicle_type
                     FROM vehicles
                     WHERE vehicle_id = :vehicle_id
                     FOR UPDATE"
                );

                $lockedVehicleStatement->execute([
                    "vehicle_id" => $vehicleId
                ]);

                $lockedVehicle =
                    $lockedVehicleStatement->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$lockedVehicle) {
                    throw new RuntimeException(
                        "The selected vehicle was not found."
                    );
                }

                $requiredSlotType = getRequiredSlotType(
                    $lockedVehicle["vehicle_type"]
                );

                /*
                 * Lock selected slot.
                 * Admin can use Public and AdminOnly slots.
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
                     FOR UPDATE"
                );

                $slotStatement->execute([
                    "slot_id" => $slotId
                ]);

                $slot = $slotStatement->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!$slot) {
                    throw new RuntimeException(
                        "The selected parking slot was not found."
                    );
                }

                if ($slot["status"] !== "Available") {
                    throw new RuntimeException(
                        "This parking slot is physically unavailable."
                    );
                }

                if (
                    $slot["slot_type"]
                    !== $requiredSlotType
                ) {
                    throw new RuntimeException(
                        "This slot is not suitable for the selected vehicle."
                    );
                }

                /*
                 * Prevent the vehicle from having two
                 * overlapping reservations.
                 */
                $vehicleOverlapStatement = $pdo->prepare(
                    "SELECT reservation_id
                     FROM reservations
                     WHERE vehicle_id = :vehicle_id
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

                $vehicleOverlapStatement->execute([
                    "vehicle_id" => $vehicleId,
                    "start_date" => $startDate,
                    "start_time" => $startTime,
                    "end_date" => $endDate,
                    "end_time" => $endTime
                ]);

                if ($vehicleOverlapStatement->fetch()) {
                    throw new RuntimeException(
                        "This vehicle already has a reservation during the selected period."
                    );
                }

                /*
                 * Recheck slot overlap.
                 */
                $slotOverlapStatement = $pdo->prepare(
                    "SELECT reservation_id
                     FROM reservations
                     WHERE slot_id = :slot_id
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

                $slotOverlapStatement->execute([
                    "slot_id" => $slotId,
                    "start_date" => $startDate,
                    "start_time" => $startTime,
                    "end_date" => $endDate,
                    "end_time" => $endTime
                ]);

                if ($slotOverlapStatement->fetch()) {
                    throw new RuntimeException(
                        "This parking slot has already been reserved for that period."
                    );
                }

                /*
                 * Insert Pending reservation.
                 */
                $insertStatement = $pdo->prepare(
                    "INSERT INTO reservations (
                        vehicle_id,
                        slot_id,
                        start_date,
                        end_date,
                        start_time,
                        end_time,
                        status
                    ) VALUES (
                        :vehicle_id,
                        :slot_id,
                        :start_date,
                        :end_date,
                        :start_time,
                        :end_time,
                        'Pending'
                    )"
                );

                $insertStatement->execute([
                    "vehicle_id" => $vehicleId,
                    "slot_id" => $slotId,
                    "start_date" => $startDate,
                    "end_date" => $endDate,
                    "start_time" => $startTime,
                    "end_time" => $endTime
                ]);

                $reservationId =
                    (int) $pdo->lastInsertId();

                $pdo->commit();

                $_SESSION["admin_success"] =
                    "Reservation #{$reservationId} created successfully. The user can now complete the payment.";

                $_SESSION["csrf_token"] = bin2hex(
                    random_bytes(32)
                );

                header(
                    "Location: add_reservation.php"
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
                    "The reservation could not be created. Please try again.";
            }

            /*
             * Show results again after an error.
             */
            if ($selectedVehicle) {
                $availableSlots = searchAdminSlots(
                    $pdo,
                    getRequiredSlotType(
                        $selectedVehicle["vehicle_type"]
                    ),
                    $startDate,
                    $startTime,
                    $endDate,
                    $endTime
                );

                $searchCompleted = true;
            }
        }
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
        Add Reservation | Smart Parking Management
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
                    #174e86 0%,
                    #0b2d55 40%,
                    #06162d 100%
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

            box-shadow:
                10px 0 30px rgba(0, 0, 0, 0.25);

            z-index: 100;
        }

        .brand-section {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 5px 8px 24px;

            border-bottom:
                1px solid rgba(255, 255, 255, 0.15);
        }

        .brand-logo {
            width: 45px;
            height: 45px;

            display: flex;
            align-items: center;
            justify-content: center;

            flex-shrink: 0;

            border-radius: 12px;

            background:
                linear-gradient(
                    135deg,
                    #2563eb,
                    #60a5fa
                );

            color: white;
            font-size: 21px;
            font-weight: bold;
        }

        .brand-title {
            margin: 0;
            color: white !important;
            font-size: 18px;
            line-height: 1.3;
        }

        .brand-subtitle {
            display: block;
            margin-top: 3px;
            color: #93c5fd;
            font-size: 12px;
            font-weight: normal;
        }

        .sidebar-navigation {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 25px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 13px 15px;

            border: 1px solid transparent;
            border-radius: 9px;

            color: #dbeafe !important;

            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
        }

        .nav-number {
            width: 27px;
            height: 27px;

            display: flex;
            align-items: center;
            justify-content: center;

            flex-shrink: 0;

            border-radius: 7px;

            background:
                rgba(255, 255, 255, 0.10);

            color: #bfdbfe;
            font-size: 12px;
        }

        .nav-link:hover {
            background:
                rgba(255, 255, 255, 0.10);
        }

        .nav-link.active {
            background:
                linear-gradient(
                    135deg,
                    #1d4ed8,
                    #3b82f6
                );

            color: white !important;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 20px;

            border-top:
                1px solid rgba(255, 255, 255, 0.15);
        }

        .logout-link {
            background:
                rgba(220, 38, 38, 0.16);

            color: #fecaca !important;
        }

        .main-content {
            min-height: 100vh;
            margin-left: 270px;
            padding: 30px 4% 50px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin: 0;
            color: white !important;
        }

        .page-heading p {
            color: #bfdbfe;
        }

        .panel {
            padding: 28px;

            background: white !important;

            border-radius: 13px;

            box-shadow:
                0 15px 35px
                rgba(0, 0, 0, 0.25) !important;
        }

        .form-grid {
            display: grid;

            grid-template-columns:
                repeat(
                    5,
                    minmax(150px, 1fr)
                );

            gap: 15px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        select,
        input {
            width: 100%;
            padding: 12px;
        }

        .search-button {
            margin-top: 20px;
            padding: 12px 20px;
        }

        .message {
            margin-bottom: 20px;
            padding: 13px;
            border-radius: 8px;
        }

        .error {
            background: #fee2e2 !important;
            color: #991b1b !important;
        }

        .success {
            background: #dcfce7 !important;
            color: #166534 !important;
        }

        .slot-heading {
            margin: 30px 0 18px;
            color: white !important;
        }

        .slot-grid {
            display: grid;

            grid-template-columns:
                repeat(
                    auto-fit,
                    minmax(230px, 1fr)
                );

            gap: 18px;
        }

        .slot-card {
            padding: 22px;

            background: white !important;

            border-top:
                4px solid #3b82f6 !important;

            border-radius: 11px;

            box-shadow:
                0 12px 28px
                rgba(0, 0, 0, 0.23) !important;
        }

        .slot-card h3 {
            margin-top: 0;
        }

        .access-badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .public {
            background: #dcfce7;
            color: #166534;
        }

        .admin-only {
            background: #fef3c7 !important;
            color: #92400e !important;
        }

        .reserve-button {
            width: 100%;
            margin-top: 12px;
            padding: 11px;
        }

        .empty-result {
            padding: 25px;
            border-radius: 10px;
            background: white;
            text-align: center;
        }

        @media (max-width: 1000px) {
            .form-grid {
                grid-template-columns: 1fr 1fr;
            }
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

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">

    <div class="brand-section">

        <div class="brand-logo">
            A
        </div>

        <h2 class="brand-title">
            Smart Parking
            Management

            <span class="brand-subtitle">
                Administrator Panel
            </span>
        </h2>

    </div>

    <div class="sidebar-navigation">

        <a href="dashboard.php" class="nav-link">
            <span class="nav-number">01</span>
            Dashboard
        </a>

        <a
            href="details.php?section=users"
            class="nav-link"
        >
            <span class="nav-number">02</span>
            Registered Users
        </a>

        <a
            href="details.php?section=slots"
            class="nav-link"
        >
            <span class="nav-number">03</span>
            Parking Slots
        </a>

        <a
            href="details.php?section=reservations"
            class="nav-link"
        >
            <span class="nav-number">04</span>
            Reservations
        </a>

        <a
            href="add_reservation.php"
            class="nav-link active"
        >
            <span class="nav-number">05</span>
            Add Reservation
        </a>

        <a
            href="details.php?section=payments"
            class="nav-link"
        >
            <span class="nav-number">06</span>
            Payments & Revenue
        </a>

    </div>

    <div class="sidebar-footer">

        <a
            href="logout.php"
            class="nav-link logout-link"
        >
            <span class="nav-number">07</span>
            Logout
        </a>

    </div>

</aside>

<main class="main-content">

    <header class="page-heading">

        <h1>Add Reservation</h1>

        <p>
            Create a parking reservation for a
            registered user.
        </p>

    </header>

    <section class="panel">

        <?php if ($successMessage !== ""): ?>

            <div class="message success">
                <?= htmlspecialchars($successMessage) ?>
            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="message error">
                <ul>
                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= htmlspecialchars($error) ?>
                        </li>

                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <?php if (empty($vehicles)): ?>

            <p>
                No registered vehicles are available.
                A user must add a vehicle first.
            </p>

        <?php else: ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION["csrf_token"]
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="search"
                >

                <div class="form-grid">

                    <div>
                        <label for="vehicle_id">
                            User and Vehicle
                        </label>

                        <select
                            id="vehicle_id"
                            name="vehicle_id"
                            required
                        >
                            <option value="">
                                Select User Vehicle
                            </option>

                            <?php foreach ($vehicles as $vehicle): ?>

                                <option
                                    value="<?= (int) $vehicle["vehicle_id"] ?>"
                                    <?= (string) $vehicleId ===
                                        (string) $vehicle["vehicle_id"]
                                        ? "selected"
                                        : "" ?>
                                >
                                    <?= htmlspecialchars(
                                        $vehicle["full_name"]
                                    ) ?>
                                    (@<?= htmlspecialchars(
                                        $vehicle["username"]
                                    ) ?>)
                                    –
                                    <?= htmlspecialchars(
                                        $vehicle["vehicle_number"]
                                    ) ?>
                                    –
                                    <?= htmlspecialchars(
                                        $vehicle["vehicle_type"]
                                    ) ?>
                                </option>

                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="start_date">
                            Start Date
                        </label>

                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            min="<?= date("Y-m-d") ?>"
                            value="<?= htmlspecialchars($startDate) ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="start_time">
                            Start Time
                        </label>

                        <input
                            type="time"
                            id="start_time"
                            name="start_time"
                            value="<?= htmlspecialchars($startTime) ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="end_date">
                            End Date
                        </label>

                        <input
                            type="date"
                            id="end_date"
                            name="end_date"
                            min="<?= date("Y-m-d") ?>"
                            value="<?= htmlspecialchars($endDate) ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="end_time">
                            End Time
                        </label>

                        <input
                            type="time"
                            id="end_time"
                            name="end_time"
                            value="<?= htmlspecialchars($endTime) ?>"
                            required
                        >
                    </div>

                </div>

                <button
                    type="submit"
                    class="search-button"
                >
                    Search Available Slots
                </button>

            </form>

        <?php endif; ?>

    </section>

    <?php if ($searchCompleted): ?>

        <h2 class="slot-heading">
            Available Parking Slots
        </h2>

        <?php if (empty($availableSlots)): ?>

            <div class="empty-result">
                No suitable slots are available for
                the selected period.
            </div>

        <?php else: ?>

            <section class="slot-grid">

                <?php foreach ($availableSlots as $slot): ?>

                    <article class="slot-card">

                        <h3>
                            Slot
                            <?= htmlspecialchars(
                                $slot["slot_number"]
                            ) ?>
                        </h3>

                        <p>
                            Floor:
                            <?= (int) $slot["floor_no"] ?>
                        </p>

                        <p>
                            Type:
                            <?= htmlspecialchars(
                                $slot["slot_type"]
                            ) ?>
                        </p>

                        <?php if (
                            $slot["access_type"]
                            === "AdminOnly"
                        ): ?>

                            <span class="access-badge admin-only">
                                Admin-Only Overflow
                            </span>

                        <?php else: ?>

                            <span class="access-badge public">
                                Public Slot
                            </span>

                        <?php endif; ?>

                        <form method="POST">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(
                                    $_SESSION["csrf_token"]
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="reserve"
                            >

                            <input
                                type="hidden"
                                name="vehicle_id"
                                value="<?= (int) $vehicleId ?>"
                            >

                            <input
                                type="hidden"
                                name="slot_id"
                                value="<?= (int) $slot["slot_id"] ?>"
                            >

                            <input
                                type="hidden"
                                name="start_date"
                                value="<?= htmlspecialchars($startDate) ?>"
                            >

                            <input
                                type="hidden"
                                name="start_time"
                                value="<?= htmlspecialchars($startTime) ?>"
                            >

                            <input
                                type="hidden"
                                name="end_date"
                                value="<?= htmlspecialchars($endDate) ?>"
                            >

                            <input
                                type="hidden"
                                name="end_time"
                                value="<?= htmlspecialchars($endTime) ?>"
                            >

                            <button
                                type="submit"
                                class="reserve-button"
                            >
                                Create Reservation
                            </button>

                        </form>

                    </article>

                <?php endforeach; ?>

            </section>

        <?php endif; ?>

    <?php endif; ?>

</main>

</body>
</html>
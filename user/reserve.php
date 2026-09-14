<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$userId = (int) $_SESSION["user_id"];

$errors = [];
$availableSlots = [];
$searchCompleted = false;

$vehicleId = "";

$startDate = date(
    "Y-m-d",
    strtotime("+1 day")
);

$startTime = "08:00";
$durationHours = 1;

$defaultStart = new DateTimeImmutable(
    $startDate . " " . $startTime
);

$defaultEnd = $defaultStart->modify(
    "+" . $durationHours . " hours"
);

$endDate = $defaultEnd->format("Y-m-d");
$endTime = $defaultEnd->format("H:i");

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/*
|--------------------------------------------------------------------------
| Load user's vehicles
|--------------------------------------------------------------------------
*/

$vehicleStatement = $pdo->prepare(
    "SELECT
        vehicle_id,
        vehicle_number,
        vehicle_type,
        vehicle_brand
     FROM vehicles
     WHERE user_id = :user_id
     ORDER BY vehicle_number"
);

$vehicleStatement->execute([
    "user_id" => $userId
]);

$vehicles = $vehicleStatement->fetchAll(
    PDO::FETCH_ASSOC
);

/*
|--------------------------------------------------------------------------
| Convert vehicle type to required slot type
|--------------------------------------------------------------------------
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
|--------------------------------------------------------------------------
| Validate start date and time
|--------------------------------------------------------------------------
*/

function createStartDateTime(
    string $date,
    string $time
): ?DateTimeImmutable {
    $dateTime = DateTimeImmutable::createFromFormat(
        "!Y-m-d H:i",
        $date . " " . $time
    );

    $dateErrors = DateTimeImmutable::getLastErrors();

    if (!$dateTime) {
        return null;
    }

    if (
        is_array($dateErrors) &&
        (
            $dateErrors["warning_count"] > 0 ||
            $dateErrors["error_count"] > 0
        )
    ) {
        return null;
    }

    if (
        $dateTime->format("Y-m-d H:i") !==
        $date . " " . $time
    ) {
        return null;
    }

    return $dateTime;
}

/*
|--------------------------------------------------------------------------
| Search available public slots
|--------------------------------------------------------------------------
*/

function searchPublicSlots(
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
            ps.status

         FROM parking_slots AS ps

         WHERE ps.status = 'Available'
           AND ps.access_type = 'Public'
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

/*
|--------------------------------------------------------------------------
| Handle search and reservation
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "search";

    if (
        !in_array(
            $action,
            ["search", "reserve"],
            true
        )
    ) {
        $errors[] = "Invalid reservation action.";
    }

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

    $durationInput = filter_input(
        INPUT_POST,
        "duration_hours",
        FILTER_VALIDATE_INT,
        [
            "options" => [
                "min_range" => 1,
                "max_range" => 24
            ]
        ]
    );

    $csrfToken = $_POST["csrf_token"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | CSRF validation
    |--------------------------------------------------------------------------
    */

    if (
        empty($_SESSION["csrf_token"]) ||
        $csrfToken === "" ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] =
            "Invalid form submission. Please try again.";
    }

    /*
    |--------------------------------------------------------------------------
    | Validate duration
    |--------------------------------------------------------------------------
    */

    if (
        $durationInput === false ||
        $durationInput === null
    ) {
        $errors[] =
            "Duration must be between 1 and 24 hours.";

        $durationHours = 1;
    } else {
        $durationHours = (int) $durationInput;
    }

    /*
    |--------------------------------------------------------------------------
    | Validate vehicle ownership
    |--------------------------------------------------------------------------
    */

    $selectedVehicle = null;

    if (!$vehicleId) {
        $errors[] = "Select a vehicle.";
    } else {
        $selectedVehicleStatement = $pdo->prepare(
            "SELECT
                vehicle_id,
                vehicle_number,
                vehicle_type

             FROM vehicles

             WHERE vehicle_id = :vehicle_id
               AND user_id = :user_id

             LIMIT 1"
        );

        $selectedVehicleStatement->execute([
            "vehicle_id" => $vehicleId,
            "user_id" => $userId
        ]);

        $selectedVehicle =
            $selectedVehicleStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$selectedVehicle) {
            $errors[] =
                "The selected vehicle is invalid.";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate end date and time securely in PHP
    |--------------------------------------------------------------------------
    */

    $startDateTime = createStartDateTime(
        $startDate,
        $startTime
    );

    $endDateTime = null;

    if (!$startDateTime) {
        $errors[] =
            "Enter a valid start date and start time.";
    } else {
        $currentDateTime = new DateTimeImmutable();

        if ($startDateTime <= $currentDateTime) {
            $errors[] =
                "The reservation must start in the future.";
        }

        $endDateTime = $startDateTime->modify(
            "+" . $durationHours . " hours"
        );

        $endDate = $endDateTime->format("Y-m-d");
        $endTime = $endDateTime->format("H:i");
    }

    /*
    |--------------------------------------------------------------------------
    | Search public parking slots
    |--------------------------------------------------------------------------
    */

    if (
        empty($errors) &&
        $selectedVehicle &&
        $endDateTime &&
        $action === "search"
    ) {
        $slotType = getRequiredSlotType(
            $selectedVehicle["vehicle_type"]
        );

        $availableSlots = searchPublicSlots(
            $pdo,
            $slotType,
            $startDate,
            $startTime,
            $endDate,
            $endTime
        );

        $searchCompleted = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Create reservation
    |--------------------------------------------------------------------------
    */

    if (
        empty($errors) &&
        $selectedVehicle &&
        $endDateTime &&
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
                |--------------------------------------------------------------------------
                | Lock and recheck vehicle
                |--------------------------------------------------------------------------
                */

                $lockedVehicleStatement = $pdo->prepare(
                    "SELECT
                        vehicle_id,
                        vehicle_type

                     FROM vehicles

                     WHERE vehicle_id = :vehicle_id
                       AND user_id = :user_id

                     FOR UPDATE"
                );

                $lockedVehicleStatement->execute([
                    "vehicle_id" => $vehicleId,
                    "user_id" => $userId
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
                |--------------------------------------------------------------------------
                | Lock and validate public slot
                |--------------------------------------------------------------------------
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
                       AND access_type = 'Public'

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
                        "This parking slot is not available for public reservation."
                    );
                }

                if ($slot["status"] !== "Available") {
                    throw new RuntimeException(
                        "This parking slot is physically unavailable."
                    );
                }

                if (
                    $slot["slot_type"] !==
                    $requiredSlotType
                ) {
                    throw new RuntimeException(
                        "This parking slot is not suitable for the selected vehicle."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Recheck overlapping reservations
                |--------------------------------------------------------------------------
                */

                $overlapStatement = $pdo->prepare(
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

                $overlapStatement->execute([
                    "slot_id" => $slotId,
                    "start_date" => $startDate,
                    "start_time" => $startTime,
                    "end_date" => $endDate,
                    "end_time" => $endTime
                ]);

                if ($overlapStatement->fetch()) {
                    throw new RuntimeException(
                        "This parking slot has just been reserved. Please select another slot."
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Insert reservation
                |--------------------------------------------------------------------------
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

                $pdo->commit();

                $_SESSION["success"] =
                    "Parking slot reserved successfully for " .
                    $durationHours .
                    " hour(s). Complete the payment to confirm it.";

                $_SESSION["csrf_token"] = bin2hex(
                    random_bytes(32)
                );

                header(
                    "Location: reservations.php"
                );
                exit;

            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = $exception->getMessage();

            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($exception->getMessage());

                $errors[] =
                    "The reservation could not be created. Please try again.";
            }

            /*
             * Reload available slots after a failed reservation.
             */

            if (
                $selectedVehicle &&
                $startDateTime &&
                $endDateTime
            ) {
                $availableSlots = searchPublicSlots(
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

    <title>Reserve Parking | Smart Parking</title>

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
            font-family:
                "Segoe UI",
                Tahoma,
                Geneva,
                Verdana,
                sans-serif;
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

        .navbar strong {
            font-size: 18px;
        }

        .navbar-links {
            display: flex;
            gap: 17px;
        }

        .navbar a {
            color: white;
            text-decoration: none;
            font-weight: 650;
        }

        main {
            width: 92%;
            max-width: 1150px;
            margin: 35px auto;
        }

        .panel {
            padding: 29px;
            border: 1px solid #d7e3f1;
            border-top: 5px solid #2563eb;
            border-radius: 14px;
            background: white;
            box-shadow: 0 10px 28px rgba(15, 39, 71, 0.09);
        }

        .panel h1 {
            margin: 0 0 24px;
            color: #071f42;
            font-size: 28px;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(
                3,
                minmax(0, 1fr)
            );
            gap: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #0f2747;
            font-weight: 700;
        }

        select,
        input {
            width: 100%;
            min-height: 44px;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: white;
            color: #0f2747;
            font-family: inherit;
        }

        select:focus,
        input:focus {
            border-color: #2563eb;
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .calculated-input {
            border-color: #93c5fd;
            background: #eff6ff;
            color: #1d4ed8;
            font-weight: 700;
            cursor: not-allowed;
        }

        .field-help {
            display: block;
            margin-top: 7px;
            color: #64748b;
            font-size: 12px;
        }

        button {
            padding: 12px 18px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(
                90deg,
                #0b2f5b,
                #2563eb
            );
            color: white;
            font-family: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            box-shadow: 0 7px 17px rgba(37, 99, 235, 0.25);
        }

        .search-button {
            margin-top: 21px;
        }

        .message {
            margin-bottom: 21px;
            padding: 14px;
            border-radius: 8px;
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

        .notice {
            margin: 15px 0 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }

        .period-summary {
            margin-top: 20px;
            padding: 14px;
            border-left: 4px solid #2563eb;
            border-radius: 8px;
            background: #eff6ff;
            color: #1e3a8a;
            font-size: 14px;
        }

        .slot-grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(230px, 1fr)
            );
            gap: 19px;
            margin-top: 26px;
        }

        .slot-card {
            padding: 23px;
            border: 1px solid #d7e3f1;
            border-top: 5px solid #059669;
            border-radius: 12px;
            background: white;
            box-shadow: 0 8px 22px rgba(15, 39, 71, 0.09);
        }

        .slot-card h3 {
            margin: 0 0 16px;
            color: #071f42;
        }

        .slot-card p {
            color: #52657d;
        }

        .slot-card button {
            width: 100%;
            margin-top: 10px;
        }

        .empty {
            margin-top: 26px;
            padding: 27px;
            border: 1px solid #d7e3f1;
            border-radius: 11px;
            background: white;
            color: #64748b;
            text-align: center;
        }

        @media (max-width: 850px) {
            .search-grid {
                grid-template-columns: repeat(
                    2,
                    minmax(0, 1fr)
                );
            }
        }

        @media (max-width: 560px) {
            .navbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .navbar-links {
                flex-wrap: wrap;
            }

            .search-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 22px;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">

    <strong>Smart Parking</strong>

    <div class="navbar-links">
        <a href="dashboard.php">
            Dashboard
        </a>

        <a href="reservations.php">
            My Reservations
        </a>

        <a href="../auth/logout.php">
            Logout
        </a>
    </div>

</nav>

<main>

    <section class="panel">

        <h1>Search Available Parking Slots</h1>

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
                Add a vehicle before searching for parking.
            </p>

            <a href="vehicles.php">
                Manage Vehicles
            </a>

        <?php else: ?>

            <form method="POST" id="searchForm">

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

                <div class="search-grid">

                    <div>
                        <label for="vehicle_id">
                            Vehicle
                        </label>

                        <select
                            id="vehicle_id"
                            name="vehicle_id"
                            required
                        >
                            <option value="">
                                Select Vehicle
                            </option>

                            <?php foreach ($vehicles as $vehicle): ?>

                                <option
                                    value="<?= (int) $vehicle[
                                        "vehicle_id"
                                    ] ?>"
                                    <?= (string) $vehicleId ===
                                        (string) $vehicle["vehicle_id"]
                                            ? "selected"
                                            : "" ?>
                                >
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
                        <label for="duration_hours">
                            Duration
                        </label>

                        <input
                            type="number"
                            id="duration_hours"
                            name="duration_hours"
                            min="1"
                            max="24"
                            step="1"
                            value="<?= (int) $durationHours ?>"
                            required
                        >

                        <small class="field-help">
                            Enter between 1 and 24 hours.
                        </small>
                    </div>

                    <div>
                        <label for="end_date_display">
                            End Date
                        </label>

                        <input
                            type="date"
                            id="end_date_display"
                            class="calculated-input"
                            value="<?= htmlspecialchars($endDate) ?>"
                            readonly
                        >
                    </div>

                    <div>
                        <label for="end_time_display">
                            End Time
                        </label>

                        <input
                            type="time"
                            id="end_time_display"
                            class="calculated-input"
                            value="<?= htmlspecialchars($endTime) ?>"
                            readonly
                        >
                    </div>

                </div>

                <div class="period-summary" id="periodSummary">
                    Reservation ends on
                    <strong id="summaryEndDate">
                        <?= htmlspecialchars($endDate) ?>
                    </strong>

                    at

                    <strong id="summaryEndTime">
                        <?= htmlspecialchars($endTime) ?>
                    </strong>.
                </div>

                <button
                    type="submit"
                    class="search-button"
                >
                    Search Available Slots
                </button>

                <p class="notice">
                    End date and time are calculated automatically.
                    Only public parking slots are displayed.
                    Overflow slots are controlled by the administrator.
                </p>

            </form>

        <?php endif; ?>

    </section>

    <?php if ($searchCompleted): ?>

        <?php if (empty($availableSlots)): ?>

            <div class="empty">
                No public parking slots are available from

                <strong>
                    <?= htmlspecialchars(
                        $startDate . " " . $startTime
                    ) ?>
                </strong>

                to

                <strong>
                    <?= htmlspecialchars(
                        $endDate . " " . $endTime
                    ) ?>
                </strong>.
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
                            <strong>Floor:</strong>
                            <?= (int) $slot["floor_no"] ?>
                        </p>

                        <p>
                            <strong>Type:</strong>

                            <?= htmlspecialchars(
                                $slot["slot_type"]
                            ) ?>
                        </p>

                        <p>
                            <strong>Start:</strong>

                            <?= htmlspecialchars(
                                $startDate . " " . $startTime
                            ) ?>
                        </p>

                        <p>
                            <strong>End:</strong>

                            <?= htmlspecialchars(
                                $endDate . " " . $endTime
                            ) ?>
                        </p>

                        <p>
                            <strong>Duration:</strong>
                            <?= (int) $durationHours ?>
                            hour(s)
                        </p>

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
                                name="duration_hours"
                                value="<?= (int) $durationHours ?>"
                            >

                            <button type="submit">
                                Reserve This Slot
                            </button>

                        </form>

                    </article>

                <?php endforeach; ?>

            </section>

        <?php endif; ?>

    <?php endif; ?>

</main>

<script>
    const startDateInput =
        document.getElementById("start_date");

    const startTimeInput =
        document.getElementById("start_time");

    const durationInput =
        document.getElementById("duration_hours");

    const endDateDisplay =
        document.getElementById("end_date_display");

    const endTimeDisplay =
        document.getElementById("end_time_display");

    const summaryEndDate =
        document.getElementById("summaryEndDate");

    const summaryEndTime =
        document.getElementById("summaryEndTime");

    function padNumber(number) {
        return String(number).padStart(2, "0");
    }

    function formatLocalDate(date) {
        return (
            date.getFullYear() +
            "-" +
            padNumber(date.getMonth() + 1) +
            "-" +
            padNumber(date.getDate())
        );
    }

    function formatLocalTime(date) {
        return (
            padNumber(date.getHours()) +
            ":" +
            padNumber(date.getMinutes())
        );
    }

    function calculateEndDateTime() {
        if (
            !startDateInput ||
            !startTimeInput ||
            !durationInput ||
            !endDateDisplay ||
            !endTimeDisplay
        ) {
            return;
        }

        const startDate = startDateInput.value;
        const startTime = startTimeInput.value;
        const durationHours = Number(
            durationInput.value
        );

        if (
            startDate === "" ||
            startTime === "" ||
            !Number.isInteger(durationHours) ||
            durationHours < 1 ||
            durationHours > 24
        ) {
            endDateDisplay.value = "";
            endTimeDisplay.value = "";

            if (summaryEndDate) {
                summaryEndDate.textContent = "—";
            }

            if (summaryEndTime) {
                summaryEndTime.textContent = "—";
            }

            return;
        }

        const startDateTime = new Date(
            startDate + "T" + startTime + ":00"
        );

        if (
            Number.isNaN(
                startDateTime.getTime()
            )
        ) {
            return;
        }

        const endDateTime = new Date(
            startDateTime.getTime() +
            durationHours * 60 * 60 * 1000
        );

        const calculatedEndDate =
            formatLocalDate(endDateTime);

        const calculatedEndTime =
            formatLocalTime(endDateTime);

        endDateDisplay.value =
            calculatedEndDate;

        endTimeDisplay.value =
            calculatedEndTime;

        if (summaryEndDate) {
            summaryEndDate.textContent =
                calculatedEndDate;
        }

        if (summaryEndTime) {
            summaryEndTime.textContent =
                calculatedEndTime;
        }
    }

    if (startDateInput) {
        startDateInput.addEventListener(
            "change",
            calculateEndDateTime
        );
    }

    if (startTimeInput) {
        startTimeInput.addEventListener(
            "change",
            calculateEndDateTime
        );
    }

    if (durationInput) {
        durationInput.addEventListener(
            "input",
            calculateEndDateTime
        );
    }

    calculateEndDateTime();
</script>

</body>
</html>
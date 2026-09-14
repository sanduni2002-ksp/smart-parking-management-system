<?php

require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../config/database.php";

$errors = [];

$success = $_SESSION["success"] ?? "";
unset($_SESSION["success"]);

$slotNumber = "";
$floorNumber = "";
$slotType = "";
$status = "Available";

$allowedTypes = [
    "Car",
    "Motorcycle",
    "Van",
    "Three Wheeler",
    "Accessible"
];

$allowedStatuses = [
    "Available",
    "Occupied",
    "Maintenance"
];

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $slotNumber = strtoupper(
        trim($_POST["slot_number"] ?? "")
    );

    $slotNumber = preg_replace(
        "/\s+/",
        "",
        $slotNumber
    );

    $floorNumber = $_POST["floor_no"] ?? "";
    $slotType = trim($_POST["slot_type"] ?? "");
    $status = trim($_POST["status"] ?? "");

    $validatedFloor = filter_var(
        $floorNumber,
        FILTER_VALIDATE_INT,
        [
            "options" => [
                "min_range" => 0
            ]
        ]
    );

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Invalid form submission.";
    }

    if (
        !preg_match(
            "/^[A-Z0-9-]{1,10}$/",
            $slotNumber
        )
    ) {
        $errors[] = "Enter a valid slot number.";
    }

    if ($validatedFloor === false) {
        $errors[] = "Floor number must be zero or greater.";
    }

    if (!in_array($slotType, $allowedTypes, true)) {
        $errors[] = "Select a valid slot type.";
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = "Select a valid slot status.";
    }

    if (empty($errors)) {
        try {
            $checkStatement = $pdo->prepare(
                "SELECT slot_id
                 FROM parking_slots
                 WHERE floor_no = :floor_no
                   AND slot_number = :slot_number"
            );

            $checkStatement->execute([
                "floor_no" => $validatedFloor,
                "slot_number" => $slotNumber
            ]);

            if ($checkStatement->fetch()) {
                $errors[] =
                    "This slot number already exists on the selected floor.";
            } else {
                $insertStatement = $pdo->prepare(
                    "INSERT INTO parking_slots (
                        slot_number,
                        floor_no,
                        slot_type,
                        status
                    ) VALUES (
                        :slot_number,
                        :floor_no,
                        :slot_type,
                        :status
                    )"
                );

                $insertStatement->execute([
                    "slot_number" => $slotNumber,
                    "floor_no" => $validatedFloor,
                    "slot_type" => $slotType,
                    "status" => $status
                ]);

                $_SESSION["success"] =
                    "Parking slot added successfully.";

                header("Location: slots.php");
                exit;
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $errors[] = "Unable to add the parking slot.";
        }
    }
}

$slotStatement = $pdo->query(
    "SELECT
        ps.slot_id,
        ps.slot_number,
        ps.floor_no,
        ps.slot_type,
        ps.status,
        COUNT(r.reservation_id) AS reservation_count
     FROM parking_slots AS ps
     LEFT JOIN reservations AS r
        ON ps.slot_id = r.slot_id
     GROUP BY
        ps.slot_id,
        ps.slot_number,
        ps.floor_no,
        ps.slot_type,
        ps.status
     ORDER BY
        ps.floor_no ASC,
        ps.slot_number ASC"
);

$slots = $slotStatement->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Parking Slots | Smart Parking</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        nav {
            display: flex;
            justify-content: space-between;
            padding: 18px 6%;
            background: #0f172a;
            color: white;
        }

        nav a {
            margin-left: 14px;
            color: white;
            text-decoration: none;
        }

        .container {
            width: 90%;
            max-width: 1150px;
            margin: 35px auto;
        }

        .panel {
            margin-bottom: 25px;
            padding: 25px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, 0.08);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(180px, 1fr)
            );
            gap: 15px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input,
        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
        }

        .add-button {
            margin-top: 18px;
            padding: 11px 20px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }

        th {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 13px;
        }

        .available {
            background: #dcfce7;
            color: #166534;
        }

        .occupied {
            background: #fee2e2;
            color: #991b1b;
        }

        .maintenance {
            background: #fef3c7;
            color: #92400e;
        }

        .edit,
        .delete {
            padding: 7px 10px;
            border: none;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            cursor: pointer;
        }

        .edit {
            background: #d97706;
        }

        .delete {
            background: #dc2626;
        }

        .delete-form {
            display: inline;
        }

        .message {
            margin-bottom: 20px;
            padding: 13px;
            border-radius: 7px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 750px) {
            .table-wrapper {
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>

<nav>
    <strong>Smart Parking Admin</strong>

    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Logout</a>
    </div>
</nav>

<main class="container">

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?= htmlspecialchars($success) ?>
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

    <section class="panel">
        <h1>Add Parking Slot</h1>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
            >

            <div class="form-grid">

                <div>
                    <label for="slot_number">Slot Number</label>

                    <input
                        type="text"
                        id="slot_number"
                        name="slot_number"
                        maxlength="10"
                        placeholder="A01"
                        value="<?= htmlspecialchars($slotNumber) ?>"
                        required
                    >
                </div>

                <div>
                    <label for="floor_no">Floor Number</label>

                    <input
                        type="number"
                        id="floor_no"
                        name="floor_no"
                        min="0"
                        value="<?= htmlspecialchars($floorNumber) ?>"
                        required
                    >
                </div>

                <div>
                    <label for="slot_type">Slot Type</label>

                    <select
                        id="slot_type"
                        name="slot_type"
                        required
                    >
                        <option value="">Select type</option>

                        <?php foreach ($allowedTypes as $type): ?>
                            <option
                                value="<?= htmlspecialchars($type) ?>"
                                <?= $slotType === $type ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="status">Status</label>

                    <select
                        id="status"
                        name="status"
                        required
                    >
                        <?php foreach ($allowedStatuses as $item): ?>
                            <option
                                value="<?= htmlspecialchars($item) ?>"
                                <?= $status === $item ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($item) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <button class="add-button" type="submit">
                Add Slot
            </button>

        </form>
    </section>

    <section class="panel">
        <h2>Parking Slots</h2>

        <div class="table-wrapper">

            <table>
                <thead>
                <tr>
                    <th>Floor</th>
                    <th>Slot</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Reservations</th>
                    <th>Actions</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($slots as $slot): ?>

                    <tr>
                        <td><?= (int) $slot["floor_no"] ?></td>

                        <td>
                            <?= htmlspecialchars($slot["slot_number"]) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars($slot["slot_type"]) ?>
                        </td>

                        <td>
                            <span class="badge <?= strtolower($slot["status"]) ?>">
                                <?= htmlspecialchars($slot["status"]) ?>
                            </span>
                        </td>

                        <td>
                            <?= (int) $slot["reservation_count"] ?>
                        </td>

                        <td>
                            <a
                                class="edit"
                                href="edit_slot.php?id=<?= (int) $slot["slot_id"] ?>"
                            >
                                Edit
                            </a>

                            <form
                                class="delete-form"
                                method="POST"
                                action="delete_slot.php"
                                onsubmit="return confirm('Delete this parking slot?');"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="slot_id"
                                    value="<?= (int) $slot["slot_id"] ?>"
                                >

                                <button class="delete" type="submit">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

        </div>
    </section>

</main>

</body>
</html>
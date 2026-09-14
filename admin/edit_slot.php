<?php

require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../config/database.php";

$slotId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$errors = [];

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

if (!$slotId) {
    header("Location: slots.php");
    exit;
}

$statement = $pdo->prepare(
    "SELECT *
     FROM parking_slots
     WHERE slot_id = :slot_id"
);

$statement->execute([
    "slot_id" => $slotId
]);

$slot = $statement->fetch();

if (!$slot) {
    http_response_code(404);
    exit("Parking slot not found.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $slotNumber = strtoupper(
        trim($_POST["slot_number"] ?? "")
    );

    $slotNumber = preg_replace("/\s+/", "", $slotNumber);

    $floorNumber = filter_var(
        $_POST["floor_no"] ?? "",
        FILTER_VALIDATE_INT,
        [
            "options" => [
                "min_range" => 0
            ]
        ]
    );

    $slotType = trim($_POST["slot_type"] ?? "");
    $status = trim($_POST["status"] ?? "");

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Invalid form submission.";
    }

    if (!preg_match("/^[A-Z0-9-]{1,10}$/", $slotNumber)) {
        $errors[] = "Enter a valid slot number.";
    }

    if ($floorNumber === false) {
        $errors[] = "Enter a valid floor number.";
    }

    if (!in_array($slotType, $allowedTypes, true)) {
        $errors[] = "Select a valid slot type.";
    }

    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = "Select a valid status.";
    }

    if (empty($errors)) {
        try {
            $updateStatement = $pdo->prepare(
                "UPDATE parking_slots
                 SET
                    slot_number = :slot_number,
                    floor_no = :floor_no,
                    slot_type = :slot_type,
                    status = :status
                 WHERE slot_id = :slot_id"
            );

            $updateStatement->execute([
                "slot_number" => $slotNumber,
                "floor_no" => $floorNumber,
                "slot_type" => $slotType,
                "status" => $status,
                "slot_id" => $slotId
            ]);

            $_SESSION["success"] =
                "Parking slot updated successfully.";

            header("Location: slots.php");
            exit;

        } catch (PDOException $exception) {
            if ($exception->getCode() === "23000") {
                $errors[] =
                    "This slot number already exists on that floor.";
            } else {
                error_log($exception->getMessage());
                $errors[] = "Unable to update the parking slot.";
            }
        }
    }

    $slot["slot_number"] = $slotNumber;
    $slot["floor_no"] = $floorNumber;
    $slot["slot_type"] = $slotType;
    $slot["status"] = $status;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Parking Slot</title>

    <style>
        body {
            margin: 0;
            padding: 30px 15px;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
        }

        .card {
            max-width: 500px;
            margin: auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
        }

        label {
            display: block;
            margin: 16px 0 7px;
            font-weight: bold;
        }

        input,
        select {
            box-sizing: border-box;
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
        }

        button {
            margin-top: 20px;
            padding: 11px 18px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            cursor: pointer;
        }

        a {
            margin-left: 12px;
            color: #475569;
        }

        .error {
            padding: 12px;
            border-radius: 7px;
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>

<body>

<div class="card">

    <h1>Edit Parking Slot</h1>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
        >

        <label for="slot_number">Slot Number</label>
        <input
            type="text"
            id="slot_number"
            name="slot_number"
            maxlength="10"
            value="<?= htmlspecialchars($slot["slot_number"]) ?>"
            required
        >

        <label for="floor_no">Floor Number</label>
        <input
            type="number"
            id="floor_no"
            name="floor_no"
            min="0"
            value="<?= (int) $slot["floor_no"] ?>"
            required
        >

        <label for="slot_type">Slot Type</label>
        <select id="slot_type" name="slot_type" required>
            <?php foreach ($allowedTypes as $type): ?>
                <option
                    value="<?= htmlspecialchars($type) ?>"
                    <?= $slot["slot_type"] === $type ? "selected" : "" ?>
                >
                    <?= htmlspecialchars($type) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="status">Status</label>
        <select id="status" name="status" required>
            <?php foreach ($allowedStatuses as $item): ?>
                <option
                    value="<?= htmlspecialchars($item) ?>"
                    <?= $slot["status"] === $item ? "selected" : "" ?>
                >
                    <?= htmlspecialchars($item) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Update Slot</button>
        <a href="slots.php">Cancel</a>

    </form>

</div>

</body>
</html>
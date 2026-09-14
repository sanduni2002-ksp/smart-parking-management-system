<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

$userId = (int) $_SESSION["user_id"];
$vehicleId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
$errors = [];

$allowedTypes = [
    "Car",
    "Motorcycle",
    "Van",
    "Three Wheeler",
    "Other"
];

if (!$vehicleId) {
    header("Location: vehicles.php");
    exit;
}

$selectStatement = $pdo->prepare(
    "SELECT
        vehicle_id,
        vehicle_number,
        vehicle_type,
        vehicle_brand
     FROM vehicles
     WHERE vehicle_id = :vehicle_id
       AND user_id = :user_id"
);

$selectStatement->execute([
    "vehicle_id" => $vehicleId,
    "user_id" => $userId
]);

$vehicle = $selectStatement->fetch();

if (!$vehicle) {
    http_response_code(404);
    exit("Vehicle not found.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $vehicleNumber = strtoupper(
        trim($_POST["vehicle_number"] ?? "")
    );

    $vehicleNumber = preg_replace(
        "/\s+/",
        "",
        $vehicleNumber
    );

    $vehicleType = trim($_POST["vehicle_type"] ?? "");
    $vehicleBrand = trim($_POST["vehicle_brand"] ?? "");

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
            "/^[A-Z0-9-]{3,20}$/",
            $vehicleNumber
        )
    ) {
        $errors[] = "Enter a valid vehicle number.";
    }

    if (!in_array($vehicleType, $allowedTypes, true)) {
        $errors[] = "Select a valid vehicle type.";
    }

    if (strlen($vehicleBrand) > 50) {
        $errors[] = "Vehicle brand cannot exceed 50 characters.";
    }

    if (empty($errors)) {
        try {
            $updateStatement = $pdo->prepare(
                "UPDATE vehicles
                 SET
                    vehicle_number = :vehicle_number,
                    vehicle_type = :vehicle_type,
                    vehicle_brand = :vehicle_brand
                 WHERE vehicle_id = :vehicle_id
                   AND user_id = :user_id"
            );

            $updateStatement->execute([
                "vehicle_number" => $vehicleNumber,
                "vehicle_type" => $vehicleType,
                "vehicle_brand" => $vehicleBrand ?: null,
                "vehicle_id" => $vehicleId,
                "user_id" => $userId
            ]);

            $_SESSION["success"] = "Vehicle updated successfully.";

            header("Location: vehicles.php");
            exit;

        } catch (PDOException $exception) {
            if ($exception->getCode() === "23000") {
                $errors[] = "This vehicle number already exists.";
            } else {
                error_log($exception->getMessage());
                $errors[] = "Unable to update the vehicle.";
            }
        }
    }

    $vehicle["vehicle_number"] = $vehicleNumber;
    $vehicle["vehicle_type"] = $vehicleType;
    $vehicle["vehicle_brand"] = $vehicleBrand;
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

    <title>Edit Vehicle</title>

    <style>
        body {
            margin: 0;
            padding: 30px;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        .card {
            max-width: 500px;
            margin: auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, 0.08);
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
            padding: 11px 20px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            cursor: pointer;
        }

        a {
            display: inline-block;
            margin-left: 10px;
            color: #475569;
        }

        .error {
            padding: 12px;
            background: #fee2e2;
            color: #991b1b;
            border-radius: 7px;
        }
    </style>
</head>

<body>

<div class="card">

    <h1>Edit Vehicle</h1>

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

        <label for="vehicle_number">Vehicle Number</label>

        <input
            type="text"
            id="vehicle_number"
            name="vehicle_number"
            maxlength="20"
            value="<?= htmlspecialchars($vehicle["vehicle_number"]) ?>"
            required
        >

        <label for="vehicle_type">Vehicle Type</label>

        <select
            id="vehicle_type"
            name="vehicle_type"
            required
        >
            <?php foreach ($allowedTypes as $type): ?>
                <option
                    value="<?= htmlspecialchars($type) ?>"
                    <?= $vehicle["vehicle_type"] === $type ? "selected" : "" ?>
                >
                    <?= htmlspecialchars($type) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="vehicle_brand">Vehicle Brand</label>

        <input
            type="text"
            id="vehicle_brand"
            name="vehicle_brand"
            maxlength="50"
            value="<?= htmlspecialchars($vehicle["vehicle_brand"] ?? "") ?>"
        >

        <button type="submit">Update Vehicle</button>

        <a href="vehicles.php">Cancel</a>

    </form>

</div>

</body>
</html>
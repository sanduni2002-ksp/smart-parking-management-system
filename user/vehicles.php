<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

$userId = (int) $_SESSION["user_id"];
$errors = [];

$success = $_SESSION["success"] ?? "";
unset($_SESSION["success"]);

$vehicleNumber = "";
$vehicleType = "";
$vehicleBrand = "";

$allowedTypes = [
    "Car",
    "Motorcycle",
    "Van",
    "Three Wheeler",
    "Other"
];

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
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

    if ($vehicleNumber === "") {
        $errors[] = "Vehicle number is required.";
    } elseif (
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
            $checkStatement = $pdo->prepare(
                "SELECT vehicle_id
                 FROM vehicles
                 WHERE vehicle_number = :vehicle_number"
            );

            $checkStatement->execute([
                "vehicle_number" => $vehicleNumber
            ]);

            if ($checkStatement->fetch()) {
                $errors[] = "This vehicle number is already registered.";
            } else {
                $insertStatement = $pdo->prepare(
                    "INSERT INTO vehicles (
                        user_id,
                        vehicle_number,
                        vehicle_type,
                        vehicle_brand
                    ) VALUES (
                        :user_id,
                        :vehicle_number,
                        :vehicle_type,
                        :vehicle_brand
                    )"
                );

                $insertStatement->execute([
                    "user_id" => $userId,
                    "vehicle_number" => $vehicleNumber,
                    "vehicle_type" => $vehicleType,
                    "vehicle_brand" => $vehicleBrand ?: null
                ]);

                $_SESSION["success"] = "Vehicle added successfully.";

                header("Location: vehicles.php");
                exit;
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $errors[] = "Unable to add the vehicle.";
        }
    }
}

/* Get only the logged-in user's vehicles */
$vehicleStatement = $pdo->prepare(
    "SELECT
        vehicle_id,
        vehicle_number,
        vehicle_type,
        vehicle_brand
     FROM vehicles
     WHERE user_id = :user_id
     ORDER BY vehicle_id DESC"
);

$vehicleStatement->execute([
    "user_id" => $userId
]);

$vehicles = $vehicleStatement->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>My Vehicles | Smart Parking</title>

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
            align-items: center;
            padding: 18px 6%;
            background: #0f172a;
            color: white;
        }

        nav a {
            margin-left: 12px;
            color: white;
            text-decoration: none;
        }

        .container {
            width: 88%;
            max-width: 1100px;
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
                minmax(200px, 1fr)
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
            padding: 11px 22px;
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
            padding: 13px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }

        th {
            background: #f8fafc;
        }

        .edit-button,
        .delete-button {
            display: inline-block;
            padding: 7px 11px;
            border: none;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            cursor: pointer;
        }

        .edit-button {
            background: #d97706;
        }

        .delete-button {
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

        @media (max-width: 700px) {
            .table-wrapper {
                overflow-x: auto;
            }
        }
    </style>
    <link
    rel="stylesheet"
    href="/smart_parking/assets/css/theme.css?v=1"
>
</head>

<body>

<nav>
    <strong>Smart Parking</strong>

    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="../auth/logout.php">Logout</a>
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
        <h1>Add Vehicle</h1>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
            >

            <div class="form-grid">

                <div>
                    <label for="vehicle_number">
                        Vehicle Number
                    </label>

                    <input
                        type="text"
                        id="vehicle_number"
                        name="vehicle_number"
                        maxlength="20"
                        placeholder="CAB-1234"
                        value="<?= htmlspecialchars($vehicleNumber) ?>"
                        required
                    >
                </div>

                <div>
                    <label for="vehicle_type">
                        Vehicle Type
                    </label>

                    <select
                        id="vehicle_type"
                        name="vehicle_type"
                        required
                    >
                        <option value="">Select type</option>

                        <?php foreach ($allowedTypes as $type): ?>
                            <option
                                value="<?= htmlspecialchars($type) ?>"
                                <?= $vehicleType === $type ? "selected" : "" ?>
                            >
                                <?= htmlspecialchars($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="vehicle_brand">
                        Vehicle Brand
                    </label>

                    <input
                        type="text"
                        id="vehicle_brand"
                        name="vehicle_brand"
                        maxlength="50"
                        placeholder="Toyota"
                        value="<?= htmlspecialchars($vehicleBrand) ?>"
                    >
                </div>

            </div>

            <button class="add-button" type="submit">
                Add Vehicle
            </button>

        </form>
    </section>

    <section class="panel">
        <h2>My Vehicles</h2>

        <?php if (empty($vehicles)): ?>

            <p>No vehicles have been registered.</p>

        <?php else: ?>

            <div class="table-wrapper">

                <table>
                    <thead>
                    <tr>
                        <th>Vehicle Number</th>
                        <th>Type</th>
                        <th>Brand</th>
                        <th>Actions</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($vehicles as $vehicle): ?>

                        <tr>
                            <td>
                                <?= htmlspecialchars($vehicle["vehicle_number"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($vehicle["vehicle_type"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $vehicle["vehicle_brand"] ?? "-"
                                ) ?>
                            </td>

                            <td>
                                <a
                                    class="edit-button"
                                    href="edit_vehicle.php?id=<?= (int) $vehicle["vehicle_id"] ?>"
                                >
                                    Edit
                                </a>

                                <form
                                    class="delete-form"
                                    method="POST"
                                    action="delete_vehicle.php"
                                    onsubmit="return confirm('Delete this vehicle?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="vehicle_id"
                                        value="<?= (int) $vehicle["vehicle_id"] ?>"
                                    >

                                    <button
                                        class="delete-button"
                                        type="submit"
                                    >
                                        Delete
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
<?php

require_once __DIR__ . "/config/database.php";

$databaseName = $pdo
    ->query("SELECT DATABASE()")
    ->fetchColumn();

$tableCount = $pdo
    ->query(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()"
    )
    ->fetchColumn();

$slotCount = $pdo
    ->query("SELECT COUNT(*) FROM parking_slots")
    ->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Database Test</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            padding: 40px;
        }

        .result-box {
            max-width: 600px;
            margin: auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        .success {
            color: #15803d;
        }
    </style>
</head>

<body>

<div class="result-box">
    <h1 class="success">Database connection successful!</h1>

    <p>
        <strong>Connected database:</strong>
        <?= htmlspecialchars($databaseName) ?>
    </p>

    <p>
        <strong>Number of tables:</strong>
        <?= htmlspecialchars($tableCount) ?>
    </p>

    <p>
        <strong>Available sample slots:</strong>
        <?= htmlspecialchars($slotCount) ?>
    </p>
</div>

</body>
</html>
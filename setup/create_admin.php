<?php

session_start();

require_once __DIR__ . "/../config/database.php";

$errors = [];
$success = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/* Prevent creating another admin through this page */
$adminCount = (int) $pdo
    ->query("SELECT COUNT(*) FROM admins")
    ->fetchColumn();

if ($_SERVER["REQUEST_METHOD"] === "POST" && $adminCount === 0) {
    $adminName = trim($_POST["admin_name"] ?? "");
    $email = strtolower(trim($_POST["email"] ?? ""));
    $phone = preg_replace(
        "/[\s-]/",
        "",
        trim($_POST["phone"] ?? "")
    );

    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Invalid form submission.";
    }

    if (strlen($adminName) < 3) {
        $errors[] = "Enter a valid administrator name.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Enter a valid email address.";
    }

    if (!preg_match("/^\+?[0-9]{9,15}$/", $phone)) {
        $errors[] = "Enter a valid phone number.";
    }

    if (strlen($password) < 8) {
        $errors[] = "Password must contain at least 8 characters.";
    }

    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {
        try {
            $statement = $pdo->prepare(
                "INSERT INTO admins (
                    admin_name,
                    email,
                    password,
                    phone
                ) VALUES (
                    :admin_name,
                    :email,
                    :password,
                    :phone
                )"
            );

            $statement->execute([
                "admin_name" => $adminName,
                "email" => $email,
                "password" => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                "phone" => $phone
            ]);

            $success = "Administrator account created successfully.";
            $adminCount = 1;

        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $errors[] = "Unable to create the administrator.";
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

    <title>Create Administrator</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
        }

        .card {
            max-width: 480px;
            margin: auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, 0.1);
        }

        label {
            display: block;
            margin: 15px 0 7px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
        }

        button {
            width: 100%;
            margin-top: 20px;
            padding: 12px;
            border: none;
            border-radius: 7px;
            background: #2563eb;
            color: white;
            cursor: pointer;
        }

        .message {
            padding: 12px;
            border-radius: 7px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        a {
            color: #2563eb;
        }
    </style>
</head>

<body>

<div class="card">

    <h1>Administrator Setup</h1>

    <?php if (!empty($errors)): ?>
        <div class="message error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($adminCount > 0): ?>

        <p>An administrator account already exists.</p>

        <a href="../admin/login.php">
            Go to Admin Login
        </a>

    <?php else: ?>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
            >

            <label for="admin_name">Administrator Name</label>
            <input
                type="text"
                id="admin_name"
                name="admin_name"
                maxlength="100"
                required
            >

            <label for="email">Email Address</label>
            <input
                type="email"
                id="email"
                name="email"
                maxlength="100"
                required
            >

            <label for="phone">Phone Number</label>
            <input
                type="tel"
                id="phone"
                name="phone"
                maxlength="15"
                required
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                required
            >

            <label for="confirm_password">
                Confirm Password
            </label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                minlength="8"
                required
            >

            <button type="submit">
                Create Administrator
            </button>

        </form>

    <?php endif; ?>

</div>

</body>
</html>
<?php

session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/admin_settings.php";

if (
    !empty($_SESSION["admin_id"]) &&
    ($_SESSION["role"] ?? "") === "admin"
) {
    header("Location: dashboard.php");
    exit;
}

$errors = [];

$adminName = "";
$username = "";
$email = "";
$phone = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $adminName = trim(
        $_POST["admin_name"] ?? ""
    );

    $username = strtolower(
        trim($_POST["username"] ?? "")
    );

    $email = strtolower(
        trim($_POST["email"] ?? "")
    );

    $phone = preg_replace(
        "/[\s-]/",
        "",
        trim($_POST["phone"] ?? "")
    );

    $registrationKey =
        $_POST["registration_key"] ?? "";

    $password =
        $_POST["password"] ?? "";

    $confirmPassword =
        $_POST["confirm_password"] ?? "";

    $csrfToken =
        $_POST["csrf_token"] ?? "";

    if (
        empty($_SESSION["csrf_token"]) ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Invalid form submission.";
    }

    if (
        !hash_equals(
            ADMIN_REGISTRATION_KEY,
            $registrationKey
        )
    ) {
        $errors[] =
            "The administrator registration key is incorrect.";
    }

    if (strlen($adminName) < 3) {
        $errors[] =
            "Administrator name must contain at least 3 characters.";
    }

    if (
        !preg_match(
            "/^[a-z0-9_]{4,30}$/",
            $username
        )
    ) {
        $errors[] =
            "Username must contain 4–30 lowercase letters, numbers or underscores.";
    }

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] = "Enter a valid email address.";
    }

    if (
        !preg_match(
            "/^\+?[0-9]{9,15}$/",
            $phone
        )
    ) {
        $errors[] = "Enter a valid phone number.";
    }

    if (strlen($password) < 8) {
        $errors[] =
            "Password must contain at least 8 characters.";
    }

    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {
        try {
            $checkStatement = $pdo->prepare(
                "SELECT
                    username,
                    email
                 FROM admins
                 WHERE username = :username
                    OR email = :email"
            );

            $checkStatement->execute([
                "username" => $username,
                "email" => $email
            ]);

            $existingAdmins = $checkStatement->fetchAll(
                PDO::FETCH_ASSOC
            );

            foreach ($existingAdmins as $existingAdmin) {

                if (
                    strtolower($existingAdmin["username"])
                    === $username
                ) {
                    $errors[] =
                        "This administrator username is already taken.";
                }

                if (
                    strtolower($existingAdmin["email"])
                    === $email
                ) {
                    $errors[] =
                        "An administrator already exists with this email.";
                }
            }

            if (empty($errors)) {

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $insertStatement = $pdo->prepare(
                    "INSERT INTO admins (
                        admin_name,
                        username,
                        email,
                        password,
                        phone
                    ) VALUES (
                        :admin_name,
                        :username,
                        :email,
                        :password,
                        :phone
                    )"
                );

                $insertStatement->execute([
                    "admin_name" => $adminName,
                    "username" => $username,
                    "email" => $email,
                    "password" => $hashedPassword,
                    "phone" => $phone
                ]);

                $_SESSION["admin_success"] =
                    "Admin registration successful. Login using your username and password.";

                $_SESSION["csrf_token"] = bin2hex(
                    random_bytes(32)
                );

                header("Location: login.php");
                exit;
            }

        } catch (PDOException $exception) {

            error_log($exception->getMessage());

            if ($exception->getCode() === "23000") {
                $errors[] =
                    "Administrator username or email already exists.";
            } else {
                $errors[] =
                    "Admin registration failed. Please try again.";
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

    <title>Admin Register | Smart Parking</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #0f172a;
            color: #1e293b;
        }

        .container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }

        .card {
            width: 100%;
            max-width: 480px;
            padding: 35px;
            background: white;
            border-radius: 15px;
        }

        h1,
        .subtitle,
        .links {
            text-align: center;
        }

        h1 {
            margin-top: 0;
        }

        .subtitle {
            color: #64748b;
        }

        label {
            display: block;
            margin: 16px 0 7px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
        }

        input:focus {
            border-color: #2563eb;
            outline: none;
        }

        small {
            display: block;
            margin-top: 6px;
            color: #64748b;
        }

        button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            border: none;
            border-radius: 8px;
            background: #059669;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #047857;
        }

        .error {
            margin-bottom: 18px;
            padding: 12px;
            border-radius: 8px;
            background: #fee2e2;
            color: #991b1b;
        }

        .error ul {
            margin: 0;
            padding-left: 20px;
        }

        .links {
            margin-top: 20px;
        }

        a {
            color: #2563eb;
            text-decoration: none;
        }
    </style>
    <link
    rel="stylesheet"
    href="/smart_parking/assets/css/theme.css?v=1"
>
</head>

<body>

<div class="container">

    <div class="card">

        <h1>Admin Registration</h1>

        <p class="subtitle">
            Create a Smart Parking administrator account
        </p>

        <?php if (!empty($errors)): ?>

            <div class="error">
                <ul>
                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= htmlspecialchars($error) ?>
                        </li>

                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION["csrf_token"]
                ) ?>"
            >

            <label for="admin_name">
                Administrator Name
            </label>

            <input
                type="text"
                id="admin_name"
                name="admin_name"
                maxlength="100"
                value="<?= htmlspecialchars($adminName) ?>"
                required
            >

            <label for="username">
                Username
            </label>

            <input
                type="text"
                id="username"
                name="username"
                minlength="4"
                maxlength="30"
                value="<?= htmlspecialchars($username) ?>"
                placeholder="admin_deshan"
                autocomplete="username"
                required
            >

            <small>
                Use lowercase letters, numbers and underscores only.
            </small>

            <label for="email">
                Email Address
            </label>

            <input
                type="email"
                id="email"
                name="email"
                maxlength="100"
                value="<?= htmlspecialchars($email) ?>"
                required
            >

            <label for="phone">
                Phone Number
            </label>

            <input
                type="tel"
                id="phone"
                name="phone"
                maxlength="16"
                value="<?= htmlspecialchars($phone) ?>"
                placeholder="0771234567"
                required
            >

            <label for="registration_key">
                Admin Registration Key
            </label>

            <input
                type="password"
                id="registration_key"
                name="registration_key"
                required
            >

            <label for="password">
                Password
            </label>

            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                autocomplete="new-password"
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
                autocomplete="new-password"
                required
            >

            <button type="submit">
                Create Admin Account
            </button>

        </form>

        <p class="links">
            Already registered?
            <a href="login.php">Admin Login</a>
        </p>

        <p class="links">
            <a href="index.php">Back to Admin Portal</a>
        </p>

    </div>

</div>

</body>
</html>
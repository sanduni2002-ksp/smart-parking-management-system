<?php

session_start();

require_once __DIR__ . "/../config/database.php";

/*
 * If the user is already logged in,
 * prevent opening the registration page.
 */
if (isset($_SESSION["user_id"])) {
    header("Location: ../user/dashboard.php");
    exit;
}

$errors = [];

$fullName = "";
$username = "";
$email = "";
$phone = "";

/*
 * Create CSRF token.
 */
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/*
 * Process registration form.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim(
        $_POST["full_name"] ?? ""
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

    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $csrfToken = $_POST["csrf_token"] ?? "";

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
        $errors[] = "Invalid form submission. Please try again.";
    }

    /*
     * Full name validation.
     */
    if (strlen($fullName) < 3) {
        $errors[] =
            "Full name must contain at least 3 characters.";
    }

    if (strlen($fullName) > 100) {
        $errors[] =
            "Full name cannot contain more than 100 characters.";
    }

    /*
     * Username validation.
     */
    if (
        !preg_match(
            "/^[a-z0-9_]{4,30}$/",
            $username
        )
    ) {
        $errors[] =
            "Username must contain 4–30 lowercase letters, numbers or underscores.";
    }

    /*
     * Email validation.
     */
    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $errors[] = "Enter a valid email address.";
    }

    /*
     * Phone validation.
     */
    if (
        !preg_match(
            "/^\+?[0-9]{9,15}$/",
            $phone
        )
    ) {
        $errors[] = "Enter a valid phone number.";
    }

    /*
     * Password validation.
     */
    if (strlen($password) < 8) {
        $errors[] =
            "Password must contain at least 8 characters.";
    }

    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    /*
     * Check username and email availability.
     */
    if (empty($errors)) {
        try {
            $checkStatement = $pdo->prepare(
                "SELECT
                    username,
                    email
                 FROM users
                 WHERE username = :username
                    OR email = :email"
            );

            $checkStatement->execute([
                "username" => $username,
                "email" => $email
            ]);

            $existingUsers = $checkStatement->fetchAll(
                PDO::FETCH_ASSOC
            );

            foreach ($existingUsers as $existingUser) {

                if (
                    strtolower($existingUser["username"])
                    === $username
                ) {
                    $errors[] =
                        "This username is already taken.";
                }

                if (
                    strtolower($existingUser["email"])
                    === $email
                ) {
                    $errors[] =
                        "An account already exists with this email.";
                }
            }

            /*
             * Insert new user.
             */
            if (empty($errors)) {

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $insertStatement = $pdo->prepare(
                    "INSERT INTO users (
                        username,
                        full_name,
                        email,
                        phone,
                        password,
                        registration_date
                    ) VALUES (
                        :username,
                        :full_name,
                        :email,
                        :phone,
                        :password,
                        CURDATE()
                    )"
                );

                $insertStatement->execute([
                    "username" => $username,
                    "full_name" => $fullName,
                    "email" => $email,
                    "phone" => $phone,
                    "password" => $hashedPassword
                ]);

                /*
                 * Save message for login.php.
                 */
                $_SESSION["success"] =
                    "Registration successful! Please login using your username and password.";

                /*
                 * Generate a new CSRF token.
                 */
                $_SESSION["csrf_token"] = bin2hex(
                    random_bytes(32)
                );

                /*
                 * Automatically redirect to login page.
                 */
                header("Location: login.php");
                exit;
            }

        } catch (PDOException $exception) {

            error_log($exception->getMessage());

            if ($exception->getCode() === "23000") {
                $errors[] =
                    "The username or email already exists.";
            } else {
                $errors[] =
                    "Registration failed. Please try again.";
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

    <title>Register | Smart Parking</title>

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
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.1);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 8px;
            text-align: center;
            color: #0f172a;
        }

        .subtitle {
            margin-bottom: 25px;
            text-align: center;
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
            font-size: 15px;
        }

        input:focus {
            border-color: #2563eb;
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .message {
            margin-bottom: 18px;
            padding: 12px;
            border-radius: 8px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .error ul {
            margin: 0;
            padding-left: 20px;
        }

        .error li + li {
            margin-top: 5px;
        }

        small {
            display: block;
            margin-top: 6px;
            color: #64748b;
        }

        .login-link,
        .home-link {
            text-align: center;
        }

        .login-link {
            margin-top: 22px;
        }

        .home-link {
            margin-top: 12px;
            margin-bottom: 0;
        }

        a {
            color: #2563eb;
            font-weight: bold;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
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

        <h1>Create Account</h1>

        <p class="subtitle">
            Smart Parking Reservation System
        </p>

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

        <form method="POST" action="">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION["csrf_token"]
                ) ?>"
            >

            <label for="full_name">
                Full Name
            </label>

            <input
                type="text"
                id="full_name"
                name="full_name"
                maxlength="100"
                value="<?= htmlspecialchars($fullName) ?>"
                autocomplete="name"
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
                placeholder="deshan_123"
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
                autocomplete="email"
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
                autocomplete="tel"
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
                Create Account
            </button>

        </form>

        <p class="login-link">
            Already registered?
            <a href="login.php">
                Login here
            </a>
        </p>

        <p class="home-link">
            <a href="../index.php">
                Back to Home
            </a>
        </p>

    </div>

</div>

</body>
</html>
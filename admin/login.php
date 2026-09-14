<?php

session_start();

require_once __DIR__ . "/../config/database.php";

if (
    !empty($_SESSION["admin_id"]) &&
    ($_SESSION["role"] ?? "") === "admin"
) {
    header("Location: dashboard.php");
    exit;
}

$successMessage =
    $_SESSION["admin_success"] ?? "";

unset($_SESSION["admin_success"]);

$error = "";
$username = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = strtolower(
        trim($_POST["username"] ?? "")
    );

    $password =
        $_POST["password"] ?? "";

    $csrfToken =
        $_POST["csrf_token"] ?? "";

    if (
        empty($_SESSION["csrf_token"]) ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $error =
            "Invalid form submission. Please try again.";

    } elseif (
        !preg_match(
            "/^[a-z0-9_]{4,30}$/",
            $username
        ) ||
        $password === ""
    ) {
        $error =
            "Enter a valid username and password.";

    } else {
        try {
            $statement = $pdo->prepare(
                "SELECT
                    admin_id,
                    admin_name,
                    username,
                    email,
                    password
                 FROM admins
                 WHERE username = :username
                 LIMIT 1"
            );

            $statement->execute([
                "username" => $username
            ]);

            $admin = $statement->fetch(
                PDO::FETCH_ASSOC
            );

            if (
                $admin &&
                password_verify(
                    $password,
                    $admin["password"]
                )
            ) {
                $_SESSION = [];

                session_regenerate_id(true);

                $_SESSION["admin_id"] =
                    (int) $admin["admin_id"];

                $_SESSION["admin_name"] =
                    $admin["admin_name"];

                $_SESSION["admin_username"] =
                    $admin["username"];

                $_SESSION["admin_email"] =
                    $admin["email"];

                $_SESSION["role"] =
                    "admin";

                $_SESSION["csrf_token"] = bin2hex(
                    random_bytes(32)
                );

                header("Location: dashboard.php");
                exit;
            }

            $error =
                "Invalid administrator username or password.";

        } catch (PDOException $exception) {

            error_log($exception->getMessage());

            $error =
                "Admin login failed. Please try again.";
        }
    }

    if ($error !== "") {
        $_SESSION["csrf_token"] = bin2hex(
            random_bytes(32)
        );
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

    <title>Admin Login | Smart Parking</title>

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
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 430px;
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
            margin: 18px 0 7px;
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

        button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: white;
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

        .success {
            background: #dcfce7;
            color: #166534;
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

        <h1>Admin Login</h1>

        <p class="subtitle">
            Enter your username and password
        </p>

        <?php if ($successMessage !== ""): ?>

            <div class="message success">
                <?= htmlspecialchars($successMessage) ?>
            </div>

        <?php endif; ?>

        <?php if ($error !== ""): ?>

            <div class="message error">
                <?= htmlspecialchars($error) ?>
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

            <label for="username">
                Admin Username
            </label>

            <input
                type="text"
                id="username"
                name="username"
                minlength="4"
                maxlength="30"
                value="<?= htmlspecialchars($username) ?>"
                placeholder="Enter admin username"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">
                Password
            </label>

            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                placeholder="Enter password"
                autocomplete="current-password"
                required
            >

            <button type="submit">
                Admin Login
            </button>

        </form>

        <p class="links">
            New administrator?
            <a href="register.php">Register here</a>
        </p>

        <p class="links">
            <a href="index.php">Back to Admin Portal</a>
        </p>

    </div>

</div>

</body>
</html>
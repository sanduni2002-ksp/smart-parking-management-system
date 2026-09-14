<?php

session_start();

require_once __DIR__ . "/../config/database.php";

/*
 * Redirect users who are already logged in.
 */
if (
    !empty($_SESSION["user_id"]) &&
    ($_SESSION["role"] ?? "") === "user"
) {
    header("Location: ../user/dashboard.php");
    exit;
}

/*
 * Read registration success message.
 */
$successMessage = $_SESSION["success"] ?? "";
unset($_SESSION["success"]);

$error = "";
$username = "";

/*
 * Create CSRF token.
 */
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/*
 * Process login form.
 */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = strtolower(
        trim($_POST["username"] ?? "")
    );

    $password = $_POST["password"] ?? "";
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
        $error = "Invalid form submission. Please try again.";

    } elseif (
        !preg_match(
            "/^[a-z0-9_]{4,30}$/",
            $username
        ) ||
        $password === ""
    ) {
        $error = "Enter a valid username and password.";

    } else {
        try {
            /*
             * Search user using username.
             */
            $statement = $pdo->prepare(
                "SELECT
                    user_id,
                    username,
                    full_name,
                    email,
                    password
                 FROM users
                 WHERE username = :username
                 LIMIT 1"
            );

            $statement->execute([
                "username" => $username
            ]);

            $user = $statement->fetch(
                PDO::FETCH_ASSOC
            );

            /*
             * Verify password.
             */
            if (
                $user &&
                password_verify(
                    $password,
                    $user["password"]
                )
            ) {
                /*
                 * Create a new secure session.
                 */
                session_regenerate_id(true);

                $_SESSION["user_id"] =
                    (int) $user["user_id"];

                $_SESSION["username"] =
                    $user["username"];

                $_SESSION["full_name"] =
                    $user["full_name"];

                $_SESSION["email"] =
                    $user["email"];

                $_SESSION["role"] = "user";

                $_SESSION["csrf_token"] = bin2hex(
                    random_bytes(32)
                );

                /*
                 * Redirect to dashboard.
                 */
                header(
                    "Location: ../user/dashboard.php"
                );
                exit;
            }

            $error = "Invalid username or password.";

        } catch (PDOException $exception) {

            error_log($exception->getMessage());

            $error = "Login failed. Please try again.";
        }
    }

    /*
     * Generate a new CSRF token after failed login.
     */
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

    <title>Login | Smart Parking</title>

    <style>

        

        * 
        {
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
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 430px;
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
            margin: 18px 0 7px;
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
            padding: 13px;
            border-radius: 8px;
            line-height: 1.5;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .register-link,
        .home-link {
            text-align: center;
        }

        .register-link {
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

        <h1>User Login</h1>

        <p class="subtitle">
            Enter your username and password
        </p>

        <!-- Registration success message -->

        <?php if ($successMessage !== ""): ?>

            <div class="message success">
                <?= htmlspecialchars($successMessage) ?>
            </div>

        <?php endif; ?>

        <!-- Login error message -->

        <?php if ($error !== ""): ?>

            <div class="message error">
                <?= htmlspecialchars($error) ?>
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
                placeholder="Enter your username"
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
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >

            <button type="submit">
                Login
            </button>

        </form>

        <p class="register-link">
            Do not have an account?

            <a href="register.php">
                Register here
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
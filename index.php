<?php

session_start();

if (isset($_SESSION["user_id"])) {
    header("Location: user/dashboard.php");
    exit;
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

    <title>Smart Parking System</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }

        .container {
            width: 100%;
            max-width: 850px;
            padding: 45px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 35px rgba(15, 23, 42, 0.12);
            text-align: center;
        }

        .container h1 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #1d4ed8;
        }

        .subtitle {
            margin-bottom: 35px;
            color: #64748b;
        }

        .options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
        }

        .option-card {
            padding: 30px 25px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }

        .option-card h2 {
            margin-top: 0;
            font-size: 22px;
        }

        .option-card p {
            min-height: 45px;
            color: #64748b;
            line-height: 1.5;
        }

        .button {
            display: inline-block;
            width: 100%;
            margin-top: 12px;
            padding: 13px 20px;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            text-decoration: none;
        }

        .login-button {
            background: #2563eb;
        }

        .login-button:hover {
            background: #1d4ed8;
        }

        .register-button {
            background: #059669;
        }

        .register-button:hover {
            background: #047857;
        }

        .admin-link {
            display: inline-block;
            margin-top: 30px;
            color: #475569;
            text-decoration: none;
        }

        .admin-link:hover {
            color: #2563eb;
        }

        @media (max-width: 650px) {
            .container {
                padding: 30px 20px;
            }

            .options {
                grid-template-columns: 1fr;
            }

            .option-card p {
                min-height: auto;
            }
        }
    </style>
</head>

<body>

<main class="container">

    <h1>Smart Parking System</h1>

    <p class="subtitle">
        Reserve and manage your parking space easily.
    </p>

    <section class="options">

        <div class="option-card">
            <h2>Registered User</h2>

            <p>
                Already have an account? Log in using your
                username and password.
            </p>

            <a
                href="auth/login.php"
                class="button login-button"
            >
                Login
            </a>
        </div>

        <div class="option-card">
            <h2>New User</h2>

            <p>
                Do not have an account? Create a new account
                before reserving parking.
            </p>

            <a
                href="auth/register.php"
                class="button register-button"
            >
                Register
            </a>
        </div>

    </section>

    <a href="admin/login.php" class="admin-link">
        Admin Login
    </a>

</main>

</body>
</html>
<?php

session_start();

if (
    !empty($_SESSION["admin_id"]) &&
    ($_SESSION["role"] ?? "") === "admin"
) {
    header("Location: dashboard.php");
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

    <title>Admin Portal | Smart Parking</title>

    <style>
        * 
        {
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
            background: #0f172a;
            color: #1e293b;
        }

        .container {
            width: 100%;
            max-width: 850px;
            padding: 40px;
            background: white;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.25);
        }

        h1 {
            margin-top: 0;
            color: #0f172a;
        }

        .subtitle {
            margin-bottom: 32px;
            color: #64748b;
        }

        .options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 22px;
        }

        .card {
            padding: 28px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }

        .card p {
            min-height: 45px;
            color: #64748b;
            line-height: 1.5;
        }

        .button {
            display: block;
            padding: 13px;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            text-decoration: none;
        }

        .login-button {
            background: #2563eb;
        }

        .register-button {
            background: #059669;
        }

        .back-link {
            display: inline-block;
            margin-top: 28px;
            color: #2563eb;
            text-decoration: none;
        }

        @media (max-width: 650px) {
            .options {
                grid-template-columns: 1fr;
            }

            .container {
                padding: 30px 20px;
            }
        }
    </style>
  <link
    rel="stylesheet"
    href="/smart_parking/assets/css/theme.css?v=1"
> 
</head>

<body>

<main class="container">

    <h1>Smart Parking Admin Portal</h1>

    <p class="subtitle">
        Administrator access only
    </p>

    <section class="options">

        <div class="card">
            <h2>Registered Admin</h2>

            <p>
                Login using your registered username
                and password.
            </p>

            <a href="login.php" class="admin-link">
    Admin Portal
</a>
        </div>

        <div class="card">
            <h2>New Admin</h2>

            <p>
                Register a new administrator using the
                private registration key.
            </p>

            <a
                href="register.php"
                class="button register-button"
            >
                Admin Register
            </a>
        </div>

    </section>

    <a href="../index.php" class="back-link">
        Back to Main Page
    </a>

</main>

</body>
</html>
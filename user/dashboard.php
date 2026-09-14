<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$userId = (int) $_SESSION["user_id"];

$fullName = $_SESSION["full_name"] ?? "User";
$username = $_SESSION["username"] ?? "";
$email = $_SESSION["email"] ?? "";

/*
|--------------------------------------------------------------------------
| Reusable count function
|--------------------------------------------------------------------------
*/

function getDashboardCount(
    PDO $pdo,
    string $sql,
    array $parameters = []
): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| Dashboard statistics
|--------------------------------------------------------------------------
*/

$vehicleCount = getDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM vehicles
     WHERE user_id = :user_id",
    [
        "user_id" => $userId
    ]
);

$publicSlotCount = getDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM parking_slots
     WHERE access_type = 'Public'"
);

$reservationCount = getDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM reservations AS r
     INNER JOIN vehicles AS v
        ON r.vehicle_id = v.vehicle_id
     WHERE v.user_id = :user_id",
    [
        "user_id" => $userId
    ]
);

/*
 * Only successfully paid payments are counted.
 * Pending Cash payments are not included.
 */

$paidPaymentCount = getDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM payments AS p
     INNER JOIN reservations AS r
        ON p.reservation_id = r.reservation_id
     INNER JOIN vehicles AS v
        ON r.vehicle_id = v.vehicle_id
     WHERE v.user_id = :user_id
       AND p.payment_status = 'Paid'",
    [
        "user_id" => $userId
    ]
);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>User Dashboard | Smart Parking</title>

    <link
        rel="stylesheet"
        href="/smart_parking/assets/css/theme.css?v=1"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family:
                "Segoe UI",
                Tahoma,
                Geneva,
                Verdana,
                sans-serif;
            background: linear-gradient(
                135deg,
                #061a36 0%,
                #123f70 100%
            );
            color: #0f2747;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 255px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            background: #061a36;
            border-right: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 8px 0 30px rgba(0, 0, 0, 0.15);
            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 27px 21px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        }

        .brand-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 43px;
            height: 43px;
            flex-shrink: 0;
            border-radius: 11px;
            background: linear-gradient(
                135deg,
                #60a5fa,
                #2563eb
            );
            color: white;
            font-size: 21px;
            font-weight: 800;
            box-shadow: 0 7px 17px rgba(37, 99, 235, 0.35);
        }

        .brand-text h2 {
            margin: 0;
            color: white;
            font-size: 16px;
            line-height: 1.25;
        }

        .brand-text p {
            margin: 4px 0 0;
            color: #93c5fd;
            font-size: 11px;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 11px;
            padding: 27px 16px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            min-height: 51px;
            padding: 14px 17px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            color: #dbeafe;
            text-decoration: none;
            font-size: 14px;
            font-weight: 650;
            letter-spacing: 0.1px;
            transition:
                background 0.2s,
                color 0.2s,
                transform 0.2s,
                border-color 0.2s;
        }

        .sidebar-link:hover {
            border-color: rgba(96, 165, 250, 0.55);
            background: #2563eb;
            color: white;
            transform: translateX(4px);
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 20px 16px 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.14);
        }

        .logged-user {
            margin-bottom: 14px;
            padding: 11px 13px;
            border-radius: 9px;
            background: rgba(255, 255, 255, 0.06);
        }

        .logged-user small {
            display: block;
            color: #93c5fd;
        }

        .logged-user strong {
            display: block;
            margin-top: 4px;
            color: white;
            overflow-wrap: anywhere;
        }

        .logout-button {
            display: block;
            width: 100%;
            padding: 12px;
            border-radius: 9px;
            background: #dc2626;
            color: white;
            text-align: center;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            transition: background 0.2s;
        }

        .logout-button:hover {
            background: #b91c1c;
        }

        /*
        |--------------------------------------------------------------------------
        | Main content
        |--------------------------------------------------------------------------
        */

        .main-content {
    min-height: 100vh;
    margin-left: 255px;
    padding: 32px 39px;

    background: linear-gradient(
        135deg,
        #071f42 0%,
        #164c80 100%
    ) !important;
}

        .page-container {
            width: 100%;
            max-width: 1250px;
            margin: 0 auto;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
        }

        .topbar h1 {
            margin: 0;
            color: white;
            font-size: 29px;
            font-weight: 750;
            letter-spacing: -0.5px;
        }

        .topbar p {
            margin: 6px 0 0;
            color: #bfdbfe;
            font-size: 14px;
        }

        .username-chip {
            padding: 9px 14px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 9px;
            background: rgba(255, 255, 255, 0.09);
            color: white;
            font-size: 13px;
            font-weight: 700;
        }

        /*
        |--------------------------------------------------------------------------
        | Welcome section
        |--------------------------------------------------------------------------
        */

        .welcome-section {
            margin-bottom: 29px;
            padding: 29px 31px;
            border: 1px solid rgba(147, 197, 253, 0.4);
            border-left: 5px solid #60a5fa;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.07);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(5px);
        }

        .welcome-section h2 {
            margin: 0 0 12px;
            color: white;
            font-size: 24px;
            font-weight: 750;
        }

        .user-information {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 25px;
            color: #dbeafe;
            font-size: 14px;
        }

        .user-information p {
            margin: 0;
        }

        .user-information strong {
            color: #93c5fd;
        }

        /*
        |--------------------------------------------------------------------------
        | Dashboard cards
        |--------------------------------------------------------------------------
        */

        .section-title {
            margin: 0 0 17px;
            color: white;
            font-size: 21px;
            font-weight: 700;
        }

        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(
                2,
                minmax(0, 1fr)
            );
            gap: 22px;
        }

        .dashboard-card {
            display: flex;
            flex-direction: column;
            min-height: 265px;
            padding: 25px;
            border: 1px solid #d9e4f2;
            border-top: 5px solid #2563eb;
            border-radius: 14px;
            background: white;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.13);
            transition:
                transform 0.2s,
                box-shadow 0.2s;
        }

        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 17px 35px rgba(0, 0, 0, 0.18);
        }

        .vehicles-card {
            border-top-color: #2563eb;
        }

        .slots-card {
            border-top-color: #06b6d4;
        }

        .reservations-card {
            border-top-color: #8b5cf6;
        }

        .payments-card {
            border-top-color: #10b981;
        }

        .card-heading {
            margin: 0;
            color: #071f42;
            font-size: 18px;
            font-weight: 750;
        }

        .card-number {
            margin: 18px 0 13px;
            color: #2563eb;
            font-size: 40px;
            font-weight: 800;
            line-height: 1;
        }

        .slots-card .card-number {
            color: #0891b2;
        }

        .reservations-card .card-number {
            color: #7c3aed;
        }

        .payments-card .card-number {
            color: #059669;
        }

        .card-description {
            margin: 0 0 22px;
            color: #5a6c83;
            font-size: 14px;
            line-height: 1.6;
        }

        .card-button {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 45px;
            margin-top: auto;
            padding: 11px 16px;
            border-radius: 9px;
            background: linear-gradient(
                90deg,
                #0b2f5b,
                #2563eb
            );
            color: white;
            text-align: center;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            transition:
                transform 0.2s,
                box-shadow 0.2s;
        }

        .card-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.28);
        }

        /*
        |--------------------------------------------------------------------------
        | Responsive
        |--------------------------------------------------------------------------
        */

        @media (max-width: 850px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .sidebar-menu {
                display: grid;
                grid-template-columns: repeat(
                    2,
                    minmax(0, 1fr)
                );
            }

            .sidebar-footer {
                margin-top: 0;
            }

            .main-content {
                margin-left: 0;
                padding: 27px 20px;
            }
        }

        @media (max-width: 650px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .dashboard-cards,
            .sidebar-menu {
                grid-template-columns: 1fr;
            }

            .welcome-section {
                padding: 23px;
            }

            .dashboard-card {
                min-height: 245px;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            P
        </div>

        <div class="brand-text">
            <h2>
                Smart Parking<br>
                Management
            </h2>

            <p>User Portal</p>
        </div>

    </div>

    <nav class="sidebar-menu">

        <a
            href="/smart_parking/user/vehicles.php"
            class="sidebar-link"
        >
            My Vehicles
        </a>

        <a
            href="/smart_parking/user/reserve.php"
            class="sidebar-link"
        >
            Available Parking Slots
        </a>

        <a
            href="/smart_parking/user/reservations.php"
            class="sidebar-link"
        >
            My Reservations
        </a>

        <a
            href="/smart_parking/user/payments.php"
            class="sidebar-link"
        >
            My Payments
        </a>

    </nav>

    <div class="sidebar-footer">

        <div class="logged-user">
            <small>Logged in as</small>

            <strong>
                @<?= htmlspecialchars($username) ?>
            </strong>
        </div>

        <a
            href="/smart_parking/auth/logout.php"
            class="logout-button"
        >
            Logout
        </a>

    </div>

</aside>

<main class="main-content">

    <div class="page-container">

        <header class="topbar">

            <div>
                <h1>User Dashboard</h1>

                <p>
                    Manage your vehicles, reservations and payments.
                </p>
            </div>

            <div class="username-chip">
                @<?= htmlspecialchars($username) ?>
            </div>

        </header>

        <section class="welcome-section">

            <h2>
                Welcome,
                <?= htmlspecialchars($fullName) ?>!
            </h2>

            <div class="user-information">

                <p>
                    Username:
                    <strong>
                        @<?= htmlspecialchars($username) ?>
                    </strong>
                </p>

                <p>
                    Email:
                    <?= htmlspecialchars($email) ?>
                </p>

            </div>

        </section>

        <h2 class="section-title">
            Parking Overview
        </h2>

        <section class="dashboard-cards">

            <article class="dashboard-card vehicles-card">

                <h3 class="card-heading">
                    My Vehicles
                </h3>

                <div class="card-number">
                    <?= $vehicleCount ?>
                </div>

                <p class="card-description">
                    Add new vehicles and manage your registered
                    vehicle information.
                </p>

                <a
                    href="/smart_parking/user/vehicles.php"
                    class="card-button"
                >
                    Manage Vehicles
                </a>

            </article>

            <article class="dashboard-card slots-card">

                <h3 class="card-heading">
                    Available Parking Slots
                </h3>

                <div class="card-number">
                    <?= $publicSlotCount ?>
                </div>

                <p class="card-description">
                    Search available public parking slots using
                    your vehicle, start date, end date and time.
                </p>

                <a
                    href="/smart_parking/user/reserve.php"
                    class="card-button"
                >
                    Search Parking Slots
                </a>

            </article>

            <article class="dashboard-card reservations-card">

                <h3 class="card-heading">
                    My Reservations
                </h3>

                <div class="card-number">
                    <?= $reservationCount ?>
                </div>

                <p class="card-description">
                    View your pending, confirmed, completed and
                    cancelled parking reservations.
                </p>

                <a
                    href="/smart_parking/user/reservations.php"
                    class="card-button"
                >
                    View Reservations
                </a>

            </article>

            <article class="dashboard-card payments-card">

                <h3 class="card-heading">
                    My Payments
                </h3>

                <div class="card-number">
                    <?= $paidPaymentCount ?>
                </div>

                <p class="card-description">
                    View successfully paid parking payments,
                    payment methods and official receipts.
                </p>

                <a
                    href="/smart_parking/user/payments.php"
                    class="card-button"
                >
                    View Payments
                </a>

            </article>

        </section>

    </div>

</main>

</body>
</html>
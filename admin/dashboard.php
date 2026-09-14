<?php

require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$adminId = (int) $_SESSION["admin_id"];

/*
|--------------------------------------------------------------------------
| Reusable count function
|--------------------------------------------------------------------------
*/

function getAdminDashboardCount(
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
| Administrator information
|--------------------------------------------------------------------------
*/

$adminStatement = $pdo->prepare(
    "SELECT
        admin_name,
        username,
        email
     FROM admins
     WHERE admin_id = :admin_id
     LIMIT 1"
);

$adminStatement->execute([
    "admin_id" => $adminId
]);

$admin = $adminStatement->fetch();

if (!$admin) {
    header(
        "Location: /smart_parking/admin/logout.php"
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Dashboard statistics
|--------------------------------------------------------------------------
*/

$totalUsers = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM users"
);

$totalSlots = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*) FROM parking_slots"
);

$publicSlots = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM parking_slots
     WHERE access_type = 'Public'"
);

$overflowSlots = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM parking_slots
     WHERE access_type = 'AdminOnly'"
);

$availableOverflowSlots = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM parking_slots
     WHERE access_type = 'AdminOnly'
       AND status = 'Available'"
);

$totalReservations = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM reservations"
);

$vehiclesAwaitingExit = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM reservations
     WHERE status = 'Confirmed'
       AND actual_exit_at IS NULL
       AND TIMESTAMP(
            start_date,
            start_time
       ) <= NOW()"
);

$pendingFineCount = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM fines
     WHERE status = 'Pending'"
);

$paidPaymentCount = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(*)
     FROM payments
     WHERE payment_status = 'Paid'"
);

$revenueStatement = $pdo->query(
    "SELECT COALESCE(SUM(amount), 0)
     FROM payments
     WHERE payment_status = 'Paid'"
);

$totalRevenue = (float) $revenueStatement->fetchColumn();

/*
|--------------------------------------------------------------------------
| Reservations eligible for overflow reassignment
|--------------------------------------------------------------------------
*/

$eligibleOverflowReservations = getAdminDashboardCount(
    $pdo,
    "SELECT COUNT(DISTINCT r.reservation_id)

     FROM reservations AS r

     INNER JOIN parking_slots AS ps
        ON r.slot_id = ps.slot_id

     INNER JOIN payments AS p
        ON r.reservation_id = p.reservation_id

     WHERE r.status = 'Confirmed'
       AND ps.access_type = 'Public'
       AND p.payment_status = 'Paid'
       AND TIMESTAMP(
            r.end_date,
            r.end_time
       ) > NOW()"
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

    <title>Administrator Dashboard | Smart Parking</title>

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
            gap: 9px;
            padding: 25px 16px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            min-height: 48px;
            padding: 12px 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            color: #dbeafe;
            text-decoration: none;
            font-size: 13px;
            font-weight: 650;
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

        .logged-admin {
            margin-bottom: 14px;
            padding: 11px 13px;
            border-radius: 9px;
            background: rgba(255, 255, 255, 0.06);
        }

        .logged-admin small {
            display: block;
            color: #93c5fd;
        }

        .logged-admin strong {
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
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            margin-bottom: 29px;
            padding: 29px 31px;
            border: 1px solid rgba(147, 197, 253, 0.4);
            border-left: 5px solid #60a5fa;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.07);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(5px);
        }

        .welcome-content h2 {
            margin: 0 0 12px;
            color: white;
            font-size: 24px;
        }

        .welcome-content p {
            margin: 6px 0;
            color: #dbeafe;
            font-size: 14px;
        }

        .welcome-content strong {
            color: #93c5fd;
        }

        .administrator-badge {
            padding: 9px 14px;
            border-radius: 30px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 13px;
            font-weight: 750;
            white-space: nowrap;
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
            min-height: 285px;
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

        .users-card {
            border-top-color: #8b5cf6;
        }

        .slots-card {
            border-top-color: #06b6d4;
        }

        .reservations-card {
            border-top-color: #2563eb;
        }

        .overflow-card {
            border-top-color: #f59e0b;
        }

        .exit-card {
            border-top-color: #ef4444;
        }

        .revenue-card {
            border-top-color: #10b981;
        }

        .card-heading {
            margin: 0;
            color: #071f42;
            font-size: 18px;
        }

        .card-number {
            margin: 18px 0 13px;
            color: #2563eb;
            font-size: 40px;
            font-weight: 800;
            line-height: 1;
        }

        .users-card .card-number {
            color: #7c3aed;
        }

        .slots-card .card-number {
            color: #0891b2;
        }

        .overflow-card .card-number {
            color: #d97706;
        }

        .exit-card .card-number {
            color: #dc2626;
        }

        .revenue-card .card-number {
            color: #059669;
            font-size: 32px;
        }

        .card-description {
            margin: 0 0 17px;
            color: #5a6c83;
            font-size: 14px;
            line-height: 1.6;
        }

        .card-details {
            margin-bottom: 19px;
            padding: 13px;
            border-radius: 9px;
            background: #f1f5f9;
            color: #52657d;
            font-size: 13px;
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
        }

        .overflow-card .card-button {
            background: linear-gradient(
                90deg,
                #b45309,
                #f59e0b
            );
        }

        .card-button:hover {
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
            .topbar,
            .welcome-section {
                align-items: flex-start;
                flex-direction: column;
            }

            .dashboard-cards,
            .sidebar-menu {
                grid-template-columns: 1fr;
            }

            .dashboard-card {
                min-height: 260px;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">

    <div class="brand">

        <div class="brand-icon">
            A
        </div>

        <div class="brand-text">
            <h2>
                Smart Parking<br>
                Management
            </h2>

            <p>Administrator Portal</p>
        </div>

    </div>

    <nav class="sidebar-menu">

        <a
            href="/smart_parking/admin/details.php?section=users"
            class="sidebar-link"
        >
            Registered Users
        </a>

        <a
            href="/smart_parking/admin/details.php?section=slots"
            class="sidebar-link"
        >
            Parking Slots
        </a>

        <a
            href="/smart_parking/admin/details.php?section=reservations"
            class="sidebar-link"
        >
            Reservations
        </a>

        <a
            href="/smart_parking/admin/assign_overflow.php"
            class="sidebar-link"
        >
            Assign Overflow Slot
        </a>

        <a
            href="/smart_parking/admin/vehicle_exit.php"
            class="sidebar-link"
        >
            Vehicle Exit & Fines
        </a>

        <a
            href="/smart_parking/admin/details.php?section=payments"
            class="sidebar-link"
        >
            Payments & Revenue
        </a>

    </nav>

    <div class="sidebar-footer">

        <div class="logged-admin">
            <small>Logged in as</small>

            <strong>
                @<?= htmlspecialchars($admin["username"]) ?>
            </strong>
        </div>

        <a
            href="/smart_parking/admin/logout.php"
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
                <h1>Administrator Dashboard</h1>

                <p>
                    Manage users, parking slots,
                    reservations and payments.
                </p>
            </div>

            <div class="username-chip">
                @<?= htmlspecialchars($admin["username"]) ?>
            </div>

        </header>

        <section class="welcome-section">

            <div class="welcome-content">

                <h2>
                    Welcome,
                    <?= htmlspecialchars($admin["admin_name"]) ?>!
                </h2>

                <p>
                    Username:
                    <strong>
                        @<?= htmlspecialchars($admin["username"]) ?>
                    </strong>
                </p>

                <p>
                    Email:
                    <?= htmlspecialchars($admin["email"]) ?>
                </p>

            </div>

            <span class="administrator-badge">
                System Administrator
            </span>

        </section>

        <h2 class="section-title">
            Parking Management
        </h2>

        <section class="dashboard-cards">

            <!-- 1. Registered Users -->
            <article class="dashboard-card users-card">

                <h3 class="card-heading">
                    Registered Users
                </h3>

                <div class="card-number">
                    <?= $totalUsers ?>
                </div>

                <p class="card-description">
                    Search and view registered users,
                    vehicles and reservation information.
                </p>

                <a
                    href="/smart_parking/admin/details.php?section=users"
                    class="card-button"
                >
                    View Registered Users
                </a>

            </article>

            <!-- 2. Parking Slots -->
            <article class="dashboard-card slots-card">

                <h3 class="card-heading">
                    Parking Slots
                </h3>

                <div class="card-number">
                    <?= $totalSlots ?>
                </div>

                <p class="card-description">
                    View public parking slots, physical status
                    and administrator-only overflow slots.
                </p>

                <div class="card-details">
                    Public slots:
                    <strong><?= $publicSlots ?></strong>

                    <br>

                    Overflow slots:
                    <strong><?= $overflowSlots ?></strong>
                </div>

                <a
                    href="/smart_parking/admin/details.php?section=slots"
                    class="card-button"
                >
                    View Parking Slots
                </a>

            </article>

            <!-- 3. Reservations -->
            <article class="dashboard-card reservations-card">

                <h3 class="card-heading">
                    Reservations
                </h3>

                <div class="card-number">
                    <?= $totalReservations ?>
                </div>

                <p class="card-description">
                    View pending, confirmed, completed
                    and cancelled parking reservations.
                </p>

                <a
                    href="/smart_parking/admin/details.php?section=reservations"
                    class="card-button"
                >
                    View Reservations
                </a>

            </article>

            <!-- 4. Assign Overflow Slot -->
            <article class="dashboard-card overflow-card">

                <h3 class="card-heading">
                    Assign Overflow Slot
                </h3>

                <div class="card-number">
                    <?= $availableOverflowSlots ?>
                </div>

                <p class="card-description">
                    Move an existing paid and confirmed reservation
                    to an administrator-only overflow slot.
                </p>

                <div class="card-details">
                    Available overflow slots:
                    <strong>
                        <?= $availableOverflowSlots ?>
                    </strong>

                    <br>

                    Eligible reservations:
                    <strong>
                        <?= $eligibleOverflowReservations ?>
                    </strong>
                </div>

                <a
                    href="/smart_parking/admin/assign_overflow.php"
                    class="card-button"
                >
                    Assign Overflow Slot
                </a>

            </article>

            <!-- 5. Vehicle Exit and Fines -->
            <article class="dashboard-card exit-card">

                <h3 class="card-heading">
                    Vehicle Exit & Fines
                </h3>

                <div class="card-number">
                    <?= $vehiclesAwaitingExit ?>
                </div>

                <p class="card-description">
                    Record actual vehicle exit times,
                    release slots and calculate overstay fines.
                </p>

                <div class="card-details">
                    Vehicles awaiting exit:
                    <strong>
                        <?= $vehiclesAwaitingExit ?>
                    </strong>

                    <br>

                    Pending fines:
                    <strong>
                        <?= $pendingFineCount ?>
                    </strong>
                </div>

                <a
                    href="/smart_parking/admin/vehicle_exit.php"
                    class="card-button"
                >
                    Manage Vehicle Exit
                </a>

            </article>

            <!-- 6. Payments and Revenue -->
            <article class="dashboard-card revenue-card">

                <h3 class="card-heading">
                    Payments & Revenue
                </h3>

                <div class="card-number">
                    Rs. <?= number_format(
                        $totalRevenue,
                        2
                    ) ?>
                </div>

                <p class="card-description">
                    View paid and pending payments and
                    successfully received revenue.
                </p>

                <div class="card-details">
                    Paid payment records:
                    <strong>
                        <?= $paidPaymentCount ?>
                    </strong>

                    <br>

                    Pending Cash payments are not included in revenue.
                </div>

                <a
                    href="/smart_parking/admin/details.php?section=payments"
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
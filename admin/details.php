<?php

require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$allowedSections = [
    "users",
    "slots",
    "reservations",
    "payments"
];

$section = $_GET["section"] ?? "users";

if (!in_array($section, $allowedSections, true)) {
    $section = "users";
}

$pageTitles = [
    "users" => "Registered Users",
    "slots" => "Parking Slot Details",
    "reservations" => "Reservation Details",
    "payments" => "Payment and Revenue Details"
];

$pageTitle = $pageTitles[$section];

$records = [];
$totalRevenue = 0.00;

/*
|--------------------------------------------------------------------------
| User search value
|--------------------------------------------------------------------------
*/

$userSearch = "";

if ($section === "users") {
    $userSearch = trim($_GET["q"] ?? "");

    if (strlen($userSearch) > 100) {
        $userSearch = substr(
            $userSearch,
            0,
            100
        );
    }
}

/*
|--------------------------------------------------------------------------
| Registered users
|--------------------------------------------------------------------------
*/

if ($section === "users") {
    $userSql = "
        SELECT
            u.user_id,
            u.username,
            u.full_name,
            u.email,
            u.phone,
            u.registration_date,

            COUNT(DISTINCT v.vehicle_id)
                AS vehicle_count,

            COUNT(DISTINCT r.reservation_id)
                AS reservation_count,

            GROUP_CONCAT(
                DISTINCT v.vehicle_number
                ORDER BY v.vehicle_number
                SEPARATOR ', '
            ) AS vehicle_numbers

        FROM users AS u

        LEFT JOIN vehicles AS v
            ON u.user_id = v.user_id

        LEFT JOIN reservations AS r
            ON v.vehicle_id = r.vehicle_id
    ";

    $userParameters = [];

   if ($userSearch !== "") {
    if (
        ctype_digit($userSearch) &&
        (int) $userSearch > 0
    ) {
        $userSql .= "
            WHERE u.user_id = :user_id
        ";

        $userParameters = [
            "user_id" => (int) $userSearch
        ];
    } else {
        /*
         * Invalid User ID returns no records.
         */
        $userSql .= "
            WHERE 1 = 0
        ";
    }
}

    $userSql .= "
        GROUP BY
            u.user_id,
            u.username,
            u.full_name,
            u.email,
            u.phone,
            u.registration_date

        ORDER BY u.user_id DESC
    ";

    $statement = $pdo->prepare($userSql);

    $statement->execute($userParameters);

    $records = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}

/*
|--------------------------------------------------------------------------
| Parking slots
|--------------------------------------------------------------------------
*/

if ($section === "slots") {
    $statement = $pdo->query(
        "SELECT
            slot_data.*,

            CASE
                WHEN slot_data.current_bookings > 0
                THEN 'Booked'
                ELSE 'Available'
            END AS booking_status,

            CASE
                WHEN slot_data.status = 'Available'
                 AND slot_data.current_bookings = 0
                THEN 'Yes'
                ELSE 'No'
            END AS available_now

         FROM (
            SELECT
                ps.slot_id,
                ps.slot_number,
                ps.floor_no,
                ps.slot_type,
                ps.access_type,
                ps.status,

                COUNT(r.reservation_id)
                    AS reservation_count,

                SUM(
                    CASE
                        WHEN r.status IN (
                            'Pending',
                            'Confirmed'
                        )
                         AND TIMESTAMP(
                            r.start_date,
                            r.start_time
                         ) <= NOW()
                         AND TIMESTAMP(
                            r.end_date,
                            r.end_time
                         ) > NOW()
                        THEN 1
                        ELSE 0
                    END
                ) AS current_bookings,

                SUM(
                    CASE
                        WHEN r.status IN (
                            'Pending',
                            'Confirmed'
                        )
                         AND TIMESTAMP(
                            r.start_date,
                            r.start_time
                         ) > NOW()
                        THEN 1
                        ELSE 0
                    END
                ) AS upcoming_reservations

            FROM parking_slots AS ps

            LEFT JOIN reservations AS r
                ON ps.slot_id = r.slot_id

            GROUP BY
                ps.slot_id,
                ps.slot_number,
                ps.floor_no,
                ps.slot_type,
                ps.access_type,
                ps.status
         ) AS slot_data

         ORDER BY
            slot_data.floor_no,
            slot_data.slot_number"
    );

    $records = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}

/*
|--------------------------------------------------------------------------
| Reservations
|--------------------------------------------------------------------------
*/

if ($section === "reservations") {
    $statement = $pdo->query(
        "SELECT
            r.reservation_id,
            r.start_date,
            r.start_time,
            r.end_date,
            r.end_time,
            r.status,

            u.username,
            u.full_name,

            v.vehicle_number,
            v.vehicle_type,

            ps.slot_number,
            ps.floor_no,
            ps.access_type,

            p.payment_id,
            p.amount,
            p.payment_method,
            p.payment_status

         FROM reservations AS r

         INNER JOIN vehicles AS v
            ON r.vehicle_id = v.vehicle_id

         INNER JOIN users AS u
            ON v.user_id = u.user_id

         INNER JOIN parking_slots AS ps
            ON r.slot_id = ps.slot_id

         LEFT JOIN payments AS p
            ON r.reservation_id = p.reservation_id

         ORDER BY
            r.start_date DESC,
            r.start_time DESC"
    );

    $records = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
*/

if ($section === "payments") {
    $statement = $pdo->query(
        "SELECT
            p.payment_id,
            p.reservation_id,
            p.amount,
            p.payment_method,
            p.payment_date,
            p.payment_status,
            p.paid_at,
            p.transaction_reference,

            u.username,
            u.full_name,

            v.vehicle_number,

            ps.slot_number,
            ps.floor_no,

            r.start_date,
            r.start_time,
            r.end_date,
            r.end_time

         FROM payments AS p

         INNER JOIN reservations AS r
            ON p.reservation_id = r.reservation_id

         INNER JOIN vehicles AS v
            ON r.vehicle_id = v.vehicle_id

         INNER JOIN users AS u
            ON v.user_id = u.user_id

         INNER JOIN parking_slots AS ps
            ON r.slot_id = ps.slot_id

         ORDER BY
            p.payment_date DESC,
            p.payment_id DESC"
    );

    $records = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    foreach ($records as $record) {
        if ($record["payment_status"] === "Paid") {
            $totalRevenue +=
                (float) $record["amount"];
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

    <title>
        <?= htmlspecialchars($pageTitle) ?>
        | Smart Parking
    </title>

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
            background: #edf4ff;
            color: #0f2747;
        }

        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 18px 5%;
            background: #071f42;
            color: white;
        }

        .navbar-links {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .navbar a {
            color: white;
            text-decoration: none;
            font-weight: 650;
        }

        .container {
            width: 94%;
            max-width: 1400px;
            margin: 30px auto;
            padding: 27px;
            border: 1px solid #d7e3f1;
            border-radius: 13px;
            background: white;
            box-shadow: 0 10px 28px rgba(15, 39, 71, 0.09);
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 22px;
        }

        .page-header h1 {
            margin: 0;
            color: #071f42;
        }

        .summary {
            padding: 11px 16px;
            border-radius: 8px;
            background: #dcfce7;
            color: #166534;
            font-weight: bold;
        }

        /*
        |--------------------------------------------------------------------------
        | Search box
        |--------------------------------------------------------------------------
        */

        .search-section {
            margin-bottom: 23px;
            padding: 18px;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            background: #eff6ff;
        }

        .search-form {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .search-input {
            width: 100%;
            min-height: 44px;
            padding: 11px 13px;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            background: white;
            color: #0f2747;
            font-family: inherit;
        }

        .search-input:focus {
            border-color: #2563eb;
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .search-button,
        .clear-button {
            min-height: 44px;
            padding: 11px 19px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-weight: bold;
            white-space: nowrap;
            cursor: pointer;
        }

        .search-button {
            background: #2563eb;
            color: white;
        }

        .clear-button {
            display: inline-flex;
            align-items: center;
            background: #64748b;
            color: white;
            text-decoration: none;
        }

        .search-help {
            margin: 11px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .search-result {
            margin: 13px 0 0;
            color: #1e40af;
            font-size: 14px;
            font-weight: 650;
        }

        /*
        |--------------------------------------------------------------------------
        | Tables
        |--------------------------------------------------------------------------
        */

        .table-wrapper {
            overflow-x: auto;
            border: 1px solid #d7e3f1;
            border-radius: 9px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #dbe4ef;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #071f42;
            color: white;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        tbody tr:hover {
            background: #eff6ff;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .available,
        .confirmed,
        .paid,
        .yes,
        .public-slot {
            background: #dcfce7;
            color: #166534;
        }

        .booked,
        .cancelled,
        .occupied,
        .not-paid,
        .no,
        .failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .pending,
        .admin-only {
            background: #fef3c7;
            color: #92400e;
        }

        .completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .refunded,
        .maintenance {
            background: #e2e8f0;
            color: #334155;
        }

        .amount-paid {
            color: #15803d;
        }

        .amount-pending {
            color: #b45309;
        }

        .empty {
            padding: 35px;
            text-align: center;
            color: #64748b;
        }

        small {
            color: #64748b;
        }

        @media (max-width: 760px) {
            .navbar,
            .page-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .search-form {
                align-items: stretch;
                flex-direction: column;
            }

            .search-button,
            .clear-button {
                justify-content: center;
                width: 100%;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">

    <strong>Smart Parking Admin</strong>

    <div class="navbar-links">

        <a href="dashboard.php">
            Dashboard
        </a>

        <a href="details.php?section=users">
            Users
        </a>

        <a href="details.php?section=slots">
            Slots
        </a>

        <a href="details.php?section=reservations">
            Reservations
        </a>

        <a href="details.php?section=payments">
            Payments
        </a>

    </div>

</nav>

<main class="container">

    <header class="page-header">

        <h1>
            <?= htmlspecialchars($pageTitle) ?>
        </h1>

        <div class="summary">

            <?php if ($section === "payments"): ?>

                Paid Revenue:
                Rs. <?= number_format(
                    $totalRevenue,
                    2
                ) ?>

            <?php elseif (
                $section === "users" &&
                $userSearch !== ""
            ): ?>

                Search Results:
                <?= count($records) ?>

            <?php else: ?>

                Total Records:
                <?= count($records) ?>

            <?php endif; ?>

        </div>

    </header>

    <?php if ($section === "users"): ?>

        <section class="search-section">

            <form method="GET" class="search-form">

                <input
                    type="hidden"
                    name="section"
                    value="users"
                >

                <input
    type="number"
    name="q"
    class="search-input"
    placeholder="Enter User ID, for example: 2"
    value="<?= htmlspecialchars($userSearch) ?>"
    min="1"
    step="1"
    autocomplete="off"
>

                <button
                    type="submit"
                    class="search-button"
                >
                    Search User
                </button>

                <?php if ($userSearch !== ""): ?>

                    <a
                        href="details.php?section=users"
                        class="clear-button"
                    >
                        Clear
                    </a>

                <?php endif; ?>

            </form>

            <p class="search-help">
    Enter the exact numeric User ID to find a registered user.
</p>

            <?php if ($userSearch !== ""): ?>

                <p class="search-result">
                    Showing results for:
                    “<?= htmlspecialchars($userSearch) ?>”
                </p>

            <?php endif; ?>

        </section>

    <?php endif; ?>

    <?php if (empty($records)): ?>

        <div class="empty">

            <?php if (
                $section === "users" &&
                $userSearch !== ""
            ): ?>

                No registered users matched
                “<?= htmlspecialchars($userSearch) ?>”.

            <?php else: ?>

                No records were found.

            <?php endif; ?>

        </div>

    <?php else: ?>

        <div class="table-wrapper">

            <?php if ($section === "users"): ?>

                <table>
                    <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Registered Vehicles</th>
                        <th>Registered Date</th>
                        <th>Vehicle Count</th>
                        <th>Reservations</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($records as $record): ?>

                        <tr>
                            <td>
                                <?= (int) $record["user_id"] ?>
                            </td>

                            <td>
                                @<?= htmlspecialchars(
                                    $record["username"]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["full_name"]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["email"]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["phone"]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["vehicle_numbers"]
                                    ?? "—"
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["registration_date"]
                                ) ?>
                            </td>

                            <td>
                                <?= (int) $record["vehicle_count"] ?>
                            </td>

                            <td>
                                <?= (int) $record["reservation_count"] ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            <?php elseif ($section === "slots"): ?>

                <table>
                    <thead>
                    <tr>
                        <th>Slot ID</th>
                        <th>Slot Number</th>
                        <th>Floor</th>
                        <th>Slot Type</th>
                        <th>Access Type</th>
                        <th>Physical Status</th>
                        <th>Booking Status</th>
                        <th>Available Now</th>
                        <th>Total Reservations</th>
                        <th>Current Bookings</th>
                        <th>Upcoming</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($records as $record): ?>

                        <tr>
                            <td>
                                <?= (int) $record["slot_id"] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["slot_number"]
                                ) ?>
                            </td>

                            <td>
                                <?= (int) $record["floor_no"] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["slot_type"]
                                ) ?>
                            </td>

                            <td>
                                <?php if (
                                    $record["access_type"]
                                    === "AdminOnly"
                                ): ?>

                                    <span class="badge admin-only">
                                        Admin Only
                                    </span>

                                <?php else: ?>

                                    <span class="badge public-slot">
                                        Public
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge <?= strtolower(
                                    $record["status"]
                                ) ?>">
                                    <?= htmlspecialchars(
                                        $record["status"]
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= strtolower(
                                    $record["booking_status"]
                                ) ?>">
                                    <?= htmlspecialchars(
                                        $record["booking_status"]
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= strtolower(
                                    $record["available_now"]
                                ) ?>">
                                    <?= htmlspecialchars(
                                        $record["available_now"]
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?= (int) $record["reservation_count"] ?>
                            </td>

                            <td>
                                <?= (int) $record["current_bookings"] ?>
                            </td>

                            <td>
                                <?= (int) $record[
                                    "upcoming_reservations"
                                ] ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            <?php elseif ($section === "reservations"): ?>

                <table>
                    <thead>
                    <tr>
                        <th>Reservation ID</th>
                        <th>User</th>
                        <th>Vehicle</th>
                        <th>Slot</th>
                        <th>Access</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                        <th>Payment</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($records as $record): ?>

                        <tr>
                            <td>
                                R_<?= str_pad(
                                    (string) $record[
                                        "reservation_id"
                                    ],
                                    3,
                                    "0",
                                    STR_PAD_LEFT
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["full_name"]
                                ) ?>

                                <br>

                                <small>
                                    @<?= htmlspecialchars(
                                        $record["username"]
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["vehicle_number"]
                                ) ?>

                                <br>

                                <small>
                                    <?= htmlspecialchars(
                                        $record["vehicle_type"]
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["slot_number"]
                                ) ?>

                                – Floor
                                <?= (int) $record["floor_no"] ?>
                            </td>

                            <td>
                                <?= $record["access_type"] === "AdminOnly"
                                    ? "Admin Only"
                                    : "Public" ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["start_date"]
                                ) ?>

                                <?= htmlspecialchars(
                                    substr(
                                        $record["start_time"],
                                        0,
                                        5
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["end_date"]
                                ) ?>

                                <?= htmlspecialchars(
                                    substr(
                                        $record["end_time"],
                                        0,
                                        5
                                    )
                                ) ?>
                            </td>

                            <td>
                                <span class="badge <?= strtolower(
                                    $record["status"]
                                ) ?>">
                                    <?= htmlspecialchars(
                                        $record["status"]
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?php if (
                                    !$record["payment_id"]
                                ): ?>

                                    <span class="badge not-paid">
                                        Not Paid
                                    </span>

                                <?php elseif (
                                    $record["payment_status"]
                                    === "Paid"
                                ): ?>

                                    <span class="badge paid">
                                        Paid – Rs.
                                        <?= number_format(
                                            (float) $record["amount"],
                                            2
                                        ) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="badge pending">
                                        <?= htmlspecialchars(
                                            $record["payment_status"]
                                        ) ?>

                                        <?= htmlspecialchars(
                                            $record["payment_method"]
                                        ) ?>
                                    </span>

                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            <?php elseif ($section === "payments"): ?>

                <table>
                    <thead>
                    <tr>
                        <th>Payment ID</th>
                        <th>Reservation ID</th>
                        <th>User</th>
                        <th>Vehicle</th>
                        <th>Slot</th>
                        <th>Parking Period</th>
                        <th>Payment Date</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Amount</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($records as $record): ?>

                        <tr>
                            <td>
                                P_<?= str_pad(
                                    (string) $record[
                                        "payment_id"
                                    ],
                                    3,
                                    "0",
                                    STR_PAD_LEFT
                                ) ?>
                            </td>

                            <td>
                                R_<?= str_pad(
                                    (string) $record[
                                        "reservation_id"
                                    ],
                                    3,
                                    "0",
                                    STR_PAD_LEFT
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["full_name"]
                                ) ?>

                                <br>

                                <small>
                                    @<?= htmlspecialchars(
                                        $record["username"]
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["vehicle_number"]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["slot_number"]
                                ) ?>

                                – Floor
                                <?= (int) $record["floor_no"] ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["start_date"]
                                ) ?>

                                <?= htmlspecialchars(
                                    substr(
                                        $record["start_time"],
                                        0,
                                        5
                                    )
                                ) ?>

                                <br>

                                to

                                <?= htmlspecialchars(
                                    $record["end_date"]
                                ) ?>

                                <?= htmlspecialchars(
                                    substr(
                                        $record["end_time"],
                                        0,
                                        5
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["payment_date"]
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $record["payment_method"]
                                ) ?>
                            </td>

                            <td>
                                <span class="badge <?= strtolower(
                                    $record["payment_status"]
                                ) ?>">
                                    <?= htmlspecialchars(
                                        $record["payment_status"]
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <strong class="<?=
                                    $record["payment_status"] === "Paid"
                                        ? "amount-paid"
                                        : "amount-pending"
                                ?>">
                                    Rs.
                                    <?= number_format(
                                        (float) $record["amount"],
                                        2
                                    ) ?>
                                </strong>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</main>

</body>
</html>
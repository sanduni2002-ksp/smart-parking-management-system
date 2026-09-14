<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

$message = $_SESSION["success"] ?? "";
unset($_SESSION["success"]);

$statement = $pdo->prepare(
    "SELECT
        p.payment_id,
        p.amount,
        p.payment_method,
        p.payment_date,
        r.reservation_id,
        r.start_date,
        r.end_date,
        r.start_time,
        r.end_time,
        v.vehicle_number,
        ps.slot_number,
        ps.floor_no
     FROM payments AS p
     INNER JOIN reservations AS r
        ON p.reservation_id = r.reservation_id
     INNER JOIN vehicles AS v
        ON r.vehicle_id = v.vehicle_id
     INNER JOIN parking_slots AS ps
        ON r.slot_id = ps.slot_id
     WHERE v.user_id = :user_id
     ORDER BY p.payment_id DESC"
);

$statement->execute([
    "user_id" => $_SESSION["user_id"]
]);

$payments = $statement->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>My Payments | Smart Parking</title>

    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
        }

        nav {
            display: flex;
            justify-content: space-between;
            padding: 18px 6%;
            background: #0f172a;
            color: white;
        }

        nav a {
            margin-left: 14px;
            color: white;
            text-decoration: none;
        }

        main {
            width: 94%;
            max-width: 1250px;
            margin: 35px auto;
            padding: 25px;
            background: white;
            border-radius: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }

        th {
            background: #f8fafc;
        }

        .message {
            margin-bottom: 20px;
            padding: 13px;
            border-radius: 7px;
            background: #dcfce7;
            color: #166534;
        }

        .amount {
            color: #166534;
            font-weight: bold;
        }

        @media (max-width: 900px) {
            .table-wrapper {
                overflow-x: auto;
            }
        }
    </style>
    <link
    rel="stylesheet"
    href="/smart_parking/assets/css/theme.css?v=1"
>
</head>

<body>

<nav>

    <strong>Smart Parking</strong>

    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="reservations.php">Reservations</a>
        <a href="reserve.php">Reserve</a>
        <a href="../auth/logout.php">Logout</a>
    </div>

</nav>

<main>

    <h1>My Payments</h1>

    <?php if ($message !== ""): ?>

        <div class="message">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>

    <?php if (empty($payments)): ?>

        <p>No payments have been recorded.</p>

    <?php else: ?>

        <div class="table-wrapper">

            <table>

                <thead>
                <tr>
                    <th>Payment</th>
                    <th>Reservation</th>
                    <th>Vehicle</th>
                    <th>Slot</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Method</th>
                    <th>Payment Date</th>
                    <th>Amount</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($payments as $payment): ?>

                    <tr>

                        <td>
                           P_<?= str_pad(
    (string) $payment["payment_id"],
    3,
    "0",
    STR_PAD_LEFT
) ?>
                        </td>

                        <td>
                           R_<?= str_pad(
    (string) $payment["reservation_id"],
    3,
    "0",
    STR_PAD_LEFT
) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $payment["vehicle_number"]
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $payment["slot_number"]
                            ) ?>

                            – Floor
                            <?= (int) $payment["floor_no"] ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $payment["start_date"]
                            ) ?>

                            <br>

                            <?= htmlspecialchars(
                                substr(
                                    $payment["start_time"],
                                    0,
                                    5
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $payment["end_date"]
                            ) ?>

                            <br>

                            <?= htmlspecialchars(
                                substr(
                                    $payment["end_time"],
                                    0,
                                    5
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $payment["payment_method"]
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $payment["payment_date"]
                            ) ?>
                        </td>

                        <td class="amount">
                            Rs.
                            <?= number_format(
                                (float) $payment["amount"],
                                2
                            ) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</main>

</body>
</html>
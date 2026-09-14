<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$userId = (int) $_SESSION["user_id"];

$message = $_SESSION["success"] ?? "";
unset($_SESSION["success"]);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/*
 * Get all reservations belonging to vehicles
 * owned by the logged-in user.
 */
$statement = $pdo->prepare(
    "SELECT
        r.reservation_id,
        r.start_date,
        r.end_date,
        r.start_time,
        r.end_time,
        r.status,
        v.vehicle_number,
        v.vehicle_type,
        ps.slot_number,
        ps.floor_no,
        ps.slot_type,
        p.payment_id,
        p.amount,
        p.payment_method,
        p.payment_date
     FROM reservations AS r
     INNER JOIN vehicles AS v
        ON r.vehicle_id = v.vehicle_id
     INNER JOIN parking_slots AS ps
        ON r.slot_id = ps.slot_id
     LEFT JOIN payments AS p
        ON r.reservation_id = p.reservation_id
     WHERE v.user_id = :user_id
     ORDER BY
        r.start_date DESC,
        r.start_time DESC"
);

$statement->execute([
    "user_id" => $userId
]);

$reservations = $statement->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>My Reservations | Smart Parking</title>

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

        nav {
            display: flex;
            align-items: center;
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

        nav a:hover {
            text-decoration: underline;
        }

        main {
            width: 94%;
            max-width: 1350px;
            margin: 35px auto;
            padding: 25px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(15, 23, 42, 0.08);
        }

        h1 {
            margin-top: 0;
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
            vertical-align: middle;
        }

        th {
            background: #f8fafc;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
        }

        .pending {
            background: #fef3c7;
            color: #92400e;
        }

        .confirmed {
            background: #dcfce7;
            color: #166534;
        }

        .cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .paid {
            color: #166534;
            font-weight: bold;
        }

        .not-paid {
            color: #b45309;
            font-weight: bold;
        }

        .pay-button,
        .cancel-button {
            display: inline-block;
            padding: 7px 10px;
            border: none;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            cursor: pointer;
        }

        .pay-button {
            background: #2563eb;
        }

        .cancel-button {
            background: #dc2626;
        }

        .message {
            margin-bottom: 20px;
            padding: 13px;
            border-radius: 7px;
            background: #dcfce7;
            color: #166534;
        }

        .empty-message {
            padding: 25px;
            border-radius: 8px;
            background: #f8fafc;
            text-align: center;
        }

        .date-time {
            white-space: nowrap;
        }

        @media (max-width: 1050px) {
            .table-wrapper {
                overflow-x: auto;
            }

            nav {
                flex-direction: column;
                gap: 14px;
                text-align: center;
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
        <a href="vehicles.php">Vehicles</a>
        <a href="reserve.php">Reserve Slot</a>
        <a href="payments.php">Payments</a>
        <a href="../auth/logout.php">Logout</a>
    </div>

</nav>

<main>

    <h1>My Reservations</h1>

    <?php if ($message !== ""): ?>

        <div class="message">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>

    <?php if (empty($reservations)): ?>

        <div class="empty-message">

            <p>No reservations have been created.</p>

            <a href="reserve.php">
                Create a Reservation
            </a>

        </div>

    <?php else: ?>

        <div class="table-wrapper">

            <table>

                <thead>
                <tr>
                    <th>ID</th>
                    <th>Vehicle</th>
                    <th>Slot</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Action</th>
                </tr>
                </thead>

                <tbody>

                <?php foreach ($reservations as $reservation): ?>

                    <?php
                    $startDateTime = new DateTime(
                        $reservation["start_date"] .
                        " " .
                        $reservation["start_time"]
                    );

                    $isFutureReservation =
                        $startDateTime > new DateTime();

                    $isPaid =
                        !empty($reservation["payment_id"]);

                    $canPay =
                        !$isPaid &&
                        $isFutureReservation &&
                        $reservation["status"] === "Pending";

                    $canCancel =
                        !$isPaid &&
                        $isFutureReservation &&
                        in_array(
                            $reservation["status"],
                            ["Pending", "Confirmed"],
                            true
                        );
                    ?>

                    <tr>

                        <td>
                           <?= str_pad(
    (string) $reservation["reservation_id"],
    3,
    "0",
    STR_PAD_LEFT
) ?>
                        </td>

                        <td>
                            <strong>
                                <?= htmlspecialchars(
                                    $reservation["vehicle_number"]
                                ) ?>
                            </strong>

                            <br>

                            <small>
                                <?= htmlspecialchars(
                                    $reservation["vehicle_type"]
                                ) ?>
                            </small>
                        </td>

                        <td>
                            <strong>
                                <?= htmlspecialchars(
                                    $reservation["slot_number"]
                                ) ?>
                            </strong>

                            <br>

                            <small>
                                Floor <?= (int) $reservation["floor_no"] ?>
                                –
                                <?= htmlspecialchars(
                                    $reservation["slot_type"]
                                ) ?>
                            </small>
                        </td>

                        <td class="date-time">
                            <?= htmlspecialchars(
                                $reservation["start_date"]
                            ) ?>

                            <br>

                            <?= htmlspecialchars(
                                substr(
                                    $reservation["start_time"],
                                    0,
                                    5
                                )
                            ) ?>
                        </td>

                        <td class="date-time">
                            <?= htmlspecialchars(
                                $reservation["end_date"]
                            ) ?>

                            <br>

                            <?= htmlspecialchars(
                                substr(
                                    $reservation["end_time"],
                                    0,
                                    5
                                )
                            ) ?>
                        </td>

                        <td>
                            <span
                                class="badge <?= strtolower(
                                    $reservation["status"]
                                ) ?>"
                            >
                                <?= htmlspecialchars(
                                    $reservation["status"]
                                ) ?>
                            </span>
                        </td>

                        <td>

                            <?php if ($isPaid): ?>

                                <span class="paid">
                                    Paid
                                </span>

                                <br>

                                <small>
                                    Rs.
                                    <?= number_format(
                                        (float) $reservation["amount"],
                                        2
                                    ) ?>
                                </small>

                            <?php elseif ($canPay): ?>

                                <span class="not-paid">
                                    Not Paid
                                </span>

                                <br><br>

                                <a
                                    class="pay-button"
                                    href="pay.php?id=<?= (int) $reservation["reservation_id"] ?>"
                                >
                                    Pay Now
                                </a>

                            <?php elseif (
                                $reservation["status"] === "Cancelled"
                            ): ?>

                                Not Applicable

                            <?php else: ?>

                                <span class="not-paid">
                                    Not Paid
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <?php if ($canCancel): ?>

                                <form
                                    method="POST"
                                    action="cancel_reservation.php"
                                    onsubmit="return confirm('Cancel this reservation?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars(
                                            $_SESSION["csrf_token"]
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="reservation_id"
                                        value="<?= (int) $reservation["reservation_id"] ?>"
                                    >

                                    <button
                                        class="cancel-button"
                                        type="submit"
                                    >
                                        Cancel
                                    </button>

                                </form>

                            <?php elseif ($isPaid): ?>

                                <span class="paid">
                                    Confirmed
                                </span>

                            <?php else: ?>

                                —

                            <?php endif; ?>

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
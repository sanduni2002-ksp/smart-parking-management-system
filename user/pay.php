<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

$userId = (int) $_SESSION["user_id"];

$errors = [];
$selectedPaymentMethod = "";

$successMessage = $_SESSION["success"] ?? "";
unset($_SESSION["success"]);

$allowedMethods = [
    "Cash",
    "Card",
    "Online"
];

$rates = [
    "Motorcycle" => 100,
    "Three Wheeler" => 150,
    "Car" => 200,
    "Accessible" => 200,
    "Van" => 300
];

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

/*
|--------------------------------------------------------------------------
| Calculate parking fee
|--------------------------------------------------------------------------
*/

function calculateParkingFee(
    array $reservation,
    array $rates
): array {
    $startDateTime = new DateTimeImmutable(
        $reservation["start_date"] .
        " " .
        $reservation["start_time"]
    );

    $endDateTime = new DateTimeImmutable(
        $reservation["end_date"] .
        " " .
        $reservation["end_time"]
    );

    $durationSeconds =
        $endDateTime->getTimestamp() -
        $startDateTime->getTimestamp();

    if ($durationSeconds <= 0) {
        throw new RuntimeException(
            "Invalid reservation period."
        );
    }

    // Partial hours are rounded up.
    $hours = max(
        1,
        (int) ceil($durationSeconds / 3600)
    );

    $hourlyRate =
        $rates[$reservation["slot_type"]] ?? 200;

    return [
        "hours" => $hours,
        "hourly_rate" => $hourlyRate,
        "amount" => $hours * $hourlyRate
    ];
}

/*
|--------------------------------------------------------------------------
| Load reservation owned by logged-in user
|--------------------------------------------------------------------------
*/

function getReservation(
    PDO $pdo,
    int $reservationId,
    int $userId
) {
    $statement = $pdo->prepare(
        "SELECT
            r.reservation_id,
            r.start_date,
            r.end_date,
            r.start_time,
            r.end_time,
            r.status,

            v.vehicle_number,

            ps.slot_number,
            ps.floor_no,
            ps.slot_type,

            p.payment_id,
            p.amount AS payment_amount,
            p.payment_method,
            p.payment_date,
            p.payment_status,
            p.paid_at,
            p.transaction_reference

         FROM reservations AS r

         INNER JOIN vehicles AS v
            ON r.vehicle_id = v.vehicle_id

         INNER JOIN parking_slots AS ps
            ON r.slot_id = ps.slot_id

         LEFT JOIN payments AS p
            ON r.reservation_id = p.reservation_id

         WHERE r.reservation_id = :reservation_id
           AND v.user_id = :user_id

         LIMIT 1"
    );

    $statement->execute([
        "reservation_id" => $reservationId,
        "user_id" => $userId
    ]);

    return $statement->fetch();
}

/*
|--------------------------------------------------------------------------
| Get reservation ID
|--------------------------------------------------------------------------
*/

$reservationId =
    $_SERVER["REQUEST_METHOD"] === "POST"
        ? filter_input(
            INPUT_POST,
            "reservation_id",
            FILTER_VALIDATE_INT
        )
        : filter_input(
            INPUT_GET,
            "id",
            FILTER_VALIDATE_INT
        );

if (!$reservationId) {
    header("Location: reservations.php");
    exit;
}

$reservation = getReservation(
    $pdo,
    $reservationId,
    $userId
);

if (!$reservation) {
    http_response_code(404);
    exit("Reservation not found.");
}

try {
    $fee = calculateParkingFee(
        $reservation,
        $rates
    );
} catch (RuntimeException $exception) {
    exit($exception->getMessage());
}

$reservationStart = new DateTimeImmutable(
    $reservation["start_date"] .
    " " .
    $reservation["start_time"]
);

/*
|--------------------------------------------------------------------------
| Process payment-method selection
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    $selectedPaymentMethod = trim(
        $_POST["payment_method"] ?? ""
    );

    if (
        $csrfToken === "" ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $csrfToken
        )
    ) {
        $errors[] = "Invalid form submission.";
    }

    if (
        !in_array(
            $selectedPaymentMethod,
            $allowedMethods,
            true
        )
    ) {
        $errors[] = "Select a valid payment method.";
    }

    if ($reservation["payment_id"]) {
        $errors[] =
            "This reservation already has a payment record.";
    }

    if ($reservation["status"] !== "Pending") {
        $errors[] =
            "This reservation is not awaiting payment.";
    }

    if ($reservationStart <= new DateTimeImmutable()) {
        $errors[] =
            "Payment cannot be processed after the reservation has started.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Lock and recheck reservation
            |--------------------------------------------------------------------------
            */

            $lockStatement = $pdo->prepare(
                "SELECT
                    r.reservation_id,
                    r.start_date,
                    r.end_date,
                    r.start_time,
                    r.end_time,
                    r.status,
                    ps.slot_type

                 FROM reservations AS r

                 INNER JOIN vehicles AS v
                    ON r.vehicle_id = v.vehicle_id

                 INNER JOIN parking_slots AS ps
                    ON r.slot_id = ps.slot_id

                 WHERE r.reservation_id = :reservation_id
                   AND v.user_id = :user_id

                 LIMIT 1
                 FOR UPDATE"
            );

            $lockStatement->execute([
                "reservation_id" => $reservationId,
                "user_id" => $userId
            ]);

            $lockedReservation = $lockStatement->fetch();

            if (!$lockedReservation) {
                throw new RuntimeException(
                    "Reservation not found."
                );
            }

            if ($lockedReservation["status"] !== "Pending") {
                throw new RuntimeException(
                    "This reservation is not awaiting payment."
                );
            }

            $lockedStart = new DateTimeImmutable(
                $lockedReservation["start_date"] .
                " " .
                $lockedReservation["start_time"]
            );

            if ($lockedStart <= new DateTimeImmutable()) {
                throw new RuntimeException(
                    "The reservation has already started."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate payment
            |--------------------------------------------------------------------------
            */

            $paymentCheckStatement = $pdo->prepare(
                "SELECT payment_id
                 FROM payments
                 WHERE reservation_id = :reservation_id
                 LIMIT 1"
            );

            $paymentCheckStatement->execute([
                "reservation_id" => $reservationId
            ]);

            if ($paymentCheckStatement->fetch()) {
                throw new RuntimeException(
                    "This reservation already has a payment record."
                );
            }

            $lockedFee = calculateParkingFee(
                $lockedReservation,
                $rates
            );

            /*
            |--------------------------------------------------------------------------
            | Decide payment and reservation status
            |--------------------------------------------------------------------------
            |
            | Cash:
            |   Payment remains Pending.
            |   Reservation remains Pending.
            |
            | Card/Online:
            |   Payment becomes Paid.
            |   Reservation becomes Confirmed.
            |
            */

            if ($selectedPaymentMethod === "Cash") {
                $paymentStatus = "Pending";
                $reservationStatus = "Pending";
                $paidAt = null;

                $transactionReference =
                    "CASH-" .
                    $reservationId .
                    "-" .
                    strtoupper(bin2hex(random_bytes(3)));

            } else {
                /*
                 * This simulates a successful Card/Online payment
                 * for the academic project.
                 */

                $paymentStatus = "Paid";
                $reservationStatus = "Confirmed";

                $paidAt = date("Y-m-d H:i:s");

                $transactionReference =
                    strtoupper($selectedPaymentMethod) .
                    "-" .
                    $reservationId .
                    "-" .
                    strtoupper(bin2hex(random_bytes(3)));
            }

            /*
            |--------------------------------------------------------------------------
            | Insert payment
            |--------------------------------------------------------------------------
            */

            $paymentStatement = $pdo->prepare(
                "INSERT INTO payments (
                    reservation_id,
                    amount,
                    payment_method,
                    payment_date,
                    payment_status,
                    paid_at,
                    transaction_reference
                ) VALUES (
                    :reservation_id,
                    :amount,
                    :payment_method,
                    CURDATE(),
                    :payment_status,
                    :paid_at,
                    :transaction_reference
                )"
            );

            $paymentStatement->execute([
                "reservation_id" => $reservationId,
                "amount" => $lockedFee["amount"],
                "payment_method" =>
                    $selectedPaymentMethod,
                "payment_status" => $paymentStatus,
                "paid_at" => $paidAt,
                "transaction_reference" =>
                    $transactionReference
            ]);

            /*
            |--------------------------------------------------------------------------
            | Update reservation status
            |--------------------------------------------------------------------------
            */

            $updateStatement = $pdo->prepare(
                "UPDATE reservations
                 SET status = :status
                 WHERE reservation_id = :reservation_id"
            );

            $updateStatement->execute([
                "status" => $reservationStatus,
                "reservation_id" => $reservationId
            ]);

            $pdo->commit();

            $_SESSION["csrf_token"] = bin2hex(
                random_bytes(32)
            );

            if ($selectedPaymentMethod === "Cash") {
                $_SESSION["success"] =
                    "Cash payment selected. Present the booking slip and pay at the parking counter.";
            } else {
                $_SESSION["success"] =
                    $selectedPaymentMethod .
                    " payment completed successfully.";
            }

            /*
             * Return to this page to show the slip or receipt.
             */

            header(
                "Location: payment.php?id=" .
                $reservationId
            );
            exit;

        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $exception->getMessage();

        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($exception->getMessage());

            $errors[] =
                "Unable to process the payment. Please try again.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Generate fallback reference for old records
|--------------------------------------------------------------------------
*/

$displayReference = "";

if ($reservation["payment_id"]) {
    $displayReference =
        $reservation["transaction_reference"] ??
        (
            strtoupper(
                $reservation["payment_method"] ?? "PAY"
            ) .
            "-" .
            $reservation["reservation_id"] .
            "-" .
            $reservation["payment_id"]
        );
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

    <title>Payment | Smart Parking</title>

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
            padding: 30px 15px;
            font-family: Arial, sans-serif;
            background:
                linear-gradient(
                    135deg,
                    #eff6ff,
                    #f8fafc
                );
            color: #0f2747;
        }

        .page-container {
            width: 100%;
            max-width: 720px;
            margin: auto;
        }

        .card {
            padding: 31px;
            border: 1px solid #d7e3f1;
            border-top: 5px solid #2563eb;
            border-radius: 15px;
            background: white;
            box-shadow: 0 12px 32px rgba(15, 39, 71, 0.1);
        }

        h1 {
            margin-top: 0;
            color: #071f42;
        }

        .subtitle {
            margin-top: -5px;
            color: #64748b;
        }

        .details {
            margin-top: 22px;
            padding: 21px;
            border-radius: 10px;
            background: #f8fafc;
        }

        .details p {
            margin: 11px 0;
        }

        .amount {
            color: #2563eb;
            font-size: 29px;
            font-weight: bold;
        }

        label {
            display: block;
            margin: 21px 0 8px;
            font-weight: bold;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: white;
        }

        .submit-button {
            width: 100%;
            margin-top: 20px;
            padding: 13px;
            border: none;
            border-radius: 8px;
            background: #0b2f5b;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .submit-button:hover {
            background: #2563eb;
        }

        .message {
            margin-bottom: 19px;
            padding: 13px;
            border-radius: 8px;
        }

        .success {
            border: 1px solid #86efac;
            background: #dcfce7;
            color: #166534;
        }

        .error {
            border: 1px solid #fca5a5;
            background: #fee2e2;
            color: #991b1b;
        }

        .error ul {
            margin: 0;
            padding-left: 20px;
        }

        .notice {
            margin-top: 17px;
            padding: 13px;
            border-radius: 8px;
            background: #fff7ed;
            color: #9a3412;
            font-size: 14px;
            line-height: 1.5;
        }

        .payment-document {
            margin-top: 22px;
            padding: 24px;
            border: 2px dashed #94a3b8;
            border-radius: 11px;
            background: white;
        }

        .payment-document h2 {
            margin: 0 0 8px;
            text-align: center;
            color: #071f42;
        }

        .document-subtitle {
            margin: 0 0 22px;
            text-align: center;
            color: #64748b;
        }

        .document-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 9px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .document-row span:first-child {
            color: #64748b;
        }

        .document-row strong {
            text-align: right;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: bold;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-paid {
            background: #dcfce7;
            color: #166534;
        }

        .document-warning {
            margin-top: 19px;
            padding: 13px;
            border-radius: 8px;
            background: #fef3c7;
            color: #92400e;
            text-align: center;
            font-weight: bold;
        }

        .print-button {
            width: 100%;
            margin-top: 17px;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: #2563eb;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #2563eb;
            text-decoration: none;
            font-weight: bold;
        }

        @media print {
            body {
                padding: 0;
                background: white;
            }

            .card {
                border: none;
                box-shadow: none;
            }

            .no-print,
            .success,
            .error {
                display: none;
            }

            .payment-document {
                border: 1px solid #000;
            }
        }

        @media (max-width: 560px) {
            .card {
                padding: 22px;
            }

            .document-row {
                align-items: flex-start;
                flex-direction: column;
                gap: 4px;
            }

            .document-row strong {
                text-align: left;
            }
        }
    </style>
</head>

<body>

<main class="page-container">

    <section class="card">

        <h1>Reservation Payment</h1>

        <p class="subtitle">
            Review the reservation and select a payment method.
        </p>

        <?php if ($successMessage !== ""): ?>

            <div class="message success">
                <?= htmlspecialchars($successMessage) ?>
            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="message error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <div class="details">

            <p>
                <strong>Vehicle:</strong>

                <?= htmlspecialchars(
                    $reservation["vehicle_number"]
                ) ?>
            </p>

            <p>
                <strong>Parking Slot:</strong>

                <?= htmlspecialchars(
                    $reservation["slot_number"]
                ) ?>

                – Floor
                <?= (int) $reservation["floor_no"] ?>
            </p>

            <p>
                <strong>Start:</strong>

                <?= htmlspecialchars(
                    $reservation["start_date"]
                ) ?>

                <?= htmlspecialchars(
                    substr(
                        $reservation["start_time"],
                        0,
                        5
                    )
                ) ?>
            </p>

            <p>
                <strong>End:</strong>

                <?= htmlspecialchars(
                    $reservation["end_date"]
                ) ?>

                <?= htmlspecialchars(
                    substr(
                        $reservation["end_time"],
                        0,
                        5
                    )
                ) ?>
            </p>

            <p>
                <strong>Duration:</strong>
                <?= (int) $fee["hours"] ?> hour(s)
            </p>

            <p>
                <strong>Hourly Rate:</strong>

                Rs.
                <?= number_format(
                    (float) $fee["hourly_rate"],
                    2
                ) ?>
            </p>

            <p class="amount">
                Total: Rs.
                <?= number_format(
                    (float) $fee["amount"],
                    2
                ) ?>
            </p>

        </div>

        <?php if (!$reservation["payment_id"]): ?>

            <?php if (
                $reservation["status"] === "Pending" &&
                $reservationStart > new DateTimeImmutable()
            ): ?>

                <form method="POST" class="no-print">

                    <input
                        type="hidden"
                        name="reservation_id"
                        value="<?= (int) $reservationId ?>"
                    >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $_SESSION["csrf_token"]
                        ) ?>"
                    >

                    <label for="payment_method">
                        Payment Method
                    </label>

                    <select
                        id="payment_method"
                        name="payment_method"
                        required
                    >
                        <option value="">
                            Select Payment Method
                        </option>

                        <?php foreach (
                            $allowedMethods as $method
                        ): ?>

                            <option
                                value="<?= htmlspecialchars($method) ?>"
                                <?= $selectedPaymentMethod === $method
                                    ? "selected"
                                    : "" ?>
                            >
                                <?= htmlspecialchars($method) ?>
                            </option>

                        <?php endforeach; ?>
                    </select>

                    <div class="notice">
                        Cash payments remain pending until an
                        administrator receives and confirms the cash.
                        Card and Online payments are simulated as
                        successful for this academic project.
                    </div>

                    <button
                        type="submit"
                        class="submit-button"
                    >
                        Confirm Payment Method
                    </button>

                </form>

            <?php else: ?>

                <p class="notice">
                    This reservation cannot be paid at this time.
                </p>

            <?php endif; ?>

        <?php else: ?>

            <?php
            $isPaid =
                $reservation["payment_status"] === "Paid";

            $documentTitle = $isPaid
                ? "Official Payment Receipt"
                : "Pay-at-Counter Booking Slip";
            ?>

            <section class="payment-document">

                <h2>
                    Smart Parking
                </h2>

                <p class="document-subtitle">
                    <?= htmlspecialchars($documentTitle) ?>
                </p>

                <div class="document-row">
                    <span>Reference</span>

                    <strong>
                        <?= htmlspecialchars($displayReference) ?>
                    </strong>
                </div>

                <div class="document-row">
                    <span>Reservation ID</span>

                    <strong>
                        #<?= (int) $reservation[
                            "reservation_id"
                        ] ?>
                    </strong>
                </div>

                <div class="document-row">
                    <span>Vehicle</span>

                    <strong>
                        <?= htmlspecialchars(
                            $reservation["vehicle_number"]
                        ) ?>
                    </strong>
                </div>

                <div class="document-row">
                    <span>Parking Slot</span>

                    <strong>
                        <?= htmlspecialchars(
                            $reservation["slot_number"]
                        ) ?>

                        – Floor
                        <?= (int) $reservation["floor_no"] ?>
                    </strong>
                </div>

                <div class="document-row">
                    <span>Payment Method</span>

                    <strong>
                        <?= htmlspecialchars(
                            $reservation["payment_method"]
                        ) ?>
                    </strong>
                </div>

                <div class="document-row">
                    <span>Payment Status</span>

                    <strong>
                        <span class="status-badge <?= $isPaid
                            ? "status-paid"
                            : "status-pending" ?>"
                        >
                            <?= htmlspecialchars(
                                $reservation["payment_status"]
                            ) ?>
                        </span>
                    </strong>
                </div>

                <div class="document-row">
                    <span>
                        <?= $isPaid
                            ? "Amount Paid"
                            : "Amount Due" ?>
                    </span>

                    <strong>
                        Rs.
                        <?= number_format(
                            (float) $reservation[
                                "payment_amount"
                            ],
                            2
                        ) ?>
                    </strong>
                </div>

                <div class="document-row">
                    <span>
                        <?= $isPaid
                            ? "Paid At"
                            : "Booking Date" ?>
                    </span>

                    <strong>
                        <?= htmlspecialchars(
                            $isPaid
                                ? (
                                    $reservation["paid_at"]
                                    ?? $reservation["payment_date"]
                                )
                                : $reservation["payment_date"]
                        ) ?>
                    </strong>
                </div>

                <?php if (!$isPaid): ?>

                    <div class="document-warning">
                        Payment has not been received. Present this
                        slip and pay at the parking counter before entry.
                        This is not a payment receipt.
                    </div>

                <?php endif; ?>

            </section>

            <button
                type="button"
                class="print-button no-print"
                onclick="window.print();"
            >
                Print
                <?= $isPaid ? "Receipt" : "Booking Slip" ?>
            </button>

        <?php endif; ?>

        <a
            href="reservations.php"
            class="back-link no-print"
        >
            ← Back to Reservations
        </a>

    </section>

</main>

</body>
</html>
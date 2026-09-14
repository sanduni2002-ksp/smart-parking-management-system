<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

date_default_timezone_set("Asia/Colombo");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: reservations.php");
    exit;
}

$reservationId = filter_input(
    INPUT_POST,
    "reservation_id",
    FILTER_VALIDATE_INT
);

$csrfToken = $_POST["csrf_token"] ?? "";

if (
    !$reservationId ||
    !hash_equals(
        $_SESSION["csrf_token"],
        $csrfToken
    )
) {
    $_SESSION["success"] = "Invalid cancellation request.";

    header("Location: reservations.php");
    exit;
}

try {
    $pdo->beginTransaction();

    /*
     * Verify that the reservation belongs to a vehicle
     * owned by the logged-in user.
     */
    $statement = $pdo->prepare(
        "SELECT
            r.reservation_id,
            r.reservation_date,
            r.start_time,
            r.status,
            p.payment_id
         FROM reservations AS r
         INNER JOIN vehicles AS v
            ON r.vehicle_id = v.vehicle_id
         WHERE r.reservation_id = :reservation_id
           AND v.user_id = :user_id
         FOR UPDATE"
    );

    $statement->execute([
        "reservation_id" => $reservationId,
        "user_id" => $_SESSION["user_id"]
    ]);

    $reservation = $statement->fetch();

    if ($reservation["payment_id"]) {
        throw new RuntimeException(
            "A paid reservation cannot be cancelled."
        );
    }

    if (
        !in_array(
            $reservation["status"],
            ["Pending", "Confirmed"],
            true
        )
    ) {
        throw new RuntimeException(
            "This reservation cannot be cancelled."
        );
    }

    $reservationStart = new DateTime(
        $reservation["reservation_date"] .
        " " .
        $reservation["start_time"]
    );

    if ($reservationStart <= new DateTime()) {
        throw new RuntimeException(
            "A started or expired reservation cannot be cancelled."
        );
    }

    $updateStatement = $pdo->prepare(
        "UPDATE reservations
         SET status = 'Cancelled'
         WHERE reservation_id = :reservation_id"
    );

    $updateStatement->execute([
        "reservation_id" => $reservationId
    ]);

    $pdo->commit();

    $_SESSION["success"] =
        "Reservation cancelled successfully.";

} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION["success"] = $exception->getMessage();

} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($exception->getMessage());

    $_SESSION["success"] =
        "Unable to cancel the reservation.";
}

header("Location: reservations.php");
exit;
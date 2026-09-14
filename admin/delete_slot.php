<?php

require_once __DIR__ . "/../includes/admin_auth.php";
require_once __DIR__ . "/../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: slots.php");
    exit;
}

$slotId = filter_input(
    INPUT_POST,
    "slot_id",
    FILTER_VALIDATE_INT
);

$csrfToken = $_POST["csrf_token"] ?? "";

if (
    !$slotId ||
    !hash_equals(
        $_SESSION["csrf_token"],
        $csrfToken
    )
) {
    $_SESSION["success"] = "Invalid delete request.";

    header("Location: slots.php");
    exit;
}

try {
    $statement = $pdo->prepare(
        "DELETE FROM parking_slots
         WHERE slot_id = :slot_id"
    );

    $statement->execute([
        "slot_id" => $slotId
    ]);

    $_SESSION["success"] =
        $statement->rowCount() === 1
            ? "Parking slot deleted successfully."
            : "Parking slot not found.";

} catch (PDOException $exception) {
    if ($exception->getCode() === "23000") {
        $_SESSION["success"] =
            "This slot cannot be deleted because it has reservations.";
    } else {
        error_log($exception->getMessage());
        $_SESSION["success"] =
            "Unable to delete the parking slot.";
    }
}

header("Location: slots.php");
exit;

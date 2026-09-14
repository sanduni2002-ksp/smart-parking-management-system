<?php

require_once __DIR__ . "/../includes/user_auth.php";
require_once __DIR__ . "/../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: vehicles.php");
    exit;
}

$csrfToken = $_POST["csrf_token"] ?? "";
$vehicleId = filter_input(
    INPUT_POST,
    "vehicle_id",
    FILTER_VALIDATE_INT
);

if (
    !$vehicleId ||
    !hash_equals(
        $_SESSION["csrf_token"],
        $csrfToken
    )
) {
    $_SESSION["success"] = "Invalid delete request.";

    header("Location: vehicles.php");
    exit;
}

try {
    $deleteStatement = $pdo->prepare(
        "DELETE FROM vehicles
         WHERE vehicle_id = :vehicle_id
           AND user_id = :user_id"
    );

    $deleteStatement->execute([
        "vehicle_id" => $vehicleId,
        "user_id" => $_SESSION["user_id"]
    ]);

    if ($deleteStatement->rowCount() === 1) {
        $_SESSION["success"] = "Vehicle deleted successfully.";
    } else {
        $_SESSION["success"] = "Vehicle not found.";
    }

} catch (PDOException $exception) {
    if ($exception->getCode() === "23000") {
        $_SESSION["success"] =
            "This vehicle cannot be deleted because it has reservations.";
    } else {
        error_log($exception->getMessage());
        $_SESSION["success"] = "Unable to delete the vehicle.";
    }
}

header("Location: vehicles.php");
exit;
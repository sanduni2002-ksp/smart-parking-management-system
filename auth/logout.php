<?php

session_start();

/* Remove all session variables */
$_SESSION = [];

/* Remove the session cookie */
if (ini_get("session.use_cookies")) {
    $cookieParameters = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $cookieParameters["path"],
        $cookieParameters["domain"],
        $cookieParameters["secure"],
        $cookieParameters["httponly"]
    );
}

/* Destroy the session */
session_destroy();

header("Location: login.php");
exit;
<?php
// Front door: the shared site password first, then the app shell (which handles sign-in itself).
define('BUGGY', true);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

startAppSession();
$gateError = '';

if (sitePassword() === null) {
    http_response_code(503);
    $gateError = 'not-configured';
    require __DIR__ . '/views/gate.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_password'])) {
    $db = openDatabase();
    $key = 'gate:' . clientIp();
    if (isThrottled($db, $key)) {
        $gateError = 'Too many attempts. Wait 15 minutes and try again.';
    } elseif (hash_equals(sitePassword(), (string) $_POST['site_password'])) {
        clearFailures($db, $key);
        session_regenerate_id(true);
        $_SESSION['gate_ok'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
        exit;
    } else {
        recordFailure($db, $key);
        $gateError = 'That’s not the password.';
    }
}

if (!gatePassed()) {
    require __DIR__ . '/views/gate.php';
    exit;
}

require __DIR__ . '/views/app.php';

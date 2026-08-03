<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// Restore session from cookies if session expired
if (!isset($_SESSION['user_id']) && isset($_COOKIE['user_id'])) {
    $_SESSION['user_id']      = $_COOKIE['user_id'];
    $_SESSION['user_name']    = $_COOKIE['user_name'] ?? '';
    $_SESSION['user_email']   = $_COOKIE['user_email'] ?? '';
    $_SESSION['user_role']    = $_COOKIE['user_role'] ?? '';
    $_SESSION['profile_image']= $_COOKIE['profile_image'] ?? '';

    if (isset($_COOKIE['role_id'])) {
        $_SESSION['role_id'] = (int)$_COOKIE['role_id'];
    }
}

// Final check – if still no session or cookies, redirect to login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['role_id'] ?? 0);

if ($userId <= 0 || $roleId <= 0) {
    session_destroy();
    header("Location: login.php?error=invalid_role");
    exit;
}

$stmt = $mysqli->prepare("
    SELECT u.user_id, u.role_id, r.status AS role_status
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE u.user_id = ?
      AND u.status = 'active'
      AND r.status = 'active'
    LIMIT 1
");

if (!$stmt) {
    session_destroy();
    header("Location: login.php?error=session_check_failed");
    exit;
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$activeUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$activeUser) {
    session_destroy();
    header("Location: login.php?error=inactive_role");
    exit;
}

$_SESSION['role_id'] = (int)$activeUser['role_id'];
?>

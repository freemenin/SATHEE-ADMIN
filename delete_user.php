<?php
require_once 'include/require_permission.php';
requirePermission('USERS', 'delete');
require_once 'include/csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid request token.'];
    header("Location: user_list.php");
    exit;
}

$user_id = intval($_POST['delete_id'] ?? 0);

if ($user_id <= 0) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid user.'];
    header("Location: user_list.php");
    exit;
}

if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'You cannot delete your own account.'];
    header("Location: user_list.php");
    exit;
}

if (isLastAdminUser($user_id)) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Cannot delete the last active Administrator user.'];
    header("Location: user_list.php");
    exit;
}

$stmt = $mysqli->prepare("DELETE FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);

if ($stmt->execute()) {
    $stmt->close();
    logPermissionAudit('USER_DELETED', 'USER', $user_id);
    $_SESSION['toast'] = ['type' => 'success', 'message' => 'User deleted successfully.'];
} else {
    $stmt->close();
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Failed to delete user.'];
}

header("Location: user_list.php");
exit;

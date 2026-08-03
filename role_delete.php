<?php
require_once 'include/require_permission.php';
requirePermission('ROLES', 'delete');

require_once 'include/csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

$roleId = (int)($_POST['role_id'] ?? 0);

if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid request token.'];
    header('Location: role_list.php');
    exit;
}

if ($roleId <= 0) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid role.'];
    header('Location: role_list.php');
    exit;
}

$stmt = $mysqli->prepare("SELECT role_code, is_system_role FROM roles WHERE role_id = ? LIMIT 1");
$stmt->bind_param("i", $roleId);
$stmt->execute();
$result = $stmt->get_result();
$role = $result->fetch_assoc();
$stmt->close();

if (!$role) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Role not found.'];
    header('Location: role_list.php');
    exit;
}

if ($role['is_system_role']) {
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'This is a built-in system role and cannot be deleted.'
    ];
    header('Location: role_list.php');
    exit;
}

if (isLastAdminRole($roleId)) {
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Cannot delete the last active Administrator role.'
    ];
    header('Location: role_list.php');
    exit;
}

// Refuse to delete a role that still has users assigned - deleting it would
// leave those users with a dangling role_id and no permissions at all.
$userCheckStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM users WHERE role_id = ?");
$userCheckStmt->bind_param("i", $roleId);
$userCheckStmt->execute();
$userCheckResult = $userCheckStmt->get_result();
$userCheckRow = $userCheckResult->fetch_assoc();
$userCheckStmt->close();

if ((int)$userCheckRow['count'] > 0) {
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Cannot delete a role that still has users assigned to it. Reassign those users first.'
    ];
    header('Location: role_list.php');
    exit;
}

$mysqli->begin_transaction();

try {
    $deletePerms = $mysqli->prepare("DELETE FROM role_page_permissions WHERE role_id = ?");
    $deletePerms->bind_param("i", $roleId);
    $deletePerms->execute();
    $deletePerms->close();

    $deleteRole = $mysqli->prepare("DELETE FROM roles WHERE role_id = ?");
    $deleteRole->bind_param("i", $roleId);
    $deleteRole->execute();
    $deleteRole->close();

    logPermissionAudit('ROLE_DELETED', 'ROLE', $roleId);

    $mysqli->commit();

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Role deleted successfully.'
    ];
} catch (Exception $e) {
    $mysqli->rollback();
    error_log("Error deleting role: " . $e->getMessage());

    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Failed to delete role.'
    ];
}

header('Location: role_list.php');
exit;

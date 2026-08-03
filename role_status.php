<?php
require_once 'include/require_permission.php';
requirePermission('ROLES', 'edit');

require_once 'include/csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

$roleId = (int)($_POST['role_id'] ?? 0);
$newStatus = $_POST['status'] ?? '';

if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid request token.'];
    header('Location: role_list.php');
    exit;
}

if ($roleId <= 0 || !in_array($newStatus, ['active', 'inactive'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid role or status.'];
    header('Location: role_list.php');
    exit;
}

$stmt = $mysqli->prepare("SELECT role_code, status, is_system_role FROM roles WHERE role_id = ? LIMIT 1");
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
        'message' => 'This is a built-in system role and cannot be deactivated.'
    ];
    header('Location: role_list.php');
    exit;
}

// Never allow the last active Administrator role to be switched off - doing so
// could lock every administrator out of the tools needed to fix it.
if ($newStatus === 'inactive' && isLastAdminRole($roleId)) {
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Cannot deactivate the last active Administrator role.'
    ];
    header('Location: role_list.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$updateStmt = $mysqli->prepare("
    UPDATE roles SET status = ?, updated_by = ?, updated_at = NOW()
    WHERE role_id = ?
");
$updateStmt->bind_param("sii", $newStatus, $userId, $roleId);

if ($updateStmt->execute()) {
    logPermissionAudit('ROLE_STATUS_CHANGED', 'ROLE', $roleId, ['status' => $role['status']], ['status' => $newStatus]);
    $updateStmt->close();

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Role status updated successfully.'
    ];
} else {
    $updateStmt->close();
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Failed to update role status.'
    ];
}

header('Location: role_list.php');
exit;

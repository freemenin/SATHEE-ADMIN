<?php
require_once 'include/require_permission.php';
requirePermission('ROLE_PERMISSIONS', 'edit');

require_once 'include/csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

$roleId = (int)($_POST['role_id'] ?? 0);
$submittedPerms = $_POST['perm'] ?? [];

if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid request token.'];
    header('Location: role_permissions.php?role_id=' . $roleId);
    exit;
}

if ($roleId <= 0) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid role.'];
    header('Location: role_list.php');
    exit;
}

$roleStmt = $mysqli->prepare("SELECT role_id FROM roles WHERE role_id = ? LIMIT 1");
$roleStmt->bind_param("i", $roleId);
$roleStmt->execute();
$roleExists = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();

if (!$roleExists) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Role not found.'];
    header('Location: role_list.php');
    exit;
}

// If this is the last active Administrator role, never let the submission
// strip view access to the pages needed to manage roles/permissions - that
// would lock every administrator out with no way back in through the UI.
$isLastAdmin = isLastAdminRole($roleId);
$protectedPageCodes = ['ROLES', 'ROLE_PERMISSIONS', 'PAGES'];

$pagesStmt = $mysqli->query("SELECT page_id, page_code FROM system_pages WHERE status = 'active'");
$allPages = [];
while ($row = $pagesStmt->fetch_assoc()) {
    $allPages[] = $row;
}

$userId = (int)($_SESSION['user_id'] ?? 0);

$mysqli->begin_transaction();

try {
    $upsertStmt = $mysqli->prepare("
        INSERT INTO role_page_permissions
            (role_id, page_id, can_view, can_add, can_edit, can_delete, can_export, can_approve, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            can_view = VALUES(can_view),
            can_add = VALUES(can_add),
            can_edit = VALUES(can_edit),
            can_delete = VALUES(can_delete),
            can_export = VALUES(can_export),
            can_approve = VALUES(can_approve),
            updated_by = VALUES(created_by),
            updated_at = NOW()
    ");

    foreach ($allPages as $pageRow) {
        $pageId = (int)$pageRow['page_id'];
        $pagePerm = $submittedPerms[$pageId] ?? [];

        $canView = !empty($pagePerm['view']) ? 1 : 0;
        $canAdd = !empty($pagePerm['add']) ? 1 : 0;
        $canEdit = !empty($pagePerm['edit']) ? 1 : 0;
        $canDelete = !empty($pagePerm['delete']) ? 1 : 0;
        $canExport = !empty($pagePerm['export']) ? 1 : 0;
        $canApprove = !empty($pagePerm['approve']) ? 1 : 0;

        if ($isLastAdmin && in_array($pageRow['page_code'], $protectedPageCodes, true)) {
            $canView = 1;
        }

        $upsertStmt->bind_param(
            "iiiiiiiii",
            $roleId, $pageId, $canView, $canAdd, $canEdit, $canDelete, $canExport, $canApprove, $userId
        );
        $upsertStmt->execute();
    }

    $upsertStmt->close();

    logPermissionAudit('ROLE_PERMISSIONS_UPDATED', 'ROLE', $roleId);

    $mysqli->commit();

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Permissions updated successfully.'
    ];
} catch (Exception $e) {
    $mysqli->rollback();
    error_log("Error saving role permissions: " . $e->getMessage());

    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Failed to update permissions.'
    ];
}

header('Location: role_permissions.php?role_id=' . $roleId);
exit;

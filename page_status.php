<?php
require_once 'include/require_permission.php';
requirePermission('PAGES', 'edit');

require_once 'include/csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

$pageId = (int)($_POST['page_id'] ?? 0);
$newStatus = $_POST['status'] ?? '';

if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid request token.'];
    header('Location: page_list.php');
    exit;
}

if ($pageId <= 0 || !in_array($newStatus, ['active', 'inactive'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid page or status.'];
    header('Location: page_list.php');
    exit;
}

$stmt = $mysqli->prepare("SELECT page_code, status FROM system_pages WHERE page_id = ? LIMIT 1");
$stmt->bind_param("i", $pageId);
$stmt->execute();
$result = $stmt->get_result();
$page = $result->fetch_assoc();
$stmt->close();

if (!$page) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Page not found.'];
    header('Location: page_list.php');
    exit;
}

// Never allow the core admin/permission pages to be switched off - doing so could
// lock every administrator out of the tools needed to fix it.
$mandatoryPages = ['ROLES', 'PAGES', 'ROLE_PERMISSIONS', 'USERS', 'DASHBOARD'];
if ($newStatus === 'inactive' && in_array($page['page_code'], $mandatoryPages, true)) {
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'This is a core system page and cannot be deactivated.'
    ];
    header('Location: page_list.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$updateStmt = $mysqli->prepare("
    UPDATE system_pages SET status = ?, updated_by = ?, updated_at = NOW()
    WHERE page_id = ?
");
$updateStmt->bind_param("sii", $newStatus, $userId, $pageId);

if ($updateStmt->execute()) {
    logPermissionAudit('PAGE_STATUS_CHANGED', 'PAGE', $pageId, ['status' => $page['status']], ['status' => $newStatus]);
    $updateStmt->close();

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Page status updated successfully.'
    ];
} else {
    $updateStmt->close();
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Failed to update page status.'
    ];
}

header('Location: page_list.php');
exit;

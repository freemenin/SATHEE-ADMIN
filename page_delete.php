<?php
require_once 'include/require_permission.php';
requirePermission('PAGES', 'delete');

require_once 'include/csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

$pageId = (int)($_POST['page_id'] ?? 0);

// Verify CSRF
if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid request token.'];
    header('Location: page_list.php');
    exit;
}

if ($pageId <= 0) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid page.'];
    header('Location: page_list.php');
    exit;
}

// Fetch page
$stmt = $mysqli->prepare("SELECT page_code FROM system_pages WHERE page_id = ? LIMIT 1");
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

// Prevent deleting mandatory system pages
$mandatoryPages = ['ROLES', 'PAGE_MANAGEMENT', 'ROLE_PERMISSIONS', 'USERS', 'DASHBOARD'];
if (in_array($page['page_code'], $mandatoryPages)) {
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'This is a system page and cannot be deleted.'
    ];
    header('Location: page_list.php');
    exit;
}

// Check if page has children
$childStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM system_pages WHERE parent_id = ?");
$childStmt->bind_param("i", $pageId);
$childStmt->execute();
$childResult = $childStmt->get_result();
$childRow = $childResult->fetch_assoc();
$childStmt->close();

if ((int)$childRow['count'] > 0) {
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Cannot delete a page that has child pages.'
    ];
    header('Location: page_list.php');
    exit;
}

// Start transaction
$mysqli->begin_transaction();

try {
    // Delete permissions
    $deletePerms = $mysqli->prepare("DELETE FROM role_page_permissions WHERE page_id = ?");
    $deletePerms->bind_param("i", $pageId);
    $deletePerms->execute();
    $deletePerms->close();

    // Delete page
    $deletePage = $mysqli->prepare("DELETE FROM system_pages WHERE page_id = ?");
    $deletePage->bind_param("i", $pageId);
    $deletePage->execute();
    $deletePage->close();

    // Log audit
    logPermissionAudit('PAGE_DELETED', 'PAGE', $pageId);

    $mysqli->commit();

    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Page deleted successfully.'
    ];
} catch (Exception $e) {
    $mysqli->rollback();
    error_log("Error deleting page: " . $e->getMessage());
    
    $_SESSION['toast'] = [
        'type' => 'danger',
        'message' => 'Failed to delete page.'
    ];
}

header('Location: page_list.php');
exit;
?>

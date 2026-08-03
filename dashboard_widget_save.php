<?php
require_once 'include/require_permission.php';
require_once 'include/csrf_helper.php';
require_once 'include/dashboard_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid request');
}

$widgetId = (int)($_POST['widget_id'] ?? 0);
requirePermission('DASHBOARD_WIDGETS', $widgetId > 0 ? 'edit' : 'add');

$widgetName = trim($_POST['widget_name'] ?? '');
$widgetCode = strtoupper(trim($_POST['widget_code'] ?? ''));
$department = strtoupper(trim($_POST['department_code'] ?? 'GENERAL'));
$relatedPage = trim($_POST['related_page_code'] ?? '');
$widgetType = trim($_POST['widget_type'] ?? 'kpi');
$title = trim($_POST['title'] ?? '');
$subtitle = trim($_POST['subtitle'] ?? '');
$iconClass = trim($_POST['icon_class'] ?? '');
$bootstrapClass = trim($_POST['bootstrap_class'] ?? '');
$displayOrder = (int)($_POST['display_order'] ?? 0);
$containsAmount = isset($_POST['contains_amount']) ? 1 : 0;
$adminOnly = isset($_POST['admin_only']) ? 1 : 0;
$status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($widgetName === '' || $widgetCode === '' || $title === '' || !preg_match('/^[A-Z0-9_]{3,150}$/', $widgetCode)) {
    http_response_code(422);
    exit('Invalid widget data');
}

$relatedPage = $relatedPage === '' ? null : $relatedPage;
$subtitle = $subtitle === '' ? null : $subtitle;
$iconClass = $iconClass === '' ? null : $iconClass;
$bootstrapClass = $bootstrapClass === '' ? null : $bootstrapClass;

if ($widgetId > 0) {
    $old = null;
    $oldStmt = $mysqli->prepare("SELECT * FROM dashboard_widgets WHERE widget_id = ? LIMIT 1");
    $oldStmt->bind_param('i', $widgetId);
    $oldStmt->execute();
    $old = $oldStmt->get_result()->fetch_assoc();
    $oldStmt->close();

    $stmt = $mysqli->prepare("
        UPDATE dashboard_widgets
        SET widget_name = ?, widget_code = ?, department_code = ?, related_page_code = ?,
            widget_type = ?, title = ?, subtitle = ?, icon_class = ?, bootstrap_class = ?,
            display_order = ?, contains_amount = ?, admin_only = ?, status = ?,
            updated_by = ?, updated_at = NOW()
        WHERE widget_id = ?
    ");
    $stmt->bind_param('sssssssssiiisii', $widgetName, $widgetCode, $department, $relatedPage, $widgetType, $title, $subtitle, $iconClass, $bootstrapClass, $displayOrder, $containsAmount, $adminOnly, $status, $userId, $widgetId);
    $stmt->execute();
    $stmt->close();
    logPermissionAudit('DASHBOARD_WIDGET_UPDATED', 'DASHBOARD_WIDGET', $widgetId, $old, $_POST);
} else {
    $stmt = $mysqli->prepare("
        INSERT INTO dashboard_widgets
        (widget_name, widget_code, department_code, related_page_code, widget_type, title, subtitle,
         icon_class, bootstrap_class, display_order, contains_amount, admin_only, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('sssssssssiiisi', $widgetName, $widgetCode, $department, $relatedPage, $widgetType, $title, $subtitle, $iconClass, $bootstrapClass, $displayOrder, $containsAmount, $adminOnly, $status, $userId);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    logPermissionAudit('DASHBOARD_WIDGET_CREATED', 'DASHBOARD_WIDGET', $newId, null, $_POST);
}

header('Location: dashboard_widget_list.php');
exit;

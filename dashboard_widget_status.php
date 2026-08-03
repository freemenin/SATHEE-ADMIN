<?php
require_once 'include/require_permission.php';
require_once 'include/csrf_helper.php';
require_once 'include/dashboard_helper.php';

requirePermission('DASHBOARD_WIDGETS', 'edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid request');
}

$widgetId = (int)($_POST['widget_id'] ?? 0);
if ($widgetId <= 0) {
    header('Location: dashboard_widget_list.php');
    exit;
}

$stmt = $mysqli->prepare("SELECT * FROM dashboard_widgets WHERE widget_id = ? LIMIT 1");
$stmt->bind_param('i', $widgetId);
$stmt->execute();
$widget = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($widget) {
    $newStatus = ($widget['status'] ?? '') === 'active' ? 'inactive' : 'active';
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $update = $mysqli->prepare("UPDATE dashboard_widgets SET status = ?, updated_by = ?, updated_at = NOW() WHERE widget_id = ?");
    $update->bind_param('sii', $newStatus, $userId, $widgetId);
    $update->execute();
    $update->close();
    logPermissionAudit('DASHBOARD_WIDGET_STATUS_CHANGED', 'DASHBOARD_WIDGET', $widgetId, $widget, ['status' => $newStatus]);
}

header('Location: dashboard_widget_list.php');
exit;

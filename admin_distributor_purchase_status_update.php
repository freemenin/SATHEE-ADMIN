<?php
require_once 'include/require_permission.php';
requirePermission('PURCHASE_REQUESTS', 'edit');
include('include/require_login.php');
require_once 'include/db.php';

$purchase_id = (int)($_POST['purchase_id'] ?? 0);
$new_status  = trim($_POST['new_status'] ?? '');

$allowed_status = ['ready', 'dispatched'];

if ($purchase_id <= 0 || !in_array($new_status, $allowed_status, true)) {
    die("Invalid request.");
}

$stmt = $mysqli->prepare("SELECT purchase_id, status FROM distributor_purchase_master WHERE purchase_id = ? LIMIT 1");
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$purchase) {
    die("Purchase request not found.");
}

/* Optional business rules */
if ($purchase['status'] === 'dispatched' && $new_status === 'ready') {
    die("Dispatched request cannot be moved back to Ready.");
}

$stmt = $mysqli->prepare("UPDATE distributor_purchase_master SET status = ? WHERE purchase_id = ?");
$stmt->bind_param("si", $new_status, $purchase_id);
$stmt->execute();
$stmt->close();

header("Location: purchase_invoice.php?id=" . $purchase_id . "&updated=1");
exit;
?>
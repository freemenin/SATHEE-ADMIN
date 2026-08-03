<?php

require_once 'include/require_permission.php';
include('include/require_login.php');
include('include/db.php');
date_default_timezone_set('Asia/Kolkata');

// Ensure session
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* ----------------------- Helpers ----------------------- */
function is_ajax(): bool {
  return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
      && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_out(array $arr): never {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr);
  exit;
}

/**
 * Safe redirect with query params appended (preserves existing ?query).
 * Prevents open-redirect by allowing only relative paths for $url.
 */
function redirect_with_params(string $url, array $params): never {
  // Allow only relative paths to avoid open redirect
  $u = parse_url($url);
  if (($u['scheme'] ?? null) || ($u['host'] ?? null)) {
    $url = 'dashboard.php';
  }
  $sep = (strpos($url, '?') !== false) ? '&' : '?';
  header('Location: ' . $url . $sep . http_build_query($params));
  exit;
}

/* ----------------------- Permission ----------------------- */
if (!hasPermission('ORDERS', 'edit')) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'FORBIDDEN']);
  header('Location: access_denied.php');
  exit;
}

/* ----------------------- Method & CSRF ----------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
  redirect_with_params('dashboard.php', ['msg' => 'method-not-allowed']);
}

$csrf_post = $_POST['csrf'] ?? '';
$csrf_sess = $_SESSION['csrf'] ?? '';
if (!$csrf_post || !$csrf_sess || !hash_equals($csrf_sess, $csrf_post)) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'CSRF_FAILED']);
  redirect_with_params('dashboard.php', ['msg' => 'csrf-failed']);
}

/* ----------------------- Inputs ----------------------- */
$order_id      = (int)($_POST['order_id'] ?? 0);
$cancel_reason = trim((string)($_POST['cancel_reason'] ?? ''));
$return_to_raw = trim((string)($_POST['return_to'] ?? 'dashboard.php'));

// sanitize return_to: keep relative paths only
$rt_parts = parse_url($return_to_raw);
$return_to = (isset($rt_parts['scheme']) || isset($rt_parts['host'])) ? 'dashboard.php' : ($return_to_raw ?: 'dashboard.php');

if ($order_id <= 0) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'INVALID_ORDER_ID']);
  redirect_with_params($return_to, ['msg' => 'invalid-order']);
}

// Optional user context
$user_id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$user_name = $_SESSION['user_name'] ?? 'System';

/* ----------------------- Fetch order ----------------------- */
$cur_status = null;
$invoice_number = '';

$stmt = $mysqli->prepare("SELECT order_status, COALESCE(invoice_number, '') FROM orders WHERE order_id=?");
if (!$stmt) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'DB_PREP_FAILED']);
  redirect_with_params($return_to, ['msg' => 'update-failed']);
}
$stmt->bind_param("i", $order_id);
$stmt->execute();
$stmt->bind_result($cur_status, $invoice_number);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'ORDER_NOT_FOUND']);
  redirect_with_params($return_to, ['msg' => 'order-not-found']);
}

/* ----------------------- Business rules ----------------------- */
// Block cancelling delivered orders (adjust to your policy)
if (strcasecmp($cur_status, 'delivered') === 0) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'ALREADY_DELIVERED', 'id' => $order_id, 'inv' => $invoice_number]);
  redirect_with_params($return_to, ['msg' => 'already-delivered', 'id' => $order_id, 'inv' => $invoice_number]);
}

// Already cancelled: short-circuit with success-like response
if (strcasecmp($cur_status, 'cancelled') === 0) {
  if (is_ajax()) json_out(['ok' => true, 'message' => 'already-cancelled', 'id' => $order_id, 'inv' => $invoice_number]);
  redirect_with_params($return_to, ['msg' => 'already-cancelled', 'id' => $order_id, 'inv' => $invoice_number]);
}

/* ----------------------- Update order ----------------------- */
$upd = $mysqli->prepare("UPDATE orders SET order_status='cancelled',delivery_status='cancel',distributor_status='cancelled' WHERE order_id=?");
if (!$upd) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'DB_PREP_FAILED']);
  redirect_with_params($return_to, ['msg' => 'update-failed']);
}
$upd->bind_param("i", $order_id);
$ok = $upd->execute();
$upd->close();

if (!$ok) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'DB_UPDATE_FAILED']);
  redirect_with_params($return_to, ['msg' => 'update-failed']);
}

/* ----------------------- Log comment (optional) ----------------------- */
// If you use an order_comments table, record who cancelled + reason.
if ($stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())")) {
  $comment = "Order cancelled by {$user_name}" . ($cancel_reason !== '' ? " — Reason: {$cancel_reason}" : "");
  // Note: "i i s" = int, int, string; if $user_id is null, cast to 0 or change schema to allow NULL
  $uid = $user_id ?? 0; // adjust if your column allows NULL
  $stmt->bind_param("iis", $order_id, $uid, $comment);
  $stmt->execute();
  $stmt->close();
}

/* ----------------------- Done ----------------------- */
if (is_ajax()) {
  json_out([
    'ok' => true,
    'msg' => 'order-cancelled',
    'id'  => $order_id,
    'inv' => $invoice_number
  ]);
}

// Redirect back with toast params (centered toast on destination page)
redirect_with_params($return_to, [
  'msg' => 'order-cancelled',
  'id'  => $order_id,
  'inv' => $invoice_number
]);

?>
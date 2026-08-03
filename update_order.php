<?php
require_once 'include/require_permission.php';
requirePermission('ORDERS', 'edit');
require_once 'include/csrf_helper.php';
require_once 'include/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$order_id = (int)($_POST['order_id'] ?? 0);

if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid request token. Please try again.'];
    header('Location: edit_order.php?id=' . $order_id);
    exit;
}

$customer_id = (int)($_POST['customer_id'] ?? 0);

if ($order_id <= 0 || $customer_id <= 0) {
    exit('Invalid IDs');
}

// -------- Update Customer --------
$full_name     = trim($_POST['full_name'] ?? '');
$mobile_number = trim($_POST['mobile_number'] ?? '');
$email         = trim($_POST['email'] ?? '');
$address       = trim($_POST['address'] ?? '');
$landmark      = trim($_POST['landmark'] ?? '');
$city          = trim($_POST['city'] ?? '');
$state         = trim($_POST['state'] ?? '');
$pincode       = trim($_POST['pincode'] ?? '');

$stmt = $mysqli->prepare("UPDATE customers
  SET full_name=?, mobile_number=?, email=?, address=?, landmark=?, city=?, state=?, pincode=?
  WHERE customer_id=?");
$stmt->bind_param(
  "ssssssssi",
  $full_name, $mobile_number, $email, $address, $landmark, $city, $state, $pincode, $customer_id
);
$stmt->execute();
$stmt->close();

// -------- Validate arrays --------
$product_ids = $_POST['product_ids'] ?? [];
$unit_prices = $_POST['unit_prices'] ?? [];
$quantities  = $_POST['quantities'] ?? [];

if (!is_array($product_ids) || !is_array($unit_prices) || !is_array($quantities)) {
    exit('Invalid item arrays');
}

// Normalize and compute totals
$items = [];
$subtotal = 0.0;

for ($i = 0; $i < count($product_ids); $i++) {
    $pid = (int)($product_ids[$i] ?? 0);
    $price = (float)($unit_prices[$i] ?? 0);
    $qty = (int)($quantities[$i] ?? 0);
    if ($pid > 0 && $qty > 0 && $price >= 0) {
        $line_total = $price * $qty;
        $subtotal += $line_total;
        $items[] = ['product_id' => $pid, 'unit_price' => $price, 'quantity' => $qty];
    }
}

$discount     = (float)($_POST['discount'] ?? 0);
$payment_mode = trim($_POST['payment_mode'] ?? 'Cash');

$grand_total = max($subtotal - $discount, 0);
$tax_included_18 = ($subtotal * 18) / 118;

// -------- Update order header --------
$stmt = $mysqli->prepare("UPDATE orders SET discount=?, payment_mode=?, tax=?, grand_total=? WHERE order_id=?");
$stmt->bind_param("dsddi", $discount, $payment_mode, $tax_included_18, $grand_total, $order_id);
$stmt->execute();
$stmt->close();

// -------- Replace order items (simple strategy) --------
$mysqli->begin_transaction();
try {
    $stmt = $mysqli->prepare("DELETE FROM order_items WHERE order_id=?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $stmt->close();

    if (!empty($items)) {
        $stmt = $mysqli->prepare("INSERT INTO order_items (order_id, product_id, unit_price, quantity) VALUES (?,?,?,?)");
        foreach ($items as $it) {
            $stmt->bind_param("iidi", $order_id, $it['product_id'], $it['unit_price'], $it['quantity']);
            $stmt->execute();
        }
        $stmt->close();
    }

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    error_log("Order update failed: " . $e->getMessage());
    exit('Failed to update order items.');
}

logPermissionAudit('ORDER_UPDATED', 'ORDER', $order_id);

header("Location: edit_order.php?id=" . $order_id . "&updated=1");
exit;

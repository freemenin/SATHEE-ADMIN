<?php
require_once 'include/require_permission.php';
requirePermission('ORDERS', 'add');
require_once 'include/csrf_helper.php';
require_once 'include/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Invalid request token. Please try again.'];
    header('Location: add_order.php');
    exit;
}

$mobile = trim($_POST['mobile_number'] ?? '');
$name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$landmark = trim($_POST['landmark'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
$pincode = trim($_POST['pincode'] ?? '');
$distributor = (int)($_POST['distributor'] ?? 0);
$discount = (float)($_POST['discount'] ?? 0);
$subtotal = (float)($_POST['subtotal'] ?? 0);
$tax = (float)($_POST['tax'] ?? 0);
$grand_total = (float)($_POST['grand_total'] ?? 0);
$order_date = date('Y-m-d');
$user = (int)($_SESSION['user_id'] ?? 0);
$repeat_order = isset($_POST['data']) ? 'Repeat' : 'New';
$order_notes = trim($_POST['order_notes'] ?? '');
$payment_mode = trim($_POST['payment_mode'] ?? 'Cash');

if ($mobile === '' || $name === '' || $distributor <= 0) {
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Mobile number, name and distributor are required.'];
    header('Location: add_order.php');
    exit;
}

// 1. Find or create customer
$customer_id = null;
$stmt = $mysqli->prepare("SELECT customer_id FROM customers WHERE mobile_number = ? LIMIT 1");
$stmt->bind_param("s", $mobile);
$stmt->execute();
$stmt->bind_result($cid);
if ($stmt->fetch()) {
    $customer_id = $cid;
}
$stmt->close();

if ($customer_id === null) {
    $stmt_insert = $mysqli->prepare("INSERT INTO customers (full_name, mobile_number, email, address, landmark, city, state, pincode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_insert->bind_param("ssssssss", $name, $mobile, $email, $address, $landmark, $city, $state, $pincode);
    $stmt_insert->execute();
    $customer_id = $stmt_insert->insert_id;
    $stmt_insert->close();
}

// 2. Generate new invoice number by incrementing last
$new_invoice = 'SH1001';
$last_invoice_result = $mysqli->query("SELECT invoice_number FROM orders ORDER BY order_id DESC LIMIT 1");
$last_invoice = $last_invoice_result ? $last_invoice_result->fetch_assoc() : null;
if ($last_invoice && preg_match('/SH(\d+)/', $last_invoice['invoice_number'], $matches)) {
    $new_invoice = 'SH' . ($matches[1] + 1);
}

// 3. Insert order
$stmt_order = $mysqli->prepare("
    INSERT INTO orders (
        customer_id, distributor_id, order_data, distributor_assigned_at,
        distributor_assigned_by, distributor_status, order_status, invoice_number,
        order_date, order_notes, created_by, payment_mode, subtotal, tax, discount, grand_total
    ) VALUES (
        ?, ?, ?, NOW(), ?, 'pending', 'Assigned', ?, ?, ?, ?, ?, ?, ?, ?, ?
    )
");

if ($stmt_order === false) {
    error_log("save_order.php prepare failed: " . $mysqli->error);
    $_SESSION['toast'] = ['type' => 'danger', 'message' => 'Failed to create order.'];
    header('Location: add_order.php');
    exit;
}

$stmt_order->bind_param(
    "iisisssisdddd",
    $customer_id,
    $distributor,
    $repeat_order,
    $user,
    $new_invoice,
    $order_date,
    $order_notes,
    $user,
    $payment_mode,
    $subtotal,
    $tax,
    $discount,
    $grand_total
);
$stmt_order->execute();
$order_id = $stmt_order->insert_id;
$stmt_order->close();

// 4. Insert order items + inventory consumption log
if (!empty($_POST['product_ids']) && is_array($_POST['product_ids'])) {
    $product_ids = $_POST['product_ids'];
    $quantities = $_POST['quantities'] ?? [];
    $unit_prices = $_POST['unit_prices'] ?? [];

    $stmt_item = $mysqli->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
    $stmt_inventory = $mysqli->prepare("INSERT INTO inventory (item_type, item_id, qty_change, unit, source, action, note, created_by, retail_price, trackid) VALUES ('product', ?, ?, ?, 'Order', 'consume', ?, ?, ?, ?)");
    $stmt_product = $mysqli->prepare("SELECT retail_price, unit FROM products WHERE product_id = ? LIMIT 1");

    for ($i = 0; $i < count($product_ids); $i++) {
        $pid = (int)$product_ids[$i];
        $qty = (int)($quantities[$i] ?? 0);
        $price = (float)($unit_prices[$i] ?? 0);

        if ($pid <= 0 || $qty <= 0) {
            continue;
        }

        $stmt_item->bind_param("iiid", $order_id, $pid, $qty, $price);
        $stmt_item->execute();

        $stmt_product->bind_param("i", $pid);
        $stmt_product->execute();
        $product = $stmt_product->get_result()->fetch_assoc();

        $unit = $product['unit'] ?? '';
        $note = "Order ID: #$new_invoice";
        $created_by = $user;
        $retail_price = $product['retail_price'] ?? 0;
        $qty_negative = -$qty;

        $stmt_inventory->bind_param("iissidi", $pid, $qty_negative, $unit, $note, $created_by, $retail_price, $order_id);
        $stmt_inventory->execute();
    }
    $stmt_item->close();
    $stmt_inventory->close();
    $stmt_product->close();
}

logPermissionAudit('ORDER_CREATED', 'ORDER', $order_id);

$_SESSION['toast'] = ['type' => 'success', 'message' => "Order $new_invoice created successfully."];
header("Location: order_list.php");
exit;

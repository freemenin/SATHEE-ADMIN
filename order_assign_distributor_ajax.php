<?php
// FILE: order_assign_distributor_ajax.php
require_once 'include/require_permission.php';
requirePermissionAjax('ORDERS', 'edit');
include('include/require_login.php');
header('Content-Type: application/json');
if (!isset($mysqli)) { include('include/db.php'); }

// Optional debug: /order_assign_distributor_ajax.php (POST) with ?debug=1 on URL
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($DEBUG) { ini_set('display_errors', 1); error_reporting(E_ALL); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Inputs
    $order_id       = (int)($_POST['order_id'] ?? 0);
    $distributor_in = trim((string)($_POST['distributor_id'] ?? ''));
    // Accept '', '0', 0 as unassign
    $distributor_id = ($distributor_in === '' || $distributor_in === '0') ? 0 : (int)$distributor_in;

    // Your auth system should set this
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $user_name = $_SESSION['user_name'];

    if ($order_id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid order id.']);
        exit;
    }

    // Validate order exists
    $stmt = $mysqli->prepare("SELECT order_id FROM orders WHERE order_id = ? LIMIT 1");
    if (!$stmt) { throw new Exception('Prepare(order check) failed: ' . $mysqli->error); }
    $stmt->bind_param('i', $order_id);
    if (!$stmt->execute()) { throw new Exception('Execute(order check) failed: ' . $stmt->error); }
    $stmt->bind_result($oidExists);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }
    $stmt->close();

    $label = null;

    if ($distributor_id > 0) {
        // Validate distributor exists + get label
        $stmt = $mysqli->prepare("SELECT distributor_code, distributor_name FROM distributors WHERE distributor_id = ? LIMIT 1");
        if (!$stmt) { throw new Exception('Prepare(dist check) failed: ' . $mysqli->error); }
        $stmt->bind_param('i', $distributor_id);
        if (!$stmt->execute()) { throw new Exception('Execute(dist check) failed: ' . $stmt->error); }
        $stmt->bind_result($dcode, $dname);
        if (!$stmt->fetch()) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Distributor not found.']);
            exit;
        }
        $stmt->close();
        $label = trim(($dcode ? $dcode : '') . ($dname ? ' - ' . $dname : ''));
        
        // Assign
        $stmt = $mysqli->prepare(
            "UPDATE orders
             SET distributor_id = ?, distributor_assigned_at = NOW(), order_status = 'Assigned', distributor_status = 'pending', distributor_assigned_by = ?
             WHERE order_id = ? LIMIT 1"
        );
        if (!$stmt) { throw new Exception('Prepare(assign) failed: ' . $mysqli->error); }
        $stmt->bind_param('iii', $distributor_id, $user_id, $order_id);
        if (!$stmt->execute()) { throw new Exception('Execute(assign) failed: ' . $stmt->error); }

        echo json_encode([
            'success' => true,
            'message' => 'Distributor assigned.',
            'order_id' => $order_id,
            'distributor_id' => $distributor_id,
            'distributor_label' => $label
        ]);
                // If you use an order_comments table, record who cancelled + reason.
        $stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
        $comment = "Order assigned {$dname} by {$user_name}";
        // Note: "i i s" = int, int, string; if $user_id is null, cast to 0 or change schema to allow NULL
        $uid = $user_id ?? 0; // adjust if your column allows NULL
        $stmt->bind_param("iis", $order_id, $uid, $comment);
        $stmt->execute();
        $stmt->close();
        exit;

    } else {
        // Unassign
        $stmt = $mysqli->prepare(
            "UPDATE orders
             SET distributor_id = NULL, distributor_assigned_at = NULL, distributor_assigned_by = ?
             WHERE order_id = ? LIMIT 1"
        );
        if (!$stmt) { throw new Exception('Prepare(unassign) failed: ' . $mysqli->error); }
        $stmt->bind_param('ii', $user_id, $order_id);
        if (!$stmt->execute()) { throw new Exception('Execute(unassign) failed: ' . $stmt->error); }

        echo json_encode([
            'success' => true,
            'message' => 'Distributor unassigned.',
            'order_id' => $order_id
        ]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    $msg = 'Server error';
    if ($DEBUG) { $msg .= ': ' . $e->getMessage(); }
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

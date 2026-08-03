<?php
// FILE: distributor_search_ajax.php
require_once 'include/require_permission.php';
requirePermissionAjax('DISTRIBUTORS', 'view');
include('include/require_login.php');
header('Content-Type: application/json');
if (!isset($mysqli)) { include('include/db.php'); }

// Optional debug: /distributor_search_ajax.php?q=abc&debug=1
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($DEBUG) { ini_set('display_errors', 1); error_reporting(E_ALL); }

try {
    // Cap the limit between 1 and 100 (default 50)
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    if ($limit < 1) $limit = 50;
    if ($limit > 100) $limit = 100;
    $limit_i = (int)$limit; // inline for portability

    $q = trim($_GET['q'] ?? '');

    // Ensure table exists (safe-guard; remove if you manage schema separately)
    $mysqli->query("CREATE TABLE IF NOT EXISTS distributors (
        distributor_id INT AUTO_INCREMENT PRIMARY KEY,
        distributor_code VARCHAR(20) UNIQUE,
        distributor_name VARCHAR(255) NOT NULL,
        contact_person VARCHAR(255),
        mobile_number VARCHAR(20),
        email VARCHAR(100),
        address TEXT,
        gstin VARCHAR(30),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($q !== '') {
        // Search by code, name, contact person, or mobile
        $like = '%' . $q . '%';
        $sql = "SELECT distributor_id, distributor_code, distributor_name, mobile_number
                FROM distributors
                WHERE distributor_code LIKE ? OR distributor_name LIKE ? OR contact_person LIKE ? OR mobile_number LIKE ?
                ORDER BY distributor_name ASC
                LIMIT $limit_i";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { throw new Exception('Prepare failed: ' . $mysqli->error); }
        $stmt->bind_param('ssss', $like, $like, $like, $like);
    } else {
        // No query: return top distributors alphabetically
        $sql = "SELECT distributor_id, distributor_code, distributor_name, mobile_number
                FROM distributors
                ORDER BY distributor_name ASC
                LIMIT $limit_i";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { throw new Exception('Prepare failed: ' . $mysqli->error); }
        // no params to bind
    }

    if (!$stmt->execute()) { throw new Exception('Execute failed: ' . $stmt->error); }

    // Bind result columns (avoid get_result() for compatibility)
    $stmt->bind_result($id, $code, $name, $mobile);

    $results = [];
    while ($stmt->fetch()) {
        $parts = [];
        if (!empty($code)) { $parts[] = $code; }
        if (!empty($name)) { $parts[] = $name; }
        $label = implode(' - ', $parts);
        if (!empty($mobile)) { $label .= ' (' . $mobile . ')'; }
        $results[] = ['id' => (int)$id, 'text' => $label];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'results' => $results]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    $msg = 'Server error';
    if ($DEBUG) { $msg .= ': ' . $e->getMessage(); }
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

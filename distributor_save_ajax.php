<?php
// FILE: distributor_save_ajax.php

require_once 'include/require_permission.php';
requirePermissionAjax('DISTRIBUTORS', 'add');
include('include/require_login.php');

header('Content-Type: application/json');

try {
    if (!isset($mysqli)) {
        include('include/db.php');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.'
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Safe schema check
    |--------------------------------------------------------------------------
    */
    function ensure_distributor_column(mysqli $mysqli, string $column, string $alterSql): void
    {
        $column = $mysqli->real_escape_string($column);
        $res = $mysqli->query("SHOW COLUMNS FROM distributors LIKE '{$column}'");

        if (!$res || $res->num_rows === 0) {
            if (!$mysqli->query($alterSql)) {
                throw new Exception("Unable to add column {$column}: " . $mysqli->error);
            }
        }
    }

    ensure_distributor_column(
        $mysqli,
        'distributor_type',
        "ALTER TABLE distributors ADD COLUMN distributor_type ENUM('main','sub') NOT NULL DEFAULT 'main' AFTER distributor_code"
    );

    ensure_distributor_column(
        $mysqli,
        'parent_distributor_id',
        "ALTER TABLE distributors ADD COLUMN parent_distributor_id INT(11) NULL AFTER distributor_type"
    );

    ensure_distributor_column(
        $mysqli,
        'status',
        "ALTER TABLE distributors ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER parent_distributor_id"
    );

    ensure_distributor_column(
        $mysqli,
        'pincode',
        "ALTER TABLE distributors ADD COLUMN pincode VARCHAR(10) NULL AFTER created_at"
    );

    $idx = $mysqli->query("SHOW INDEX FROM distributors WHERE Key_name = 'idx_parent_distributor_id'");
    if (!$idx || $idx->num_rows === 0) {
        @$mysqli->query("ALTER TABLE distributors ADD INDEX idx_parent_distributor_id (parent_distributor_id)");
    }

    /*
    |--------------------------------------------------------------------------
    | Input
    |--------------------------------------------------------------------------
    */
    $distributor_type = trim($_POST['distributor_type'] ?? 'main');
    $parent_id_input  = (int)($_POST['parent_distributor_id'] ?? 0);

    $name    = trim($_POST['distributor_name'] ?? '');
    $person  = trim($_POST['contact_person'] ?? '');
    $mobile  = preg_replace('/\D+/', '', $_POST['mobile_number'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $gstin   = strtoupper(trim($_POST['gstin'] ?? ''));
    $pincode = preg_replace('/\D+/', '', $_POST['pincode'] ?? '');
    $addr    = trim($_POST['address'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');
    $pin     = trim($_POST['pin'] ?? '');
    $pin2    = trim($_POST['pin_confirm'] ?? '');
    $status  = trim($_POST['status'] ?? 'active');

    /*
    |--------------------------------------------------------------------------
    | Mobile normalize
    |--------------------------------------------------------------------------
    */
    if (strlen($mobile) > 10) {
        $mobile = substr($mobile, -10);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
    if (!in_array($distributor_type, ['main', 'sub'], true)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid distributor type.'
        ]);
        exit;
    }

    if ($name === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Distributor name is required.'
        ]);
        exit;
    }

    if ($mobile === '' || !preg_match('/^[0-9]{10}$/', $mobile)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Enter a valid 10-digit mobile number.'
        ]);
        exit;
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Enter a valid email address.'
        ]);
        exit;
    }

    if ($gstin !== '' && strlen($gstin) > 15) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'GSTIN cannot be more than 15 characters.'
        ]);
        exit;
    }

    if ($pincode !== '' && !preg_match('/^[0-9]{5,10}$/', $pincode)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Enter a valid pincode.'
        ]);
        exit;
    }

    if ($pin === '' || !preg_match('/^[0-9]{4}$/', $pin)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'PIN must be exactly 4 digits.'
        ]);
        exit;
    }

    if ($pin !== $pin2) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'PIN and Confirm PIN do not match.'
        ]);
        exit;
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    /*
    |--------------------------------------------------------------------------
    | Parent distributor validation
    |--------------------------------------------------------------------------
    */
    $parent_id = null;

    if ($distributor_type === 'sub') {
        if ($parent_id_input <= 0) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Please select parent main distributor.'
            ]);
            exit;
        }

        $checkParent = $mysqli->prepare("
            SELECT distributor_id, distributor_name
            FROM distributors
            WHERE distributor_id = ?
              AND distributor_type = 'main'
            LIMIT 1
        ");

        if (!$checkParent) {
            throw new Exception('Parent check prepare failed: ' . $mysqli->error);
        }

        $checkParent->bind_param('i', $parent_id_input);
        $checkParent->execute();

        $parentResult = $checkParent->get_result();
        $parentRow = $parentResult->fetch_assoc();

        $checkParent->close();

        if (!$parentRow) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Selected parent distributor is invalid.'
            ]);
            exit;
        }

        $parent_id = $parent_id_input;
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate mobile check
    |--------------------------------------------------------------------------
    */
    $dup = $mysqli->prepare("
        SELECT distributor_id, distributor_name
        FROM distributors
        WHERE mobile_number = ?
        LIMIT 1
    ");

    if (!$dup) {
        throw new Exception('Duplicate mobile check prepare failed: ' . $mysqli->error);
    }

    $dup->bind_param('s', $mobile);
    $dup->execute();

    $dupRes = $dup->get_result();
    $dupRow = $dupRes->fetch_assoc();

    $dup->close();

    if ($dupRow) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'This mobile number is already used by: ' . $dupRow['distributor_name']
        ]);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Code generation
    |--------------------------------------------------------------------------
    | Main Distributor: D1000, D1001...
    | Sub Distributor:  SDB100, SDB101...
    |--------------------------------------------------------------------------
    */
    function get_code_config(string $type): array
    {
        if ($type === 'sub') {
            return [
                'prefix' => 'SD',
                'base' => 100,
                'regex' => '^SD[0-9]+$',
                'substring_start' => 4,
                'php_regex' => '/^SD(\d+)$/'
            ];
        }

        return [
            'prefix' => 'D',
            'base' => 1000,
            'regex' => '^D[0-9]+$',
            'substring_start' => 2,
            'php_regex' => '/^D(\d+)$/'
        ];
    }

    function get_next_distributor_code_locked(mysqli $mysqli, string $type): string
    {
        $config = get_code_config($type);

        $regex = $config['regex'];
        $substringStart = (int)$config['substring_start'];

        $stmt = $mysqli->prepare("
            SELECT distributor_code
            FROM distributors
            WHERE distributor_code REGEXP ?
            ORDER BY CAST(SUBSTRING(distributor_code, ?) AS UNSIGNED) DESC
            LIMIT 1
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception('Code generation prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('si', $regex, $substringStart);
        $stmt->execute();

        $res = $stmt->get_result();

        $next = (int)$config['base'];

        if ($res && $row = $res->fetch_assoc()) {
            $lastCode = (string)$row['distributor_code'];

            if (preg_match($config['php_regex'], $lastCode, $m)) {
                $next = max((int)$config['base'], (int)$m[1] + 1);
            }
        }

        $stmt->close();

        return $config['prefix'] . $next;
    }

    function get_next_distributor_code_preview(mysqli $mysqli, string $type): string
    {
        $config = get_code_config($type);

        $regex = $config['regex'];
        $substringStart = (int)$config['substring_start'];

        $stmt = $mysqli->prepare("
            SELECT distributor_code
            FROM distributors
            WHERE distributor_code REGEXP ?
            ORDER BY CAST(SUBSTRING(distributor_code, ?) AS UNSIGNED) DESC
            LIMIT 1
        ");

        if (!$stmt) {
            return $config['prefix'] . $config['base'];
        }

        $stmt->bind_param('si', $regex, $substringStart);
        $stmt->execute();

        $res = $stmt->get_result();

        $next = (int)$config['base'];

        if ($res && $row = $res->fetch_assoc()) {
            $lastCode = (string)$row['distributor_code'];

            if (preg_match($config['php_regex'], $lastCode, $m)) {
                $next = max((int)$config['base'], (int)$m[1] + 1);
            }
        }

        $stmt->close();

        return $config['prefix'] . $next;
    }

    /*
    |--------------------------------------------------------------------------
    | Insert
    |--------------------------------------------------------------------------
    */
    $pin_hash = password_hash($pin, PASSWORD_DEFAULT);

    $mysqli->begin_transaction();

    $code = get_next_distributor_code_locked($mysqli, $distributor_type);

    $stmt = $mysqli->prepare("
        INSERT INTO distributors
        (
            distributor_code,
            distributor_type,
            parent_distributor_id,
            status,
            distributor_name,
            contact_person,
            mobile_number,
            login_pin_hash,
            email,
            address,
            gstin,
            notes,
            pincode
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception('Insert prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param(
        'ssissssssssss',
        $code,
        $distributor_type,
        $parent_id,
        $status,
        $name,
        $person,
        $mobile,
        $pin_hash,
        $email,
        $addr,
        $gstin,
        $notes,
        $pincode
    );

    $attempts = 0;
    $maxAttempts = 5;

    while (true) {
        if ($stmt->execute()) {
            break;
        }

        if ($mysqli->errno == 1062 && $attempts < $maxAttempts) {
            $attempts++;

            $code = get_next_distributor_code_locked($mysqli, $distributor_type);

            $stmt->bind_param(
                'ssissssssssss',
                $code,
                $distributor_type,
                $parent_id,
                $status,
                $name,
                $person,
                $mobile,
                $pin_hash,
                $email,
                $addr,
                $gstin,
                $notes,
                $pincode
            );

            continue;
        }

        throw new Exception('Insert failed: ' . $stmt->error);
    }

    $newId = $stmt->insert_id;
    $stmt->close();

    $mysqli->commit();

    $nextMainCode = get_next_distributor_code_preview($mysqli, 'main');
    $nextSubCode  = get_next_distributor_code_preview($mysqli, 'sub');

    $typeLabel = ($distributor_type === 'sub') ? 'Sub distributor' : 'Distributor';

    echo json_encode([
        'success' => true,
        'message' => $typeLabel . ' added successfully. Code: ' . $code,
        'distributor_id' => $newId,
        'distributor_code' => $code,
        'distributor_type' => $distributor_type,
        'parent_distributor_id' => $parent_id,
        'next_main_code' => $nextMainCode,
        'next_sub_code' => $nextSubCode,
        'next_code' => ($distributor_type === 'sub') ? $nextSubCode : $nextMainCode
    ]);
    exit;

} catch (Throwable $e) {
    if (isset($mysqli)) {
        @$mysqli->rollback();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
    exit;
}
?>
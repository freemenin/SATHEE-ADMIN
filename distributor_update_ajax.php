<?php
// distributor_update_ajax.php
// Keep this file silent: no BOM, no stray spaces before <?php

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/php-errors.log');

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/include/require_login.php';
require_once __DIR__ . '/include/require_permission.php';
requirePermissionAjax('DISTRIBUTORS', 'edit');
require_once __DIR__ . '/include/db.php';

$DEBUG = isset($_POST['debug']) && $_POST['debug'] == '1';

function jout(bool $ok, string $msg, array $extra = []): never
{
    echo json_encode(array_merge([
        'success' => $ok,
        'message' => $msg
    ], $extra));
    exit;
}

/*
|--------------------------------------------------------------------------
| Safe schema helpers
|--------------------------------------------------------------------------
*/
function ensure_distributor_column(mysqli $mysqli, string $column, string $alterSql): void
{
    $columnEsc = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM distributors LIKE '{$columnEsc}'");

    if (!$res || $res->num_rows === 0) {
        if (!$mysqli->query($alterSql)) {
            throw new Exception("Unable to add column {$column}: " . $mysqli->error);
        }
    }
}

function ensure_distributor_schema(mysqli $mysqli): void
{
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
            'prefix' => 'SDB',
            'base' => 100,
            'regex' => '^SDB[0-9]+$',
            'substring_start' => 4,
            'php_regex' => '/^SDB(\d+)$/'
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

function code_matches_type(string $code, string $type): bool
{
    $config = get_code_config($type);
    return (bool)preg_match($config['php_regex'], $code);
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jout(false, 'Method not allowed.');
    }

    ensure_distributor_schema($mysqli);

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        jout(false, 'Security check failed.');
    }

    /*
    |--------------------------------------------------------------------------
    | Inputs
    |--------------------------------------------------------------------------
    */
    $distributor_id = isset($_POST['distributor_id']) ? (int)$_POST['distributor_id'] : 0;

    if ($distributor_id <= 0) {
        jout(false, 'Invalid distributor ID.');
    }

    $distributor_type = trim($_POST['distributor_type'] ?? 'main');
    $parent_id_input = isset($_POST['parent_distributor_id']) ? (int)$_POST['parent_distributor_id'] : 0;
    $status = trim($_POST['status'] ?? 'active');

    $distributor_name = trim($_POST['distributor_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $mobile_number = preg_replace('/\D+/', '', $_POST['mobile_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gstin = strtoupper(trim($_POST['gstin'] ?? ''));
    $notes = trim($_POST['notes'] ?? '');
    $pincode = preg_replace('/\D+/', '', $_POST['pincode'] ?? '');

    $new_pin = trim($_POST['new_pin'] ?? '');
    $confirm_pin = trim($_POST['confirm_pin'] ?? '');

    if (strlen($mobile_number) > 10) {
        $mobile_number = substr($mobile_number, -10);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
    if (!in_array($distributor_type, ['main', 'sub'], true)) {
        jout(false, 'Invalid distributor type.');
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    if ($distributor_name === '') {
        jout(false, 'Distributor name is required.');
    }

    if ($mobile_number === '' || !preg_match('/^[0-9]{10}$/', $mobile_number)) {
        jout(false, 'Mobile number must be exactly 10 digits.');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jout(false, 'Enter a valid email address.');
    }

    if ($gstin !== '' && strlen($gstin) > 15) {
        jout(false, 'GSTIN cannot be more than 15 characters.');
    }

    if ($pincode !== '' && !preg_match('/^[0-9]{5,10}$/', $pincode)) {
        jout(false, 'Enter a valid pincode.');
    }

    /*
    |--------------------------------------------------------------------------
    | Fetch existing distributor
    |--------------------------------------------------------------------------
    */
    $stmt = $mysqli->prepare("
        SELECT 
            distributor_id,
            distributor_code,
            distributor_type,
            parent_distributor_id
        FROM distributors
        WHERE distributor_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Existing distributor prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('i', $distributor_id);
    $stmt->execute();

    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        jout(false, 'Distributor not found.');
    }

    $oldType = $existing['distributor_type'] ?: 'main';
    $oldCode = (string)($existing['distributor_code'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Parent distributor validation
    |--------------------------------------------------------------------------
    */
    $parent_id = null;

    if ($distributor_type === 'sub') {
        if ($parent_id_input <= 0) {
            jout(false, 'Please select parent main distributor.');
        }

        if ($parent_id_input === $distributor_id) {
            jout(false, 'Distributor cannot be parent of itself.');
        }

        $checkParent = $mysqli->prepare("
            SELECT distributor_id
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

        $parentRow = $checkParent->get_result()->fetch_assoc();
        $checkParent->close();

        if (!$parentRow) {
            jout(false, 'Selected parent main distributor is invalid.');
        }

        $parent_id = $parent_id_input;
    }

    /*
    |--------------------------------------------------------------------------
    | Prevent risky conversion
    |--------------------------------------------------------------------------
    | If this distributor has sub-distributors under it, it should not become sub.
    |--------------------------------------------------------------------------
    */
    if ($distributor_type === 'sub') {
        $childCheck = $mysqli->prepare("
            SELECT distributor_id
            FROM distributors
            WHERE parent_distributor_id = ?
            LIMIT 1
        ");

        if ($childCheck) {
            $childCheck->bind_param('i', $distributor_id);
            $childCheck->execute();

            $hasChild = $childCheck->get_result()->fetch_assoc();
            $childCheck->close();

            if ($hasChild) {
                jout(false, 'This distributor has sub-distributors under it. Remove or move them before changing it to sub-distributor.');
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate mobile check
    |--------------------------------------------------------------------------
    */
    $stmt = $mysqli->prepare("
        SELECT distributor_id, distributor_name
        FROM distributors
        WHERE mobile_number = ?
          AND distributor_id <> ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Duplicate mobile prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('si', $mobile_number, $distributor_id);
    $stmt->execute();

    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dup) {
        jout(false, 'Mobile number already used by another distributor.');
    }

    /*
    |--------------------------------------------------------------------------
    | Optional PIN update
    |--------------------------------------------------------------------------
    */
    $updatePin = false;
    $pin_hash = null;

    if ($new_pin !== '' || $confirm_pin !== '') {
        if (!preg_match('/^\d{4}$/', $new_pin)) {
            jout(false, 'PIN must be exactly 4 digits.');
        }

        if ($new_pin !== $confirm_pin) {
            jout(false, 'PIN and Confirm PIN do not match.');
        }

        $pin_hash = password_hash($new_pin, PASSWORD_DEFAULT);
        $updatePin = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Distributor code handling
    |--------------------------------------------------------------------------
    | If type is same and old code matches type, keep old code.
    | If type changed or wrong code exists, generate correct new code.
    |--------------------------------------------------------------------------
    */
    $mysqli->begin_transaction();

    $finalCode = $oldCode;

    if ($finalCode === '' || $oldType !== $distributor_type || !code_matches_type($finalCode, $distributor_type)) {
        $finalCode = get_next_distributor_code_locked($mysqli, $distributor_type);
    }

    /*
    |--------------------------------------------------------------------------
    | Code duplicate check
    |--------------------------------------------------------------------------
    */
    $stmt = $mysqli->prepare("
        SELECT distributor_id
        FROM distributors
        WHERE distributor_code = ?
          AND distributor_id <> ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Duplicate code prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('si', $finalCode, $distributor_id);
    $stmt->execute();

    $dupCode = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dupCode) {
        $finalCode = get_next_distributor_code_locked($mysqli, $distributor_type);
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */
    if ($updatePin) {
        $sql = "
            UPDATE distributors
            SET 
                distributor_code = ?,
                distributor_type = ?,
                parent_distributor_id = ?,
                status = ?,
                distributor_name = ?,
                contact_person = ?,
                mobile_number = ?,
                email = ?,
                address = ?,
                gstin = ?,
                notes = ?,
                pincode = ?,
                login_pin_hash = ?
            WHERE distributor_id = ?
            LIMIT 1
        ";

        $stmt = $mysqli->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare failed update with PIN: ' . $mysqli->error);
        }

        $stmt->bind_param(
            "ssissssssssssi",
            $finalCode,
            $distributor_type,
            $parent_id,
            $status,
            $distributor_name,
            $contact_person,
            $mobile_number,
            $email,
            $address,
            $gstin,
            $notes,
            $pincode,
            $pin_hash,
            $distributor_id
        );
    } else {
        $sql = "
            UPDATE distributors
            SET 
                distributor_code = ?,
                distributor_type = ?,
                parent_distributor_id = ?,
                status = ?,
                distributor_name = ?,
                contact_person = ?,
                mobile_number = ?,
                email = ?,
                address = ?,
                gstin = ?,
                notes = ?,
                pincode = ?
            WHERE distributor_id = ?
            LIMIT 1
        ";

        $stmt = $mysqli->prepare($sql);

        if (!$stmt) {
            throw new Exception('Prepare failed update without PIN: ' . $mysqli->error);
        }

        $stmt->bind_param(
            "ssisssssssssi",
            $finalCode,
            $distributor_type,
            $parent_id,
            $status,
            $distributor_name,
            $contact_person,
            $mobile_number,
            $email,
            $address,
            $gstin,
            $notes,
            $pincode,
            $distributor_id
        );
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('Execute failed: ' . $err);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    $mysqli->commit();

    $typeLabel = ($distributor_type === 'sub') ? 'Sub distributor' : 'Distributor';

    jout(true, $updatePin ? "{$typeLabel} updated and PIN changed." : "{$typeLabel} updated successfully.", [
        'rows_affected' => $affected,
        'distributor_id' => $distributor_id,
        'distributor_code' => $finalCode,
        'distributor_type' => $distributor_type,
        'parent_distributor_id' => $parent_id,
        'status' => $status
    ]);

} catch (Throwable $e) {
    if (isset($mysqli)) {
        @$mysqli->rollback();
    }

    error_log('Throwable in distributor_update_ajax: ' . $e->getMessage());

    jout(false, $DEBUG ? ('Unexpected: ' . $e->getMessage()) : 'Unexpected server error.');
}
?>
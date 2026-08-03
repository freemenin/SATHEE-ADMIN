<?php
include('include/db.php');

date_default_timezone_set('Asia/Kolkata');

/* =========================
   JSON RESPONSE HELPER
========================= */
function json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* =========================
   SAFE STRING
========================= */
function safe_str($value) {
    return trim((string)$value);
}

/* =========================
   NEXT JOB
========================= */
if (isset($_GET['action']) && $_GET['action'] === 'next_job') {
    $mysqli->begin_transaction();

    try {
        $jobQuery = $mysqli->query("
            SELECT job_id, purchase_id
            FROM thermal_print_jobs
            WHERE status = 'pending'
            ORDER BY job_id ASC
            LIMIT 1
            FOR UPDATE
        ");

        $job = $jobQuery ? $jobQuery->fetch_assoc() : null;

        if (!$job) {
            $mysqli->commit();
            json_response([
                'success' => true,
                'found'   => false
            ]);
        }

        $update = $mysqli->prepare("
            UPDATE thermal_print_jobs
            SET status = 'printing'
            WHERE job_id = ? AND status = 'pending'
            LIMIT 1
        ");
        $update->bind_param("i", $job['job_id']);
        $update->execute();
        $affected = $update->affected_rows;
        $update->close();

        if ($affected <= 0) {
            $mysqli->rollback();
            json_response([
                'success' => false,
                'message' => 'Job lock failed'
            ]);
        }

        $mysqli->commit();

        json_response([
            'success'     => true,
            'found'       => true,
            'job_id'      => (int)$job['job_id'],
            'purchase_id' => (int)$job['purchase_id']
        ]);

    } catch (Throwable $e) {
        $mysqli->rollback();
        json_response([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/* =========================
   JOB DATA
========================= */
if (isset($_GET['action']) && $_GET['action'] === 'job_data') {
    $job_id = (int)($_GET['job_id'] ?? 0);

    if ($job_id <= 0) {
        json_response([
            'success' => false,
            'message' => 'Invalid job ID'
        ]);
    }

    $stmt = $mysqli->prepare("
        SELECT 
            j.job_id,
            j.purchase_id,
            j.status,
            pm.purchase_no,
            pm.purchase_date,
            pm.total_qty,
            pm.total_amount,
            pm.remarks,
            d.distributor_name,
            d.mobile_number,
            d.address
        FROM thermal_print_jobs j
        INNER JOIN distributor_purchase_master pm 
            ON pm.purchase_id = j.purchase_id
        LEFT JOIN distributors d 
            ON d.distributor_id = pm.distributor_id
        WHERE j.job_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$purchase) {
        json_response([
            'success' => false,
            'message' => 'Print job not found'
        ]);
    }

    $stmt = $mysqli->prepare("
        SELECT product_title, unit, rate, qty, amount
        FROM distributor_purchase_items
        WHERE purchase_id = ?
        ORDER BY item_id ASC
    ");
    $stmt->bind_param("i", $purchase['purchase_id']);
    $stmt->execute();
    $itemsResult = $stmt->get_result();

    $items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = [
            'title'  => safe_str($row['product_title']),
            'unit'   => safe_str($row['unit']),
            'qty'    => (int)$row['qty'],
            'rate'   => number_format((float)$row['rate'], 2, '.', ''),
            'amount' => number_format((float)$row['amount'], 2, '.', '')
        ];
    }
    $stmt->close();

    json_response([
        'success'          => true,
        'job_id'           => (int)$purchase['job_id'],
        'purchase_id'      => (int)$purchase['purchase_id'],
        'purchase_no'      => safe_str($purchase['purchase_no']),
        'purchase_date'    => date('d-m-Y', strtotime($purchase['purchase_date'])),
        'print_time'       => date('h:i A'),
        'distributor_name' => safe_str($purchase['distributor_name']),
        'mobile_number'    => safe_str($purchase['mobile_number']),
        'address'          => preg_replace("/[\r\n]+/", ", ", safe_str($purchase['address'])),
        'remarks'          => preg_replace("/[\r\n]+/", ", ", safe_str($purchase['remarks'])),
        'total_qty'        => (int)$purchase['total_qty'],
        'total_amount'     => number_format((float)$purchase['total_amount'], 2, '.', ''),
        'items'            => $items
    ]);
}

/* =========================
   MARK DONE
========================= */
if (isset($_GET['action']) && $_GET['action'] === 'mark_done') {
    $job_id = (int)($_POST['job_id'] ?? 0);

    if ($job_id <= 0) {
        json_response([
            'success' => false,
            'message' => 'Invalid job ID'
        ]);
    }

    $stmt = $mysqli->prepare("
        UPDATE thermal_print_jobs
        SET status = 'printed', printed_at = NOW()
        WHERE job_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $job_id);
    $ok = $stmt->execute();
    $stmt->close();

    json_response([
        'success' => $ok ? true : false
    ]);
}

/* =========================
   MARK FAILED
========================= */
if (isset($_GET['action']) && $_GET['action'] === 'mark_failed') {
    $job_id = (int)($_POST['job_id'] ?? 0);
    $msg    = trim($_POST['message'] ?? 'Print failed');

    if ($job_id <= 0) {
        json_response([
            'success' => false,
            'message' => 'Invalid job ID'
        ]);
    }

    $stmt = $mysqli->prepare("
        UPDATE thermal_print_jobs
        SET status = 'failed', error_message = ?
        WHERE job_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $msg, $job_id);
    $ok = $stmt->execute();
    $stmt->close();

    json_response([
        'success' => $ok ? true : false
    ]);
}

/* =========================
   INVALID ACTION
========================= */
json_response([
    'success' => false,
    'message' => 'Invalid action'
]);
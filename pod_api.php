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
   PURCHASE TYPE BY PURCHASE NO
   DPR  = Distributor = wholesale price
   SDPR = Sub distributor = retail price
========================= */
function get_print_type_by_purchase_no($purchase_no) {
    $purchase_no = strtoupper(trim((string)$purchase_no));

    /*
       Check SDPR first because SDPR also contains DPR text.
    */
    if (strpos($purchase_no, 'SDPR') === 0) {
        return [
            'party_type' => 'sub_distributor',
            'rate_type'  => 'retail'
        ];
    }

    if (strpos($purchase_no, 'DPR') === 0) {
        return [
            'party_type' => 'distributor',
            'rate_type'  => 'wholesale'
        ];
    }

    /*
       Safe fallback
    */
    return [
        'party_type' => 'distributor',
        'rate_type'  => 'wholesale'
    ];
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
                'found'   => false,
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
            'found'   => false,
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

    /* =========================
       FETCH PURCHASE MASTER
    ========================= */
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
            pm.distributor_id,

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

    $purchase_no = safe_str($purchase['purchase_no']);
    $typeInfo    = get_print_type_by_purchase_no($purchase_no);

    $partyType = $typeInfo['party_type'];
    $rateType  = $typeInfo['rate_type'];

    /*
       FINAL RATE LOGIC:

       DPR  = distributor      = products.wholesale_price
       SDPR = sub_distributor  = products.retail_price

       Fallback:
       distributor_purchase_items.rate
    */
    if ($rateType === 'retail') {
        $rateSelect = "COALESCE(NULLIF(p.retail_price, 0), NULLIF(i.rate, 0), 0)";
    } else {
        $rateSelect = "COALESCE(NULLIF(p.wholesale_price, 0), NULLIF(i.rate, 0), 0)";
    }

    /* =========================
       FETCH ITEMS
       distributor_purchase_items.product_id = products.product_id
    ========================= */
    $itemsSql = "
        SELECT 
            i.item_id,
            i.purchase_id,
            i.product_id,
            i.product_title,
            i.unit,
            i.rate AS stored_rate,
            i.qty,
            i.amount AS stored_amount,

            p.title AS product_master_title,
            p.wholesale_price,
            p.retail_price,

            $rateSelect AS print_rate

        FROM distributor_purchase_items i

        LEFT JOIN products p
            ON p.product_id = i.product_id

        WHERE i.purchase_id = ?
        ORDER BY i.item_id ASC
    ";

    $stmt = $mysqli->prepare($itemsSql);
    $stmt->bind_param("i", $purchase['purchase_id']);
    $stmt->execute();
    $itemsResult = $stmt->get_result();

    $items = [];
    $totalQty = 0;
    $totalAmount = 0;

    while ($row = $itemsResult->fetch_assoc()) {
        $qty = (int)$row['qty'];

        if ($qty <= 0) {
            $qty = 1;
        }

        $rate = (float)$row['print_rate'];

        /*
           Final fallback:
           If product master price is missing, use stored item rate.
           This prevents "No print data received" type issue due to blank product rate.
        */
        if ($rate <= 0) {
            $rate = (float)$row['stored_rate'];
        }

        $amount = $rate * $qty;

        $totalQty += $qty;
        $totalAmount += $amount;

        $title = safe_str($row['product_title']);
        if ($title === '') {
            $title = safe_str($row['product_master_title']);
        }

        $items[] = [
            'title'  => $title,
            'unit'   => safe_str($row['unit']),
            'qty'    => $qty,
            'rate'   => number_format($rate, 2, '.', ''),
            'amount' => number_format($amount, 2, '.', ''),

            /*
               Debug data.
               ESP/printer can ignore these fields.
            */
            'product_id'       => (int)$row['product_id'],
            'wholesale_price'  => number_format((float)$row['wholesale_price'], 2, '.', ''),
            'retail_price'     => number_format((float)$row['retail_price'], 2, '.', ''),
            'stored_rate'      => number_format((float)$row['stored_rate'], 2, '.', '')
        ];
    }

    $stmt->close();

    if (count($items) <= 0) {
        json_response([
            'success'     => false,
            'message'     => 'No items found in distributor_purchase_items for this purchase_id',
            'job_id'      => (int)$purchase['job_id'],
            'purchase_id' => (int)$purchase['purchase_id'],
            'purchase_no' => $purchase_no
        ]);
    }

    json_response([
        'success'          => true,
        'job_id'           => (int)$purchase['job_id'],
        'purchase_id'      => (int)$purchase['purchase_id'],
        'purchase_no'      => $purchase_no,
        'purchase_date'    => date('d-m-Y', strtotime($purchase['purchase_date'])),
        'print_time'       => date('h:i A'),

        /*
           Final decision from purchase_no:
           DPR  = distributor / wholesale
           SDPR = sub_distributor / retail
        */
        'party_type'       => $partyType,
        'print_party_type' => $partyType,
        'rate_type'        => $rateType,

        'distributor_name' => safe_str($purchase['distributor_name']),
        'mobile_number'    => safe_str($purchase['mobile_number']),
        'address'          => preg_replace("/[\r\n]+/", ", ", safe_str($purchase['address'])),
        'remarks'          => preg_replace("/[\r\n]+/", ", ", safe_str($purchase['remarks'])),

        'total_qty'        => $totalQty,
        'total_amount'     => number_format($totalAmount, 2, '.', ''),
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
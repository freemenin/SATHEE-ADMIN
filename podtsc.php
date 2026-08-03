<?php
include('include/db.php');

date_default_timezone_set('Asia/Kolkata');

/* =========================
   AJAX: GET NEXT JOB
========================= */
if (isset($_GET['action']) && $_GET['action'] === 'next_job') {
    header('Content-Type: application/json');

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
            echo json_encode([
                'success' => true,
                'found'   => false
            ]);
            exit;
        }

        $update = $mysqli->prepare("
            UPDATE thermal_print_jobs
            SET status = 'printing'
            WHERE job_id = ? AND status = 'pending'
            LIMIT 1
        ");
        $update->bind_param("i", $job['job_id']);
        $update->execute();
        $updatedRows = $update->affected_rows;
        $update->close();

        if ($updatedRows <= 0) {
            $mysqli->rollback();
            echo json_encode([
                'success' => false,
                'message' => 'Job lock failed.'
            ]);
            exit;
        }

        $mysqli->commit();

        echo json_encode([
            'success'     => true,
            'found'       => true,
            'job_id'      => (int)$job['job_id'],
            'purchase_id' => (int)$job['purchase_id']
        ]);
        exit;

    } catch (Throwable $e) {
        $mysqli->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

/* =========================
   AJAX: MARK PRINTED
========================= */
if (isset($_GET['action']) && $_GET['action'] === 'mark_done') {
    header('Content-Type: application/json');

    $job_id = (int)($_POST['job_id'] ?? 0);

    if ($job_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid job ID']);
        exit;
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

    echo json_encode(['success' => $ok]);
    exit;
}

/* =========================
   AJAX: MARK FAILED
========================= */
if (isset($_GET['action']) && $_GET['action'] === 'mark_failed') {
    header('Content-Type: application/json');

    $job_id = (int)($_POST['job_id'] ?? 0);
    $msg    = trim($_POST['message'] ?? 'Print failed');

    if ($job_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid job ID']);
        exit;
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

    echo json_encode(['success' => $ok]);
    exit;
}

/* =========================
   PRINT VIEW
========================= */
if (isset($_GET['print_job'])) {
    $job_id = (int)$_GET['print_job'];

    if ($job_id <= 0) {
        die("Invalid print job.");
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
        INNER JOIN distributor_purchase_master pm ON pm.purchase_id = j.purchase_id
        LEFT JOIN distributors d ON d.distributor_id = pm.distributor_id
        WHERE j.job_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$purchase) {
        die("Print job not found.");
    }

    $stmt = $mysqli->prepare("
        SELECT product_title, unit, rate, qty, amount
        FROM distributor_purchase_items
        WHERE purchase_id = ?
        ORDER BY item_id ASC
    ");
    $stmt->bind_param("i", $purchase['purchase_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    $item_count = count($items);

    /*
     * 4 inch paper height formula
     * More width means better readability,
     * still keeping enough height buffer to avoid split.
     */
    $base_height_mm      = 88;
    $per_item_height_mm  = 9;
    $remarks_height_mm   = !empty($purchase['remarks']) ? 15 : 0;
    $address_height_mm   = !empty($purchase['address']) ? 14 : 0;
    $mobile_height_mm    = !empty($purchase['mobile_number']) ? 4 : 0;
    $footer_buffer_mm    = 14;

    $page_height_mm = $base_height_mm
                    + ($item_count * $per_item_height_mm)
                    + $remarks_height_mm
                    + $address_height_mm
                    + $mobile_height_mm
                    + $footer_buffer_mm;

    if ($page_height_mm < 130) $page_height_mm = 130;
    if ($page_height_mm > 500) $page_height_mm = 500;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Thermal Print</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            html, body{
                margin:0;
                padding:0;
                width:101.6mm;
                background:#ffffff;
                color:#000000;
                overflow:hidden;
                font-family:Arial, Helvetica, sans-serif;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            *{
                box-sizing:border-box;
            }

            body, table, th, td, div, span{
                color:#000 !important;
            }

            .receipt{
                width:98mm;
                margin:0 auto;
                padding:2mm 1mm 2mm 1mm;
                page-break-inside:avoid !important;
                break-inside:avoid !important;
                font-size:16px;
                line-height:1.32;
                font-weight:600;
            }

            .center{ text-align:center; }
            .right{ text-align:right; }
            .bold{ font-weight:700; }

            .shop-name{
                font-size:28px;
                font-weight:700;
                line-height:1.08;
                margin-bottom:2px;
                letter-spacing:0.4px;
            }

            .slip-title{
                font-size:18px;
                font-weight:700;
                margin-bottom:6px;
                text-transform:uppercase;
            }

            .meta,
            .customer-block,
            .remarks-block,
            .footer-note{
                font-size:16px;
                font-weight:600;
                word-break:break-word;
            }

            .meta div,
            .customer-block div,
            .remarks-block div,
            .footer-note div{
                margin-bottom:4px;
            }

            .divider{
                border-top:1px dashed #000;
                margin:6px 0;
            }

            .items-table,
            .totals-table{
                width:100%;
                border-collapse:collapse;
                table-layout:fixed;
                page-break-inside:avoid !important;
                break-inside:avoid !important;
            }

            .items-table thead th{
                font-size:15px;
                font-weight:700;
                border-bottom:1px solid #000;
                padding:4px 0 5px 0;
                text-transform:uppercase;
            }

            .items-table th,
            .items-table td,
            .totals-table td{
                vertical-align:top;
                padding:4px 0;
                word-break:break-word;
                color:#000 !important;
                font-weight:600;
            }

            .items-table tr,
            .items-table td,
            .items-table th,
            .totals-table tr,
            .totals-table td{
                page-break-inside:avoid !important;
                break-inside:avoid !important;
            }

            .col-item{ width:48%; text-align:left; }
            .col-qty{ width:10%; text-align:right; }
            .col-rate{ width:18%; text-align:right; }
            .col-amt{ width:24%; text-align:right; }

            .item-name{
                display:block;
                font-size:15px;
                font-weight:700;
                line-height:1.18;
                margin-bottom:1px;
                text-transform:uppercase;
            }

            .item-unit{
                display:block;
                font-size:12px;
                font-weight:600;
                line-height:1.08;
                text-transform:uppercase;
            }

            .qty-val,
            .rate-val,
            .amt-val{
                white-space:nowrap;
                display:inline-block;
                width:100%;
                text-align:right;
            }

            .totals-table td{
                font-size:18px;
                font-weight:700;
                padding:5px 0;
                text-transform:uppercase;
            }

            .grand-total td{
                font-size:22px;
                font-weight:700;
                border-top:1px solid #000;
                padding-top:6px;
            }

            .footer-note{
                text-align:center;
                margin-top:5px;
                font-size:15px;
                font-weight:700;
                text-transform:uppercase;
            }

            @page{
                size:101.6mm <?php echo $page_height_mm; ?>mm;
                margin:0;
            }

            @media print{
                html, body{
                    width:101.6mm !important;
                    margin:0 !important;
                    padding:0 !important;
                    overflow:hidden !important;
                    background:#fff !important;
                }

                .receipt{
                    width:98mm !important;
                    margin:0 auto !important;
                    padding:2mm 1mm 2mm 1mm !important;
                }
            }
        </style>
    </head>
    <body>
        <div class="receipt">
            <div class="center shop-name">SATHEE</div>
            <div class="center slip-title">Distributor Purchase Slip</div>

            <div class="divider"></div>

            <div class="meta">
                <div><span class="bold">REQUEST NO:</span> <?php echo htmlspecialchars(strtoupper($purchase['purchase_no'])); ?></div>
                <div><span class="bold">DATE:</span> <?php echo date('d-m-Y', strtotime($purchase['purchase_date'])); ?></div>
                <div><span class="bold">TIME:</span> <?php echo date('h:i A'); ?></div>
            </div>

            <div class="divider"></div>

            <div class="customer-block">
                <div class="bold"><?php echo htmlspecialchars(strtoupper($purchase['distributor_name'] ?? 'N/A')); ?></div>

                <?php if (!empty($purchase['mobile_number'])): ?>
                    <div>MOB: <?php echo htmlspecialchars($purchase['mobile_number']); ?></div>
                <?php endif; ?>

                <?php if (!empty($purchase['address'])): ?>
                    <div><?php echo nl2br(htmlspecialchars(strtoupper($purchase['address']))); ?></div>
                <?php endif; ?>
            </div>

            <div class="divider"></div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th class="col-item">ITEM</th>
                        <th class="col-qty">QTY</th>
                        <th class="col-rate">RATE</th>
                        <th class="col-amt">AMT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="col-item">
                                <span class="item-name"><?php echo htmlspecialchars(strtoupper($item['product_title'])); ?></span>
                                <span class="item-unit"><?php echo htmlspecialchars(strtoupper($item['unit'])); ?></span>
                            </td>
                            <td class="col-qty"><span class="qty-val"><?php echo (int)$item['qty']; ?></span></td>
                            <td class="col-rate"><span class="rate-val"><?php echo number_format((float)$item['rate'], 2); ?></span></td>
                            <td class="col-amt"><span class="amt-val"><?php echo number_format((float)$item['amount'], 2); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="divider"></div>

            <table class="totals-table">
                <tr>
                    <td>TOTAL QTY</td>
                    <td class="right"><?php echo (int)$purchase['total_qty']; ?></td>
                </tr>
                <tr class="grand-total">
                    <td>GRAND TOTAL</td>
                    <td class="right">₹<?php echo number_format((float)$purchase['total_amount'], 2); ?></td>
                </tr>
            </table>

            <?php if (!empty($purchase['remarks'])): ?>
                <div class="divider"></div>
                <div class="remarks-block">
                    <div class="bold">REMARKS:</div>
                    <div><?php echo nl2br(htmlspecialchars(strtoupper($purchase['remarks']))); ?></div>
                </div>
            <?php endif; ?>

            <div class="divider"></div>
            <div class="footer-note">
                <div>THANK YOU</div>
                <div>JOB #<?php echo (int)$purchase['job_id']; ?></div>
            </div>
        </div>

        <script>
            let printedOnce = false;

            window.onload = function () {
                if (printedOnce) return;
                printedOnce = true;

                setTimeout(function () {
                    try {
                        window.focus();
                        window.print();
                    } catch (e) {}
                }, 650);
            };

            window.onafterprint = function () {
                setTimeout(function () {
                    try {
                        window.close();
                    } catch (e) {}
                }, 300);
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Thermal Print Station</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            font-family:Arial, sans-serif;
            background:#f5f6fa;
            margin:0;
            padding:20px;
        }
        .wrap{
            max-width:800px;
            margin:0 auto;
        }
        .card{
            background:#fff;
            border-radius:12px;
            box-shadow:0 4px 20px rgba(0,0,0,0.08);
            padding:20px;
        }
        h2{
            margin-top:0;
            margin-bottom:10px;
        }
        .status{
            padding:12px 15px;
            border-radius:8px;
            margin-top:15px;
            background:#eef5ff;
        }
        .muted{
            color:#666;
        }
        iframe{
            width:0;
            height:0;
            border:0;
            visibility:hidden;
            position:absolute;
        }
        .dot{
            display:inline-block;
            width:10px;
            height:10px;
            border-radius:50%;
            background:green;
            margin-right:8px;
        }
        .small{
            font-size:13px;
            color:#555;
        }
        .ok{
            color:green;
            font-weight:700;
        }
        .err{
            color:red;
            font-weight:700;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h2>Thermal Print Station</h2>
        <p class="muted">
            Keep this page open on the system where the thermal printer is connected.
            Whenever a pending print request arrives, this page will automatically print it.
        </p>

        <div class="status">
            <span class="dot"></span>
            <strong>Station Running...</strong>
            <div id="liveStatus" class="muted" style="margin-top:8px;">Waiting for print job...</div>
        </div>

        <p class="small" style="margin-top:15px;">
            Important: for silent direct printing, run the browser in kiosk-printing mode.
        </p>
    </div>
</div>

<iframe id="printFrame"></iframe>

<script>
let isPrinting = false;
let currentJobId = 0;
let currentPurchaseId = 0;
const STATION_URL = window.location.pathname;

function setStatus(msg, type = '') {
    const el = document.getElementById('liveStatus');
    el.className = 'muted';
    if (type === 'ok') el.className = 'ok';
    if (type === 'err') el.className = 'err';
    el.innerText = msg;
}

function parseJsonSafe(text) {
    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error(text);
    }
}

function postAction(url, dataObj) {
    const formData = new FormData();
    for (const key in dataObj) {
        formData.append(key, dataObj[key]);
    }

    return fetch(url, {
        method: 'POST',
        body: formData,
        cache: 'no-store'
    })
    .then(r => r.text())
    .then(text => parseJsonSafe(text));
}

function resetPrintFrame(frame) {
    frame.onload = null;
    frame.src = 'about:blank';
}

function checkNextJob() {
    if (isPrinting) return;

    fetch(STATION_URL + '?action=next_job&_=' + Date.now(), {
        cache: 'no-store'
    })
    .then(res => res.text())
    .then(text => parseJsonSafe(text))
    .then(data => {
        if (!data.success) {
            setStatus('Error checking jobs: ' + (data.message || 'Unknown error'), 'err');
            return;
        }

        if (!data.found) {
            setStatus('Waiting for print job...');
            return;
        }

        isPrinting = true;
        currentJobId = parseInt(data.job_id, 10) || 0;
        currentPurchaseId = parseInt(data.purchase_id, 10) || 0;

        if (currentJobId <= 0) {
            isPrinting = false;
            setStatus('Invalid job received from server.', 'err');
            return;
        }

        setStatus('Printing job #' + currentJobId + ' for purchase #' + currentPurchaseId);

        const frame = document.getElementById('printFrame');
        resetPrintFrame(frame);

        setTimeout(function () {
            frame.onload = function() {
                setTimeout(function () {
                    postAction(STATION_URL + '?action=mark_done', {
                        job_id: currentJobId
                    })
                    .then(doneRes => {
                        if (doneRes.success) {
                            setStatus('Printed job #' + currentJobId, 'ok');
                        } else {
                            setStatus('Printed but failed to mark job #' + currentJobId, 'err');
                        }

                        currentJobId = 0;
                        currentPurchaseId = 0;
                        isPrinting = false;
                        resetPrintFrame(frame);
                    })
                    .catch(err => {
                        setStatus('Print completion error: ' + err.message, 'err');

                        postAction(STATION_URL + '?action=mark_failed', {
                            job_id: currentJobId,
                            message: err.message
                        }).finally(() => {
                            currentJobId = 0;
                            currentPurchaseId = 0;
                            isPrinting = false;
                            resetPrintFrame(frame);
                        });
                    });
                }, 4000);
            };

            frame.src = STATION_URL + '?print_job=' + currentJobId + '&_=' + Date.now();
        }, 250);
    })
    .catch(err => {
        setStatus('Connection error: ' + err.message, 'err');
    });
}

setInterval(checkNextJob, 3000);
checkNextJob();
</script>
</body>
</html>
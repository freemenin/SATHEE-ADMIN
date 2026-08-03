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
    $items = $stmt->get_result();
    $stmt->close();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Thermal Print</title>
        <style>
            body{
                margin:0;
                padding:0;
                background:#fff;
                font-family:Arial, Helvetica, sans-serif;
                color:#000;
            }

            .receipt{
                width:78mm;
                margin:0 auto;
                padding:3mm 2.5mm;
                box-sizing:border-box;
                font-size:14px;
                line-height:1.45;
                font-weight:500;
            }

            .center{ text-align:center; }
            .right{ text-align:right; }
            .bold{ font-weight:700; }

            .shop-name{
                font-size:18px;
                font-weight:700;
                line-height:1.2;
                margin-bottom:2px;
            }

            .slip-title{
                font-size:13px;
                font-weight:700;
                margin-bottom:4px;
            }

            .meta,
            .customer-block,
            .remarks-block,
            .footer-note{
                font-size:13px;
            }

            .divider{
                border-top:1px dashed #000;
                margin:7px 0;
            }

            .items-table,
            .totals-table{
                width:100%;
                border-collapse:collapse;
                table-layout:fixed;
            }

            .items-table th,
            .items-table td,
            .totals-table td{
                padding:3px 0;
                vertical-align:top;
                font-size:13px;
            }

            .items-table thead th{
                font-size:12px;
                font-weight:700;
                border-bottom:1px solid #000;
                padding-bottom:4px;
            }

            .col-item{ width:46%; text-align:left; }
            .col-qty{ width:14%; text-align:right; }
            .col-rate{ width:18%; text-align:right; }
            .col-amt{ width:22%; text-align:right; }

            .item-name{
                font-size:15px;
                font-weight:600;
                line-height:1.25;
                word-break:break-word;
            }

            .item-unit{
                display:block;
                font-size:12px;
                opacity:1;
                margin-top:1px;
            }

            .totals-table td{
                font-size:15px;
                font-weight:700;
                padding:4px 0;
            }

            .grand-total td{
                font-size:15px;
                font-weight:700;
                border-top:1px solid #000;
                padding-top:5px;
            }

            .footer-note{
                text-align:center;
                margin-top:4px;
                font-size:12px;
            }

            @media print {
                @page{
                    size:80mm auto;
                    margin:2mm;
                }

                html, body{
                    width:80mm;
                    margin:0;
                    padding:0;
                    background:#fff;
                }

                .receipt{
                    width:100%;
                    margin:0;
                    padding:2mm 2mm;
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
                <div><span class="bold">Request No:</span> <?php echo htmlspecialchars($purchase['purchase_no']); ?></div>
                <div><span class="bold">Date:</span> <?php echo date('d-m-Y', strtotime($purchase['purchase_date'])); ?></div>
                <div><span class="bold">Time:</span> <?php echo date('h:i A'); ?></div>
            </div>

            <div class="divider"></div>

            <div class="customer-block">
                <div class="bold"><?php echo htmlspecialchars($purchase['distributor_name'] ?? 'N/A'); ?></div>
                <?php if (!empty($purchase['mobile_number'])): ?>
                    <div>Mob: <?php echo htmlspecialchars($purchase['mobile_number']); ?></div>
                <?php endif; ?>
                <?php if (!empty($purchase['address'])): ?>
                    <div><?php echo nl2br(htmlspecialchars($purchase['address'])); ?></div>
                <?php endif; ?>
            </div>

            <div class="divider"></div>

            <table class="items-table">
                <thead>
                    <tr>
                        <th class="col-item">Item</th>
                        <th class="col-qty">Qty</th>
                        <th class="col-rate">Rate</th>
                        <th class="col-amt">Amt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($item = $items->fetch_assoc()): ?>
                        <tr>
                            <td class="col-item">
                                <span class="item-name"><?php echo htmlspecialchars($item['product_title']); ?></span>
                                <span class="item-unit"><?php echo htmlspecialchars($item['unit']); ?></span>
                            </td>
                            <td class="col-qty"><?php echo (int)$item['qty']; ?></td>
                            <td class="col-rate"><?php echo number_format((float)$item['rate'], 2); ?></td>
                            <td class="col-amt"><?php echo number_format((float)$item['amount'], 2); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <div class="divider"></div>

            <table class="totals-table">
                <tr>
                    <td>Total Qty</td>
                    <td class="right"><?php echo (int)$purchase['total_qty']; ?></td>
                </tr>
                <tr class="grand-total">
                    <td>Grand Total</td>
                    <td class="right">₹<?php echo number_format((float)$purchase['total_amount'], 2); ?></td>
                </tr>
            </table>

            <?php if (!empty($purchase['remarks'])): ?>
                <div class="divider"></div>
                <div class="remarks-block">
                    <div class="bold">Remarks:</div>
                    <div><?php echo nl2br(htmlspecialchars($purchase['remarks'])); ?></div>
                </div>
            <?php endif; ?>

            <div class="divider"></div>
            <div class="footer-note">
                <div>Thank you</div>
                <div>Job #<?php echo (int)$purchase['job_id']; ?></div>
            </div>
        </div>

        <script>
            window.onload = function () {
                setTimeout(function () {
                    window.print();
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
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h2>Thermal Print Station</h2>
        <p class="muted">
            इस page को उसी system में open रखो जहाँ thermal printer connected है.
            जो भी pending print request आएगी, यह page उसे auto print करेगा.
        </p>

        <div class="status">
            <span class="dot"></span>
            <strong>Station Running...</strong>
            <div id="liveStatus" class="muted" style="margin-top:8px;">Waiting for print job...</div>
        </div>

        <p class="small" style="margin-top:15px;">
            Important: direct silent print के लिए browser को kiosk-printing mode में चलाना होगा.
        </p>
    </div>
</div>

<iframe id="printFrame"></iframe>

<script>
let isPrinting = false;
let currentJobId = 0;
const STATION_URL = window.location.pathname;

function setStatus(msg) {
    document.getElementById('liveStatus').innerText = msg;
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
        body: formData
    })
    .then(r => r.text())
    .then(text => parseJsonSafe(text));
}

function checkNextJob() {
    if (isPrinting) return;

    fetch(STATION_URL + '?action=next_job&_=' + Date.now())
        .then(res => res.text())
        .then(text => parseJsonSafe(text))
        .then(data => {
            if (!data.success) {
                setStatus('Error checking jobs: ' + (data.message || 'Unknown error'));
                return;
            }

            if (!data.found) {
                setStatus('Waiting for print job...');
                return;
            }

            isPrinting = true;
            currentJobId = data.job_id;
            setStatus('Printing job #' + currentJobId + ' for purchase #' + data.purchase_id);

            const frame = document.getElementById('printFrame');

            frame.onload = function() {
                setTimeout(function () {
                    postAction(STATION_URL + '?action=mark_done', {
                        job_id: currentJobId
                    })
                    .then(doneRes => {
                        if (doneRes.success) {
                            setStatus('Printed job #' + currentJobId);
                        } else {
                            setStatus('Failed to mark printed job #' + currentJobId);
                        }
                        currentJobId = 0;
                        isPrinting = false;
                    })
                    .catch(err => {
                        setStatus('Print completion error: ' + err.message);

                        postAction(STATION_URL + '?action=mark_failed', {
                            job_id: currentJobId,
                            message: err.message
                        }).finally(() => {
                            currentJobId = 0;
                            isPrinting = false;
                        });
                    });
                }, 3500);
            };

            frame.src = STATION_URL + '?print_job=' + currentJobId + '&_=' + Date.now();
        })
        .catch(err => {
            setStatus('Connection error: ' + err.message);
        });
}

setInterval(checkNextJob, 3000);
checkNextJob();
</script>
</body>
</html>
<?php
require_once 'include/require_permission.php';
requirePermission('BATCHES', 'view');
include('include/require_login.php');
include('include/header.php');

$batch_id = intval($_GET['id'] ?? 0);

if ($batch_id <= 0) {
    die("Invalid Batch ID");
}

function companyName($code) {
    $code = strtoupper((string)$code);

    if ($code == 'SATHEE') return 'Sathee Enterprise';
    if ($code == 'CMD') return 'CMD Enterprise';

    return '-';
}

function statusBadge($status) {
    if ($status == 'pending') {
        return '<span class="badge bg-warning text-dark">Pending</span>';
    }

    if ($status == 'settled') {
        return '<span class="badge bg-success">Settled</span>';
    }

    return '<span class="badge bg-secondary">None</span>';
}

$stmt = $mysqli->prepare("
    SELECT 
        b.*,
        p.title AS product_name,
        u.name AS created_by_name
    FROM batches b
    LEFT JOIN products p ON b.product_id = p.product_id
    LEFT JOIN users u ON b.created_by = u.user_id
    WHERE b.batch_id = ?
");

$stmt->bind_param("i", $batch_id);
$stmt->execute();
$batch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$batch) {
    die("Batch not found.");
}

$itemStmt = $mysqli->prepare("
    SELECT 
        brm.*,
        rm.material_name
    FROM batch_raw_materials brm
    LEFT JOIN raw_materials rm ON brm.raw_material_id = rm.raw_material_id
    WHERE brm.batch_id = ?
    ORDER BY brm.id ASC
");

$itemStmt->bind_param("i", $batch_id);
$itemStmt->execute();
$items = $itemStmt->get_result();

$total_material_cost = 0;
$total_settlement = 0;
$sathee_to_cmd = 0;
$cmd_to_sathee = 0;

$rows = [];

while ($row = $items->fetch_assoc()) {
    $amount = (float)$row['amount'];

    $total_material_cost += $amount;

    if ((int)$row['settlement_required'] === 1) {
        $total_settlement += $amount;

        if ($row['payable_from'] == 'sathee' && $row['payable_to'] == 'cmd') {
            $sathee_to_cmd += $amount;
        }

        if ($row['payable_from'] == 'cmd' && $row['payable_to'] == 'sathee') {
            $cmd_to_sathee += $amount;
        }
    }

    $rows[] = $row;
}

$itemStmt->close();
?>

<style>
@media print {
    .no-print {
        display: none !important;
    }

    body {
        background: #fff !important;
    }

    .card {
        border: 1px solid #ddd !important;
        box-shadow: none !important;
    }

    table {
        font-size: 12px;
    }
}
</style>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div>
            <h4 class="mb-1">Batch Details</h4>
            <small class="text-muted">Complete batch material usage and settlement details</small>
        </div>

        <div>
            <a href="batch_list.php" class="btn btn-secondary btn-sm">Back</a>
            <a href="batch_settlement_report.php" class="btn btn-info btn-sm">Settlement Report</a>
            <button onclick="window.print()" class="btn btn-primary btn-sm">Print</button>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white">
            <strong>Batch Information</strong>
        </div>

        <div class="card-body">

            <div class="row">

                <div class="col-md-3 mb-3">
                    <small class="text-muted">Batch Code</small>
                    <h5><?= htmlspecialchars($batch['batch_code']) ?></h5>
                </div>

                <div class="col-md-3 mb-3">
                    <small class="text-muted">Product</small>
                    <h5><?= htmlspecialchars($batch['product_name'] ?? '-') ?></h5>
                </div>

                <div class="col-md-3 mb-3">
                    <small class="text-muted">Batch Owner</small>
                    <h5><?= companyName($batch['batch_owner']) ?></h5>
                </div>

                <div class="col-md-3 mb-3">
                    <small class="text-muted">Production Date</small>
                    <h5><?= date('d-m-Y', strtotime($batch['production_date'])) ?></h5>
                </div>

                <div class="col-md-3 mb-3">
                    <small class="text-muted">Produced Quantity</small>
                    <h5><?= number_format((float)$batch['product_qty'], 2) ?></h5>
                </div>

                <div class="col-md-3 mb-3">
                    <small class="text-muted">Created By</small>
                    <h5><?= htmlspecialchars($batch['created_by_name'] ?? '-') ?></h5>
                </div>

                <div class="col-md-3 mb-3">
                    <small class="text-muted">Created At</small>
                    <h5><?= date('d-m-Y h:i A', strtotime($batch['created_at'])) ?></h5>
                </div>

            </div>

            <?php if (!empty($batch['notes'])): ?>
                <hr>
                <small class="text-muted">Notes</small>
                <p class="mb-0"><?= nl2br(htmlspecialchars($batch['notes'])) ?></p>
            <?php endif; ?>

        </div>
    </div>

    <div class="row mb-3">

        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Total Material Cost</small>
                    <h5 class="mb-0">₹<?= number_format($total_material_cost, 2) ?></h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Total Settlement</small>
                    <h5 class="mb-0 text-warning">₹<?= number_format($total_settlement, 2) ?></h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Sathee To CMD</small>
                    <h5 class="mb-0 text-danger">₹<?= number_format($sathee_to_cmd, 2) ?></h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">CMD To Sathee</small>
                    <h5 class="mb-0 text-success">₹<?= number_format($cmd_to_sathee, 2) ?></h5>
                </div>
            </div>
        </div>

    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Raw Material Usage</strong>
        </div>

        <div class="card-body">

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Material</th>
                            <th>Material Owner</th>
                            <th>Qty Used</th>
                            <th>Unit</th>
                            <th>Rate</th>
                            <th>Amount</th>
                            <th>Settlement</th>
                            <th>Payable From</th>
                            <th>Payable To</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (count($rows) > 0): ?>
                            <?php $i = 1; foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= $i++ ?></td>

                                    <td>
                                        <strong><?= htmlspecialchars($row['material_name'] ?? '-') ?></strong>
                                    </td>

                                    <td><?= companyName($row['material_owner_company']) ?></td>

                                    <td><?= number_format((float)$row['quantity_used'], 2) ?></td>

                                    <td><?= htmlspecialchars($row['unit'] ?? '-') ?></td>

                                    <td>₹<?= number_format((float)$row['rate'], 2) ?></td>

                                    <td>
                                        <strong>₹<?= number_format((float)$row['amount'], 2) ?></strong>
                                    </td>

                                    <td>
                                        <?php if ((int)$row['settlement_required'] === 1): ?>
                                            <span class="badge bg-warning text-dark">Required</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">No</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= companyName($row['payable_from']) ?></td>

                                    <td><?= companyName($row['payable_to']) ?></td>

                                    <td><?= statusBadge($row['settlement_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted">
                                    No raw material usage found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th colspan="6" class="text-end">Total</th>
                            <th>₹<?= number_format($total_material_cost, 2) ?></th>
                            <th colspan="4"></th>
                        </tr>
                    </tfoot>

                </table>
            </div>

        </div>
    </div>

</div>

<?php include('include/footer.php'); ?>
<?php
require_once 'include/require_permission.php';
requirePermission('REPORTS', 'view');
include('include/require_login.php');
include('include/header.php');

$where = "WHERE brm.settlement_required = 1";
$params = [];
$types = "";

$status = $_GET['status'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

if ($status != '') {
    $where .= " AND brm.settlement_status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($from_date != '') {
    $where .= " AND b.production_date >= ?";
    $params[] = $from_date;
    $types .= "s";
}

if ($to_date != '') {
    $where .= " AND b.production_date <= ?";
    $params[] = $to_date;
    $types .= "s";
}

$sql = "
    SELECT 
        brm.id,
        brm.batch_id,
        brm.raw_material_id,
        brm.unit,
        brm.quantity_used,
        brm.material_owner_company,
        brm.rate,
        brm.amount,
        brm.payable_from,
        brm.payable_to,
        brm.settlement_status,

        b.batch_code,
        b.production_date,
        b.batch_owner,
        b.product_qty,

        p.title AS product_name,
        rm.material_name

    FROM batch_raw_materials brm
    INNER JOIN batches b ON brm.batch_id = b.batch_id
    LEFT JOIN products p ON b.product_id = p.product_id
    LEFT JOIN raw_materials rm ON brm.raw_material_id = rm.raw_material_id
    $where
    ORDER BY b.production_date DESC, brm.id DESC
";

$stmt = $mysqli->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$total_sathee_to_cmd = 0;
$total_cmd_to_sathee = 0;
$total_pending = 0;
$total_settled = 0;

$rows = [];

while ($row = $result->fetch_assoc()) {
    $amount = (float)$row['amount'];

    if ($row['payable_from'] == 'sathee' && $row['payable_to'] == 'cmd') {
        $total_sathee_to_cmd += $amount;
    }

    if ($row['payable_from'] == 'cmd' && $row['payable_to'] == 'sathee') {
        $total_cmd_to_sathee += $amount;
    }

    if ($row['settlement_status'] == 'pending') {
        $total_pending += $amount;
    }

    if ($row['settlement_status'] == 'settled') {
        $total_settled += $amount;
    }

    $rows[] = $row;
}

$net_balance = $total_sathee_to_cmd - $total_cmd_to_sathee;

function companyName($code) {
    $code = strtolower((string)$code);

    if ($code == 'sathee') {
        return 'Sathee Enterprise';
    }

    if ($code == 'cmd') {
        return 'CMD Enterprise';
    }

    if ($code == 'SATHEE') {
        return 'Sathee Enterprise';
    }

    if ($code == 'CMD') {
        return 'CMD Enterprise';
    }

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
            <h4 class="mb-1">Batch Settlement Report</h4>
            <small class="text-muted">
                Sathee Enterprise ↔ CMD Enterprise material usage settlement
            </small>
        </div>

        <div>
            <a href="batch_list.php" class="btn btn-secondary btn-sm">Back to Batch List</a>
            <button onclick="window.print()" class="btn btn-primary btn-sm">Print Report</button>
        </div>
    </div>

    <div class="card shadow-sm mb-3 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">

                <div class="col-md-3">
                    <label class="form-label">Settlement Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="pending" <?= ($status == 'pending') ? 'selected' : '' ?>>Pending</option>
                        <option value="settled" <?= ($status == 'settled') ? 'selected' : '' ?>>Settled</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
                </div>

                <div class="col-md-3">
                    <button type="submit" class="btn btn-success">Filter</button>
                    <a href="batch_settlement_report.php" class="btn btn-outline-secondary">Reset</a>
                </div>

            </form>
        </div>
    </div>

    <div class="row mb-3">

        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Sathee Has To Pay CMD</small>
                    <h5 class="mb-0 text-danger">
                        ₹<?= number_format($total_sathee_to_cmd, 2) ?>
                    </h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">CMD Has To Pay Sathee</small>
                    <h5 class="mb-0 text-success">
                        ₹<?= number_format($total_cmd_to_sathee, 2) ?>
                    </h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Pending Settlement</small>
                    <h5 class="mb-0 text-warning">
                        ₹<?= number_format($total_pending, 2) ?>
                    </h5>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <small class="text-muted">Net Balance</small>
                    <h5 class="mb-0">
                        <?php if ($net_balance > 0): ?>
                            Sathee → CMD ₹<?= number_format(abs($net_balance), 2) ?>
                        <?php elseif ($net_balance < 0): ?>
                            CMD → Sathee ₹<?= number_format(abs($net_balance), 2) ?>
                        <?php else: ?>
                            ₹0.00
                        <?php endif; ?>
                    </h5>
                </div>
            </div>
        </div>

    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Settlement Details</strong>
        </div>

        <div class="card-body">

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Batch</th>
                            <th>Batch Owner</th>
                            <th>Product</th>
                            <th>Material</th>
                            <th>Material Owner</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Amount</th>
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
                                    <td><?= date('d-m-Y', strtotime($row['production_date'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($row['batch_code']) ?></strong>
                                    </td>
                                    <td><?= companyName($row['batch_owner']) ?></td>
                                    <td><?= htmlspecialchars($row['product_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['material_name'] ?? '-') ?></td>
                                    <td><?= companyName($row['material_owner_company']) ?></td>
                                    <td>
                                        <?= number_format((float)$row['quantity_used'], 2) ?>
                                        <?= htmlspecialchars($row['unit']) ?>
                                    </td>
                                    <td>₹<?= number_format((float)$row['rate'], 2) ?></td>
                                    <td>
                                        <strong>₹<?= number_format((float)$row['amount'], 2) ?></strong>
                                    </td>
                                    <td><?= companyName($row['payable_from']) ?></td>
                                    <td><?= companyName($row['payable_to']) ?></td>
                                    <td><?= statusBadge($row['settlement_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="13" class="text-center text-muted">
                                    No settlement records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                    <tfoot>
                        <tr>
                            <th colspan="9" class="text-end">Total</th>
                            <th>₹<?= number_format(array_sum(array_column($rows, 'amount')), 2) ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>

                </table>
            </div>

        </div>
    </div>

</div>

<?php include('include/footer.php'); ?>
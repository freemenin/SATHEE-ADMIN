<?php
require_once 'include/require_permission.php';
requirePermission('REPORTS', 'edit');
include('include/require_login.php');
include('include/db.php');

function companyName($code) {
    $code = strtolower((string)$code);

    if ($code == 'sathee') return 'Sathee Enterprise';
    if ($code == 'cmd') return 'CMD Enterprise';

    return '-';
}

/* POST logic must come before header.php */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payable_from = strtolower($_POST['payable_from'] ?? '');
    $payable_to   = strtolower($_POST['payable_to'] ?? '');
    $from_date    = $_POST['from_date'] ?? '';
    $to_date      = $_POST['to_date'] ?? '';

    if (
        !in_array($payable_from, ['sathee', 'cmd']) ||
        !in_array($payable_to, ['sathee', 'cmd']) ||
        $payable_from == $payable_to
    ) {
        header("Location: batch_settlement_pay.php?error=" . urlencode("Invalid company selection"));
        exit;
    }

    $where = "
        settlement_required = 1
        AND settlement_status = 'pending'
        AND payable_from = ?
        AND payable_to = ?
    ";

    $params = [$payable_from, $payable_to];
    $types = "ss";

    if ($from_date != '') {
        $where .= " AND batch_id IN (
            SELECT batch_id FROM batches WHERE production_date >= ?
        )";
        $params[] = $from_date;
        $types .= "s";
    }

    if ($to_date != '') {
        $where .= " AND batch_id IN (
            SELECT batch_id FROM batches WHERE production_date <= ?
        )";
        $params[] = $to_date;
        $types .= "s";
    }

    $stmt = $mysqli->prepare("
        UPDATE batch_raw_materials
        SET settlement_status = 'settled'
        WHERE $where
    ");

    if (!$stmt) {
        header("Location: batch_settlement_pay.php?error=" . urlencode($mysqli->error));
        exit;
    }

    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        header("Location: batch_settlement_pay.php?success=" . urlencode("Settlement marked as settled"));
        exit;
    } else {
        header("Location: batch_settlement_pay.php?error=" . urlencode($stmt->error));
        exit;
    }
}

include('include/header.php');

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$summarySql = "
    SELECT
        payable_from,
        payable_to,
        SUM(amount) AS total_amount,
        COUNT(*) AS total_entries
    FROM batch_raw_materials
    WHERE settlement_required = 1
    AND settlement_status = 'pending'
    GROUP BY payable_from, payable_to
";

$summaryResult = $mysqli->query($summarySql);

$pendingDetailsSql = "
    SELECT 
        brm.id,
        brm.batch_id,
        brm.raw_material_id,
        brm.quantity_used,
        brm.unit,
        brm.rate,
        brm.amount,
        brm.payable_from,
        brm.payable_to,
        brm.settlement_status,

        b.batch_code,
        b.production_date,
        b.batch_owner,

        p.title AS product_name,
        rm.material_name

    FROM batch_raw_materials brm
    INNER JOIN batches b ON brm.batch_id = b.batch_id
    LEFT JOIN products p ON b.product_id = p.product_id
    LEFT JOIN raw_materials rm ON brm.raw_material_id = rm.raw_material_id
    WHERE brm.settlement_required = 1
    AND brm.settlement_status = 'pending'
    ORDER BY b.production_date DESC, brm.id DESC
";

$pendingDetails = $mysqli->query($pendingDetailsSql);
?>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Batch Settlement Payment</h4>
            <small class="text-muted">Mark pending Sathee ↔ CMD material settlements as paid</small>
        </div>

        <div>
            <a href="batch_settlement_report.php" class="btn btn-info btn-sm">Settlement Report</a>
            <a href="batch_list.php" class="btn btn-secondary btn-sm">Batch List</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="row mb-3">

        <?php if ($summaryResult && $summaryResult->num_rows > 0): ?>
            <?php while ($s = $summaryResult->fetch_assoc()): ?>
                <div class="col-md-6 mb-3">
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <small class="text-muted">
                                <?= companyName($s['payable_from']) ?> has to pay <?= companyName($s['payable_to']) ?>
                            </small>

                            <h4 class="mb-1">
                                ₹<?= number_format((float)$s['total_amount'], 2) ?>
                            </h4>

                            <small class="text-muted">
                                <?= intval($s['total_entries']) ?> pending entries
                            </small>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-md-12">
                <div class="alert alert-success">
                    No pending settlement found.
                </div>
            </div>
        <?php endif; ?>

    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <strong>Mark Settlement As Paid</strong>
        </div>

        <div class="card-body">
            <form method="POST" class="row g-3">

                <div class="col-md-3">
                    <label class="form-label">Payable From</label>
                    <select name="payable_from" class="form-select" required>
                        <option value="">Select</option>
                        <option value="sathee">Sathee Enterprise</option>
                        <option value="cmd">CMD Enterprise</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Payable To</label>
                    <select name="payable_to" class="form-select" required>
                        <option value="">Select</option>
                        <option value="sathee">Sathee Enterprise</option>
                        <option value="cmd">CMD Enterprise</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control">
                </div>

                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control">
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100"
                        onclick="return confirm('Are you sure you want to mark selected settlements as settled?');">
                        Mark Settled
                    </button>
                </div>

            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Pending Settlement Entries</strong>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Batch</th>
                            <th>Product</th>
                            <th>Material</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Amount</th>
                            <th>Payable From</th>
                            <th>Payable To</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($pendingDetails && $pendingDetails->num_rows > 0): ?>
                            <?php $i = 1; while ($row = $pendingDetails->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $i++ ?></td>

                                    <td><?= date('d-m-Y', strtotime($row['production_date'])) ?></td>

                                    <td>
                                        <a href="batch_view.php?id=<?= $row['batch_id'] ?>">
                                            <strong><?= htmlspecialchars($row['batch_code']) ?></strong>
                                        </a>
                                    </td>

                                    <td><?= htmlspecialchars($row['product_name'] ?? '-') ?></td>

                                    <td><?= htmlspecialchars($row['material_name'] ?? '-') ?></td>

                                    <td>
                                        <?= number_format((float)$row['quantity_used'], 2) ?>
                                        <?= htmlspecialchars($row['unit'] ?? '') ?>
                                    </td>

                                    <td>₹<?= number_format((float)$row['rate'], 2) ?></td>

                                    <td>
                                        <strong>₹<?= number_format((float)$row['amount'], 2) ?></strong>
                                    </td>

                                    <td><?= companyName($row['payable_from']) ?></td>

                                    <td><?= companyName($row['payable_to']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">
                                    No pending settlement entries.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                </table>
            </div>
        </div>
    </div>

</div>

<?php include('include/footer.php'); ?>
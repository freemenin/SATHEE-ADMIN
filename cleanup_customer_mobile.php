<?php
require_once 'include/require_permission.php';
requirePermission('CUSTOMERS', 'edit');
include('include/require_login.php');
include('include/header.php');

/*
|--------------------------------------------------------------------------
| Customer Mobile Number Cleanup
|--------------------------------------------------------------------------
| This page cleans customers.mobile_number to fixed 10 digit Indian mobile.
| It safely skips invalid and duplicate numbers.
*/

function normalizeIndianMobile($mobile) {
    $raw = trim((string)$mobile);

    // Remove everything except numbers
    $digits = preg_replace('/\D+/', '', $raw);

    // Remove 0091 prefix
    if (substr($digits, 0, 4) === '0091' && strlen($digits) > 10) {
        $digits = substr($digits, 4);
    }

    // Remove +91 / 91 prefix
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
        $digits = substr($digits, 2);
    }

    // Remove leading zero prefix
    while (strlen($digits) > 10 && substr($digits, 0, 1) === '0') {
        $digits = substr($digits, 1);
    }

    // Last safety: if number is still more than 10 and last 10 look like Indian mobile
    if (strlen($digits) > 10) {
        $last10 = substr($digits, -10);
        if (preg_match('/^[6-9][0-9]{9}$/', $last10)) {
            $digits = $last10;
        }
    }

    // Final validation: 10 digit Indian mobile
    if (preg_match('/^[6-9][0-9]{9}$/', $digits)) {
        return $digits;
    }

    return false;
}

$isRun = isset($_POST['run_cleanup']) && $_POST['run_cleanup'] === '1';

$rows = [];
$res = $mysqli->query("SELECT customer_id, full_name, mobile_number FROM customers ORDER BY customer_id ASC");

while ($row = $res->fetch_assoc()) {
    $normalized = normalizeIndianMobile($row['mobile_number']);

    $rows[] = [
        'customer_id' => (int)$row['customer_id'],
        'full_name' => $row['full_name'],
        'old_mobile' => $row['mobile_number'],
        'new_mobile' => $normalized
    ];
}

// Group by normalized mobile to detect duplicate after cleanup
$mobileMap = [];

foreach ($rows as $r) {
    if ($r['new_mobile'] !== false) {
        $mobileMap[$r['new_mobile']][] = $r['customer_id'];
    }
}

$validUpdates = [];
$invalidRows = [];
$duplicateRows = [];
$alreadyClean = [];

foreach ($rows as $r) {
    if ($r['new_mobile'] === false) {
        $invalidRows[] = $r;
        continue;
    }

    if (count($mobileMap[$r['new_mobile']]) > 1) {
        $duplicateRows[] = $r;
        continue;
    }

    if ($r['old_mobile'] === $r['new_mobile']) {
        $alreadyClean[] = $r;
        continue;
    }

    $validUpdates[] = $r;
}

$updated = 0;
$errorMsg = '';

if ($isRun) {
    $mysqli->begin_transaction();

    try {
        $stmt = $mysqli->prepare("
            UPDATE customers 
            SET mobile_number = ? 
            WHERE customer_id = ?
        ");

        foreach ($validUpdates as $u) {
            $stmt->bind_param("si", $u['new_mobile'], $u['customer_id']);
            $stmt->execute();
            $updated++;
        }

        $mysqli->commit();

    } catch (Exception $e) {
        $mysqli->rollback();
        $errorMsg = $e->getMessage();
    }
}
?>

<div class="container-fluid mt-4">

    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Customer Mobile Number Cleanup</h5>
        </div>

        <div class="card-body">

            <?php if ($isRun && !$errorMsg): ?>
                <div class="alert alert-success">
                    Cleanup completed. Total updated: <strong><?= $updated ?></strong>
                </div>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger">
                    Error: <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>

            <div class="row mb-4">

                <div class="col-md-3">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h4><?= count($validUpdates) ?></h4>
                            <p class="mb-0">Ready To Update</p>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h4><?= count($alreadyClean) ?></h4>
                            <p class="mb-0">Already Clean</p>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card border-warning">
                        <div class="card-body text-center">
                            <h4><?= count($duplicateRows) ?></h4>
                            <p class="mb-0">Duplicate After Cleanup</p>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card border-danger">
                        <div class="card-body text-center">
                            <h4><?= count($invalidRows) ?></h4>
                            <p class="mb-0">Invalid Mobile</p>
                        </div>
                    </div>
                </div>

            </div>

            <?php if (!$isRun): ?>
                <form method="post" onsubmit="return confirm('Are you sure? Please confirm backup is already taken.');">
                    <input type="hidden" name="run_cleanup" value="1">
                    <button type="submit" class="btn btn-success">
                        Run Cleanup Now
                    </button>
                </form>
            <?php endif; ?>

        </div>
    </div>

    <div class="card mt-4 shadow-sm">
        <div class="card-header bg-success text-white">
            <h6 class="mb-0">Ready To Update</h6>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Old Mobile</th>
                        <th>New Mobile</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($validUpdates as $r): ?>
                        <tr>
                            <td><?= $r['customer_id'] ?></td>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= htmlspecialchars($r['old_mobile']) ?></td>
                            <td><strong><?= htmlspecialchars($r['new_mobile']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($validUpdates)): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">No records ready for update</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-4 shadow-sm">
        <div class="card-header bg-warning">
            <h6 class="mb-0">Duplicate Mobile Numbers After Cleanup - Manual Check Required</h6>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Old Mobile</th>
                        <th>Clean Mobile</th>
                        <th>Duplicate Customer IDs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($duplicateRows as $r): ?>
                        <tr>
                            <td><?= $r['customer_id'] ?></td>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= htmlspecialchars($r['old_mobile']) ?></td>
                            <td><strong><?= htmlspecialchars($r['new_mobile']) ?></strong></td>
                            <td><?= implode(', ', $mobileMap[$r['new_mobile']]) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($duplicateRows)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">No duplicate found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mt-4 shadow-sm">
        <div class="card-header bg-danger text-white">
            <h6 class="mb-0">Invalid Mobile Numbers - Manual Correction Required</h6>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Mobile Number</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invalidRows as $r): ?>
                        <tr>
                            <td><?= $r['customer_id'] ?></td>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= htmlspecialchars($r['old_mobile']) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($invalidRows)): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted">No invalid mobile found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include('include/footer.php'); ?>
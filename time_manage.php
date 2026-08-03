<?php
// FILE: time_manage.php
// Admin page to manage distributor order visibility time duration.
// If distributor is selected, settings apply only to that distributor.
// If no distributor is selected / All Distributors selected, settings apply to all distributors.

require_once __DIR__ . '/include/require_permission.php';
requirePermission('TIME_MANAGEMENT', 'view');
require_once __DIR__ . '/include/csrf_helper.php';
require_once __DIR__ . '/include/db.php';

date_default_timezone_set('Asia/Kolkata');

/* =========================
   BASIC HELPERS
========================= */
function cleanText($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function timeNice($time) {
    if (empty($time)) return '-';
    return date('h:i A', strtotime($time));
}

function yesNoBadge($value) {
    if ((int)$value === 1) {
        return '<span class="badge bg-success">Enabled</span>';
    }
    return '<span class="badge bg-danger">Disabled</span>';
}

function ensure_column(mysqli $db, string $table, string $column, string $ddl): void {
    $tableSafe = $db->real_escape_string($table);
    $colSafe   = $db->real_escape_string($column);

    $res = $db->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$colSafe}'");
    if (!$res || $res->num_rows === 0) {
        $db->query("ALTER TABLE `{$tableSafe}` ADD COLUMN `{$colSafe}` {$ddl}");
    }
}

function redirectSelf($msgType = '', $msg = '') {
    $url = 'time_manage.php';
    if ($msgType !== '' && $msg !== '') {
        $url .= '?msg_type=' . urlencode($msgType) . '&msg=' . urlencode($msg);
    }
    header('Location: ' . $url);
    exit;
}

/* =========================
   ENSURE REQUIRED COLUMNS
========================= */
ensure_column($mysqli, 'distributors', 'order_view_enabled', "TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=allow order view, 0=block order view'");
ensure_column($mysqli, 'distributors', 'order_view_start', "TIME NULL COMMENT 'Distributor order visible start time'");
ensure_column($mysqli, 'distributors', 'order_view_end', "TIME NULL COMMENT 'Distributor order visible end time'");
ensure_column($mysqli, 'distributors', 'order_view_message', "VARCHAR(255) NULL COMMENT 'Optional blocked message for distributor portal'");
ensure_column($mysqli, 'distributors', 'order_view_updated_at', "DATETIME NULL COMMENT 'Last order view setting update time'");

/* =========================
   FORM SAVE ACTION
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_time_setting'])) {
    requirePermission('TIME_MANAGEMENT', 'edit');

    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        redirectSelf('danger', 'Invalid request token. Please try again.');
    }

    $distributor_id = intval($_POST['distributor_id'] ?? 0);
    $enabled        = isset($_POST['order_view_enabled']) ? 1 : 0;
    $start_time     = trim($_POST['order_view_start'] ?? '');
    $end_time       = trim($_POST['order_view_end'] ?? '');
    $message        = trim($_POST['order_view_message'] ?? '');

    if ($message === '') {
        $message = 'Orders are not visible right now. Please check again during allowed time.';
    }

    // Validate time format only if visibility is enabled.
    if ($enabled === 1) {
        if ($start_time === '' || $end_time === '') {
            redirectSelf('danger', 'Please select both start time and end time.');
        }

        if (!preg_match('/^\d{2}:\d{2}$/', $start_time) || !preg_match('/^\d{2}:\d{2}$/', $end_time)) {
            redirectSelf('danger', 'Invalid time format. Please select valid start and end time.');
        }

        $start_time .= ':00';
        $end_time   .= ':00';
    } else {
        // If disabled, keep time optional. If admin filled it, store it; otherwise NULL.
        if ($start_time !== '' && preg_match('/^\d{2}:\d{2}$/', $start_time)) {
            $start_time .= ':00';
        } else {
            $start_time = null;
        }

        if ($end_time !== '' && preg_match('/^\d{2}:\d{2}$/', $end_time)) {
            $end_time .= ':00';
        } else {
            $end_time = null;
        }
    }

    if ($distributor_id > 0) {
        // Apply to one selected distributor only.
        $stmt = $mysqli->prepare("\n            UPDATE distributors\n            SET order_view_enabled = ?,\n                order_view_start = ?,\n                order_view_end = ?,\n                order_view_message = ?,\n                order_view_updated_at = NOW()\n            WHERE distributor_id = ?\n            LIMIT 1\n        ");

        if (!$stmt) {
            redirectSelf('danger', 'Prepare error: ' . $mysqli->error);
        }

        $stmt->bind_param('isssi', $enabled, $start_time, $end_time, $message, $distributor_id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok) {
            redirectSelf('success', 'Order view time updated for selected distributor.');
        }

        redirectSelf('danger', 'Unable to update selected distributor.');
    } else {
        // Apply to all distributors.
        $stmt = $mysqli->prepare("\n            UPDATE distributors\n            SET order_view_enabled = ?,\n                order_view_start = ?,\n                order_view_end = ?,\n                order_view_message = ?,\n                order_view_updated_at = NOW()\n        ");

        if (!$stmt) {
            redirectSelf('danger', 'Prepare error: ' . $mysqli->error);
        }

        $stmt->bind_param('isss', $enabled, $start_time, $end_time, $message);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            redirectSelf('success', 'Order view time updated for all distributors.');
        }

        redirectSelf('danger', 'Unable to update all distributors.');
    }
}

/* =========================
   RESET ONE DISTRIBUTOR
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_id'])) {
    requirePermission('TIME_MANAGEMENT', 'edit');

    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        redirectSelf('danger', 'Invalid request token. Please try again.');
    }

    $reset_id = intval($_POST['reset_id']);

    if ($reset_id > 0) {
        $stmt = $mysqli->prepare("\n            UPDATE distributors\n            SET order_view_enabled = 1,\n                order_view_start = NULL,\n                order_view_end = NULL,\n                order_view_message = NULL,\n                order_view_updated_at = NOW()\n            WHERE distributor_id = ?\n            LIMIT 1\n        ");

        if ($stmt) {
            $stmt->bind_param('i', $reset_id);
            $stmt->execute();
            $stmt->close();
            redirectSelf('success', 'Selected distributor time setting reset.');
        }
    }

    redirectSelf('danger', 'Unable to reset distributor setting.');
}

/* =========================
   PAGE DATA
========================= */
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['filter_status'] ?? 'all';

$dropdownDistributors = $mysqli->query("\n    SELECT distributor_id, distributor_code, distributor_name, mobile_number\n    FROM distributors\n    ORDER BY distributor_name ASC\n");

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(distributor_name LIKE ? OR distributor_code LIKE ? OR mobile_number LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($filter_status === 'enabled') {
    $where[] = "order_view_enabled = 1";
} elseif ($filter_status === 'disabled') {
    $where[] = "order_view_enabled = 0";
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$listSql = "\n    SELECT\n        distributor_id,\n        distributor_code,\n        distributor_name,\n        mobile_number,\n        order_view_enabled,\n        order_view_start,\n        order_view_end,\n        order_view_message,\n        order_view_updated_at\n    FROM distributors\n    {$whereSql}\n    ORDER BY distributor_name ASC\n";

$listStmt = $mysqli->prepare($listSql);
if (!$listStmt) {
    die('List query error: ' . $mysqli->error);
}

if (!empty($params)) {
    $listStmt->bind_param($types, ...$params);
}

$listStmt->execute();
$distributorRows = $listStmt->get_result();

$totalDistributors = 0;
$enabledCount = 0;
$disabledCount = 0;
$timedCount = 0;

$countResult = $mysqli->query("\n    SELECT\n        COUNT(*) AS total_count,\n        SUM(CASE WHEN order_view_enabled = 1 THEN 1 ELSE 0 END) AS enabled_count,\n        SUM(CASE WHEN order_view_enabled = 0 THEN 1 ELSE 0 END) AS disabled_count,\n        SUM(CASE WHEN order_view_start IS NOT NULL AND order_view_end IS NOT NULL THEN 1 ELSE 0 END) AS timed_count\n    FROM distributors\n");

if ($countResult && $countRow = $countResult->fetch_assoc()) {
    $totalDistributors = (int)$countRow['total_count'];
    $enabledCount = (int)$countRow['enabled_count'];
    $disabledCount = (int)$countRow['disabled_count'];
    $timedCount = (int)$countRow['timed_count'];
}

include('include/header.php');
?>

<style>
body {
    background: #f5f6fa;
}

.page-wrap {
    padding: 18px;
}

.page-title-box,
.sa-card,
.summary-box {
    background: #ffffff;
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.06);
}

.page-title-box {
    padding: 16px 18px;
    margin-bottom: 18px;
}

.page-title-box h4 {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
}

.sa-card {
    overflow: hidden;
    margin-bottom: 20px;
}

.sa-card-header {
    padding: 15px 18px;
    border-bottom: 1px solid #eef0f4;
    background: #ffffff;
    font-weight: 700;
}

.sa-card-body {
    padding: 18px;
}

.form-label {
    font-size: 13px;
    font-weight: 600;
    color: #444;
}

.form-control,
.form-select {
    border-radius: 10px;
    min-height: 42px;
    font-size: 14px;
}

.btn {
    border-radius: 10px;
    font-weight: 600;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.summary-box {
    padding: 15px;
}

.summary-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
}

.summary-value {
    font-size: 22px;
    font-weight: 800;
    color: #111827;
    margin-top: 4px;
}

.help-box {
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 13px;
    color: #4b5563;
}

.time-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 10px;
    border-radius: 999px;
    background: #eef4ff;
    color: #0d6efd;
    font-weight: 700;
    font-size: 12px;
}

.message-text {
    max-width: 330px;
    white-space: normal;
    color: #4b5563;
}

.table thead th {
    font-size: 13px;
    white-space: nowrap;
}

.table td {
    font-size: 14px;
    vertical-align: middle;
}

@media (max-width: 992px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-wrap {
        padding: 12px;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .btn-mobile-full {
        width: 100%;
    }

    .table {
        min-width: 1050px;
    }
}
</style>

<div class="container-fluid page-wrap">

    <div class="page-title-box">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div>
                <h4>⏱️ Distributor Order Time Management</h4>
                <small class="text-muted">
                    Set order visibility time duration for all distributors or one selected distributor.
                </small>
            </div>
            <div>
                <a href="distributor_report.php" class="btn btn-outline-secondary btn-sm btn-mobile-full">← Back to Distributor Report</a>
            </div>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= cleanText($_GET['msg_type'] ?? 'info'); ?> alert-dismissible fade show" role="alert">
            <?= cleanText($_GET['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-box">
            <div class="summary-label">Total Distributors</div>
            <div class="summary-value"><?= number_format($totalDistributors); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Enabled</div>
            <div class="summary-value text-success"><?= number_format($enabledCount); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Disabled</div>
            <div class="summary-value text-danger"><?= number_format($disabledCount); ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Time Window Set</div>
            <div class="summary-value text-primary"><?= number_format($timedCount); ?></div>
        </div>
    </div>

    <?php if (hasPermission('TIME_MANAGEMENT', 'edit')): ?>
    <div class="sa-card">
        <div class="sa-card-header">Apply Order View Time</div>
        <div class="sa-card-body">
            <form method="POST" class="row g-3 align-items-end" onsubmit="return confirmApplySetting();">
                <?= csrfTokenField() ?>
                <input type="hidden" name="save_time_setting" value="1">

                <div class="col-md-3">
                    <label class="form-label">Distributor</label>
                    <select name="distributor_id" id="distributor_id" class="form-select">
                        <option value="0">All Distributors</option>
                        <?php if ($dropdownDistributors): ?>
                            <?php while ($d = $dropdownDistributors->fetch_assoc()): ?>
                                <option value="<?= intval($d['distributor_id']); ?>">
                                    <?= cleanText($d['distributor_name']); ?><?= !empty($d['distributor_code']) ? ' - ' . cleanText($d['distributor_code']) : ''; ?><?= !empty($d['mobile_number']) ? ' (' . cleanText($d['mobile_number']) . ')' : ''; ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Start Time</label>
                    <input type="time" name="order_view_start" class="form-control" value="09:00">
                </div>

                <div class="col-md-2">
                    <label class="form-label">End Time</label>
                    <input type="time" name="order_view_end" class="form-control" value="18:00">
                </div>

                <div class="col-md-2">
                    <label class="form-label d-block">Order View Status</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="order_view_enabled" id="order_view_enabled" checked>
                        <label class="form-check-label fw-semibold" for="order_view_enabled">Enabled</label>
                    </div>
                    <small class="text-muted">Turn off to block order view.</small>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Blocked Message</label>
                    <input type="text" name="order_view_message" class="form-control" maxlength="255" value="હાલમાં ઓર્ડર જોવા માટે સમય ઉપલબ્ધ નથી. કૃપા કરીને નક્કી કરેલા સમયમાં ફરીથી ચેક કરો.">
                </div>

                <div class="col-12">
                    <div class="help-box">
                        <strong>Logic:</strong> If distributor is selected, only that distributor will be updated. If “All Distributors” is selected, the same time duration will be applied to every distributor. Overnight time also works, for example 10:00 PM to 06:00 AM.
                    </div>
                </div>

                <div class="col-12 d-flex flex-column flex-md-row gap-2">
                    <button type="submit" class="btn btn-primary btn-mobile-full">Save Time Setting</button>
                    <a href="time_manage.php" class="btn btn-light border btn-mobile-full">Reset Form</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="sa-card">
        <div class="sa-card-header">Filter Current Distributor Settings</div>
        <div class="sa-card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Search Distributor</label>
                    <input type="text" name="search" class="form-control" value="<?= cleanText($search); ?>" placeholder="Search by name, code, or mobile">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="filter_status" class="form-select">
                        <option value="all" <?= ($filter_status === 'all') ? 'selected' : ''; ?>>All</option>
                        <option value="enabled" <?= ($filter_status === 'enabled') ? 'selected' : ''; ?>>Enabled</option>
                        <option value="disabled" <?= ($filter_status === 'disabled') ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                </div>

                <div class="col-md-4 d-flex flex-column flex-md-row gap-2">
                    <button type="submit" class="btn btn-primary btn-mobile-full">Apply Filter</button>
                    <a href="time_manage.php" class="btn btn-light border btn-mobile-full">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="sa-card">
        <div class="sa-card-header">Current Distributor Order Time Settings</div>
        <div class="sa-card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Distributor</th>
                            <th>Mobile</th>
                            <th>Status</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Message</th>
                            <th>Updated At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($distributorRows && $distributorRows->num_rows > 0): ?>
                            <?php $i = 1; while ($row = $distributorRows->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $i++; ?></td>
                                    <td>
                                        <strong><?= cleanText($row['distributor_name']); ?></strong><br>
                                        <small class="text-muted"><?= cleanText($row['distributor_code'] ?? '-'); ?></small>
                                    </td>
                                    <td><?= cleanText($row['mobile_number'] ?? '-'); ?></td>
                                    <td><?= yesNoBadge($row['order_view_enabled']); ?></td>
                                    <td><span class="time-pill"><?= cleanText(timeNice($row['order_view_start'])); ?></span></td>
                                    <td><span class="time-pill"><?= cleanText(timeNice($row['order_view_end'])); ?></span></td>
                                    <td><div class="message-text"><?= cleanText($row['order_view_message'] ?: '-'); ?></div></td>
                                    <td><?= !empty($row['order_view_updated_at']) ? cleanText(date('d M Y, h:i A', strtotime($row['order_view_updated_at']))) : '-'; ?></td>
                                    <td>
                                        <?php if (hasPermission('TIME_MANAGEMENT', 'edit')): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Reset this distributor setting?');">
                                                <?= csrfTokenField() ?>
                                                <input type="hidden" name="reset_id" value="<?= intval($row['distributor_id']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Reset</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No distributor found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
function confirmApplySetting() {
    const distributorSelect = document.getElementById('distributor_id');
    const selectedValue = distributorSelect ? distributorSelect.value : '0';

    if (selectedValue === '0') {
        return confirm('You selected All Distributors. This time setting will apply to every distributor. Continue?');
    }

    return confirm('This time setting will apply only to selected distributor. Continue?');
}
</script>

<?php
$listStmt->close();
include('include/footer.php');
?>

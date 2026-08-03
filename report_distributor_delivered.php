<?php
// FILE: report_distributor_delivered.php
// Requirements: Bootstrap 5 (CDN), your DB include returns $mysqli (mysqli)

require_once 'include/require_permission.php';
requirePermission('REPORT_DISTRIBUTOR_DELIVERED', 'view');
include('include/db.php');
date_default_timezone_set('Asia/Kolkata');

// -------- Inputs (GET) --------
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to   = $_GET['to']   ?? date('Y-m-d');              // inclusive to
$search = trim($_GET['q'] ?? '');
$export = isset($_GET['export']) ? 1 : 0;

if ($export) {
    requirePermission('REPORT_DISTRIBUTOR_DELIVERED', 'export');
}

// We’ll filter delivered orders by delivered_at if available, else distributor_status_at
// Adjust the COALESCE below if your column names differ.
$date_field_sql = "COALESCE(o.delivered_at, o.distributor_status_at)";

// Build WHERE for the orders side only (LEFT JOIN condition)
$whereParts = [];
$params = [];
$types = "";

// Date range (include full end day)
$whereParts[] = "$date_field_sql >= ? AND $date_field_sql < DATE_ADD(?, INTERVAL 1 DAY)";
$types .= "ss";
$params[] = $from;
$params[] = $to;

// Optional search on distributor name/code
if ($search !== '') {
    $whereParts[] = "(d.distributor_name LIKE ? OR d.distributor_code LIKE ?)";
    $types .= "ss";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}

// Final WHERE string to append inside JOIN condition
$whereSql = "";
if (!empty($whereParts)) {
    $whereSql = " AND " . implode(" AND ", $whereParts);
}

// -------- Main SQL --------
// Counts only delivered orders, filtered by date range (and optional search)
$sql = "
SELECT 
    d.distributor_id,
    d.distributor_code,
    d.distributor_name,
    COUNT(o.order_id) AS delivered_count
FROM distributors d
LEFT JOIN orders o
       ON d.distributor_id = o.distributor_id
      AND o.distributor_status = 'delivered'
      $whereSql
GROUP BY d.distributor_id, d.distributor_code, d.distributor_name
ORDER BY delivered_count DESC, d.distributor_name ASC
";

// Prepare + bind
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("Query prepare failed: " . $mysqli->error);
}
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Totals for KPI
$total_distributors = count($rows);
$total_delivered = 0;
foreach ($rows as $r) { $total_delivered += (int)$r['delivered_count']; }

// CSV export
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=distributor_delivered_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Distributor ID','Code','Distributor Name','Delivered Count','From','To','Generated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['distributor_id'],
            $r['distributor_code'],
            $r['distributor_name'],
            $r['delivered_count'],
            $from,
            $to,
            date('Y-m-d H:i:s')
        ]);
    }
    fclose($out);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Distributor Delivered Report</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb;}
    .card{border-radius:1rem;}
    .kpi-card{border:0; border-radius:1rem;}
    .kpi-num{font-size:1.5rem; font-weight:700;}
    .table thead th{white-space:nowrap;}
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h3 class="mb-2">Distributor Delivered Report</h3>
    <div class="text-muted small">
      Period: <strong><?php echo htmlspecialchars($from); ?></strong> to <strong><?php echo htmlspecialchars($to); ?></strong>
    </div>
  </div>

  <!-- Filters -->
  <form class="card p-3 mb-4" method="get" action="">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($from); ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($to); ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Search Distributor (name/code)</label>
        <input type="text" class="form-control" name="q" placeholder="e.g., Rahul / D-1002" value="<?php echo htmlspecialchars($search); ?>">
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-primary" type="submit">Apply</button>
      </div>
    </div>
    <div class="mt-2 d-flex gap-2">
      <a class="btn btn-outline-secondary" href="report_distributor_delivered.php">Reset</a>
      <a class="btn btn-success" href="?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&q=<?php echo urlencode($search); ?>&export=1">Export CSV</a>
    </div>
  </form>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="card kpi-card shadow-sm p-3">
        <div class="text-muted">Total Distributors</div>
        <div class="kpi-num"><?php echo number_format($total_distributors); ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card kpi-card shadow-sm p-3">
        <div class="text-muted">Total Delivered Orders</div>
        <div class="kpi-num"><?php echo number_format($total_delivered); ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card kpi-card shadow-sm p-3">
        <div class="text-muted">Avg Delivered / Distributor</div>
        <div class="kpi-num">
          <?php echo $total_distributors ? number_format($total_delivered / $total_distributors, 2) : '0.00'; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Table -->
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table align-middle table-hover">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Distributor</th>
              <th>Code</th>
              <th class="text-end">Delivered Count</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No data for selected filters.</td></tr>
          <?php else: 
                $i=1;
                foreach ($rows as $r): ?>
            <tr>
              <td><?php echo $i++; ?></td>
              <td><?php echo htmlspecialchars($r['distributor_name']); ?></td>
              <td><?php echo htmlspecialchars($r['distributor_code']); ?></td>
              <td class="text-end fw-semibold"><?php echo number_format($r['delivered_count']); ?></td>
              <td>
                <!-- Example deep link; change the target to your page -->
                <a class="btn btn-sm btn-outline-primary"
                   href="order_list.php?distributor_id=<?php echo (int)$r['distributor_id']; ?>&status=delivered&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>">
                  View Orders
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <p class="text-muted small mt-3 mb-0">
    * Filter applies to delivered orders using <code>COALESCE(o.delivered_at, o.distributor_status_at)</code>. Adjust if your schema differs.
  </p>
</div>
</body>
</html>

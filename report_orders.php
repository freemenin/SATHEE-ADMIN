<?php
// FILE: report_orders.php
// Requires: include/db.php => $mysqli (MySQLi connection)
// Works on MySQL 5.7+ (no CTEs). TZ: Asia/Kolkata.

declare(strict_types=1);
require_once __DIR__ . '/include/require_permission.php';
requirePermission('REPORT_ORDERS', 'view');
date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/include/db.php'; // $mysqli

// --------- Filters ----------
$df   = $_GET['from'] ?? date('Y-m-01'); // default: 1st of this month
$dt   = $_GET['to']   ?? date('Y-m-d');  // default: today
$dist = isset($_GET['distributor_id']) && $_GET['distributor_id'] !== '' ? (int)$_GET['distributor_id'] : null;

// Distributor dropdown data
$dists = [];
if ($res = $mysqli->query("SELECT distributor_id, distributor_name FROM distributors ORDER BY distributor_name")) {
  while ($row = $res->fetch_assoc()) $dists[] = $row;
  $res->free();
}

// Helper: seconds → "Xd Yh Zm"
function fmt_dhms($seconds) {
  if ($seconds === null || $seconds === '' ) return '—';
  $s = (int)$seconds;
  $d = intdiv($s, 86400); $s %= 86400;
  $h = intdiv($s, 3600);  $s %= 3600;
  $m = intdiv($s, 60);
  $parts = [];
  if ($d) $parts[] = "{$d}d";
  if ($h) $parts[] = "{$h}h";
  if ($m || !$parts) $parts[] = "{$m}m";
  return implode(' ', $parts);
}

// Common WHERE (use created_at; switch to order_date if you prefer)
$where = "o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
$params = [$df, $dt]; $types = "ss";
if ($dist) { $where .= " AND o.distributor_id = ?"; $params[] = $dist; $types .= "i"; }

// --------- 1) Orders Summary ----------
$sql_summary = "
SELECT
  COUNT(*) AS total_orders,
  SUM(CASE WHEN (o.order_status='pending' OR o.distributor_status IN ('pending','accepted','ofd') OR o.distributor_status IS NULL) THEN 1 ELSE 0 END) AS total_pending,
  SUM(CASE WHEN o.distributor_status='delivered' THEN 1 ELSE 0 END) AS total_delivered,
  SUM(CASE WHEN o.order_status='cancelled' THEN 1 ELSE 0 END) AS total_cancelled,
  ROUND(COALESCE(SUM(o.grand_total),0),2) AS total_revenue
FROM orders o
WHERE $where
";
$st = $mysqli->prepare($sql_summary);
$st->bind_param($types, ...$params);
$st->execute();
$sum = $st->get_result()->fetch_assoc() ?: ['total_orders'=>0,'total_pending'=>0,'total_delivered'=>0,'total_cancelled'=>0,'total_revenue'=>0];
$st->close();

// --------- 2) Daily Trend (orders placed vs delivered) - MySQL 5.7 safe ----------
$sql_daily = "
SELECT
  x.day,
  COALESCE(x.orders_placed,0)    AS orders_placed,
  COALESCE(y.orders_delivered,0) AS orders_delivered
FROM (
  SELECT DATE(o.created_at) AS day, COUNT(*) AS orders_placed
  FROM orders o
  WHERE o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)
    " . ($dist ? " AND o.distributor_id = ?" : "") . "
  GROUP BY DATE(o.created_at)
) x
LEFT JOIN (
  SELECT DATE(o.distributor_status_at) AS day, COUNT(*) AS orders_delivered
  FROM orders o
  WHERE o.distributor_status='delivered'
    AND o.distributor_status_at >= ? AND o.distributor_status_at < DATE_ADD(?, INTERVAL 1 DAY)
    " . ($dist ? " AND o.distributor_id = ?" : "") . "
  GROUP BY DATE(o.distributor_status_at)
) y ON y.day = x.day
ORDER BY x.day;
";
if ($dist) {
  $st = $mysqli->prepare($sql_daily);
  // (created df, created dt, dist) (deliv df, deliv dt, dist)
  $st->bind_param("ssissis", $df, $dt, $dist, $df, $dt, $dist);
} else {
  $st = $mysqli->prepare($sql_daily);
  // (created df, created dt) (deliv df, deliv dt)
  $st->bind_param("ssss", $df, $dt, $df, $dt);
}
$st->execute();
$daily = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// --------- 3) TAT (assign → delivered) avg + median (median via GROUP_CONCAT fallback) ----------
$sql_tat_avg = "
SELECT
  AVG(TIMESTAMPDIFF(SECOND, o.distributor_assigned_at, o.distributor_status_at)) AS avg_s
FROM orders o
WHERE $where
  AND o.distributor_status='delivered'
  AND o.distributor_assigned_at IS NOT NULL
  AND o.distributor_status_at IS NOT NULL
";
$st = $mysqli->prepare($sql_tat_avg);
$st->bind_param($types, ...$params);
$st->execute();
$tat_avg = $st->get_result()->fetch_assoc()['avg_s'] ?? null;
$st->close();

// Approx median (works on 5.7)
$sql_tat_med = "
SELECT 
  SUBSTRING_INDEX(
    SUBSTRING_INDEX(GROUP_CONCAT(tt ORDER BY tt SEPARATOR ','), ',', FLOOR((COUNT(*)+1)/2)), 
  ',', -1) AS median_s
FROM (
  SELECT TIMESTAMPDIFF(SECOND, o.distributor_assigned_at, o.distributor_status_at) AS tt
  FROM orders o
  WHERE $where
    AND o.distributor_status='delivered'
    AND o.distributor_assigned_at IS NOT NULL
    AND o.distributor_status_at IS NOT NULL
) t
";
$st = $mysqli->prepare($sql_tat_med);
$st->bind_param($types, ...$params);
$st->execute();
$tat_med = $st->get_result()->fetch_assoc()['median_s'] ?? null;
$st->close();

// --------- 4) Aging (pending by buckets) ----------
$sql_age = "
SELECT bucket, COUNT(*) AS cnt FROM (
  SELECT
    CASE
      WHEN hours_wait <= 24 THEN '0–24h'
      WHEN hours_wait <= 72 THEN '25–72h'
      WHEN hours_wait <= 168 THEN '3–7d'
      ELSE '>7d'
    END AS bucket
  FROM (
    SELECT
      TIMESTAMPDIFF(HOUR, COALESCE(o.distributor_assigned_at, o.created_at), NOW()) AS hours_wait
    FROM orders o
    WHERE $where
      AND (o.distributor_status IS NULL OR o.distributor_status NOT IN ('delivered','cancelled'))
  ) x
) y
GROUP BY bucket
ORDER BY FIELD(bucket,'0–24h','25–72h','3–7d','>7d');
";
$st = $mysqli->prepare($sql_age);
$st->bind_param($types, ...$params);
$st->execute();
$aging = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// --------- 5) Distributor Performance (safe bindings) ----------
// --------- 5) Distributor Performance (safe bindings) ----------
$SLA_HOURS = 48; // change as needed

$sql_dist = "
SELECT
  d.distributor_id,
  d.distributor_name,
  COALESCE(SUM(CASE WHEN o.distributor_status='delivered' THEN 1 ELSE 0 END),0) AS delivered,
  ROUND(AVG(CASE WHEN o.distributor_status='delivered' 
                 AND o.distributor_assigned_at IS NOT NULL 
                 AND o.distributor_status_at IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, o.distributor_assigned_at, o.distributor_status_at) END)) AS avg_tat_s,
  ROUND(
    100 * 
    COALESCE(SUM(CASE WHEN o.distributor_status='delivered' 
                       AND o.distributor_assigned_at IS NOT NULL 
                       AND o.distributor_status_at IS NOT NULL
                       AND TIMESTAMPDIFF(HOUR, o.distributor_assigned_at, o.distributor_status_at) <= ?
                 THEN 1 ELSE 0 END),0)
    / NULLIF(SUM(CASE WHEN o.distributor_status='delivered' THEN 1 ELSE 0 END),0)
  ,2) AS on_time_pct
FROM distributors d
LEFT JOIN (
  SELECT * FROM orders 
  WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
  " . ($dist ? " AND distributor_id = ?" : "") . "
) o
ON o.distributor_id = d.distributor_id
GROUP BY d.distributor_id, d.distributor_name
-- Sort: delivered (desc), then put NULL on_time_pct last, then on_time_pct (desc)
ORDER BY delivered DESC, (on_time_pct IS NULL) ASC, on_time_pct DESC
";

if ($dist) {
  $st = $mysqli->prepare($sql_dist);
  $st->bind_param("issi", $SLA_HOURS, $df, $dt, $dist);
} else {
  $st = $mysqli->prepare($sql_dist);
  $st->bind_param("iss", $SLA_HOURS, $df, $dt);
}
$st->execute();
$distperf = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

// --------- 6) Customer Order History (new vs repeat) ----------
$sql_cust = "
SELECT
  SUM(CASE WHEN first_order_date >= ? AND first_order_date < DATE_ADD(?, INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS new_customers,
  SUM(CASE WHEN first_order_date < ? THEN 1 ELSE 0 END) AS repeat_customers
FROM (
  SELECT c.customer_id, MIN(o.created_at) AS first_order_date
  FROM customers c
  JOIN orders o ON o.customer_id = c.customer_id
  GROUP BY c.customer_id
) z
";
$st = $mysqli->prepare($sql_cust);
$st->bind_param("sss", $df, $dt, $df);
$st->execute();
$cust = $st->get_result()->fetch_assoc() ?: ['new_customers'=>0,'repeat_customers'=>0];
$st->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Order & Delivery Reports</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f7fb;}
    .card{border-radius:1rem;}
    .kpi{font-weight:700;font-size:1.25rem;}
    .muted{color:#6c757d;}
    .table thead th{white-space:nowrap;}
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">📦 Order & Delivery Reports</h4>
  </div>

  <!-- Filters -->
  <form class="card mb-4 p-3" method="get">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($df) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($dt) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Distributor (optional)</label>
        <select name="distributor_id" class="form-select">
          <option value="">— All —</option>
          <?php foreach($dists as $d): ?>
            <option value="<?= (int)$d['distributor_id'] ?>" <?= $dist===(int)$d['distributor_id']?'selected':'' ?>>
              <?= htmlspecialchars($d['distributor_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary w-100">Apply</button>
      </div>
    </div>
  </form>

  <!-- KPI Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="card p-3 h-100">
        <div class="muted">Total Orders</div>
        <div class="kpi"><?= (int)$sum['total_orders'] ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3 h-100">
        <div class="muted">Delivered</div>
        <div class="kpi"><?= (int)$sum['total_delivered'] ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3 h-100">
        <div class="muted">Pending</div>
        <div class="kpi"><?= (int)$sum['total_pending'] ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card p-3 h-100">
        <div class="muted">Revenue (₹)</div>
        <div class="kpi"><?= number_format((float)$sum['total_revenue'],2) ?></div>
      </div>
    </div>
  </div>

  <!-- TAT + Aging -->
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card p-3 h-100">
        <div class="d-flex justify-content-between">
          <h6 class="mb-2">Avg / Median Delivery TAT</h6>
        </div>
        <div>Average: <strong><?= fmt_dhms($tat_avg) ?></strong></div>
        <div>Median: <strong><?= fmt_dhms($tat_med) ?></strong></div>
        <small class="text-muted">From <code>distributor_assigned_at</code> → <code>distributor_status_at</code></small>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-3 h-100">
        <h6 class="mb-2">Pending Orders Aging</h6>
        <table class="table table-sm mb-0">
          <thead><tr><th>Bucket</th><th class="text-end">Count</th></tr></thead>
          <tbody>
            <?php foreach($aging as $a): ?>
              <tr>
                <td><?= htmlspecialchars($a['bucket']) ?></td>
                <td class="text-end"><?= (int)$a['cnt'] ?></td>
              </tr>
            <?php endforeach; if(empty($aging)): ?>
              <tr><td colspan="2" class="text-center text-muted">No pending orders</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Daily Trend -->
  <div class="card p-3 mb-4">
    <h6 class="mb-2">Daily Trend (Orders vs Delivered)</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th class="text-end">Orders</th>
            <th class="text-end">Delivered</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($daily as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['day']) ?></td>
              <td class="text-end"><?= (int)$row['orders_placed'] ?></td>
              <td class="text-end"><?= (int)$row['orders_delivered'] ?></td>
            </tr>
          <?php endforeach; if(empty($daily)): ?>
            <tr><td colspan="3" class="text-center text-muted">No data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <small class="text-muted">Note: shows only dates with data (MySQL 5.7-compatible). If you want zero-fill days with no data, I can give you a small <code>calendar</code> table version.</small>
  </div>

  <!-- Distributor Performance -->
  <div class="card p-3 mb-4">
    <h6 class="mb-2">Distributor Performance (SLA ≤ <?= (int)$SLA_HOURS ?>h)</h6>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Distributor</th>
            <th class="text-end">Delivered</th>
            <th class="text-end">Avg TAT</th>
            <th class="text-end">On-time %</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($distperf as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['distributor_name']) ?></td>
              <td class="text-end"><?= (int)$r['delivered'] ?></td>
              <td class="text-end"><?= fmt_dhms($r['avg_tat_s']) ?></td>
              <td class="text-end"><?= is_null($r['on_time_pct']) ? '—' : number_format((float)$r['on_time_pct'],2).'%' ?></td>
            </tr>
          <?php endforeach; if(empty($distperf)): ?>
            <tr><td colspan="4" class="text-center text-muted">No data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Customer Order History -->
  <div class="row g-3 mb-5">
    <div class="col-md-6">
      <div class="card p-3 h-100">
        <h6 class="mb-2">Customers (New vs Repeat)</h6>
        <div>New in range: <strong><?= (int)$cust['new_customers'] ?></strong></div>
        <div>Repeat (1st order before range): <strong><?= (int)$cust['repeat_customers'] ?></strong></div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card p-3 h-100">
        <h6 class="mb-2">Export</h6>
        <span class="text-muted small">CSV export not yet available.</span>
      </div>
    </div>
  </div>

</div>
</body>
</html>

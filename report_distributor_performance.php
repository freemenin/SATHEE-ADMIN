<?php
// FILE: report_distributor_performance.php
require_once 'include/require_permission.php';
requirePermission('REPORT_DISTRIBUTOR_PERFORMANCE', 'view');
include('include/db.php');
include('include/header.php');
date_default_timezone_set('Asia/Kolkata');

/**
 * Filters (GET)
 * - from/to   : report range
 * - q         : distributor search
 * - export    : csv export flag
 * - top_n     : how many in Top list (default 5)
 */
$from   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to     = $_GET['to']   ?? date('Y-m-d'); // inclusive end day
$fromd   = $_GET['from'] ?? date('d-m-Y', strtotime('-30 days'));
$tod     = $_GET['to']   ?? date('d-m-Y'); // inclusive end day
$q      = trim($_GET['q'] ?? '');
$export = isset($_GET['export']) ? 1 : 0;
$top_n  = max(1, (int)($_GET['top_n'] ?? 5));

// choose date field for delivered filtering & avg time
// If you only have distributor_status_at for delivered, this COALESCE keeps it working.
$date_delivered_sql = "COALESCE(o.delivered_at, o.distributor_status_at)";

// Build WHERE that applies *inside* our LEFT JOIN (so distributors with 0 still show)
$whereParts = [];
$types = "";
$params = [];

// limit by delivered window (but note: other statuses are *not* date limited this way)
// To make the range apply to *all* counts, push the date check out of the delivered-only filter
// and into each CASE WHEN below (done later). For accuracy, we apply date range to all statuses.
$whereDistributor = ""; // optional name/code filter later

if ($q !== "") {
    $whereDistributor = " AND (d.distributor_name LIKE ? OR d.distributor_code LIKE ?)";
    $types .= "ss";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
}

// We will compute counts with CASE WHEN and include date range inside each CASE WHEN.
// Build safe date params once.
$date_types = "ss";  // from, to
$date_params = [$from, $to];

/** -------------------------
 * MAIN PER-DISTRIBUTOR QUERY
 * - All counts date-bounded by order_date between [from, to + 1 day)
 * - Delivery rate: Delivered / (Delivered + Cancelled + Returned)
 * - Avg delivery minutes: AVG(TIMESTAMPDIFF(MINUTE, order_date, delivered_at))
 * -------------------------- */
$sql = "
SELECT
  d.distributor_id,
  d.distributor_code,
  d.distributor_name,

  /* Delivered in range */
  SUM(
    CASE 
      WHEN o.distributor_status = 'delivered'
       AND $date_delivered_sql >= ? AND $date_delivered_sql < DATE_ADD(?, INTERVAL 1 DAY)
      THEN 1 ELSE 0
    END
  ) AS delivered_count,

  /* Pending in range: pending by status */
  SUM(
    CASE 
      WHEN o.distributor_status = 'pending'
       AND o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)
      THEN 1 ELSE 0
    END
  ) AS pending_count,

  /* Assigned (accepted/ofd/etc.) in range */
  SUM(
    CASE 
      WHEN o.distributor_status IN ('assigned','accepted','ofd')
       AND o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)
      THEN 1 ELSE 0
    END
  ) AS assigned_count,

  /* Cancelled in range */
  SUM(
    CASE 
      WHEN o.distributor_status = 'accepted'
       AND o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)
      THEN 1 ELSE 0
    END
  ) AS cancelled_count,

  /* Returned in range */
  SUM(
    CASE 
      WHEN o.distributor_status = 'accepted'
       AND o.order_date >= ? AND o.order_date < DATE_ADD(?, INTERVAL 1 DAY)
      THEN 1 ELSE 0
    END
  ) AS accepted_count,

  /* Avg delivery time (minutes) in range, only for delivered rows */
  AVG(
    CASE 
      WHEN o.distributor_status = 'delivered'
       AND $date_delivered_sql >= ? AND $date_delivered_sql < DATE_ADD(?, INTERVAL 1 DAY)
       AND o.created_at IS NOT NULL
      THEN TIMESTAMPDIFF(MINUTE, o.created_at, $date_delivered_sql)
      ELSE NULL
    END
  ) AS avg_delivery_minutes

FROM distributors d
LEFT JOIN orders o
       ON d.distributor_id = o.distributor_id
WHERE 1=1
$whereDistributor
GROUP BY d.distributor_id, d.distributor_code, d.distributor_name
ORDER BY delivered_count DESC, d.distributor_name ASC
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $mysqli->error);
}

// Bind order: delivered dates, pending dates, assigned dates, cancelled dates, returned dates, avg dates + optional distributor filter
$bind_types = "ss"  // for delivered count range
            . "ss"  // pending
            . "ss"  // assigned
            . "ss"  // cancelled
            . "ss"  // returned
            . "ss"; // avg delivery minutes
$bind_params = [
    $from, $to,
    $from, $to,
    $from, $to,
    $from, $to,
    $from, $to,
    $from, $to
];
// add distributor filter params if any
if ($q !== "") {
    $bind_types .= $types;
    $bind_params = array_merge($bind_params, $params);
}

$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Totals + derived metrics */
$total_distributors = count($rows);
$tot_delivered = $tot_pending = $tot_assigned = $tot_cancelled = $tot_returned = 0;
foreach ($rows as &$r) {
    $r['delivered_count'] = (int)$r['delivered_count'];
    $r['pending_count']   = (int)$r['pending_count'];
    $r['assigned_count']  = (int)$r['assigned_count'];
    $r['cancelled_count'] = (int)$r['cancelled_count'];
    $r['accepted_count']  = (int)$r['accepted_count'];

    $denom = $r['delivered_count'] + $r['cancelled_count'] + $r['accepted_count'] + $r['assigned_count'] + $r['pending_count'];
    $r['delivery_rate'] = $denom > 0 ? ($r['delivered_count'] / $denom) * 100.0 : 0.0;

    // avg delivery time to pretty text (d h m)
    $avg_min = is_null($r['avg_delivery_minutes']) ? null : (int)round($r['avg_delivery_minutes']);
    if ($avg_min !== null) {
        $days = intdiv($avg_min, 1440);
        $rem  = $avg_min % 1440;
        $hrs  = intdiv($rem, 60);
        $mins = $rem % 60;
        $r['avg_delivery_human'] =
            ($days ? $days.'d ' : '') . ($hrs ? $hrs.'h ' : '') . ($mins ? $mins.'m' : ($days||$hrs ? '' : '0m'));
    } else {
        $r['avg_delivery_human'] = '—';
    }

    $tot_delivered += $r['delivered_count'];
    $tot_pending   += $r['pending_count'];
    $tot_assigned  += $r['assigned_count'];
    $tot_cancelled += $r['cancelled_count'];
    $tot_returned  += $r['accepted_count'];
}
unset($r);

// CSV export
if ($export) {
    requirePermission('REPORT_DISTRIBUTOR_PERFORMANCE', 'export');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=distributor_performance_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Distributor ID','Code','Distributor Name','Delivered','Pending','Assigned','Cancelled','Returned','Delivery Rate %','Avg Delivery Time','From','To','Generated At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['distributor_id'],
            $r['distributor_code'],
            $r['distributor_name'],
            $r['delivered_count'],
            $r['pending_count'],
            $r['assigned_count'],
            $r['cancelled_count'],
            $r['accepted_count'],
            number_format($r['delivery_rate'], 2),
            $r['avg_delivery_human'],
            $from, $to, date('Y-m-d H:i:s')
        ]);
    }
    fclose($out);
    exit;
}

/* -------------------------
   TOP 5 WIDGETS (Today/Week/Month)
   ------------------------- */
function getTopN($mysqli, $n, $period, $whereDistributor, $types, $params) {
    // Resolve period boundaries
    $today = new DateTime('today', new DateTimeZone('Asia/Kolkata'));
    $start = null; $end = new DateTime('tomorrow', new DateTimeZone('Asia/Kolkata')); // exclusive

    if ($period === 'today') {
        $start = clone $today;
    } elseif ($period === 'week') {
        // Monday as start of week
        $dow = (int)$today->format('N'); // 1..7
        $start = (clone $today)->modify('-'.($dow-1).' days');
    } else { // month
        $start = new DateTime($today->format('Y-m-01'), new DateTimeZone('Asia/Kolkata'));
    }

    $sql = "
      SELECT d.distributor_name, d.distributor_code,
             COUNT(o.order_id) AS delivered_count
      FROM distributors d
      LEFT JOIN orders o
        ON d.distributor_id = o.distributor_id
       AND o.distributor_status = 'delivered'
       AND COALESCE(o.delivered_at, o.distributor_status_at) >= ?
       AND COALESCE(o.delivered_at, o.distributor_status_at) < ?
      WHERE 1=1
      $whereDistributor
      GROUP BY d.distributor_id, d.distributor_name, d.distributor_code
      ORDER BY delivered_count DESC, d.distributor_name ASC
      LIMIT ?
    ";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return [];

    $bind_types = "ss";
    $bind_params = [$start->format('Y-m-d'), $end->format('Y-m-d')];
    if ($whereDistributor) {
        $bind_types .= $types;
        $bind_params = array_merge($bind_params, $params);
    }
    $bind_types .= "i";
    $bind_params[] = $n;

    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$top_today  = getTopN($mysqli, $top_n, 'today', $whereDistributor, $types, $params ?? []);
$top_week   = getTopN($mysqli, $top_n, 'week',  $whereDistributor, $types, $params ?? []);
$top_month  = getTopN($mysqli, $top_n, 'month', $whereDistributor, $types, $params ?? []);

?>
  <style>
    body{background:#f6f7fb;}
    .card{border-radius:1rem;}
    .kpi-num{font-size:1.5rem; font-weight:700;}
    .rate-badge{font-weight:600;}
    .rate-good{background:#e8f7ec;color:#137333;}
    .rate-mid{background:#fff7e6;color:#9a6700;}
    .rate-bad{background:#fde8e8;color:#a50e0e;}
    @media print {
      .no-print {display:none !important;}
      body {background:#fff;}
      .card {box-shadow:none; border:1px solid #ccc;}
    }
  </style>
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
  </div>

  <!-- Filters -->
  <form class="card p-3 mb-4 no-print" method="get" action="">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?php echo htmlspecialchars($from); ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?php echo htmlspecialchars($to); ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Search Distributor (name/code)</label>
        <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="e.g., Rahul / D-1002">
      </div>
      <div class="col-md-2">
        <label class="form-label">Top N</label>
        <input type="number" class="form-control" name="top_n" min="1" max="20" value="<?php echo (int)$top_n; ?>">
      </div>
      <div class="col-md-1 d-grid">
        <button class="btn btn-primary" type="submit">Apply</button>
      </div>
    </div>
    <div class="mt-2 d-flex gap-2">
      <a class="btn btn-outline-secondary" href="report_distributor_performance.php">Reset</a>
      <a class="btn btn-success" href="?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&q=<?php echo urlencode($q); ?>&top_n=<?php echo (int)$top_n; ?>&export=1">Export CSV</a>
      <button type="button" onclick="window.print()" class="btn btn-outline-dark">Print</button>
    </div>
  </form>

  <!-- KPI Row -->
  <div class="row g-3 mb-4">
    <div class="col-md-2"><div class="card p-3"><div class="text-muted">Distributors</div><div class="kpi-num"><?php echo number_format($total_distributors); ?></div></div></div>
    <div class="col-md-2"><div class="card p-3"><div class="text-muted">Delivered</div><div class="kpi-num"><?php echo number_format($tot_delivered); ?></div></div></div>
    <div class="col-md-2"><div class="card p-3"><div class="text-muted">Pending</div><div class="kpi-num"><?php echo number_format($tot_pending); ?></div></div></div>
    <div class="col-md-2"><div class="card p-3"><div class="text-muted">Assigned</div><div class="kpi-num"><?php echo number_format($tot_assigned); ?></div></div></div>
    <div class="col-md-2"><div class="card p-3"><div class="text-muted">Cancelled</div><div class="kpi-num"><?php echo number_format($tot_cancelled); ?></div></div></div>
    <div class="col-md-2"><div class="card p-3"><div class="text-muted">Returned</div><div class="kpi-num"><?php echo number_format($tot_returned); ?></div></div></div>
  </div>

  <!-- Top N Widgets -->
  <div class="row g-3 mb-4">
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header bg-light fw-semibold">Top <?php echo (int)$top_n; ?> Today</div>
        <div class="card-body">
          <?php if (!$top_today): ?><div class="text-muted">No data.</div><?php else: ?>
          <ol class="mb-0">
            <?php foreach ($top_today as $t): ?>
            <li><?php echo htmlspecialchars($t['distributor_name']); ?> <span class="text-muted">[<?php echo htmlspecialchars($t['distributor_code']); ?>]</span> — <strong><?php echo (int)$t['delivered_count']; ?></strong></li>
            <?php endforeach; ?>
          </ol>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header bg-light fw-semibold">Top <?php echo (int)$top_n; ?> This Week</div>
        <div class="card-body">
          <?php if (!$top_week): ?><div class="text-muted">No data.</div><?php else: ?>
          <ol class="mb-0">
            <?php foreach ($top_week as $t): ?>
            <li><?php echo htmlspecialchars($t['distributor_name']); ?> <span class="text-muted">[<?php echo htmlspecialchars($t['distributor_code']); ?>]</span> — <strong><?php echo (int)$t['delivered_count']; ?></strong></li>
            <?php endforeach; ?>
          </ol>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header bg-light fw-semibold">Top <?php echo (int)$top_n; ?> This Month</div>
        <div class="card-body">
          <?php if (!$top_month): ?><div class="text-muted">No data.</div><?php else: ?>
          <ol class="mb-0">
            <?php foreach ($top_month as $t): ?>
            <li><?php echo htmlspecialchars($t['distributor_name']); ?> <span class="text-muted">[<?php echo htmlspecialchars($t['distributor_code']); ?>]</span> — <strong><?php echo (int)$t['delivered_count']; ?></strong></li>
            <?php endforeach; ?>
          </ol>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Table -->
  <div class="card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table align-middle table-hover">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Distributor</th>
              <th>Code</th>
              <th class="text-end">Delivered</th>
              <th class="text-end">Pending</th>
              <th class="text-end">Assigned</th>
              <th class="text-end">Cancelled</th>
              <th class="text-end">Accepted</th>
              <th class="text-center">Delivery Rate</th>
              <th class="text-center">Avg Delivery Time</th>
              <th class="no-print">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="11" class="text-center text-muted py-4">No data for selected filters.</td></tr>
            <?php else: $i=1; foreach($rows as $r): 
              $rateClass = $r['delivery_rate'] >= 85 ? 'rate-good' : ($r['delivery_rate'] >= 60 ? 'rate-mid' : 'rate-bad');
            ?>
              <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($r['distributor_name']); ?></td>
                <td><?php echo htmlspecialchars($r['distributor_code']); ?></td>
                <td class="text-end fw-semibold"><?php echo number_format($r['delivered_count']); ?></td>
                <td class="text-end"><?php echo number_format($r['pending_count']); ?></td>
                <td class="text-end"><?php echo number_format($r['assigned_count']); ?></td>
                <td class="text-end"><?php echo number_format($r['cancelled_count']); ?></td>
                <td class="text-end"><?php echo number_format($r['accepted_count']); ?></td>
                <td class="text-center">
                  <span class="badge rate-badge <?php echo $rateClass; ?>">
                    <?php echo number_format($r['delivery_rate'], 1); ?>%
                  </span>
                </td>
                <td class="text-center"><?php echo htmlspecialchars($r['avg_delivery_human']); ?></td>
                <td class="no-print">
                  <a class="btn btn-sm btn-outline-primary"
                     href="distributor_view.php?id=<?php echo (int)$r['distributor_id']; ?>&status=delivered&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>">
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

  <p class="text-muted small mt-3">
    * Delivery rate = Delivered / (Delivered + Cancelled + Returned) within the selected date range.  
    * Avg Delivery Time uses <code>TIMESTAMPDIFF(MINUTE, order_date, COALESCE(delivered_at, distributor_status_at))</code> for delivered orders only.
  </p>
</div>
<?php include('include/footer.php'); ?>


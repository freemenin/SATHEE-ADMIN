<?php
// FILE: distributor_view.php
require_once 'include/require_permission.php';
requirePermission('DISTRIBUTORS', 'view');
include('include/require_login.php');
include('include/db.php');
include('include/header.php');

date_default_timezone_set('Asia/Kolkata');

// --- Input & basics ---
$distributor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($distributor_id <= 0) {
  http_response_code(400);
  echo '<div class="container py-5"><div class="alert alert-warning">Invalid distributor ID.</div></div>';
  include('include/footer.php'); exit;
}

// --- Fetch distributor info ---
$dist = null;
$stmt = $mysqli->prepare("
  SELECT distributor_id, distributor_code, distributor_name, contact_person,
         mobile_number, email, address, created_at
  FROM distributors
  WHERE distributor_id = ?
  LIMIT 1
");
$stmt->bind_param("i", $distributor_id);
$stmt->execute();
$res = $stmt->get_result();
$dist = $res->fetch_assoc();
$stmt->close();

if (!$dist) {
  echo '<div class="container py-5"><div class="alert alert-warning">Distributor not found.</div></div>';
  include('include/footer.php'); exit;
}

// --- Counts: assigned, delivered, pending ---
$excludeFromPending = ['delivered','cancelled','canceled','not_my_area','rto','on_hold'];
$placeholders = implode(",", array_fill(0, count($excludeFromPending), '?'));

$types = "i" . str_repeat("s", count($excludeFromPending));

// total assigned = all orders tied to this distributor
$sqlCounts = "
  SELECT
    COUNT(*) AS total_assigned,
    SUM(CASE WHEN o.distributor_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
    SUM(
      CASE
        WHEN o.distributor_status NOT IN ($placeholders) THEN 1 ELSE 0
      END
    ) AS pending_count
  FROM orders o
  WHERE o.distributor_id = ?
";
// We need distributor_id at the end; easier: rebuild to match types order
$sqlCounts = "
  SELECT
    COUNT(*) AS total_assigned,
    SUM(CASE WHEN o.distributor_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
    SUM(CASE WHEN o.distributor_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
    SUM(CASE WHEN o.distributor_status NOT IN ($placeholders) THEN 1 ELSE 0 END) AS pending_count
  FROM orders o
  WHERE o.distributor_id = ?
";

$stmt = $mysqli->prepare($sqlCounts);

// bind: first the NOT IN list, then distributor_id
$params = $excludeFromPending;
$params[] = $distributor_id;

// dynamic bind helper
$bindNames[] = $types;
foreach ($params as $k => $p) { $bindNames[] = &$params[$k]; }
call_user_func_array([$stmt, 'bind_param'], $bindNames);

$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_assigned  = (int)($counts['total_assigned'] ?? 0);
$delivered_count = (int)($counts['delivered_count'] ?? 0);
$cancelled_count = (int)($counts['cancelled_count'] ?? 0);
$pending_count   = $total_assigned-($cancelled_count+$delivered_count);

// --- Orders list with pagination ---
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// total rows for pagination (reuse simpler count)
$stmt = $mysqli->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE distributor_id = ?");
$stmt->bind_param("i", $distributor_id);
$stmt->execute();
$totalRows = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

$stmt = $mysqli->prepare("
  SELECT
    o.order_id, o.invoice_number, o.order_date, o.created_at,
    o.order_status, o.delivery_status, o.distributor_status,
    o.subtotal, o.tax, o.discount, o.grand_total,
    c.full_name, c.mobile_number
  FROM orders o
  LEFT JOIN customers c ON c.customer_id = o.customer_id
  WHERE o.distributor_id = ?
  ORDER BY o.order_id DESC
  LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $distributor_id, $perPage, $offset);
$stmt->execute();
$orders = $stmt->get_result();
?>

<div class="container py-4">
  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Distributor Details</h1>
    <a href="distributor_list.php" class="btn btn-outline-secondary">← Back</a>
  </div>

  <!-- Distributor Card -->
  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <h5 class="mb-1"><?php echo htmlspecialchars($dist['distributor_name']); ?></h5>
          <div class="text-muted small">
            Code: <?php echo htmlspecialchars($dist['distributor_code'] ?? '-'); ?> ·
            Status: <span class="badge bg-<?php echo ($dist['status'] ?? 'Active') === 'Suspended' ? 'danger' : 'success'; ?>">
              <?php echo htmlspecialchars($dist['status'] ?? 'Active'); ?>
            </span><br>
            Contact: <?php echo htmlspecialchars($dist['contact_person'] ?? '-'); ?><br>
            Mobile: <a href="tel:<?php echo htmlspecialchars($dist['mobile_number']); ?>">
              <?php echo htmlspecialchars($dist['mobile_number']); ?>
            </a><br>
            Email: <?php echo htmlspecialchars($dist['email'] ?? '-'); ?><br>
            Address: <?php echo nl2br(htmlspecialchars($dist['address'] ?? '-')); ?><br>
            Added: <?php echo !empty($dist['created_at']) ? date('d M Y, h:i A', strtotime($dist['created_at'])) : '—'; ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="row text-center">
            <div class="col-4">
              <div class="border rounded p-3">
                <div class="text-muted small">Assigned</div>
                <div class="fs-4 fw-bold"><?php echo $total_assigned; ?></div>
              </div>
            </div>
            <div class="col-4">
              <div class="border rounded p-3">
                <div class="text-muted small">Delivered</div>
                <div class="fs-4 fw-bold text-success"><?php echo $delivered_count; ?></div>
              </div>
            </div>
            <div class="col-4">
              <div class="border rounded p-3">
                <div class="text-muted small">Pending</div>
                <div class="fs-4 fw-bold text-warning"><?php echo $pending_count; ?></div>
              </div>
            </div>
          </div>
        </div>
      </div> <!-- row -->
    </div>
  </div>

  <!-- Orders Table -->
  <div class="card">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Orders (<?php echo number_format($totalRows); ?>)</h2>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>#Invoice</th>
              <th>Customer</th>
              <th>Mobile</th>
              <th>Order Date</th>
              <th>Amount</th>
              <th>Order Status</th>
              <th>Delivery Status</th>
              <th>Dist. Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($orders->num_rows === 0): ?>
            <tr><td colspan="9" class="text-center text-muted">No orders found.</td></tr>
          <?php else: ?>
            <?php while ($o = $orders->fetch_assoc()): ?>
              <?php
                $amt = (float)$o['grand_total'];
                $badge = function($s) {
                  $s = strtolower(trim((string)$s));
                  $map = [
                    'delivered' => 'success',
                    'ofd'       => 'primary',
                    'accepted'  => 'primary',
                    'pending'   => 'warning',
                    'new'       => 'warning',
                    'on_hold'   => 'secondary',
                    'rto'       => 'dark',
                    'cancelled' => 'danger',
                    'canceled'  => 'danger',
                    'not_my_area' => 'info',
                  ];
                  $color = $map[$s] ?? 'secondary';
                  return '<span class="badge bg-'.$color.'">'.htmlspecialchars($s ?: '—').'</span>';
                };
              ?>
              <tr>
                <td><?php echo htmlspecialchars($o['invoice_number'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($o['full_name'] ?? '—'); ?></td>
                <td><a href="tel:<?php echo htmlspecialchars($o['mobile_number']); ?>"><?php echo htmlspecialchars($o['mobile_number'] ?? '—'); ?></a></td>
                <td>
                  <?php
                    $dt = $o['created_at'];
                    echo $dt ? date('d M Y, h:i A', strtotime($dt)) : '—';
                  ?>
                </td>
                <td>₹<?php echo number_format($amt, 2); ?></td>
                <td><?php echo $badge($o['order_status']); ?></td>
                <td><?php echo $badge($o['delivery_status']); ?></td>
                <td><?php echo $badge($o['distributor_status']); ?></td>
                <td>
                  <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-primary" href="view_order.php?id=<?php echo (int)$o['order_id']; ?>">View</a>
                    <a class="btn btn-outline-secondary" href="edit_order.php?id=<?php echo (int)$o['order_id']; ?>">Edit</a>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($totalPages > 1):
      ?>
      <nav>
        <ul class="pagination mb-0">
          <?php
            $base = 'distributor_view.php?id='.$distributor_id.'&page=';
            for ($p = 1; $p <= $totalPages; $p++):
          ?>
            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
              <a class="page-link" href="<?php echo $base.$p; ?>"><?php echo $p; ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php include('include/footer.php'); ?>

<?php
require_once 'include/require_permission.php';
requirePermission('PENDING_ORDERS', 'view');
include('include/db.php');
include('include/require_login.php'); // if you use auth
include('include/header.php');
// FLASH / TOAST via ?msg=&id=&inv=
$flash_code = $_GET['msg'] ?? '';
$flash_id   = isset($_GET['id']) ? (int)$_GET['id'] : null;
$flash_inv  = trim($_GET['inv'] ?? ''); // <-- NEW

$FLASH_MAP = [
  'method-not-allowed' => ['danger',    'Method not allowed.'],
  'csrf-failed'        => ['danger',    'Security check failed. Please try again.'],
  'invalid-order'      => ['warning',   'Invalid order ID.'],
  'order-not-found'    => ['warning',   'Order not found.'],
  'already-delivered'  => ['info',      'Order already delivered — cannot cancel.'],
  'already-cancelled'  => ['danger', 'Order already cancelled.'],
  'update-failed'      => ['danger',    'Update failed. Please try again.'],
  // include invoice placeholder:
  'order-cancelled'    => ['success',   'Order {inv} cancelled successfully.'],
];
$flash = null;
if ($flash_code && isset($FLASH_MAP[$flash_code])) {
  [$type, $text] = $FLASH_MAP[$flash_code];
  $text = str_replace(
    ['{id}', '{inv}'],
    [(string)$flash_id, ($flash_inv !== '' ? $flash_inv : '-')],
    $text
  );
  $flash = ['type'=>$type, 'text'=>$text];
}

// ---------- CONFIG ----------
$PER_PAGE_DEFAULT = 25;

// ---------- INPUTS ----------
$q             = trim($_GET['q'] ?? '');
$from          = trim($_GET['from'] ?? '');
$to            = trim($_GET['to'] ?? '');
$pay_status    = trim($_GET['pay_status'] ?? '');
$del_status    = trim($_GET['del_status'] ?? '');
$page          = max(1, intval($_GET['page'] ?? 1));
$per_page      = max(1, intval($_GET['per_page'] ?? $PER_PAGE_DEFAULT));
$offset        = ($page - 1) * $per_page;

// ---------- HELPERS ----------
function build_where_and_params(&$types, &$params, $q, $from, $to, $pay_status, $del_status) {
    $where = [];
    $types = '';
    $params = [];

    if ($q !== '') {
        $like = "%{$q}%";
        $where[] = "(o.invoice_number LIKE ? OR c.full_name LIKE ? OR c.mobile_number LIKE ?)";
        $types .= 'sss';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($from !== '' && $to !== '') {
        $where[] = "o.order_date BETWEEN ? AND ?";
        $types .= 'ss';
        $params[] = $from; $params[] = $to;
    } elseif ($from !== '') {
        $where[] = "o.order_date >= ?";
        $types .= 's';
        $params[] = $from;
    } elseif ($to !== '') {
        $where[] = "o.order_date <= ?";
        $types .= 's';
        $params[] = $to;
    }
    if ($del_status !== '') {
        $where[] = "o.order_status = ?";
        $types .= 's';
        $params[] = $del_status;
    }

    // Always exclude delivered/cancelled
    $base = "o.distributor_status NOT IN ('delivered','cancelled')";

    if ($where) {
        return "WHERE $base AND " . implode(' AND ', $where);
    } else {
        return "WHERE $base";
    }
}

// ---------- MAIN LIST QUERY ----------
$types = ''; $params = [];
$whereSql = build_where_and_params($types, $params, $q, $from, $to, $pay_status, $del_status);

$sqlList = "
SELECT
  o.order_id,
  o.invoice_number,
  o.order_date,
  order_status,
  o.payment_mode,
  COALESCE(o.delivery_status, 'Pending') AS delivery_status,
  o.subtotal, o.tax, o.discount, o.grand_total,
  c.full_name, c.mobile_number,
  o.distributor_id,
  COALESCE(d.distributor_name, 'Unassigned') AS distributor_name,
  COALESCE(items.item_count, 0)   AS item_count,
  COALESCE(comments.comment_count, 0) AS comment_count
FROM orders o
JOIN customers c ON c.customer_id = o.customer_id
LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
LEFT JOIN (
  SELECT order_id, SUM(quantity) AS item_count
  FROM order_items
  GROUP BY order_id
) items ON items.order_id = o.order_id
LEFT JOIN (
  SELECT order_id, COUNT(*) AS comment_count
  FROM order_comments
  GROUP BY order_id
) comments ON comments.order_id = o.order_id
{$whereSql}
ORDER BY o.order_id DESC
LIMIT ? OFFSET ?";


// bind + execute (list)
$typesList = $types . 'ii';
$paramsList = array_merge($params, [$per_page, $offset]);
$stmtList = $mysqli->prepare($sqlList);
$stmtList->bind_param($typesList, ...$paramsList);
$stmtList->execute();
$resList = $stmtList->get_result();

// ---------- TOTALS (for pagination and page totals) ----------
$typesTot = ''; $paramsTot = [];
$whereTot = build_where_and_params($typesTot, $paramsTot, $q, $from, $to, $pay_status, $del_status);

// count rows
$sqlCount = "
SELECT COUNT(*) AS total_rows
FROM orders o
JOIN customers c ON c.customer_id = o.customer_id
{$whereTot}";
$stmtCount = $mysqli->prepare($sqlCount);
if ($typesTot) $stmtCount->bind_param($typesTot, ...$paramsTot);
$stmtCount->execute();
$resCount = $stmtCount->get_result()->fetch_assoc();
$total_rows = intval($resCount['total_rows'] ?? 0);
$total_pages = max(1, ceil($total_rows / $per_page));

// sums (page totals for filtered set)
$sqlSums = "
SELECT
  COALESCE(SUM(o.subtotal),0) AS sum_subtotal,
  COALESCE(SUM(o.tax),0)      AS sum_tax,
  COALESCE(SUM(o.discount),0) AS sum_discount,
  COALESCE(SUM(o.grand_total),0) AS sum_grand
FROM orders o
JOIN customers c ON c.customer_id = o.customer_id
{$whereTot}";
$stmtSums = $mysqli->prepare($sqlSums);
if ($typesTot) $stmtSums->bind_param($typesTot, ...$paramsTot);
$stmtSums->execute();
$sumRow = $stmtSums->get_result()->fetch_assoc();
$sum_subtotal = (float)$sumRow['sum_subtotal'];
$sum_tax      = (float)$sumRow['sum_tax'];
$sum_discount = (float)$sumRow['sum_discount'];
$sum_grand    = (float)$sumRow['sum_grand'];

// ---------- TOAST ----------
$toast = $_SESSION['toast'] ?? '';
unset($_SESSION['toast']);
?>
<?php if ($flash): 
  // Map type -> toast bg/text classes
  $TOAST_CLASS = [
    'success'   => 'bg-success text-white',
    'danger'    => 'bg-danger text-white',
    'warning'   => 'bg-warning text-dark',
    'info'      => 'bg-info text-dark',
    'secondary' => 'bg-secondary text-white',
  ];
  $toastClass = $TOAST_CLASS[$flash['type']] ?? 'bg-secondary text-white';
?>
  <div class="position-fixed top-50 start-50 translate-middle p-2" style="z-index:1080;">
    <div id="appToast"
         class="toast align-items-center shadow-lg rounded-3 <?= htmlspecialchars($toastClass) ?>"
         role="status" aria-live="polite" aria-atomic="true"
         data-bs-autohide="true" data-bs-delay="4000">
      <div class="d-flex">
        <div class="toast-body fw-semibold">
          <?= htmlspecialchars($flash['text']) ?>
        </div>
        <button type="button" class="btn-close <?php echo (str_contains($toastClass,'text-white')?'btn-close-white':''); ?> me-2 m-auto"
                data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="container-fluid py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Orders Pending</h4>
    <div class="d-flex gap-2">
      <a href="add_order.php" class="btn btn-primary">+ New Order</a>
      <a href="export_orders.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-secondary">Export CSV</a>
    </div>
  </div>

  <!-- Filters -->
  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2">
        <div class="col-md-3">
          <input type="text" name="q" class="form-control" placeholder="Search invoice / name / mobile" value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="col-md-2">
          <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="col-md-2">
          <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div class="col-md-2">
          <select name="del_status" class="form-select">
            <option value="">Delivery Status</option>
            <?php
              $delOpts = ['New','Open','Assigned','Ready to Delivery','change distributor'];
              foreach ($delOpts as $opt) {
                $sel = ($del_status===$opt)?'selected':'';
                echo "<option {$sel} value=\"{$opt}\">{$opt}</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-md-1">
          <select name="per_page" class="form-select">
            <?php foreach ([10,25,50,100] as $pp) {
              $sel = ($per_page==$pp)?'selected':'';
              echo "<option {$sel} value=\"{$pp}\">{$pp}/p</option>";
            } ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2 mt-1">
          <button class="btn btn-dark" type="submit">Apply</button>
          <a class="btn btn-outline-secondary" href="order_list.php">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Totals (for filtered set) -->
  <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between">
    <div><strong>Filtered Orders:</strong> <?= number_format($total_rows) ?></div>
    <div><strong>Subtotal:</strong> ₹<?= number_format($sum_subtotal, 2) ?></div>
    <div><strong>Tax:</strong> ₹<?= number_format($sum_tax, 2) ?></div>
    <div><strong>Discount:</strong> ₹<?= number_format($sum_discount, 2) ?></div>
    <div><strong>Grand Total:</strong> ₹<?= number_format($sum_grand, 2) ?></div>
  </div>

  <!-- Orders Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th style="width: 100px;">Order ID</th>
            <th>Customer</th>
            <th style="width: 120px;">Date</th>
            <th class="text-end" style="width: 120px;">Grand (₹)</th>
            <th style="width: 110px;">Items</th>
            <th style="width: 120px;">Distributor</th>
            <th style="width: 140px;">Payment</th>
            <th style="width: 150px;">Delivery</th>
            <th style="width: 220px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($resList->num_rows === 0): ?>
            <tr><td colspan="9" class="text-center text-muted">No orders found.</td></tr>
          <?php else: ?>
            <?php while ($row = $resList->fetch_assoc()): ?>
              <?php
                $delBadgeClass = match($row['order_status']) {
                  'Delivered' => 'bg-success',
                  'Assigned','Ready to Delivery','Packed' => 'bg-primary text-dark',
                  'change distributor' => 'bg-danger text-dark',
                  'Cancelled' => 'bg-dark',
                  default => 'bg-warning '
                };
              ?>
              <tr>
                <td><span class="fw-semibold"><?= htmlspecialchars($row['invoice_number']) ?> <span class="badge bg-dark"><?= (int)$row['comment_count'] ?></span></span></td>
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars($row['full_name']) ?></div>
                  <div class="text-muted small"><?= htmlspecialchars($row['mobile_number']) ?></div>
                </td>
                <td><?= htmlspecialchars($row['order_date']) ?></td>
                <td class="text-end"><?= number_format((float)$row['grand_total'], 2) ?></td>
                <td>
                  <span class="badge bg-info"><?= (int)$row['item_count'] ?></span>
                </td>
                <td>
                  <?= htmlspecialchars($row['distributor_name']) ?>
                </td>
                <td>
                  <div class="small text-muted"><?= htmlspecialchars($row['payment_mode']) ?></div>
                </td>
                <td>
                  <span class="badge <?= $delBadgeClass ?>"><?= htmlspecialchars($row['order_status']) ?></span><br>
                 
                </td>
                <td>
                  <div class="btn-group">
                    <a class="btn btn-sm btn-outline-primary" href="view_order.php?id=<?= (int)$row['order_id'] ?>">View</a>
                    <a class="btn btn-sm btn-outline-warning" href="edit_order.php?id=<?= (int)$row['order_id'] ?>">Edit</a>
                    <?php
                        // Make sure a CSRF token exists at top of the page (dashboard.php)
                        if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
                        ?>

                        <form method="post" action="order_cancel.php" class="d-inline"
                            onsubmit="return confirm('Cancel this order?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="order_id" value="<?= (int)$row['order_id'] ?>">
                        <input type="hidden" name="invoice" value="<?= htmlspecialchars($row['invoice_number']) ?>">
                        <input type="hidden" name="return_to" value="order_list.php">
                        <!-- Optional text field if you want a reason in a modal or inline:
                        <input type="hidden" name="cancel_reason" value="">
                        -->
                        <button class="btn btn-outline-danger btn-sm">Cancel</button>
                        </form>

                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between">
      <div class="text-muted small">
        Showing <strong><?= ($total_rows ? ($offset+1) : 0) ?></strong>–<strong><?= min($offset+$per_page, $total_rows) ?></strong> of <strong><?= $total_rows ?></strong>
      </div>
      <nav>
        <ul class="pagination mb-0">
          <?php
            // preserve filters in links
            $baseQuery = $_GET;
            for ($p=1; $p<=$total_pages; $p++) {
              $baseQuery['page'] = $p;
              $href = 'order_list.php?' . http_build_query($baseQuery);
              $active = ($p === $page) ? 'active' : '';
              echo "<li class='page-item {$active}'><a class='page-link' href='{$href}'>{$p}</a></li>";
            }
          ?>
        </ul>
      </nav>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var t = document.getElementById('appToast');
  if (t && window.bootstrap && bootstrap.Toast) {
    new bootstrap.Toast(t).show();
  }
});
</script>
<?php if (!empty($toast)): ?>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    const t = <?= json_encode($toast) ?>;
    if (t) { alert(t); } // Replace with your Bootstrap toast if you have one
  });
</script>
<?php endif; ?>

<?php include('include/footer.php'); ?>

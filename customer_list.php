<?php
require_once 'include/require_permission.php';
requirePermission('CUSTOMERS', 'view');
include('include/db.php');
include('include/require_login.php');
include('include/header.php');

// ---- CONFIG ----
$PER_PAGE_DEFAULT = 25;

// ---- INPUTS ----
$q        = trim($_GET['q'] ?? '');
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = max(1, intval($_GET['per_page'] ?? $PER_PAGE_DEFAULT));
$offset   = ($page - 1) * $per_page;

// ---- SEARCH WHERE ----
$where = '';
$types = '';
$params = [];

if ($q !== '') {
    $like = "%{$q}%";
    $where = "WHERE (full_name LIKE ? OR mobile_number LIKE ? OR email LIKE ? OR city LIKE ? OR state LIKE ?)";
    $types = "sssss";
    $params = [$like, $like, $like, $like, $like];
}

// ---- MAIN QUERY ----
$sql = "
SELECT 
  customer_id,
  full_name,
  mobile_number,
  email,
  city,
  state,
  pincode,
  address,
  landmark,
  created_at
FROM customers
{$where}
ORDER BY customer_id DESC
LIMIT ? OFFSET ?
";
$types_list = $types . "ii";
$params_list = array_merge($params, [$per_page, $offset]);

$stmt = $mysqli->prepare($sql);
if ($types) $stmt->bind_param($types_list, ...$params_list);
else $stmt->bind_param("ii", $per_page, $offset);
$stmt->execute();
$res = $stmt->get_result();

// ---- TOTAL COUNT ----
$sqlCount = "SELECT COUNT(*) AS total FROM customers " . ($where ?: '');
$stmtCount = $mysqli->prepare($sqlCount);
if ($types) $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$total_rows = $stmtCount->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = max(1, ceil($total_rows / $per_page));

$toast = $_SESSION['toast'] ?? '';
unset($_SESSION['toast']);
?>

<div class="container-fluid py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Customers</h4>
    <div class="d-flex gap-2">
      <a href="add_customer.php" class="btn btn-primary">+ Add Customer</a>
      <a href="export_customers.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-secondary">Export CSV</a>
    </div>
  </div>

  <!-- Search -->
  <div class="card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2">
        <div class="col-md-6 col-lg-4">
          <input type="text" name="q" class="form-control" placeholder="Search name / mobile / email / city / state" value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="col-md-2">
          <select name="per_page" class="form-select">
            <?php foreach ([10,25,50,100] as $pp) {
              $sel = ($per_page==$pp)?'selected':'';
              echo "<option {$sel} value=\"{$pp}\">{$pp}/p</option>";
            } ?>
          </select>
        </div>
        <div class="col-md-2">
          <button class="btn btn-dark w-100" type="submit">Search</button>
        </div>
        <div class="col-md-2">
          <a href="customer_list.php" class="btn btn-outline-secondary w-100">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Summary -->
  <div class="alert alert-info">
    <strong>Total Customers:</strong> <?= number_format($total_rows) ?>
  </div>

  <!-- Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:80px;">ID</th>
            <th>Name</th>
            <th>Mobile</th>
            <th>Email</th>
            <th>City / State</th>
            <th>Address</th>
            <th style="width:150px;">Added On</th>
            <th style="width:180px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($res->num_rows === 0): ?>
            <tr><td colspan="8" class="text-center text-muted">No customers found.</td></tr>
          <?php else: ?>
            <?php while ($row = $res->fetch_assoc()): ?>
              <tr>
                <td>#<?= (int)$row['customer_id'] ?></td>
                <td><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>
                <td><?= htmlspecialchars($row['mobile_number']) ?></td>
                <td><?= htmlspecialchars($row['email'] ?: '-') ?></td>
                <td><?= htmlspecialchars($row['city'] ?: '-') ?><?= $row['state'] ? ', '.htmlspecialchars($row['state']) : '' ?></td>
                <td class="small text-muted"><?= htmlspecialchars($row['address']) ?><?= $row['landmark'] ? ', '.htmlspecialchars($row['landmark']) : '' ?></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($row['created_at']))) ?></td>
                <td>
                  <div class="btn-group">
                    <a href="view_customer.php?id=<?= (int)$row['customer_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                    <a href="edit_customer.php?id=<?= (int)$row['customer_id'] ?>" class="btn btn-sm btn-outline-warning">Edit</a>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
       <!-- Pagination -->
    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between">
      <div class="text-muted small">
        Showing <strong><?= ($total_rows ? ($offset+1) : 0) ?></strong>–<strong><?= min($offset+$per_page, $total_rows) ?></strong> of <strong><?= $total_rows ?></strong>
      </div>

      <nav>
        <ul class="pagination mb-0">
          <?php
            $baseQuery = $_GET;

            $max_visible = 7; // number of pages to show at once
            $half = floor($max_visible / 2);

            // Calculate visible page range
            $start_page = max(1, $page - $half);
            $end_page = min($total_pages, $start_page + $max_visible - 1);

            // Adjust if we're near the end
            if (($end_page - $start_page + 1) < $max_visible) {
              $start_page = max(1, $end_page - $max_visible + 1);
            }

            // First page button
            if ($page > 1) {
              $baseQuery['page'] = 1;
              $href = 'customer_list.php?' . http_build_query($baseQuery);
              echo "<li class='page-item'><a class='page-link' href='{$href}'>&laquo; First</a></li>";
            }

            // Previous button
            if ($page > 1) {
              $baseQuery['page'] = $page - 1;
              $href = 'customer_list.php?' . http_build_query($baseQuery);
              echo "<li class='page-item'><a class='page-link' href='{$href}'>&lsaquo;</a></li>";
            }

            // Page numbers
            for ($p = $start_page; $p <= $end_page; $p++) {
              $baseQuery['page'] = $p;
              $href = 'customer_list.php?' . http_build_query($baseQuery);
              $active = ($p === $page) ? 'active' : '';
              echo "<li class='page-item {$active}'><a class='page-link' href='{$href}'>{$p}</a></li>";
            }

            // Next button
            if ($page < $total_pages) {
              $baseQuery['page'] = $page + 1;
              $href = 'customer_list.php?' . http_build_query($baseQuery);
              echo "<li class='page-item'><a class='page-link' href='{$href}'>&rsaquo;</a></li>";
            }

            // Last page button
            if ($page < $total_pages) {
              $baseQuery['page'] = $total_pages;
              $href = 'customer_list.php?' . http_build_query($baseQuery);
              echo "<li class='page-item'><a class='page-link' href='{$href}'>Last &raquo;</a></li>";
            }
          ?>
        </ul>
      </nav>
    </div>

  </div>
</div>

<?php if (!empty($toast)): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const t = <?= json_encode($toast) ?>;
  if (t) { alert(t); } // Replace with Bootstrap toast if used
});
</script>
<?php endif; ?>

<?php include('include/footer.php'); ?>

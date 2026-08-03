<?php
require_once 'include/require_permission.php';
requirePermission('PRODUCT_REPORT', 'view');
include('include/db.php');
include('include/require_login.php');
include('include/header.php');
?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">📊 Product Performance Report</h4>
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">⬅ Back to Dashboard</a>
  </div>

  <?php
  // ===================== FETCH DATA =====================
  $sql = "SELECT 
              p.product_id, 
              p.title, 
              COUNT(oi.order_id) AS total_orders, 
              SUM(oi.quantity) AS total_qty, 
              SUM(oi.unit_price) AS total_sales, 
              ROUND(AVG(oi.unit_price), 2) AS avg_order_value, 
              MAX(o.created_at) AS last_order_date 
          FROM order_items oi 
          JOIN products p ON oi.product_id = p.product_id 
          JOIN orders o ON oi.order_id = o.order_id 
          WHERE o.order_status = 'DELIVERED'
          GROUP BY p.product_id 
          ORDER BY total_orders DESC";

  $result = $mysqli->query($sql);

  $rows = [];
  $topProduct = '';
  $topSales = 0;
  $totalProducts = 0;
  $grandSales = 0;
  $totalOrders = 0;
  $chartLabels = [];
  $chartData = [];

  if ($result && $result->num_rows > 0) {
      while ($row = $result->fetch_assoc()) {
          $rows[] = $row;
          $totalProducts++;
          $grandSales += $row['total_sales'];
          $totalOrders += $row['total_orders'];

          // find top product
          if ($row['total_sales'] > $topSales) {
              $topSales = $row['total_sales'];
              $topProduct = $row['title'];
          }

          // for chart (top 5)
          if (count($chartLabels) < 5) {
              $chartLabels[] = $row['title'];
              $chartData[] = $row['total_sales'];
          }
      }
  }
  ?>

  <!-- ===================== SUMMARY CARDS ===================== -->
  <div class="row mb-4">
    <div class="col-md-3 mb-3">
      <div class="card text-center shadow-sm border-0 h-100">
        <div class="card-body">
          <h6 class="text-muted">🏆 Top Product</h6>
          <h5 class="fw-bold text-dark mt-2"><?= htmlspecialchars($topProduct ?: 'N/A') ?></h5>
        </div>
      </div>
    </div>
    <div class="col-md-3 mb-3">
      <div class="card text-center shadow-sm border-0 h-100">
        <div class="card-body">
          <h6 class="text-muted">📦 Total Products</h6>
          <h5 class="fw-bold text-dark mt-2"><?= number_format($totalProducts) ?></h5>
        </div>
      </div>
    </div>
    <div class="col-md-3 mb-3">
      <div class="card text-center shadow-sm border-0 h-100">
        <div class="card-body">
          <h6 class="text-muted">🧾 Total Orders</h6>
          <h5 class="fw-bold text-dark mt-2"><?= number_format($totalOrders) ?></h5>
        </div>
      </div>
    </div>
    <div class="col-md-3 mb-3">
      <div class="card text-center shadow-sm border-0 h-100">
        <div class="card-body">
          <h6 class="text-muted">💰 Total Sales Value</h6>
          <h5 class="fw-bold text-success mt-2">₹<?= number_format($grandSales, 2) ?></h5>
        </div>
      </div>
    </div>
  </div>

  <!-- ===================== CHART ===================== -->
  <div class="card mb-4 shadow-sm border-0">
    <div class="card-body">
      <h5 class="card-title mb-3">Top 5 Selling Products (By Sales Value)</h5>
      <canvas id="topProductsChart" height="100"></canvas>
    </div>
  </div>

  <!-- ===================== TABLE ===================== -->
  <div class="card shadow-sm border-0">
    <div class="card-body">
      <h5 class="card-title mb-3">Detailed Product Report</h5>
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
          <thead class="table-dark">
            <tr>
              <th>#</th>
              <th>Product Title</th>
              <th>Total Orders</th>
              <th>Quantity Sold</th>
              <th>Total Sales (₹)</th>
              <th>Avg Order Value (₹)</th>
              <th>Last Ordered Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($rows)): $i = 1; ?>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><?= htmlspecialchars($row['title']) ?></td>
                  <td><?= number_format($row['total_orders']) ?></td>
                  <td><?= number_format($row['total_qty']) ?></td>
                  <td>₹<?= number_format($row['total_sales'], 2) ?></td>
                  <td>₹<?= number_format($row['avg_order_value'], 2) ?></td>
                  <td><?= date('d M Y, h:i A', strtotime($row['last_order_date'])) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" class="text-center text-muted">No Data Found</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ===================== CHART.JS ===================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('topProductsChart');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'Sales (₹)',
      data: <?= json_encode($chartData) ?>,
      backgroundColor: 'rgba(54, 162, 235, 0.7)',
      borderRadius: 8
    }]
  },
  options: {
    scales: { y: { beginAtZero: true } },
    plugins: { legend: { display: false } }
  }
});
</script>

<?php include('include/footer.php'); ?>

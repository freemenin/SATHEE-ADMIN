<?php
require_once 'include/require_permission.php';
requirePermission('CUSTOMERS', 'view');
include('include/header.php');

$customer_id = (int)($_GET['id'] ?? 0);
if ($customer_id <= 0) {
    echo "<div class='alert alert-danger m-3'>Invalid customer ID.</div>";
    include('include/footer.php');
    exit;
}

// Fetch customer info
$stmt = $mysqli->prepare("SELECT * FROM customers WHERE customer_id = ?");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    echo "<div class='alert alert-danger m-3'>Customer not found.</div>";
    include('include/footer.php');
    exit;
}

// Fetch orders + their items
$stmt = $mysqli->prepare("
    SELECT o.order_id, o.invoice_number, o.order_date, o.grand_total, o.order_status
    FROM orders o
    WHERE o.customer_id = ?
    ORDER BY o.order_date DESC
");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build order items map
$order_items_map = [];
if (!empty($orders)) {
    $order_ids = implode(',', array_map('intval', array_column($orders, 'order_id')));
    $resItems = $mysqli->query("
        SELECT oi.order_id, p.title, oi.quantity, oi.unit_price
        FROM order_items oi
        JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id IN ($order_ids)
        ORDER BY oi.order_id DESC
    ");
    while ($row = $resItems->fetch_assoc()) {
        $order_items_map[$row['order_id']][] = $row;
    }
}

// Calculate order analytics
$total_orders = count($orders);
$total_value = array_sum(array_column($orders, 'grand_total'));

$order_gap_days = null;
$frequency_label = 'No Orders';
if ($total_orders > 1) {
    $dates = array_column($orders, 'order_date');
    $date_objects = array_map(fn($d) => new DateTime($d), $dates);
    $gaps = [];
    for ($i = 0; $i < count($date_objects) - 1; $i++) {
        $gap = $date_objects[$i]->diff($date_objects[$i + 1])->days;
        $gaps[] = $gap;
    }
    $order_gap_days = round(array_sum($gaps) / count($gaps), 1);

    if ($order_gap_days <= 15) $frequency_label = "Highly Frequent";
    elseif ($order_gap_days <= 45) $frequency_label = "Moderate";
    else $frequency_label = "Occasional";
} elseif ($total_orders == 1) {
    $frequency_label = "One-time Buyer";
}
?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Customer Profile</h4>
  </div>

  <!-- Customer Info -->
  <div class="card mb-4">
    <div class="card-body">
      <div class="row">
        <div class="col-md-6">
          <h5 class="fw-bold"><?= htmlspecialchars($customer['full_name']) ?></h5>
          <p class="mb-1"><strong>Mobile:</strong> <?= htmlspecialchars($customer['mobile_number']) ?></p>
          <p class="mb-1"><strong>Email:</strong> <?= htmlspecialchars($customer['email'] ?: '—') ?></p>
          <p class="mb-1"><strong>Address:</strong> <?= htmlspecialchars($customer['address']) ?></p>
          <?php if (!empty($customer['landmark'])): ?>
            <p class="mb-1"><strong>Landmark:</strong> <?= htmlspecialchars($customer['landmark']) ?></p>
          <?php endif; ?>
          <p class="mb-1"><strong>City:</strong> <?= htmlspecialchars($customer['city']) ?></p>
          <p class="mb-1"><strong>State:</strong> <?= htmlspecialchars($customer['state']) ?></p>
          <p class="mb-1"><strong>Pincode:</strong> <?= htmlspecialchars($customer['pincode']) ?></p>
        </div>
        <div class="col-md-6">
          <div class="card bg-light h-100">
            <div class="card-body">
              <h6 class="fw-bold mb-3">Customer Analytics</h6>
              <p><strong>Total Orders:</strong> <?= $total_orders ?></p>
              <p><strong>Total Order Value:</strong> ₹<?= number_format($total_value, 2) ?></p>
              <p><strong>Avg Gap Between Orders:</strong> <?= $order_gap_days ? "{$order_gap_days} days" : "—" ?></p>
              <p><strong>Frequency Type:</strong>
                <span class="badge
                  <?= $frequency_label === 'Highly Frequent' ? 'bg-success' :
                      ($frequency_label === 'Moderate' ? 'bg-primary' :
                      ($frequency_label === 'Occasional' ? 'bg-warning text-dark' : 'bg-secondary')) ?>">
                  <?= $frequency_label ?>
                </span>
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Orders Section -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Order History</h5>
      <?php if (hasPermission('ORDERS', 'add')): ?>
        <a href="add_order.php" class="btn btn-sm btn-primary">+ New Order</a>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Invoice</th>
            <th>Date</th>
            <th class="text-end">Grand Total (₹)</th>
            <th>Status</th>
            <th style="width:200px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($total_orders === 0): ?>
            <tr><td colspan="5" class="text-center text-muted">No orders yet.</td></tr>
          <?php else: ?>
            <?php foreach ($orders as $order): ?>
              <?php
                $statusColor = match($order['order_status']) {
                  'Delivered' => 'bg-success',
                  'Shipped','Packed' => 'bg-primary',
                  'Processing' => 'bg-warning text-dark',
                  'Cancelled' => 'bg-dark',
                  default => 'bg-secondary'
                };
              ?>
              <tr>
                <td><strong><?= htmlspecialchars($order['invoice_number']) ?></strong></td>
                <td><?= htmlspecialchars(date('d M Y', strtotime($order['order_date']))) ?></td>
                <td class="text-end"><?= number_format($order['grand_total'], 2) ?></td>
                <td><span class="badge <?= $statusColor ?>"><?= htmlspecialchars($order['order_status']) ?></span></td>
                <td>
                  <?php if (hasPermission('ORDERS', 'view')): ?>
                    <div class="btn-group">
                      <a href="view_order.php?id=<?= (int)$order['order_id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>

              <!-- Order products -->
              <?php if (!empty($order_items_map[$order['order_id']])): ?>
                <tr>
                  <td colspan="5" class="bg-light p-0">
                    <table class="table table-sm mb-0">
                      <thead class="table-secondary">
                        <tr>
                          <th style="width:50%">Product</th>
                          <th style="width:20%" class="text-center">Qty</th>
                          <th style="width:30%" class="text-end">Unit Price (₹)</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($order_items_map[$order['order_id']] as $item): ?>
                          <tr>
                            <td><?= htmlspecialchars($item['title']) ?></td>
                            <td class="text-center"><?= (int)$item['quantity'] ?></td>
                            <td class="text-end"><?= number_format($item['unit_price'], 2) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include('include/footer.php'); ?>

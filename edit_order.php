<?php
require_once 'include/require_permission.php';
requirePermission('ORDERS', 'edit');
require_once 'include/csrf_helper.php';
?>
<?php
include('include/header.php');
require_once 'include/db.php';

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($order_id <= 0) { echo "Invalid order ID"; include('include/footer.php'); exit; }

// Fetch order
$stmt = $mysqli->prepare("SELECT * FROM orders WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$order) { echo "Order not found"; include('include/footer.php'); exit; }

// Fetch customer
$stmt = $mysqli->prepare("SELECT * FROM customers WHERE customer_id = ?");
$stmt->bind_param("i", $order['customer_id']);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// (optional) fetch payment modes from a table if you have one
$paymentModes = ['Cash','prepaid'];
?>
<div class="container container--max--xl py-5">
  <h1 class="h3 mb-4">Edit Order #<?= htmlspecialchars($order['invoice_number']) ?></h1>

  <form method="post" action="update_order.php" id="orderEditForm">
    <?= csrfTokenField() ?>
    <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
    <input type="hidden" name="customer_id" value="<?= (int)$order['customer_id'] ?>">

    <!-- CUSTOMER INFO (editable) -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h2 class="fs-5 mb-0">Customer Info</h2>
          <span class="badge bg-secondary">Customer ID: <?= (int)$order['customer_id'] ?></span>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($customer['full_name'] ?? '') ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Mobile</label>
            <input type="text" name="mobile_number" class="form-control" value="<?= htmlspecialchars($customer['mobile_number'] ?? '') ?>" maxlength="15" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($customer['address'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Landmark</label>
            <input type="text" name="landmark" class="form-control" value="<?= htmlspecialchars($customer['landmark'] ?? '') ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">City</label>
            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($customer['city'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">State</label>
            <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($customer['state'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Pincode</label>
            <input type="text" name="pincode" class="form-control" value="<?= htmlspecialchars($customer['pincode'] ?? '') ?>" maxlength="10">
          </div>
        </div>
      </div>
    </div>

    <!-- ORDER ITEMS -->
    <div class="card mb-4">
      <div class="card-body">
        <h2 class="fs-5 mb-3">Order Items</h2>
        <div class="table-responsive">
          <table class="table align-middle" id="itemsTable">
            <thead>
              <tr>
                <th style="min-width:260px">Product</th>
                <th>Unit Price</th>
                <th>Qty</th>
                <th>Total</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody><!-- Filled via AJAX --></tbody>
          </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addNewItemRow()">+ Add Item</button>
      </div>
    </div>

    <!-- SUMMARY -->
    <div class="card mb-4">
      <div class="card-body">
        <h2 class="fs-5 mb-3">Summary</h2>
        <div class="row g-3 mb-2">
          <div class="col-md-4">
            <label class="form-label">Discount</label>
            <input type="number" step="0.01" name="discount" id="discount" class="form-control" value="<?= htmlspecialchars($order['discount']) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Payment Mode</label>
            <select name="payment_mode" class="form-control">
              <?php foreach ($paymentModes as $pm): ?>
                <option value="<?= htmlspecialchars($pm) ?>" <?= ($order['payment_mode']===$pm?'selected':'') ?>><?= htmlspecialchars($pm) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Tax (included 18%)</label>
            <input type="text" name="tax" id="tax" class="form-control" readonly>
          </div>
          <div class="col-md-4">
            <label class="form-label">Final Total</label>
            <input type="text" id="grand_total" name="grand_total" class="form-control" readonly>
          </div>
        </div>
      </div>
    </div>

    <div class="text-end">
      <button type="submit" class="btn btn-primary">Save Changes</button>
    </div>
  </form>
</div>

<script>
const orderId = <?= (int)$order_id ?>;

function calculateSummary() {
  let total = 0;
  document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
    const qty   = parseFloat(row.querySelector('.qty')?.value)   || 0;
    const price = parseFloat(row.querySelector('.price')?.value) || 0;
    total += qty * price;
    const lt = row.querySelector('.line-total');
    if (lt) lt.textContent = '₹' + (qty * price).toFixed(2);
  });

  const tax = (total * 18) / 118; // GST included calculation
  const discount = parseFloat(document.getElementById('discount').value) || 0;
  const grandTotal = Math.max(total - discount, 0);

  document.getElementById('tax').value = tax.toFixed(2);
  document.getElementById('grand_total').value = grandTotal.toFixed(2);
}

function removeRow(button) {
  const row = button.closest('tr');
  row.remove();
  calculateSummary();
}

function addNewItemRow() {
  fetch('ajax_get_products.php')
    .then(res => res.json())
    .then(products => {
      const row = document.createElement('tr');
      const productSelectId = 'product_' + Date.now();

      const selectHTML = `
        <select name="product_ids[]" class="form-control product-select" id="${productSelectId}">
          <option value="">Select Product</option>
          ${products.map(p => `<option value="${p.product_id}" data-price="${p.retail_price}">${p.title}</option>`).join('')}
        </select>
      `;

      row.innerHTML = `
        <td>${selectHTML}</td>
        <td><input type="number" step="0.01" name="unit_prices[]" class="form-control price" value="0"></td>
        <td><input type="number" name="quantities[]" class="form-control qty" value="1" min="1"></td>
        <td class="line-total">₹0.00</td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">Delete</button></td>
      `;
      document.querySelector('#itemsTable tbody').appendChild(row);

      const $select = $('#' + productSelectId);
      if ($select.select2) { $select.select2(); }

      $select.on('change', function () {
        const selected = this.options[this.selectedIndex];
        const price = selected?.getAttribute('data-price') || 0;
        row.querySelector('.price').value = parseFloat(price).toFixed(2);
        calculateSummary();
      });

      row.addEventListener('input', e => {
        if (e.target.classList.contains('qty') || e.target.classList.contains('price')) calculateSummary();
      });

      calculateSummary();
    });
}

// Load existing order items
fetch('ajax_get_order_items.php?order_id=' + orderId)
  .then(res => res.json())
  .then(data => {
    const tbody = document.querySelector('#itemsTable tbody');
    tbody.innerHTML = '';
    data.forEach(item => {
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>
          <input type="hidden" name="product_ids[]" value="${item.product_id}">
          ${item.title}
        </td>
        <td><input type="number" step="0.01" name="unit_prices[]" class="form-control price" value="${item.unit_price}"></td>
        <td><input type="number" name="quantities[]" class="form-control qty" value="${item.quantity}" min="1"></td>
        <td class="line-total">₹${(item.unit_price * item.quantity).toFixed(2)}</td>
        <td><button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">Delete</button></td>
      `;
      tbody.appendChild(row);
    });
    calculateSummary();
  });

document.getElementById('discount').addEventListener('input', calculateSummary);
document.addEventListener('input', function(e) {
  if (e.target.classList.contains('qty') || e.target.classList.contains('price')) calculateSummary();
});
</script>

<?php include('include/footer.php'); ?>

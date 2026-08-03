<?php
require_once 'include/require_permission.php';
requirePermission('ORDERS', 'view');
include('include/header.php');

date_default_timezone_set('Asia/Kolkata');

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money')) {
    function money($value) {
        return '₹ ' . number_format((float)$value, 2);
    }
}

if (!function_exists('status_class')) {
    function status_class($status) {
        $s = strtolower(trim((string)$status));
        if (in_array($s, ['delivered', 'paid', 'completed'], true)) return 'ov-badge-success';
        if (in_array($s, ['cancelled', 'canceled', 'failed'], true)) return 'ov-badge-danger';
        if (in_array($s, ['ready to delivery', 'assigned', 'out for delivery'], true)) return 'ov-badge-warning';
        if (in_array($s, ['cash', 'cod', 'prepaid'], true)) return 'ov-badge-info';
        return 'ov-badge-muted';
    }
}

// FLASH / TOAST via ?msg=&id=&inv=
$flash_code = $_GET['msg'] ?? '';
$flash_id   = isset($_GET['id']) ? (int)$_GET['id'] : null;
$flash_inv  = trim($_GET['inv'] ?? '');

$FLASH_MAP = [
    'method-not-allowed' => ['danger',  'Method not allowed.'],
    'csrf-failed'        => ['danger',  'Security check failed. Please try again.'],
    'invalid-order'      => ['warning', 'Invalid order ID.'],
    'order-not-found'    => ['warning', 'Order not found.'],
    'already-delivered'  => ['info',    'Order already delivered — cannot cancel.'],
    'already-cancelled'  => ['danger',  'Order already cancelled.'],
    'update-failed'      => ['danger',  'Update failed. Please try again.'],
    'order-cancelled'    => ['success', 'Order {inv} cancelled successfully.'],
];

$flash = null;
if ($flash_code && isset($FLASH_MAP[$flash_code])) {
    [$type, $text] = $FLASH_MAP[$flash_code];
    $text = str_replace(['{id}', '{inv}'], [(string)$flash_id, ($flash_inv !== '' ? $flash_inv : '-')], $text);
    $flash = ['type' => $type, 'text' => $text];
}

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($order_id <= 0) {
    echo '<div class="container py-5"><div class="alert alert-warning">Invalid order ID</div></div>';
    include('include/footer.php');
    exit;
}

// Fetch order
$stmt = $mysqli->prepare("SELECT o.*, c.full_name, c.mobile_number, c.email, c.address, c.landmark, c.city, c.state, c.pincode, DATE(o.created_at) AS formatted_order_date FROM orders o JOIN customers c ON o.customer_id = c.customer_id WHERE o.order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    echo '<div class="container py-5"><div class="alert alert-warning">Order not found</div></div>';
    include('include/footer.php');
    exit;
}

// Fetch order items as array for flexible layout
$order_items = [];
$stmt_items = $mysqli->prepare("SELECT oi.*, p.title, p.image_url, p.product_id FROM order_items oi JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = ?");
$stmt_items->bind_param("i", $order_id);
$stmt_items->execute();
$res_items = $stmt_items->get_result();
while ($row = $res_items->fetch_assoc()) {
    $order_items[] = $row;
}
$stmt_items->close();
$item_count = count($order_items);
$total_qty = 0;
foreach ($order_items as $it) {
    $total_qty += (int)($it['quantity'] ?? 0);
}

try {
    $dt = new DateTime($order['created_at'] ?? 'now');
    $order_date_formatted = $dt->format('d M Y, h:i A');
} catch (Exception $e) {
    $order_date_formatted = 'N/A';
}

$currentDistributor = null;
if (!empty($order['distributor_id'])) {
    $stmt = $mysqli->prepare("SELECT distributor_id, distributor_code, distributor_name FROM distributors WHERE distributor_id = ? LIMIT 1");
    $stmt->bind_param('i', $order['distributor_id']);
    $stmt->execute();
    $stmt->bind_result($did, $dcode, $dname);
    if ($stmt->fetch()) {
        $currentDistributor = ['id' => (int)$did, 'code' => $dcode, 'name' => $dname];
    }
    $stmt->close();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$isCancelled = (($order['order_status'] ?? '') === 'Cancelled');
$subtotal_before_tax = (float)($order['subtotal'] ?? 0) - (float)($order['tax'] ?? 0);
$customer_initial = strtoupper(mb_substr(trim((string)($order['full_name'] ?? 'C')), 0, 1));
?>

<style>
.order-view-pro {
    --ov-bg: #f5f7fb;
    --ov-card: #ffffff;
    --ov-text: #172033;
    --ov-muted: #6f7a8a;
    --ov-border: #e7ecf3;
    --ov-primary: #2563eb;
    --ov-success: #16a34a;
    --ov-danger: #dc2626;
    --ov-warning: #d97706;
    --ov-info: #0891b2;
    background: var(--ov-bg);
    min-height: calc(100vh - 80px);
    padding: 28px 0 48px;
}
.order-view-pro .ov-shell { max-width: 1320px; margin: 0 auto; padding: 0 18px; }
.ov-hero {
    border: 1px solid rgba(255,255,255,.24);
    border-radius: 26px;
    background: radial-gradient(circle at top left, #3b82f6 0, #1d4ed8 38%, #111827 100%);
    color: #fff;
    box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
    overflow: hidden;
    position: relative;
}
.ov-hero:after {
    content: "";
    position: absolute;
    right: -80px;
    top: -90px;
    width: 260px;
    height: 260px;
    border-radius: 50%;
    background: rgba(255,255,255,.11);
}
.ov-hero-body { position: relative; z-index: 2; padding: 26px; }
.ov-title-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; flex-wrap: wrap; }
.ov-eyebrow { font-size: 13px; letter-spacing: .08em; text-transform: uppercase; opacity: .76; font-weight: 700; }
.ov-title { font-size: clamp(25px, 3vw, 38px); font-weight: 800; margin: 4px 0 8px; line-height: 1.1; }
.ov-subtitle { color: rgba(255,255,255,.78); display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; }
.ov-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.ov-action-btn {
    border: 0;
    border-radius: 999px;
    padding: 10px 18px;
    font-weight: 700;
    box-shadow: 0 10px 22px rgba(0,0,0,.16);
}
.ov-btn-light { background: #fff; color: #111827; }
.ov-btn-danger { background: #ef4444; color: #fff; }
.ov-btn-danger:hover, .ov-btn-light:hover { opacity: .92; }
.ov-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-top: 24px; }
.ov-kpi { background: rgba(255,255,255,.13); border: 1px solid rgba(255,255,255,.20); border-radius: 18px; padding: 15px; backdrop-filter: blur(10px); }
.ov-kpi-label { font-size: 12px; color: rgba(255,255,255,.68); font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
.ov-kpi-value { font-size: 19px; font-weight: 800; margin-top: 6px; }
.ov-grid { display: grid; grid-template-columns: minmax(0, 1fr) 390px; gap: 22px; margin-top: 22px; align-items: start; }
.ov-card { background: var(--ov-card); border: 1px solid var(--ov-border); border-radius: 22px; box-shadow: 0 10px 28px rgba(15, 23, 42, .06); overflow: hidden; }
.ov-card + .ov-card { margin-top: 20px; }
.ov-card-header { padding: 19px 22px; border-bottom: 1px solid var(--ov-border); display: flex; justify-content: space-between; gap: 12px; align-items: center; }
.ov-card-title { font-size: 17px; font-weight: 800; color: var(--ov-text); margin: 0; }
.ov-card-sub { color: var(--ov-muted); font-size: 13px; }
.ov-card-body { padding: 22px; }
.ov-badge { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 6px 11px; font-size: 12px; font-weight: 800; line-height: 1; }
.ov-badge-success { background: #dcfce7; color: #166534; }
.ov-badge-danger { background: #fee2e2; color: #991b1b; }
.ov-badge-warning { background: #fef3c7; color: #92400e; }
.ov-badge-info { background: #cffafe; color: #155e75; }
.ov-badge-muted { background: #eef2f7; color: #475569; }
.ov-alert-cancelled { background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; padding: 14px 18px; border-radius: 18px; margin-top: 18px; font-weight: 700; }
.ov-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.ov-table thead th { background: #f8fafc; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; padding: 13px 16px; border-bottom: 1px solid var(--ov-border); }
.ov-table tbody td { padding: 16px; border-bottom: 1px solid var(--ov-border); vertical-align: middle; color: var(--ov-text); }
.ov-table tbody tr:last-child td { border-bottom: 0; }
.ov-product { display: flex; align-items: center; gap: 13px; min-width: 280px; }
.ov-product-img { width: 58px; height: 58px; border-radius: 14px; object-fit: cover; background: #f1f5f9; border: 1px solid #e2e8f0; }
.ov-product-name { font-weight: 800; color: #172033; text-decoration: none; }
.ov-product-name:hover { color: var(--ov-primary); }
.ov-product-id { font-size: 12px; color: var(--ov-muted); margin-top: 3px; }
.ov-money { font-weight: 800; white-space: nowrap; }
.ov-qty-pill { display: inline-flex; min-width: 34px; height: 30px; padding: 0 10px; align-items: center; justify-content: center; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-weight: 800; }
.ov-summary { margin-left: auto; max-width: 430px; padding: 18px 22px 22px; border-top: 1px solid var(--ov-border); background: linear-gradient(180deg, #fff, #f8fafc); }
.ov-summary-row { display: flex; justify-content: space-between; gap: 18px; padding: 9px 0; color: #475569; }
.ov-summary-row strong { color: #172033; }
.ov-summary-total { margin-top: 8px; padding-top: 14px; border-top: 1px dashed #cbd5e1; font-size: 20px; font-weight: 900; color: #0f172a; }
.ov-person { display: flex; gap: 14px; align-items: center; }
.ov-avatar { width: 54px; height: 54px; border-radius: 18px; display: grid; place-items: center; background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; font-weight: 900; font-size: 22px; }
.ov-info-list { display: grid; gap: 13px; }
.ov-info-item { display: flex; gap: 12px; align-items: flex-start; }
.ov-info-icon { width: 34px; height: 34px; border-radius: 12px; background: #f1f5f9; display: grid; place-items: center; flex: 0 0 auto; }
.ov-info-label { color: var(--ov-muted); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.ov-info-value { color: var(--ov-text); font-weight: 700; margin-top: 2px; word-break: break-word; }
.ov-status-flow { display: grid; gap: 12px; }
.ov-step { display: flex; gap: 12px; align-items: flex-start; }
.ov-step-dot { width: 13px; height: 13px; border-radius: 999px; margin-top: 4px; background: #cbd5e1; box-shadow: 0 0 0 5px #f1f5f9; }
.ov-step.active .ov-step-dot { background: var(--ov-primary); box-shadow: 0 0 0 5px #dbeafe; }
.ov-step.done .ov-step-dot { background: var(--ov-success); box-shadow: 0 0 0 5px #dcfce7; }
.ov-step-title { font-weight: 800; color: #172033; }
.ov-step-note { color: var(--ov-muted); font-size: 13px; }
.ov-dist-current { border: 1px dashed #cbd5e1; background: #f8fafc; border-radius: 16px; padding: 13px; margin-bottom: 14px; }
.ov-input, .ov-select, .ov-comment-box textarea { border-radius: 14px !important; border-color: #dbe2ea !important; }
.ov-comment-item { border: 1px solid var(--ov-border); border-radius: 16px; padding: 13px; margin-bottom: 10px; background: #fbfdff; }
.ov-comment-form { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: stretch; }
.ov-mobile-items { display: none; }
@media (max-width: 1100px) { .ov-grid { grid-template-columns: 1fr; } }
@media (max-width: 768px) {
    .order-view-pro { padding-top: 14px; }
    .ov-shell { padding: 0 12px; }
    .ov-hero-body { padding: 20px; }
    .ov-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ov-actions { width: 100%; }
    .ov-actions .ov-action-btn, .ov-actions a { flex: 1; text-align: center; }
    .ov-table-wrap { display: none; }
    .ov-mobile-items { display: grid; gap: 12px; padding: 14px; }
    .ov-mobile-item { border: 1px solid var(--ov-border); border-radius: 18px; padding: 13px; background: #fff; }
    .ov-mobile-item-top { display: flex; gap: 12px; }
    .ov-mobile-line { display: flex; justify-content: space-between; gap: 12px; margin-top: 10px; color: #475569; }
    .ov-summary { max-width: none; margin-left: 0; }
    .ov-comment-form { grid-template-columns: 1fr; }
}
</style>

<?php if ($flash):
$TOAST_CLASS = [
    'success' => 'bg-success text-white',
    'danger'  => 'bg-danger text-white',
    'warning' => 'bg-warning text-dark',
    'info'    => 'bg-info text-dark',
];
$toastClass = $TOAST_CLASS[$flash['type']] ?? 'bg-secondary text-white';
?>
<div class="position-fixed top-50 start-50 translate-middle p-2" style="z-index:1080;">
    <div id="appToast" class="toast align-items-center shadow-lg rounded-3 <?= e($toastClass) ?>" role="status" aria-live="polite" aria-atomic="true" data-bs-autohide="true" data-bs-delay="4000">
        <div class="d-flex">
            <div class="toast-body fw-semibold"><?= e($flash['text']) ?></div>
            <button type="button" class="btn-close <?= (str_contains($toastClass, 'text-white') ? 'btn-close-white' : '') ?> me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="order-view-pro" id="top">
    <div class="ov-shell">
        <section class="ov-hero">
            <div class="ov-hero-body">
                <div class="ov-title-row">
                    <div>
                        <div class="ov-eyebrow">Order Details</div>
                        <h1 class="ov-title">Order #<?= e($order['invoice_number']) ?></h1>
                        <div class="ov-subtitle">
                            <span>Created: <?= e($order_date_formatted) ?></span>
                            <span>•</span>
                            <span><?= (int)$item_count ?> item<?= $item_count === 1 ? '' : 's' ?> / <?= (int)$total_qty ?> qty</span>
                            <span>•</span>
                            <span><?= e($order['full_name']) ?></span>
                        </div>
                    </div>

                    <div class="ov-actions">
                        <?php if (!$isCancelled): ?>
                            <form method="post" action="order_cancel.php" class="d-inline" onsubmit="return confirm('Cancel this order?');">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="order_id" value="<?= (int)$order['order_id'] ?>">
                                <input type="hidden" name="invoice" value="<?= e($order['invoice_number']) ?>">
                                <input type="hidden" name="return_to" value="view_order.php">
                                <button class="ov-action-btn ov-btn-danger" type="submit">Cancel Order</button>
                            </form>
                            <a href="edit_order.php?id=<?= (int)$order['order_id'] ?>" class="ov-action-btn ov-btn-light text-decoration-none">Edit Order</a>
                        <?php else: ?>
                            <span class="ov-badge ov-badge-danger">Cancelled</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isCancelled): ?>
                    <div class="ov-alert-cancelled">Order #<?= e($order['invoice_number']) ?> is marked as Cancelled.</div>
                <?php endif; ?>

                <div class="ov-kpi-grid">
                    <div class="ov-kpi">
                        <div class="ov-kpi-label">Grand Total</div>
                        <div class="ov-kpi-value"><?= money($order['grand_total']) ?></div>
                    </div>
                    <div class="ov-kpi">
                        <div class="ov-kpi-label">Payment</div>
                        <div class="ov-kpi-value"><?= e($order['payment_mode']) ?></div>
                    </div>
                    <div class="ov-kpi">
                        <div class="ov-kpi-label">Delivery</div>
                        <div class="ov-kpi-value"><?= e($order['delivery_status']) ?></div>
                    </div>
                    <div class="ov-kpi">
                        <div class="ov-kpi-label">Distributor</div>
                        <div class="ov-kpi-value"><?= $currentDistributor ? e($currentDistributor['name']) : 'Unassigned' ?></div>
                    </div>
                </div>
            </div>
        </section>

        <div class="ov-grid">
            <main>
                <section class="ov-card">
                    <div class="ov-card-header">
                        <div>
                            <h2 class="ov-card-title">Order Items</h2>
                            <div class="ov-card-sub">Product wise rate, quantity and amount</div>
                        </div>
                        <span class="ov-badge <?= status_class($order['delivery_status']) ?>"><?= e($order['delivery_status']) ?></span>
                    </div>

                    <div class="ov-table-wrap table-responsive">
                        <table class="ov-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Rate</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order_items as $item):
                                    $lineTotal = (float)$item['unit_price'] * (int)$item['quantity'];
                                    $img = trim((string)($item['image_url'] ?? ''));
                                ?>
                                <tr>
                                    <td>
                                        <div class="ov-product">
                                            <?php if ($img !== ''): ?>
                                                <img src="https://app.mysathee.com/<?= e($img) ?>" class="ov-product-img" alt="">
                                            <?php else: ?>
                                                <div class="ov-product-img d-flex align-items-center justify-content-center text-muted">No</div>
                                            <?php endif; ?>
                                            <div>
                                                <a href="product_view.php?product_id=<?= (int)$item['product_id'] ?>" class="ov-product-name"><?= e($item['title']) ?></a>
                                                <div class="ov-product-id">Product ID: <?= (int)$item['product_id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end"><span class="ov-money"><?= money($item['unit_price']) ?></span></td>
                                    <td class="text-center"><span class="ov-qty-pill"><?= (int)$item['quantity'] ?></span></td>
                                    <td class="text-end"><span class="ov-money"><?= money($lineTotal) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="ov-mobile-items">
                        <?php foreach ($order_items as $item):
                            $lineTotal = (float)$item['unit_price'] * (int)$item['quantity'];
                            $img = trim((string)($item['image_url'] ?? ''));
                        ?>
                            <div class="ov-mobile-item">
                                <div class="ov-mobile-item-top">
                                    <?php if ($img !== ''): ?>
                                        <img src="https://app.mysathee.com/<?= e($img) ?>" class="ov-product-img" alt="">
                                    <?php else: ?>
                                        <div class="ov-product-img"></div>
                                    <?php endif; ?>
                                    <div>
                                        <a href="product_view.php?product_id=<?= (int)$item['product_id'] ?>" class="ov-product-name"><?= e($item['title']) ?></a>
                                        <div class="ov-product-id">Product ID: <?= (int)$item['product_id'] ?></div>
                                    </div>
                                </div>
                                <div class="ov-mobile-line"><span>Rate</span><strong><?= money($item['unit_price']) ?></strong></div>
                                <div class="ov-mobile-line"><span>Qty</span><strong><?= (int)$item['quantity'] ?></strong></div>
                                <div class="ov-mobile-line"><span>Amount</span><strong><?= money($lineTotal) ?></strong></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="ov-summary">
                        <div class="ov-summary-row"><span>Subtotal</span><strong><?= money($subtotal_before_tax) ?></strong></div>
                        <div class="ov-summary-row"><span>GST / Tax</span><strong><?= money($order['tax']) ?></strong></div>
                        <div class="ov-summary-row"><span>Discount</span><strong><?= money($order['discount']) ?></strong></div>
                        <div class="ov-summary-row ov-summary-total"><span>Total</span><span><?= money($order['grand_total']) ?></span></div>
                    </div>
                </section>

                <section class="ov-card" id="comments">
                    <div class="ov-card-header">
                        <div>
                            <h2 class="ov-card-title">Order Comments / Updates</h2>
                            <div class="ov-card-sub">Internal follow-up notes for this order</div>
                        </div>
                    </div>
                    <div class="ov-card-body ov-comment-box">
                        <div id="order-comments-list" class="mb-3">
                            <div class="text-muted">Loading comments…</div>
                        </div>

                        <form id="order-comment-form" class="ov-comment-form">
                            <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
                            <textarea name="comment" class="form-control" rows="2" placeholder="Add a comment about this order..." required></textarea>
                            <button type="submit" class="btn btn-primary px-4">Add</button>
                        </form>
                        <div id="order-comment-status" class="small mt-2"></div>
                    </div>
                </section>
            </main>

            <aside>
                <section class="ov-card">
                    <div class="ov-card-header">
                        <h2 class="ov-card-title">Customer</h2>
                        <span class="ov-badge <?= status_class($order['payment_mode']) ?>"><?= e($order['payment_mode']) ?></span>
                    </div>
                    <div class="ov-card-body">
                        <div class="ov-person">
                            <div class="ov-avatar"><?= e($customer_initial) ?></div>
                            <div>
                                <div class="fw-bold fs-5"><?= e($order['full_name']) ?></div>
                                <div class="text-muted small"><?= e($order['order_data'] ?? 'New') ?> Customer</div>
                            </div>
                        </div>

                        <div class="ov-info-list mt-4">
                            <div class="ov-info-item">
                                <div class="ov-info-icon">☎</div>
                                <div>
                                    <div class="ov-info-label">Mobile</div>
                                    <div class="ov-info-value"><a href="tel:<?= e($order['mobile_number']) ?>" class="text-decoration-none"><?= e($order['mobile_number']) ?></a></div>
                                </div>
                            </div>
                            <div class="ov-info-item">
                                <div class="ov-info-icon">✉</div>
                                <div>
                                    <div class="ov-info-label">Email</div>
                                    <div class="ov-info-value"><?= trim((string)$order['email']) !== '' ? '<a href="mailto:' . e($order['email']) . '" class="text-decoration-none">' . e($order['email']) . '</a>' : 'Not available' ?></div>
                                </div>
                            </div>
                            <div class="ov-info-item">
                                <div class="ov-info-icon">⌂</div>
                                <div>
                                    <div class="ov-info-label">Shipping Address</div>
                                    <div class="ov-info-value">
                                        <?= e($order['address']) ?><br>
                                        <?php if (!empty($order['landmark'])): ?>Landmark: <?= e($order['landmark']) ?><br><?php endif; ?>
                                        <?= e($order['city']) ?><?= !empty($order['state']) ? ', ' . e($order['state']) : '' ?> - <?= e($order['pincode']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="ov-card">
                    <div class="ov-card-header">
                        <h2 class="ov-card-title">Order Status</h2>
                    </div>
                    <div class="ov-card-body">
                        <?php
                        $status = $order['delivery_status'] ?? '';
                        $isAssigned = in_array($status, ['Assigned', 'Ready to Delivery', 'Delivered'], true);
                        $isReady = in_array($status, ['Ready to Delivery', 'Delivered'], true);
                        $isDelivered = ($status === 'Delivered');
                        ?>
                        <div class="ov-status-flow">
                            <div class="ov-step done"><div class="ov-step-dot"></div><div><div class="ov-step-title">Order Created</div><div class="ov-step-note"><?= e($order_date_formatted) ?></div></div></div>
                            <div class="ov-step <?= $isAssigned ? 'done' : 'active' ?>"><div class="ov-step-dot"></div><div><div class="ov-step-title">Distributor Assigned</div><div class="ov-step-note"><?= $currentDistributor ? e($currentDistributor['name']) : 'Waiting for assignment' ?></div></div></div>
                            <div class="ov-step <?= $isReady ? 'done' : '' ?>"><div class="ov-step-dot"></div><div><div class="ov-step-title">Ready to Delivery</div><div class="ov-step-note">Current: <?= e($status) ?></div></div></div>
                            <div class="ov-step <?= $isDelivered ? 'done' : '' ?>"><div class="ov-step-dot"></div><div><div class="ov-step-title">Delivered</div><div class="ov-step-note">Final delivery confirmation</div></div></div>
                        </div>
                    </div>
                </section>

                <?php if (!$isCancelled): ?>
                <section class="ov-card" id="distCard">
                    <div class="ov-card-header">
                        <div>
                            <h2 class="ov-card-title">Distributor Management</h2>
                            <div class="ov-card-sub">Assign or change order distributor</div>
                        </div>
                    </div>
                    <div class="ov-card-body">
                        <div class="ov-dist-current">
                            <?php if ($currentDistributor): ?>
                                <div class="ov-info-label">Current Distributor</div>
                                <div class="fw-bold mt-1"><span class="ov-badge ov-badge-muted me-1"><?= e($currentDistributor['code']) ?></span> <?= e($currentDistributor['name']) ?></div>
                            <?php else: ?>
                                <div class="fw-bold text-muted">Currently Unassigned</div>
                            <?php endif; ?>
                        </div>

                        <input type="hidden" id="distOrderId" value="<?= (int)$order['order_id'] ?>">
                        <label class="form-label fw-bold">Search Distributor</label>
                        <input type="text" id="distSearch" class="form-control ov-input" placeholder="Type code / name / contact / mobile">
                        <div class="form-text mb-3">Type to search. Results appear below.</div>

                        <label class="form-label fw-bold">Select Distributor</label>
                        <select id="distSelect" class="form-select ov-select" size="7"></select>

                        <div class="d-grid gap-2 mt-3">
                            <button type="button" class="btn btn-primary" id="btnDistAssign">Update Distributor</button>
                            <button type="button" class="btn btn-outline-warning" id="btnDistUnassign">Unassign</button>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</div>

<div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
    <div id="distToast" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="distToastMsg">Done.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const appToast = document.getElementById('appToast');
    if (appToast && window.bootstrap && bootstrap.Toast) {
        new bootstrap.Toast(appToast).show();
    }
});
</script>

<?php if (!$isCancelled): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const orderId    = document.getElementById('distOrderId');
    const distSearch = document.getElementById('distSearch');
    const distSelect = document.getElementById('distSelect');
    const btnAssign  = document.getElementById('btnDistAssign');
    const btnUnasgn  = document.getElementById('btnDistUnassign');
    const toastEl    = document.getElementById('distToast');
    const toastMsg   = document.getElementById('distToastMsg');

    if (!orderId || !distSearch || !distSelect || !btnAssign || !btnUnasgn || !toastEl || !toastMsg) return;

    let bsToast = null;
    if (window.bootstrap && bootstrap.Toast) {
        bsToast = new bootstrap.Toast(toastEl, { delay: 2200 });
    }

    function showToast(message, variant = 'primary') {
        toastEl.className = `toast align-items-center text-bg-${variant} border-0`;
        toastMsg.textContent = message;
        if (bsToast) {
            bsToast.show();
        } else {
            alert(message);
        }
    }

    let t;
    distSearch.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
            fetchDistributors(distSearch.value.trim());
        }, 350);
    });

    async function fetchDistributors(q) {
        try {
            distSelect.innerHTML = '<option disabled>Loading...</option>';
            const res = await fetch('distributor_search_ajax.php?q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (res.ok && data.success) {
                distSelect.innerHTML = '';
                (data.results || []).forEach(function (opt) {
                    const o = document.createElement('option');
                    o.value = opt.id;
                    o.textContent = opt.text;
                    distSelect.appendChild(o);
                });
                if (!distSelect.options.length) {
                    distSelect.innerHTML = '<option disabled>No results</option>';
                }
            } else {
                distSelect.innerHTML = '<option disabled>Error loading list</option>';
            }
        } catch (e) {
            console.error(e);
            distSelect.innerHTML = '<option disabled>Network error</option>';
        }
    }

    btnAssign.addEventListener('click', async function () {
        const distId = distSelect.value || '';
        if (!distId) {
            showToast('Select a distributor', 'warning');
            return;
        }

        btnAssign.disabled = true;
        btnUnasgn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('order_id', orderId.value);
            fd.append('distributor_id', distId);
            const res = await fetch('order_assign_distributor_ajax.php', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (res.ok && data.success) {
                showToast('Distributor updated', 'success');
                setTimeout(function () { location.reload(); }, 700);
            } else {
                showToast(data.message || 'Failed to update', 'danger');
            }
        } catch (e) {
            console.error(e);
            showToast('Network error', 'danger');
        } finally {
            btnAssign.disabled = false;
            btnUnasgn.disabled = false;
        }
    });

    btnUnasgn.addEventListener('click', async function () {
        if (!confirm('Unassign distributor from this order?')) return;

        btnAssign.disabled = true;
        btnUnasgn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('order_id', orderId.value);
            fd.append('distributor_id', '');
            const res = await fetch('order_assign_distributor_ajax.php', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (res.ok && data.success) {
                showToast('Distributor unassigned', 'success');
                setTimeout(function () { location.reload(); }, 700);
            } else {
                showToast(data.message || 'Failed to unassign', 'danger');
            }
        } catch (e) {
            console.error(e);
            showToast('Network error', 'danger');
        } finally {
            btnAssign.disabled = false;
            btnUnasgn.disabled = false;
        }
    });

    fetchDistributors('');
});
</script>
<?php endif; ?>

<script>
(function() {
    const orderId = <?= (int)$order_id ?>;
    const listEl = document.getElementById('order-comments-list');
    const formEl = document.getElementById('order-comment-form');
    const statusEl = document.getElementById('order-comment-status');

    if (!listEl || !formEl || !statusEl) return;

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.innerText = s || '';
        return d.innerHTML;
    }

    function renderComments(comments) {
        if (!comments || comments.length === 0) {
            listEl.innerHTML = '<p class="text-muted m-0">No comments yet.</p>';
            return;
        }
        listEl.innerHTML = comments.map(c => `
            <div class="ov-comment-item">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <strong>@${escapeHtml(c.user_name || 'System')}</strong>
                    <span class="text-muted small">${escapeHtml(c.created_at_fmt)}</span>
                </div>
                <div class="mt-1">${escapeHtml(c.comment).replace(/\n/g,'<br>')}</div>
            </div>
        `).join('');
    }

    async function loadComments() {
        try {
            const res = await fetch('order_comment_list.php?order_id=' + orderId, { credentials: 'same-origin' });
            const data = await res.json();
            if (data.ok) renderComments(data.comments);
            else listEl.innerHTML = '<div class="text-danger">Failed to load comments.</div>';
        } catch (e) {
            listEl.innerHTML = '<div class="text-danger">Error loading comments.</div>';
        }
    }

    async function addComment(formData) {
        statusEl.textContent = 'Saving…';
        try {
            const res = await fetch('order_comment_add.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.ok) {
                formEl.reset();
                statusEl.textContent = 'Added!';
                await loadComments();
            } else {
                statusEl.innerHTML = '<span class="text-danger">Failed to add comment.</span>';
            }
        } catch (e) {
            statusEl.innerHTML = '<span class="text-danger">Error adding comment.</span>';
        } finally {
            setTimeout(() => statusEl.textContent = '', 1200);
        }
    }

    formEl.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(formEl);
        const comment = (fd.get('comment') || '').trim();
        if (!comment) return;
        addComment(fd);
    });

    loadComments();
})();
</script>

<?php include('include/footer.php'); ?>

<?php
// FILE: distributor_portal.php
// Requires: include/db.php (must set $mysqli as mysqli connection)

session_start();
require_once __DIR__ . '/include/db.php'; // $mysqli

// ----------------- Page state -----------------
$distLoggedIn = isset($_SESSION['dist_id']) && (int)$_SESSION['dist_id'] > 0;
$distId   = $distLoggedIn ? (int)$_SESSION['dist_id'] : 0;
$distName = $distLoggedIn ? htmlspecialchars($_SESSION['dist_name'] ?? '') : '';
$distCode = $distLoggedIn ? htmlspecialchars($_SESSION['dist_code'] ?? '') : '';

$DEBUG = isset($_GET['debug']) || (isset($_POST['debug']) && $_POST['debug'] == '1');
if ($DEBUG) { ini_set('display_errors', 1); error_reporting(E_ALL); }

// --- Helpers (only for distributors table safety) ---
function ensure_column(mysqli $db, string $table, string $column, string $ddl): void {
  $qt = $db->real_escape_string($table);
  $qc = $db->real_escape_string($column);
  $res = $db->query("SHOW COLUMNS FROM `{$qt}` LIKE '{$qc}'");
  if (!$res || $res->num_rows === 0) {
    $sql = "ALTER TABLE `{$qt}` ADD COLUMN `{$qc}` {$ddl}";
    $db->query($sql);
  }
}
function ensure_index(mysqli $db, string $table, string $index, string $colsCsv): void {
  $qt = $db->real_escape_string($table);
  $qi = $db->real_escape_string($index);
  $res = $db->query("SHOW INDEX FROM `{$qt}` WHERE Key_name='{$qi}'");
  if ($res && $res->num_rows === 0) {
    $db->query("ALTER TABLE `{$qt}` ADD INDEX `{$qi}` ({$colsCsv})");
  }
}

// Ensure login PIN column for distributors (safe; no changes to orders/customers)
ensure_column($mysqli, 'distributors', 'login_pin_hash', "VARCHAR(255) NULL");
ensure_index($mysqli, 'distributors', 'idx_distributors_mobile', '`mobile_number`');

// ----------------- AJAX (same-file endpoints) -----------------
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax && isset($_POST['action'])) {
  header('Content-Type: application/json');
  try {
    $action = $_POST['action'];

    // Step 1: check_mobile
    if ($action === 'check_mobile') {
      $mobile = preg_replace('/\D+/', '', $_POST['mobile'] ?? '');
      if (strlen($mobile) !== 10) {
        http_response_code(422); echo json_encode(['success'=>false,'message'=>'Enter a valid 10-digit mobile number.']); exit;
      }
      $stmt = $mysqli->prepare("SELECT distributor_id, distributor_name, login_pin_hash FROM distributors WHERE mobile_number = ? LIMIT 1");
      if(!$stmt){ throw new Exception('Prepare failed: '.$mysqli->error); }
      $stmt->bind_param('s', $mobile);
      $stmt->execute();
      $stmt->bind_result($did, $dname, $pin_hash);
      if ($stmt->fetch()) {
        if (empty($pin_hash)) {
          http_response_code(423);
          echo json_encode(['success'=>false,'message'=>'PIN not set for this distributor.']);
        } else {
          echo json_encode(['success'=>true,'message'=>'Mobile verified. Proceed to PIN.','distributor_name'=>$dname]);
        }
      } else {
        http_response_code(404); echo json_encode(['success'=>false,'message'=>'No distributor found with this mobile']);
      }
      $stmt->close();
      exit;
    }

    // Step 2: check_pin
    if ($action === 'check_pin') {
      $mobile = preg_replace('/\D+/', '', $_POST['mobile'] ?? '');
      $pin    = trim($_POST['pin'] ?? '');
      if (strlen($mobile) !== 10) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid mobile']); exit; }
      if (!preg_match('/^\d{4}$/', $pin)) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Enter 4 digit PIN']); exit; }

      $stmt = $mysqli->prepare("SELECT distributor_id, distributor_code, distributor_name, login_pin_hash FROM distributors WHERE mobile_number = ? LIMIT 1");
      if(!$stmt){ throw new Exception('Prepare failed: '.$mysqli->error); }
      $stmt->bind_param('s', $mobile);
      $stmt->execute();
      $stmt->bind_result($did, $dcode, $dname, $pin_hash);
      if ($stmt->fetch() && !empty($pin_hash) && password_verify($pin, $pin_hash)) {
        $_SESSION['dist_id']   = (int)$did;
        $_SESSION['dist_code'] = (string)$dcode;
        $_SESSION['dist_name'] = (string)$dname;
        echo json_encode(['success'=>true,'message'=>'Login successful']);
      } else {
        http_response_code(401); echo json_encode(['success'=>false,'message'=>'Incorrect PIN']);
      }
      $stmt->close();
      exit;
    }

    // Order detail: now includes items + totals
            if ($action === 'order_detail') {
        $distId = (int)($_SESSION['dist_id'] ?? 0);
        if ($distId <= 0) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid order id']); exit; }

        // helper: get columns for a table
        $getCols = function(mysqli $db, string $table): array {
            $cols = [];
            if ($res = $db->query("SHOW COLUMNS FROM `{$db->real_escape_string($table)}`")) {
            while ($row = $res->fetch_assoc()) $cols[] = $row['Field'];
            $res->close();
            }
            return $cols;
        };

        // ---- 1) Load order + customer ----
        $ordersCols = $getCols($mysqli, 'orders');
        $has_subtotal   = in_array('subtotal', $ordersCols);
        $has_tax        = in_array('tax', $ordersCols);
        $has_discount   = in_array('discount', $ordersCols);
        $has_grand_total= in_array('grand_total', $ordersCols);

        $selectTotals = [];
        $selectTotals[] = $has_subtotal    ? 'o.subtotal'    : 'NULL AS subtotal';
        $selectTotals[] = $has_tax         ? 'o.tax'         : 'NULL AS tax';
        $selectTotals[] = $has_discount    ? 'o.discount'    : 'NULL AS discount';
        $selectTotals[] = $has_grand_total ? 'o.grand_total' : 'NULL AS grand_total';
        $selectTotalsSQL = implode(', ', $selectTotals);

       $sql = "SELECT
                    o.order_id,
                    o.invoice_number,
                    o.distributor_status,
                    o.distributor_assigned_at,
                    $selectTotalsSQL,
                    c.full_name,
                    c.mobile_number,
                    c.address,
                    c.landmark,
                    c.city,
                    c.state,
                    c.pincode
                FROM orders o
                INNER JOIN customers c ON o.customer_id = c.customer_id
                WHERE o.order_id = ? AND o.distributor_id = ?
                LIMIT 1";
        if (!($stmt = $mysqli->prepare($sql))) {
            throw new Exception('Prepare(order) failed: '.$mysqli->error);
        }
        $stmt->bind_param('ii', $order_id, $distId);
        $stmt->execute();
        $stmt->bind_result(
            $oid, $invoice_number, $status, $assigned_at,
            $subtotal, $tax, $discount, $grand_total,
            $cname, $cmobile, $addr, $landmark, $city, $state, $pincode
        );
        if (!$stmt->fetch()) {
            http_response_code(404); echo json_encode(['success'=>false,'message'=>'Order not found or not assigned to you']); exit;
        }
        $stmt->close();

        // ---- 2) Items (prefer order_items name; fallback to products.title) ----
$items = [];

// Discover columns (only if you have that helper; else hardcode as below)
$oiCols   = $getCols($mysqli, 'order_items');
$prodCols = $getCols($mysqli, 'products');

// order_items column aliases
$col_name = in_array('product_name', $oiCols) ? 'oi.product_name'
          : (in_array('item_name', $oiCols)   ? 'oi.item_name'
          : (in_array('name', $oiCols)        ? 'oi.name' : null));

$col_sku  = in_array('sku', $oiCols) ? 'oi.sku' : null;
$col_qty  = in_array('quantity', $oiCols) ? 'oi.quantity'
          : (in_array('qty', $oiCols)      ? 'oi.qty' : null);
$col_unit = in_array('unit_price', $oiCols) ? 'oi.unit_price'
          : (in_array('price', $oiCols)     ? 'oi.price'
          : (in_array('rate', $oiCols)      ? 'oi.rate' : null));
$col_id   = in_array('id', $oiCols) ? 'oi.id'
          : (in_array('order_item_id', $oiCols) ? 'oi.order_item_id' : null);

// product_id column in order_items (needed for the JOIN)
$col_pid  = in_array('product_id', $oiCols) ? 'oi.product_id'
          : (in_array('pid', $oiCols)       ? 'oi.pid' : null);

// If name missing in order_items, fallback to products.<title>
$p_name = null; $p_sku = null;
if (!$col_name && $col_pid && !empty($prodCols)) {
  // PRIORITY: products.title (as you requested), then other usual suspects
  if (in_array('title', $prodCols))         $p_name = 'p.title';
  elseif (in_array('product_name', $prodCols)) $p_name = 'p.product_name';
  elseif (in_array('name', $prodCols))      $p_name = 'p.name';

  if (in_array('sku', $prodCols))           $p_sku = 'p.sku';
  elseif (in_array('product_sku', $prodCols)) $p_sku = 'p.product_sku';
}

// Build SELECT for items
$sel = [];
$sel[] = $col_name ? "{$col_name} AS item_name"
       : ($p_name ? "{$p_name} AS item_name"
       : "CONCAT('Item #', COALESCE({$col_pid},0)) AS item_name");

$sel[] = $col_sku ? "{$col_sku} AS sku"
       : ($p_sku ? "{$p_sku} AS sku" : "'' AS sku");

$sel[] = $col_qty ? "{$col_qty} AS qty" : "0 AS qty";
$sel[] = $col_unit ? "{$col_unit} AS unit_price" : "0 AS unit_price";

$join = ($p_name || $p_sku) ? "LEFT JOIN products p ON p.product_id = oi.product_id" : "";
$orderBy = $col_id ? $col_id : ($col_pid ? $col_pid : 'oi.order_id');

$sqlItems = "
  SELECT ".implode(', ', $sel)."
  FROM order_items oi
  $join
  WHERE oi.order_id = ?
  ORDER BY $orderBy ASC
";

if ($stmt = $mysqli->prepare($sqlItems)) {
  $stmt->bind_param('i', $order_id);
  $stmt->execute();
  $stmt->bind_result($iname, $isku, $iqty, $uprice);
  while ($stmt->fetch()) {
    $line_total = (float)$iqty * (float)$uprice;
    $items[] = [
      'name'       => (string)($iname ?? ''),
      'qty'        => (float)($iqty ?? 0),
      'unit_price' => (float)($uprice ?? 0),
      'line_total' => (float)$line_total,
    ];
  }
  $stmt->close();
}


        // ---- 3) If totals missing on orders, compute from items ----
        if (!$has_subtotal || !$has_grand_total) {
            $computed_subtotal = 0.0;
            foreach ($items as $it) $computed_subtotal += (float)$it['line_total'];
            if (!$has_subtotal)    $subtotal = $computed_subtotal;
            if (!$has_tax)         $tax = (float)($tax ?? 0);
            if (!$has_discount)    $discount = (float)($discount ?? 0);
            if (!$has_grand_total) $grand_total = $subtotal + $tax - $discount;
        }

        echo json_encode([
            'success'=>true,
            'order'=>[
            'order_id'        => (int)$oid,
            'invoice_number'  => (string)$invoice_number,
            'status'          => (string)($status ?: 'pending'),
            'assigned_at'     => (string)($assigned_at ?: ''),
            'customer_name'   => (string)$cname,
            'customer_mobile' => (string)$cmobile,
            'address'         => (string)$addr,
            'landmark'        => (string)($landmark ?? ''),
            'city'            => (string)($city ?? ''),
            'state'           => (string)($state ?? ''),
            'pincode'         => (string)($pincode ?? ''),
            'totals'          => [
                'subtotal'    => (float)($subtotal ?? 0),
                'tax'         => (float)($tax ?? 0),
                'discount'    => (float)($discount ?? 0),
                'grand_total' => (float)($grand_total ?? 0),
            ],
            'items'           => $items,
            ]
        ]);
        exit;
        }


    // Order action: accept, not_my_area, delivered
    if ($action === 'order_action') {
      $distId = (int)($_SESSION['dist_id'] ?? 0);
      if ($distId <= 0) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
      $order_id = (int)($_POST['order_id'] ?? 0);
      $op = trim($_POST['op'] ?? '');
      if ($order_id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid order id']); exit; }

      // check assignment + current status
      $stmt = $mysqli->prepare("SELECT distributor_id, distributor_status FROM orders WHERE order_id=? LIMIT 1");
      if(!$stmt){ throw new Exception('Prepare(check) failed: '.$mysqli->error); }
      $stmt->bind_param('i', $order_id);
      $stmt->execute();
      $stmt->bind_result($ownDist, $curStatus);
      if(!$stmt->fetch()){ http_response_code(404); echo json_encode(['success'=>false,'message'=>'Order not found']); exit; }
      $stmt->close();
      if ((int)$ownDist !== $distId) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'This order is not assigned to you']); exit; }
      $curStatus = $curStatus ?: 'pending';

      if ($op === 'accept') {
        if ($curStatus === 'delivered') { http_response_code(409); echo json_encode(['success'=>false,'message'=>'Already delivered']); exit; }
        $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='accepted', order_status='Ready to Delivery', distributor_status_at=NOW() WHERE order_id=? LIMIT 1");
        $stmt->bind_param('i', $order_id); $stmt->execute(); $stmt->close();

        // comment
        if ($stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, distributor_id, comment, created_at) VALUES (?, ?, ?, NOW())")) {
          $comment = "Order accept by {$distName} distributor code is {$distCode}";
          $stmt->bind_param("iis", $order_id, $distId, $comment);
          $stmt->execute(); $stmt->close();
        }

        echo json_encode(['success'=>true,'new_status'=>'accepted']); exit;

      } elseif ($op === 'not_my_area') {
        $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='not_my_area', order_status='Change distributor', distributor_status_at=NOW(), distributor_id=NULL WHERE order_id=? LIMIT 1");
        $stmt->bind_param('i', $order_id); $stmt->execute(); $stmt->close();

        // comment
        if ($stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, distributor_id, comment, created_at) VALUES (?, ?, ?, NOW())")) {
          $comment = "The order was rejected by the distributor {$distName} because it is not his area. code is {$distCode}";
          $stmt->bind_param("iis", $order_id, $distId, $comment);
          $stmt->execute(); $stmt->close();
        }

        echo json_encode(['success'=>true,'new_status'=>'not_my_area']); exit;

      } elseif ($op === 'delivered') {
        if ($curStatus !== 'accepted') { http_response_code(409); echo json_encode(['success'=>false,'message'=>'Accept order before marking delivered']); exit; }
        $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='delivered', order_status='delivered', distributor_status_at=NOW(), delivered_at=NOW() WHERE order_id=? LIMIT 1");
        $stmt->bind_param('i', $order_id); $stmt->execute(); $stmt->close();

        // comment
        if ($stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, distributor_id, comment, created_at) VALUES (?, ?, ?, NOW())")) {
          $comment = "The {$distName} distributor successfully delivered the order. code is {$distCode}";
          $stmt->bind_param("iis", $order_id, $distId, $comment);
          $stmt->execute(); $stmt->close();
        }

        echo json_encode(['success'=>true,'new_status'=>'delivered']); exit;

      } else {
        http_response_code(400); echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
      }
    }

    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Bad request']); exit;

  } catch (Throwable $e) {
    http_response_code(500);
    error_log('[DistributorPortal AJAX] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    echo json_encode(['success'=>false,'message'=> $DEBUG ? ('Server error: '.$e->getMessage()) : 'Server error']);
    exit;
  }
}

// ----------------- Logout -----------------
if (isset($_GET['logout'])) {
  unset($_SESSION['dist_id'], $_SESSION['dist_code'], $_SESSION['dist_name']);
  header('Location: '.$_SERVER['PHP_SELF']);
  exit;
}

// ----------------- Paging/Search -----------------
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20; $offset = ($page - 1) * $limit;
$q = trim($_GET['q'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <title>Distributor Portal</title>

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Inter font (optional, looks crisp on mobile) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
      /* brand colors for the gradient badge */
      --brand:   #5b8def;
      --brand-2: #6dd5fa;

      --bg: #f6f7fb;            /* page bg */
      --card-radius: 16px;      /* rounded cards */
    }

    body{
      background: var(--bg);
      font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans";
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    /* -------- MOBILE HEADER (xs only) -------- */
    .app-header-mobile{ display:none; }
    @media (max-width: 576px){
      .app-header-mobile{
        display:block;
        position: sticky;
        top: 10px;
        z-index: 1020;
        padding: 0 10px;
      }
      .app-header-desktop{ display:none !important; } /* hide desktop header on mobile */
    }

    .mobile-bar{
      background:#ffffff;
      border-radius: var(--card-radius);
      padding: 10px 12px;
      display:flex;
      align-items:center;
      gap:.75rem;
      box-shadow: 0 8px 24px rgba(15,23,42,.08);
    }

    .brand-badge{
      width:42px; height:42px;
      border-radius: 12px;
      background: linear-gradient(135deg,var(--brand),var(--brand-2));
      color:#fff;
      display:grid; place-items:center;
      box-shadow: 0 6px 16px rgba(91,141,239,.35);
      flex: 0 0 42px;
    }
    .brand-badge svg{ width:20px; height:20px; }

    .title-wrap{ line-height:1.05; }
    .title-wrap .title{ font-weight:600; font-size:1.05rem; margin:0; }
    .title-wrap .subtitle{ font-size:.8rem; color:#6b7280; margin-top:2px; }

    .user-avatar{
      width:34px; height:34px; border-radius:50%;
      background: linear-gradient(135deg,var(--brand),var(--brand-2));
      color:#fff; font-weight:700; font-size:.95rem;
      display:flex; align-items:center; justify-content:center;
      box-shadow: inset 0 0 0 2px rgba(255,255,255,.25);
    }

    /* -------- DESKTOP HEADER (sm and up) -------- */
    .app-header-desktop{
      margin-bottom: 1.25rem;
    }
    .app-header-card{
      background:#fff;
      border-radius: var(--card-radius);
      padding: 14px 18px;
      box-shadow: 0 10px 30px rgba(15,23,42,.08);
    }
    .code-badge{
      background: #e7f1ff;
      color:#2563eb;
      border: 1px solid #cfe3ff;
    }

    /* your existing utilities */
    .card{ border-radius: var(--card-radius); }
    .pin-input{ letter-spacing:.6rem; font-family:monospace; font-size:1.2rem; }
    .sa-badge{ vertical-align: middle; }
  </style>
</head>
<body>

<div class="container-xxl py-4">

  <!-- ===== MOBILE HEADER (only <=576px) ===== -->
  <header class="app-header-mobile d-sm-none">
    <div class="mobile-bar">
      <div class="brand-badge">
        <!-- Feather icon will be injected -->
        <svg xmlns="http://www.w3.org/2000/svg" class="feather feather-truck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="1" y="3" width="15" height="13"></rect>
          <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
          <circle cx="5.5" cy="18.5" r="2.5"></circle>
          <circle cx="18.5" cy="18.5" r="2.5"></circle>
        </svg>
      </div>

      <div class="title-wrap">
        <div class="title">Distributor Portal</div>
        <div class="subtitle">Hi. <?php echo $distName.'('.$distCode.')'; ?></div>
      </div>

      <?php if(!empty($distLoggedIn)): ?>
        <div class="ms-auto d-flex align-items-center gap-2">
          <div class="user-avatar">
            <?php echo strtoupper(mb_substr(($distName ?? 'U'), 0, 1, 'UTF-8')); ?>
          </div>
          <a href="?logout=1" class="btn btn-outline-secondary btn-sm px-2 py-1" title="Logout">
            <!-- Feather icon -->
            <svg xmlns="http://www.w3.org/2000/svg" class="feather feather-log-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
          </a>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <!-- ===== DESKTOP HEADER (>=576px) ===== -->
  <div class="app-header-desktop">
    <div class="app-header-card d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-3">
        <h3 class="mb-0">Distributor Portal</h3>
      </div>
      <?php if(!empty($distLoggedIn)): ?>
        <div class="d-flex align-items-center gap-2">
          <div class="text-nowrap">
            <span class="me-2">Logged in as</span>
            <span class="badge rounded-pill code-badge align-middle"><?php echo htmlspecialchars($distCode ?? ''); ?></span>
            <span class="ms-2 fw-semibold"><?php echo htmlspecialchars($distName ?? ''); ?></span>
          </div>
          <a href="?logout=1" class="btn btn-sm btn-outline-danger ms-2">
            <!-- Feather icon -->
            <svg xmlns="http://www.w3.org/2000/svg" class="feather feather-log-out me-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            Logout
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== Your page content starts here ===== -->
  <div class="content pt-3">

  <?php if (!$distLoggedIn): ?>
    <!-- Login Card -->
    <div class="row justify-content-center">
      <div class="col-md-7 col-lg-6">
        <div class="card shadow-sm"><div class="card-body p-4">
          <h5 class="mb-3">Login with Mobile & PIN</h5>
          <div id="stepMobile">
            <label class="form-label">Mobile Number</label>
            <input type="tel" id="mobile" class="form-control" maxlength="10" placeholder="10-digit mobile">
            <div class="form-text">We will check if this number is registered.</div>
            <button class="btn btn-primary mt-3" id="btnCheckMobile">Continue</button>
          </div>
          <div id="stepPin" class="mt-4" style="display:none;">
            <label class="form-label">4-digit PIN</label>
            <input type="password" id="pin" class="form-control pin-input" maxlength="4" placeholder="••••" inputmode="numeric" pattern="\d{4}">
            <div class="form-text">Enter your 4-digit login PIN.</div>
            <button class="btn btn-success mt-3" id="btnLogin">Login</button>
            <button class="btn btn-link mt-3" id="btnBack">Back</button>
          </div>
        </div></div>
      </div>
    </div>
  <?php else: ?>
    <!-- Orders List -->
    <div class="card shadow-sm"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Assigned Orders</h5>
        <form class="d-flex" method="get" action="">
          <input type="hidden" name="page" value="1">
          <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control form-control-sm me-2" placeholder="Search order id / customer name">
          <button class="btn btn-sm btn-outline-secondary">Search</button>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:140px;">Invoice</th>
              <th>Customer</th>
              <th style="width:140px;">Mobile</th>
              <th style="width:140px;">Status</th>
              <th style="width:200px;">Assigned At</th>
              <th style="width:140px;">Total (₹)</th>
              <th style="width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $total = 0;
            $where = 'WHERE o.distributor_id = ?';
            $types = 'i';
            $params = [$distId];

            if ($q !== '') {
              if (ctype_digit($q)) {
                // search by order_id or invoice number numeric
                $where .= ' AND (o.order_id = ? OR o.invoice_number = ?)';
                $types .= 'is';
                $params[] = (int)$q;
                $params[] = $q;
              } else {
                $where .= ' AND (c.full_name LIKE ? OR o.invoice_number LIKE ?)';
                $types .= 'ss';
                $like = '%'.$q.'%';
                $params[] = $like; $params[] = $like;
              }
            }

            // COUNT
            $sqlC = "SELECT COUNT(*) FROM orders o INNER JOIN customers c ON o.customer_id=c.customer_id $where";
            $stmt = $mysqli->prepare($sqlC);
            if ($stmt) {
              $stmt->bind_param($types, ...$params);
              $stmt->execute();
              $stmt->bind_result($cnt);
              $stmt->fetch();
              $total = (int)($cnt ?? 0);
              $stmt->close();
            }

            $pages = max(1, (int)ceil($total / $limit));
            if ($page > $pages) { $page = $pages; $offset = ($page-1)*$limit; }

            // LIST
             $limit_i = (int)$limit; $offset_i = (int)$offset;
             $sql = "SELECT 
                      o.invoice_number,
                      o.order_id,
                      c.full_name,
                      c.mobile_number,
                      o.distributor_status,
                      o.distributor_assigned_at,
                      o.grand_total
                    FROM orders o
                    INNER JOIN customers c ON o.customer_id=c.customer_id
                    $where
                    ORDER BY o.order_id DESC
                    LIMIT $limit_i OFFSET $offset_i";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
              $stmt->bind_param($types, ...$params);
              $stmt->execute();
              $stmt->bind_result($oinvoice,$oid, $cname, $cmobile, $status, $assignedAt, $grandTotal);
              $had = false;
              while ($stmt->fetch()) {
                $had = true; $st = $status ?: 'pending';
                $badge = ($st==='accepted'?'info':($st==='delivered'?'success':($st==='not_my_area'?'warning':'secondary')));
                echo '<tr>';
                echo '<td>'.htmlspecialchars((string)$oinvoice).'</td>';
                echo '<td>'.htmlspecialchars((string)$cname).'</td>';
                echo '<td>'.htmlspecialchars((string)$cmobile).'</td>';
                echo '<td><span class="badge text-bg-'.$badge.'">'.htmlspecialchars($st).'</span></td>';
                echo '<td>'.htmlspecialchars((string)$assignedAt).'</td>';
                echo '<td class="text-end">'.number_format((float)$grandTotal, 2).'</td>';
                echo '<td><button type="button" class="btn btn-sm btn-outline-primary btn-view" data-order="'.(int)$oid.'">View</button></td>';
                echo '</tr>';
              }
              if (!$had) { echo "<tr><td colspan='7' class='text-center py-4'>No orders found.</td></tr>"; }
              $stmt->close();
            } else {
              echo "<tr><td colspan='7' class='text-danger'>Error loading orders.</td></tr>";
            }
          ?>
          </tbody>
        </table>
      </div>

      <div class="d-flex justify-content-between align-items-center pt-3">
        <div class="small text-muted">
          <?php $from = ($total===0)?0:($offset+1); $to = min($total, $offset+$limit); echo 'Showing '.$from.'–'.$to.' of '.$total; ?>
        </div>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <?php
              if ($pages > 1) {
                $prev = max(1, $page - 1);
                $next = min($pages, $page + 1);
                $qParam = $q !== '' ? '&q='.urlencode($q) : '';
                echo '<li class="page-item'.($page<=1?' disabled':'').'"><a class="page-link" href="?page='.$prev.$qParam.'">&laquo;</a></li>';
                $window = 7; $half = (int)floor(($window-1)/2);
                $start = max(1, $page - $half); $end = min($pages, $start + $window - 1);
                if (($end - $start + 1) < $window) { $start = max(1, $end - $window + 1); }
                for ($i=$start; $i<=$end; $i++) {
                  echo '<li class="page-item'.($i==$page?' active':'').'"><a class="page-link" href="?page='.$i.$qParam.'">'.$i.'</a></li>';
                }
                echo '<li class="page-item'.($page>=$pages?' disabled':'').'"><a class="page-link" href="?page='.$next.$qParam.'">&raquo;</a></li>';
              }
            ?>
          </ul>
        </nav>
      </div>
    </div></div>
  <?php endif; ?>
</div>

<!-- Order Detail Modal -->
<div class="modal fade" id="exampleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">  <!-- wide for items table -->
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="exampleModalLabel">Order</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body"><!-- filled by JS --></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger" id="btnNotMyArea">Not my area</button>
        <button type="button" class="btn btn-primary" id="btnSaveChange">Save changes</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]));
  const isLoggedIn = <?php echo $distLoggedIn ? 'true' : 'false'; ?>;

  // Login handlers
  const stepMobile = document.getElementById('stepMobile');
  const stepPin = document.getElementById('stepPin');
  const mobile = document.getElementById('mobile');
  const pin = document.getElementById('pin');
  const btnCheck = document.getElementById('btnCheckMobile');
  const btnLogin = document.getElementById('btnLogin');
  const btnBack  = document.getElementById('btnBack');

  if (!isLoggedIn) {
    btnCheck?.addEventListener('click', async ()=>{
      const m = (mobile.value||'').replace(/\D+/g,'');
      if (m.length!==10) { alert('Enter a valid 10-digit mobile'); return; }
      const fd = new FormData(); fd.append('action','check_mobile'); fd.append('mobile', m);
      const res = await fetch(location.href, { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
      const data = await res.json();
      if (res.ok && data.success) { stepMobile.style.display='none'; stepPin.style.display='block'; pin.focus(); }
      else { alert(data.message||'Mobile not found'); }
    });
    btnBack?.addEventListener('click', ()=>{ stepPin.style.display='none'; stepMobile.style.display='block'; });
    btnLogin?.addEventListener('click', async ()=>{
      const m = (mobile.value||'').replace(/\D+/g,'');
      const p = (pin.value||'').trim();
      if (!/^\d{4}$/.test(p)) { alert('Enter 4 digit PIN'); return; }
      const fd = new FormData(); fd.append('action','check_pin'); fd.append('mobile', m); fd.append('pin', p);
      const res = await fetch(location.href, { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
      const data = await res.json();
      if (res.ok && data.success) { location.reload(); } else { alert(data.message||'Invalid PIN'); }
    });
    return;
  }

  // Modal + actions
  const modalEl = document.getElementById('exampleModal');
  const modal   = new bootstrap.Modal(modalEl);
  const titleEl = modalEl.querySelector('#exampleModalLabel');
  const bodyEl  = modalEl.querySelector('.modal-body');
  const btnSave = modalEl.querySelector('#btnSaveChange');
  const btnNMA  = modalEl.querySelector('#btnNotMyArea');

  let currentOrderId = null;
  let currentStatus  = 'pending';

  function updateButtons(){
    if (currentStatus==='pending') {
      btnSave.textContent='Accept';
      btnSave.classList.add('btn-primary');
      btnSave.classList.remove('btn-success');
      btnSave.disabled=false;
      btnNMA.disabled=false;
    } else if (currentStatus==='accepted') {
      btnSave.textContent='Delivered';
      btnSave.classList.remove('btn-primary');
      btnSave.classList.add('btn-success');
      btnSave.disabled=false;
      btnNMA.disabled=false;
    } else {
      btnSave.textContent='Done';
      btnSave.disabled=true;
      btnNMA.disabled=true;
    }
  }

  async function loadOrder(oid){
    const fd = new FormData();
    fd.append('action', 'order_detail');
    fd.append('order_id', oid);

    const res  = await fetch(location.href, { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
    const data = await res.json();
    if (!(res.ok && data.success)) throw new Error(data.message || 'Server error');

    const o = data.order;
    currentOrderId = o.order_id;
    currentStatus  = o.status || 'pending';

    // Title: include invoice number if available
    titleEl.textContent = o.invoice_number ? `Order # ${o.invoice_number}` : `Order # ${o.order_id}`;

    // Address line
    const addrLine = [o.address, o.landmark, o.city, o.state, o.pincode].filter(Boolean).join(', ');

    // Items table
    let itemsHtml = '';
    if (Array.isArray(o.items) && o.items.length) {
      itemsHtml += `
        <div class="table-responsive mt-3">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Item</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Unit</th>
                <th class="text-end">Line Total</th>
              </tr>
            </thead>
            <tbody>
      `;
      o.items.forEach((it, idx) => {
        itemsHtml += `
          <tr>
            <td>${idx+1}</td>
            <td>${esc(it.name)}</td>
            <td class="text-end">${Number(it.qty).toLocaleString()}</td>
            <td class="text-end">${Number(it.unit_price).toFixed(2)}</td>
            <td class="text-end fw-medium">${Number(it.line_total).toFixed(2)}</td>
          </tr>
        `;
      });
      itemsHtml += `
            </tbody>
          </table>
        </div>
      `;
    } else {
      itemsHtml = `<div class="alert alert-warning mt-3 mb-0">No items found for this order.</div>`;
    }

    // Totals block
    const t = o.totals || {subtotal:0,tax:0,discount:0,grand_total:0};
    const totalsHtml = `
      <div class="row g-3 mt-2">
        <div class="col-md-6">
          <div class="small text-muted">Delivery Address</div>
          <div class="border rounded p-2 bg-light mt-1">${esc(addrLine)}</div>
        </div>
        <div class="col-md-6">
          <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between mb-1">
                <div class="text-muted">Subtotal</div><div>₹ ${Number(t.subtotal).toFixed(2)}</div>
              </div>
              <div class="d-flex justify-content-between mb-1">
                <div class="text-muted">Tax</div><div>₹ ${Number(t.tax).toFixed(2)}</div>
              </div>
              <div class="d-flex justify-content-between mb-1">
                <div class="text-muted">Discount</div><div>− ₹ ${Number(t.discount).toFixed(2)}</div>
              </div>
              <hr class="my-2"/>
              <div class="d-flex justify-content-between">
                <div class="fw-semibold">Grand Total</div>
                <div class="fw-bold fs-5">₹ ${Number(t.grand_total).toFixed(2)}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;

    bodyEl.innerHTML = `
      <div class="mb-2"><strong>Order No:</strong> ${esc(o.invoice_number || '')}</div>
      <div class="mb-2"><strong>Customer:</strong> ${esc(o.customer_name || '')}</div>
      <div class="mb-2"><strong>Mobile:</strong>
      <a href="tel:${esc(o.customer_mobile || '')}">
              ${esc(o.customer_mobile || '')}</a></div>
      <div class="mb-2"><strong>Status:</strong> 
        <span class="badge bg-secondary" id="mStatusBadge">${esc(currentStatus)}</span>
      </div>
      <div class="mb-2"><strong>Assigned At:</strong> ${esc(o.assigned_at || '')}</div>

      ${itemsHtml}
      ${totalsHtml}
    `;

    const badge = modalEl.querySelector('#mStatusBadge');
    if (badge) {
      let cls = 'bg-secondary';
      if (currentStatus === 'accepted') cls = 'bg-info';
      else if (currentStatus === 'delivered') cls = 'bg-success';
      else if (currentStatus === 'not_my_area') cls = 'bg-warning';
      badge.className = 'badge ' + cls;
    }

    updateButtons();
  }

  async function doAction(op){
    const fd = new FormData(); fd.append('action','order_action'); fd.append('order_id', currentOrderId); fd.append('op', op);
    const res = await fetch(location.href, { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
    const data = await res.json();
    if (!(res.ok && data.success)) throw new Error(data.message||'Action failed');
    currentStatus = data.new_status || currentStatus; updateButtons(); setTimeout(()=>location.reload(), 400);
  }

  document.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.btn-view');
    if (!btn) return;
    const oid = parseInt(btn.getAttribute('data-order'), 10);
    if (!Number.isInteger(oid) || oid<=0) { alert('Order id missing'); return; }
    try { await loadOrder(oid); modal.show(); } catch (err) { alert(err.message||'Error'); }
  });

  btnSave?.addEventListener('click', async ()=>{
    try {
      if (currentStatus==='pending') await doAction('accept');
      else if (currentStatus==='accepted') await doAction('delivered');
    } catch(err){ alert(err.message||'Error'); }
  });
  btnNMA?.addEventListener('click', async ()=>{
    try { await doAction('not_my_area'); } catch(err){ alert(err.message||'Error'); }
  });
})();
</script>
</div>

</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- (Optional) If you prefer the JS-based Feather replacement instead of inline SVGs, include and call this: -->
<script src="https://unpkg.com/feather-icons"></script>
<script>
  // If you switch icons to <i data-feather="...">, uncomment next line:
  // feather.replace();
</script>

</body>
</html>

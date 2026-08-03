<?php
// FILE: distributor_portal.php (tailored to your schema)
// Tables used:
//   customers(customer_id PK, full_name, mobile_number, email, address, landmark, city, state, pincode, ...)
//   orders(order_id PK, customer_id, distributor_id, distributor_assigned_at, distributor_assigned_by,
//          invoice_number, order_date, order_status, delivery_status, ...,
//          distributor_status, distributor_status_at, delivered_at)
//   distributors(..., mobile_number, login_pin_hash)
//
// Features:
// - Mobile + 4-digit PIN login (hashed with password_hash)
// - List of orders assigned to this distributor (search + pagination)
// - Modal (your markup) to show customer + address
// - Actions: Not my area / Accept / Delivered (update orders.distributor_status, etc)

session_start();
require_once __DIR__ . '/include/db.php'; // must define $mysqli (mysqli)

// ----------------- Page state -----------------
$distLoggedIn = isset($_SESSION['dist_id']) && $_SESSION['dist_id'] > 0;
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
      if (strlen($mobile) !== 10) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Enter a valid 10-digit mobile number.']); exit; }
      $stmt = $mysqli->prepare("SELECT distributor_id, distributor_name, login_pin_hash FROM distributors WHERE mobile_number = ? LIMIT 1");
      if(!$stmt){ throw new Exception('Prepare failed: '.$mysqli->error); }
      $stmt->bind_param('s', $mobile);
      $stmt->execute();
      $stmt->bind_result($did, $dname, $pin_hash);
      if ($stmt->fetch()) {
        if (empty($pin_hash)) { http_response_code(423); echo json_encode(['success'=>false,'message'=>'PIN not set for this distributor.']); }
        else { echo json_encode(['success'=>true,'message'=>'Mobile verified. Proceed to PIN.','distributor_name'=>$dname]); }
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

    // Order detail (use your exact columns)
    if ($action === 'order_detail') {
      $distId = (int)($_SESSION['dist_id'] ?? 0);
      if ($distId <= 0) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
      $order_id = (int)($_POST['order_id'] ?? 0);
      if ($order_id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid order id']); exit; }

      $sql = "SELECT
                o.order_id,
                o.invoice_number,
                o.distributor_status,
                o.distributor_assigned_at,
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
      $stmt = $mysqli->prepare($sql);
      if(!$stmt){ throw new Exception('Prepare failed: '.$mysqli->error); }
      $stmt->bind_param('ii', $order_id, $distId);
      $stmt->execute();
      $stmt->bind_result($oid, $invoice_number, $status, $assigned_at, $cname, $cmobile, $addr, $landmark, $city, $state, $pincode);
      if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Order not found or not assigned to you']); exit; }
      $stmt->close();

      echo json_encode([
        'success'=>true,
        'order'=>[
          'order_id'=>(int)$oid,
          'invoice_number' => (string)$invoice_number,
          'status'=>(string)($status ?: 'pending'),
          'assigned_at'=>(string)($assigned_at ?: ''),
          'customer_name'=>(string)$cname,
          'customer_mobile'=>(string)$cmobile,
          'address'=>(string)$addr,
          'landmark'=>(string)($landmark ?? ''),
          'city'=>(string)($city ?? ''),
          'state'=>(string)($state ?? ''),
          'pincode'=>(string)($pincode ?? '')
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
        $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='accepted',order_status = 'Ready to Delivery', distributor_status_at=NOW() WHERE order_id=? LIMIT 1");
        $stmt->bind_param('i', $order_id); $stmt->execute();
        echo json_encode(['success'=>true,'new_status'=>'accepted']); 
         // If you use an order_comments table, record who cancelled + reason.
        $stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, distributor_id, comment, created_at) VALUES (?, ?, ?, NOW())");
        $comment = "Order accept by {$distName} distributor code is {$distCode}";
        // Note: "i i s" = int, int, string; if $user_id is null, cast to 0 or change schema to allow NULL
        $uid = $user_id ?? 0; // adjust if your column allows NULL
        $stmt->bind_param("iis", $order_id, $distId, $comment);
        $stmt->execute();
        $stmt->close();

        exit;

      } elseif ($op === 'not_my_area') {
        $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='not_my_area',order_status='Change distributor', distributor_status_at=NOW(), distributor_id=NULL WHERE order_id=? LIMIT 1");
        $stmt->bind_param('i', $order_id); $stmt->execute();
        echo json_encode(['success'=>true,'new_status'=>'not_my_area']); 
         // If you use an order_comments table, record who cancelled + reason.
        $stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, distributor_id, comment, created_at) VALUES (?, ?, ?, NOW())");
        $comment = "The order was rejected by the distributor {$distName} because it is not his area. code is {$distCode}";
        // Note: "i i s" = int, int, string; if $user_id is null, cast to 0 or change schema to allow NULL
        $uid = $user_id ?? 0; // adjust if your column allows NULL
        $stmt->bind_param("iis", $order_id, $distId, $comment);
        $stmt->execute();
        $stmt->close();
        exit;

      } elseif ($op === 'delivered') {
        if ($curStatus !== 'accepted') { http_response_code(409); echo json_encode(['success'=>false,'message'=>'Accept order before marking delivered']); exit; }
        $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='delivered', order_status='delivered', distributor_status_at=NOW(), delivered_at=NOW() WHERE order_id=? LIMIT 1");
        $stmt->bind_param('i', $order_id); $stmt->execute();
        echo json_encode(['success'=>true,'new_status'=>'delivered']); 
         // If you use an order_comments table, record who cancelled + reason.
        $stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, distributor_id, comment, created_at) VALUES (?, ?, ?, NOW())");
        $comment = "The {$distName} distributor successfully delivered the order. code is {$distCode}";
        // Note: "i i s" = int, int, string; if $user_id is null, cast to 0 or change schema to allow NULL
        $uid = $user_id ?? 0; // adjust if your column allows NULL
        $stmt->bind_param("iis", $order_id, $distId, $comment);
        $stmt->execute();
        $stmt->close();
        exit;

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

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20; $offset = ($page - 1) * $limit;
$q = trim($_GET['q'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Distributor Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style> body{background:#f6f7fb;} .card{border-radius:1rem;} .pin-input{letter-spacing:.6rem;font-family:monospace;font-size:1.2rem;} </style>
</head>
<body>
<div class="container py-5">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">Distributor Portal</h3>
    <?php if($distLoggedIn): ?>
      <div>
        <span class="me-3">Logged in as <span class="badge text-bg-info"><?php echo $distCode; ?></span> <?php echo $distName; ?></span>
        <a href="?logout=1" class="btn btn-sm btn-outline-danger">Logout</a>
      </div>
    <?php endif; ?>
  </div>

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
              <th style="width:110px;">Order ID</th>
              <th>Customer</th>
              <th style="width:140px;">Mobile</th>
              <th style="width:140px;">Status</th>
              <th style="width:200px;">Assigned At</th>
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
                $where .= ' AND (o.order_id = ?)';
                $types .= 'i';
                $params[] = (int)$q;
              } else {
                $where .= ' AND (c.full_name LIKE ?)';
                $types .= 's';
                $params[] = '%'.$q.'%';
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
            $sql = "SELECT o.invoice_number,o.order_id, c.full_name, c.mobile_number, o.distributor_status, o.distributor_assigned_at
                    FROM orders o
                    INNER JOIN customers c ON o.customer_id=c.customer_id
                    $where
                    ORDER BY o.order_id DESC
                    LIMIT $limit_i OFFSET $offset_i";
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
              $stmt->bind_param($types, ...$params);
              $stmt->execute();
              $stmt->bind_result($oinvoice,$oid, $cname, $cmobile, $status, $assignedAt);
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
                echo '<td><button type="button" class="btn btn-sm btn-outline-primary btn-view" data-order="'.(int)$oid.'">View</button></td>';
                echo '</tr>';
              }
              if (!$had) { echo "<tr><td colspan='6' class='text-center py-4'>No orders found.</td></tr>"; }
              $stmt->close();
            } else {
              echo "<tr><td colspan='6' class='text-danger'>Error loading orders.</td></tr>";
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

<!-- Your modal markup -->
<div class="modal fade" id="exampleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="exampleModalLabel">Modal title</h5>
      </div>
      <div class="modal-body">Woohoo, you&#x27;re reading this text in a modal!</div>
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
    if (currentStatus==='pending') { btnSave.textContent='Accept'; btnSave.classList.add('btn-primary'); btnSave.classList.remove('btn-success'); btnSave.disabled=false; }
    else if (currentStatus==='accepted') { btnSave.textContent='Delivered'; btnSave.classList.remove('btn-primary'); btnSave.classList.add('btn-success'); btnSave.disabled=false; }
    else { btnSave.textContent='Done'; btnSave.disabled=true; }
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
  titleEl.textContent = o.invoice_number
    ? `Order # ${o.invoice_number}`
    : `Order # ${o.order_id}`;

  // Build address (no invoice number here)
  const addrLine = [o.address, o.landmark, o.city, o.state, o.pincode]
    .filter(Boolean).join(', ');

  bodyEl.innerHTML = `
    <div class="mb-2"><strong>Order No:</strong> ${esc(o.invoice_number || '')}</div>
    <div class="mb-2"><strong>Customer:</strong> ${esc(o.customer_name || '')}</div>
    <div class="mb-2"><strong>Mobile:</strong> ${esc(o.customer_mobile || '')}</div>
    <div class="mb-2"><strong>Status:</strong> <span class="badge bg-secondary" id="mStatusBadge">${esc(currentStatus)}</span></div>
    <div class="mb-2"><strong>Assigned At:</strong> ${esc(o.assigned_at || '')}</div>
    <div class="mb-0"><strong>Address:</strong><br>
      <div class="border rounded p-2 bg-light mt-1">${esc(addrLine)}</div>
    </div>
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

  btnSave.addEventListener('click', async ()=>{
    try { if (currentStatus==='pending') await doAction('accept'); else if (currentStatus==='accepted') await doAction('delivered'); }
    catch(err){ alert(err.message||'Error'); }
  });
  btnNMA.addEventListener('click', async ()=>{
    try { await doAction('not_my_area'); } catch(err){ alert(err.message||'Error'); }
  });
})();
</script>
</body>
</html>

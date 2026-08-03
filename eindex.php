<?php
session_start();
require_once __DIR__ . '/include/db.php';

/*
  Required existing product table columns:
  products(product_id, title, image_url, retail_price, wholesale_price, cost_price, unit, status)

  This page auto-creates website order tables:
  ecom_orders and ecom_order_items.
*/

$mysqli->query("CREATE TABLE IF NOT EXISTS ecom_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(40) NOT NULL UNIQUE,
  customer_name VARCHAR(150) NOT NULL,
  mobile VARCHAR(10) NOT NULL,
  address TEXT NOT NULL,
  city VARCHAR(100) NULL,
  pincode VARCHAR(10) NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_mode VARCHAR(20) NOT NULL DEFAULT 'Cash',
  status VARCHAR(30) NOT NULL DEFAULT 'New',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(mobile),
  INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("CREATE TABLE IF NOT EXISTS ecom_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(200) NOT NULL,
  unit VARCHAR(50) NULL,
  quantity INT NOT NULL DEFAULT 1,
  rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(order_id),
  INDEX(product_id),
  CONSTRAINT fk_ecom_order_items_order FOREIGN KEY (order_id) REFERENCES ecom_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function json_response($arr, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

function clean_mobile($mobile) {
  $m = preg_replace('/\D+/', '', (string)$mobile);
  if (strlen($m) > 10) $m = substr($m, -10);
  return $m;
}

function product_price_sql() {
  return "COALESCE(NULLIF(retail_price,0), NULLIF(wholesale_price,0), NULLIF(cost_price,0), 0)";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = $_POST;
  $action = $data['action'] ?? '';

  if ($action === 'place_order') {
    $name = trim((string)($data['name'] ?? ''));
    $mobile = clean_mobile($data['mobile'] ?? '');
    $address = trim((string)($data['address'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $pincode = preg_replace('/\D+/', '', (string)($data['pincode'] ?? ''));
    $items = $data['items'] ?? [];

    if ($name === '') json_response(['success'=>false,'message'=>'Please enter customer name.'], 422);
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) json_response(['success'=>false,'message'=>'Please enter valid 10 digit mobile number.'], 422);
    if ($address === '') json_response(['success'=>false,'message'=>'Please enter address.'], 422);
    if (!is_array($items) || count($items) === 0) json_response(['success'=>false,'message'=>'Cart is empty.'], 422);

    $productIds = [];
    $qtyMap = [];
    foreach ($items as $it) {
      $pid = (int)($it['product_id'] ?? 0);
      $qty = (int)($it['quantity'] ?? 0);
      if ($pid > 0 && $qty > 0) {
        $productIds[$pid] = $pid;
        $qtyMap[$pid] = ($qtyMap[$pid] ?? 0) + $qty;
      }
    }
    if (!$productIds) json_response(['success'=>false,'message'=>'Cart is empty.'], 422);

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $types = str_repeat('i', count($productIds));
    $sql = "SELECT product_id, title, unit, " . product_price_sql() . " AS price
            FROM products
            WHERE status='active' AND `product_owner` != 'CMD' AND product_id IN ($placeholders)";
    $stmt = $mysqli->prepare($sql);
    $ids = array_values($productIds);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $dbProducts = [];
    while ($r = $res->fetch_assoc()) $dbProducts[(int)$r['product_id']] = $r;
    $stmt->close();

    if (count($dbProducts) !== count($productIds)) json_response(['success'=>false,'message'=>'Some products are inactive or not found. Please refresh page.'], 422);

    $subtotal = 0;
    $finalItems = [];
    foreach ($qtyMap as $pid=>$qty) {
      $p = $dbProducts[$pid];
      $rate = (float)$p['price'];
      if ($rate <= 0) json_response(['success'=>false,'message'=>'Product price missing: '.$p['title']], 422);
      $amount = $rate * $qty;
      $subtotal += $amount;
      $finalItems[] = [
        'product_id'=>$pid,
        'product_name'=>$p['title'],
        'unit'=>$p['unit'] ?: 'Pcs',
        'quantity'=>$qty,
        'rate'=>$rate,
        'amount'=>$amount
      ];
    }

    $orderNo = 'MS' . date('ymdHis') . random_int(10,99);

    try {
      $mysqli->begin_transaction();
      $stmt = $mysqli->prepare("INSERT INTO ecom_orders
        (order_no, customer_name, mobile, address, city, pincode, subtotal, grand_total, payment_mode, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Cash', 'New')");
      $stmt->bind_param('ssssssdd', $orderNo, $name, $mobile, $address, $city, $pincode, $subtotal, $subtotal);
      $stmt->execute();
      $orderId = $stmt->insert_id;
      $stmt->close();

      $stmt = $mysqli->prepare("INSERT INTO ecom_order_items
        (order_id, product_id, product_name, unit, quantity, rate, amount)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
      foreach ($finalItems as $it) {
        $stmt->bind_param('iissidd', $orderId, $it['product_id'], $it['product_name'], $it['unit'], $it['quantity'], $it['rate'], $it['amount']);
        $stmt->execute();
      }
      $stmt->close();
      $mysqli->commit();
      json_response(['success'=>true,'order_no'=>$orderNo,'order_id'=>$orderId,'grand_total'=>$subtotal,'items'=>$finalItems]);
    } catch (Throwable $e) {
      $mysqli->rollback();
      json_response(['success'=>false,'message'=>'Order save failed: '.$e->getMessage()], 500);
    }
  }

  json_response(['success'=>false,'message'=>'Invalid action.'], 400);
}

$products = [];
$priceExpr = product_price_sql();
$sql = "SELECT product_id, title, image_url, unit, $priceExpr AS price
        FROM products
        WHERE status='active' AND `product_owner` != 'CMD'
        ORDER BY product_id DESC";
$result = $mysqli->query($sql);
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $products[] = [
      'id' => (string)$row['product_id'],
      'code' => (string)$row['title'],
      'name' => (string)$row['title'],
      'unit' => (string)($row['unit'] ?: 'Pcs'),
      'price' => (float)$row['price'],
      'image' => (string)($row['image_url'] ?: '')
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
<title>MySathee — Trusted cleaning products</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600;700;800&family=Mukta+Vaani:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#F4F6FC; --surface:#FFFFFF; --ink:#1A2238; --muted:#5B6480;
  --green:#2C3F7A; --green-dark:#20305E; --green-soft:#ECEFF9; --green-line:#D2D8EE;
  --amber:#1488D6; --amber-dark:#0F6FB0; --amber-soft:#E2F0FA;
  --line:#E6E8F1; --danger:#C0392B;
  --r:14px; --r-lg:20px;
  --sh-sm:0 1px 3px rgba(20,40,30,.06),0 1px 2px rgba(20,40,30,.04);
  --sh-md:0 6px 20px rgba(20,40,30,.08);
  --sh-lg:0 14px 40px rgba(20,40,30,.12);
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0}
body{background:var(--bg);color:var(--ink);font-family:'Mukta','Mukta Vaani',system-ui,sans-serif;font-size:18px;line-height:1.55}
h1,h2,h3{margin:0;font-weight:800;line-height:1.15;letter-spacing:-.01em}
button{font-family:inherit;cursor:pointer}
:focus-visible{outline:3px solid var(--amber);outline-offset:2px}
.wrap{max-width:1080px;margin:0 auto;padding:0 18px}

/* announce */
.announce{background:var(--green-dark);color:#Eaf7f0;font-size:14px;font-weight:500;text-align:center;padding:7px 12px}
/* header */
.hdr{position:sticky;top:0;z-index:40;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border-bottom:1px solid var(--line);transition:box-shadow .2s}
.hdr.scrolled{box-shadow:var(--sh-md)}
.hdr-in{display:flex;align-items:center;gap:14px;max-width:1080px;margin:0 auto;padding:11px 18px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:25px;color:var(--green);letter-spacing:-.02em}
.brand .logo{width:40px;height:40px;border-radius:11px;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:var(--sh-sm)}
.brand .logo svg{width:23px;height:23px}
.brand small{display:block;font-weight:500;font-size:12px;color:var(--muted);letter-spacing:0;margin-top:-2px}
.spacer{flex:1}
.lang{display:flex;border:1px solid var(--line);border-radius:999px;padding:3px;background:var(--surface)}
.lang button{border:0;background:transparent;color:var(--muted);font-weight:700;font-size:14px;padding:7px 12px;border-radius:999px}
.lang button.on{background:var(--green);color:#fff}
.cartbtn{display:flex;align-items:center;gap:8px;background:var(--amber);color:#fff;border:0;border-radius:999px;padding:10px 16px;font-weight:700;font-size:16px;min-height:46px;box-shadow:var(--sh-sm)}
.cartbtn .ct{background:#fff;color:var(--amber-dark);border-radius:999px;min-width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:14px;padding:0 6px}

/* HERO SLIDER */
.hero{position:relative;margin:18px auto 0;max-width:1080px;padding:0 18px}
.hero-frame{position:relative;border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh-md)}
.hero-track{display:flex;transition:transform .55s cubic-bezier(.4,0,.2,1)}
.slide{flex:0 0 100%;min-height:300px;display:flex;align-items:center;padding:40px 48px;position:relative;overflow:hidden}
.slide-in{position:relative;z-index:2;max-width:560px}
.slide .eyebrow{display:inline-block;background:rgba(255,255,255,.18);color:#fff;font-weight:600;font-size:13px;letter-spacing:.04em;text-transform:uppercase;padding:5px 12px;border-radius:999px;margin-bottom:14px}
.slide h2{color:#fff;font-size:36px;margin-bottom:10px}
.slide p{color:rgba(255,255,255,.92);font-size:19px;margin:0 0 22px;font-weight:500}
.slide .cta{background:#fff;color:var(--green-dark);border:0;border-radius:12px;padding:14px 26px;font-weight:800;font-size:17px;box-shadow:var(--sh-sm)}
.slide .deco{position:absolute;right:-40px;top:-40px;width:280px;height:280px;opacity:.16;z-index:1}
.slide .deco2{position:absolute;right:90px;bottom:-70px;width:150px;height:150px;opacity:.12;z-index:1}
.s0{background:linear-gradient(120deg,#243567,#324A93)}
.s1{background:linear-gradient(120deg,#1670B8,#1E93D8)}
.s2{background:linear-gradient(120deg,#202B66,#3257B0)}
.hero-arrow{position:absolute;top:50%;transform:translateY(-50%);width:46px;height:46px;border-radius:50%;border:0;background:rgba(255,255,255,.9);color:var(--green-dark);font-size:26px;font-weight:700;display:flex;align-items:center;justify-content:center;box-shadow:var(--sh-sm);z-index:3}
.hero-arrow.prev{left:30px}.hero-arrow.next{right:30px}
.hero-dots{position:absolute;bottom:16px;left:0;right:0;display:flex;justify-content:center;gap:8px;z-index:3}
.hero-dots button{width:10px;height:10px;border-radius:999px;border:0;background:rgba(255,255,255,.5);padding:0}
.hero-dots button.on{background:#fff;width:26px}

/* sections */
.section{max-width:1080px;margin:0 auto;padding:0 18px}
.sec-head{display:flex;align-items:flex-end;justify-content:space-between;margin:42px 0 16px}
.sec-head h3{font-size:26px}
.sec-head .sub{color:var(--muted);font-size:15px;margin-top:3px;font-weight:500}
.sec-arrows{display:flex;gap:8px}
.sec-arrows button{width:42px;height:42px;border-radius:50%;border:1px solid var(--line);background:var(--surface);color:var(--ink);font-size:22px;box-shadow:var(--sh-sm)}

/* trust */
.trust{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:26px}
.tcard{display:flex;align-items:center;gap:14px;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px;box-shadow:var(--sh-sm)}
.tcard .ic{width:46px;height:46px;flex:none;border-radius:12px;background:var(--green-soft);color:var(--green);display:flex;align-items:center;justify-content:center}
.tcard .ic svg{width:24px;height:24px}
.tcard b{display:block;font-weight:700;font-size:16px}
.tcard span{color:var(--muted);font-size:14px}

/* featured slider */
.fslider{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;padding:4px 0 12px;-webkit-overflow-scrolling:touch}
.fslider::-webkit-scrollbar{height:0}
.fcard{flex:0 0 210px;scroll-snap-align:start}

/* product card */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-top:8px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:14px;display:flex;flex-direction:column;box-shadow:var(--sh-sm);transition:transform .16s,box-shadow .16s}
.card:hover{transform:translateY(-3px);box-shadow:var(--sh-md)}
.thumb{height:120px;border-radius:12px;background:linear-gradient(135deg,#E9EEF9,#F1F4FB);display:flex;align-items:center;justify-content:center;color:#b9cabf;margin-bottom:12px;position:relative}
.thumb svg{width:42px;height:42px;opacity:.7}
.pname{font-weight:700;font-size:17px;line-height:1.2;display:flex;align-items:flex-start;gap:6px;min-height:41px}
.pname .edit{flex:none;border:0;background:var(--amber-soft);border-radius:8px;width:30px;height:30px;display:none;align-items:center;justify-content:center}
body.owner .pname .edit{display:inline-flex}
.unit{align-self:flex-start;background:var(--green-soft);color:var(--green-dark);font-weight:600;font-size:12.5px;padding:3px 10px;border-radius:999px;margin:9px 0}
.price{font-weight:800;font-size:22px;color:var(--green-dark)}
.price small{font-weight:500;font-size:13px;color:var(--muted)}
.act{margin-top:12px}
.addbtn{width:100%;background:var(--amber);color:#fff;border:0;border-radius:12px;min-height:48px;font-weight:700;font-size:16px;box-shadow:var(--sh-sm)}
.addbtn:active{background:var(--amber-dark)}
.stepper{display:flex;align-items:center;justify-content:space-between;background:var(--green);border-radius:12px;min-height:48px;overflow:hidden;box-shadow:var(--sh-sm)}
.stepper button{flex:none;width:48px;height:48px;background:transparent;border:0;color:#fff;font-size:25px;font-weight:700}
.stepper .q{color:#fff;font-weight:800;font-size:18px}

/* search + chips */
.toolbar{margin-top:8px}
.search{width:100%;border:1px solid var(--line);background:var(--surface);border-radius:14px;padding:14px 16px 14px 46px;font-size:18px;font-family:inherit;min-height:54px;box-shadow:var(--sh-sm);background-image:url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" stroke="%235A6B65" stroke-width="2" stroke-linecap="round"><circle cx="10" cy="10" r="7"/><path d="M21 21l-5-5"/></svg>');background-repeat:no-repeat;background-position:14px center}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 4px}
.chip{border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:999px;padding:9px 16px;font-weight:600;font-size:15px;box-shadow:var(--sh-sm)}
.chip.on{background:var(--green);border-color:var(--green);color:#fff}

/* owner bar */
.owner-bar{display:flex;align-items:center;gap:12px;margin:24px 0 0;padding:13px 16px;background:var(--amber-soft);border:1px solid #F4DDB6;border-radius:14px;font-size:15px;color:#7a5a1c}
.switch{position:relative;width:50px;height:28px;flex:none}
.switch input{opacity:0;width:100%;height:100%;margin:0;cursor:pointer}
.switch .tr{position:absolute;inset:0;background:#D9CBB2;border-radius:999px;transition:.18s;pointer-events:none}
.switch .kn{position:absolute;top:3px;left:3px;width:22px;height:22px;background:#fff;border-radius:50%;transition:.18s;pointer-events:none;box-shadow:var(--sh-sm)}
.switch input:checked ~ .tr{background:var(--amber)}
.switch input:checked ~ .kn{transform:translateX(22px)}
.owner-bar .ob-actions{margin-left:auto}
.linkbtn{background:transparent;border:0;color:var(--green);font-weight:700;font-size:15px;text-decoration:underline}

/* views */
.view{display:none}
.view.on{display:block}
.backrow{margin:18px 0 6px}
.back{display:inline-flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 16px;font-weight:600;font-size:16px;color:var(--ink);box-shadow:var(--sh-sm)}
h2.vtitle{font-size:28px;margin:6px 0 18px}

.crow{display:flex;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:13px;margin-bottom:12px;box-shadow:var(--sh-sm)}
.crow .ci{flex:1;min-width:0}
.crow .cn{font-weight:700;font-size:17px}
.crow .cp{color:var(--muted);font-size:14px}
.crow .lt{font-weight:800;font-size:18px;color:var(--green-dark);white-space:nowrap}
.mini{display:flex;align-items:center;gap:2px;background:var(--green-soft);border-radius:10px}
.mini button{width:40px;height:40px;border:0;background:transparent;color:var(--green-dark);font-size:22px;font-weight:700}
.mini .q{min-width:26px;text-align:center;font-weight:800}
.remove{background:transparent;border:0;color:var(--danger);font-weight:600;font-size:14px;text-decoration:underline;padding:6px 0}

.summary{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px;margin-top:8px;box-shadow:var(--sh-sm)}
.sline{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:17px}
.sline.total{border-top:2px dashed var(--line);margin-top:8px;padding-top:14px;font-weight:800;font-size:23px;color:var(--green-dark)}

.empty{text-align:center;padding:54px 16px;color:var(--muted)}
.empty svg{width:60px;height:60px;opacity:.5;margin-bottom:12px}

label.fld{display:block;margin:16px 0 0}
label.fld .lb{font-weight:700;font-size:16px;margin-bottom:6px;display:block}
.input,textarea.input{width:100%;border:1px solid var(--line);background:var(--surface);border-radius:12px;padding:13px 15px;font-size:18px;font-family:inherit;min-height:54px;box-shadow:var(--sh-sm)}
textarea.input{min-height:84px;resize:vertical}
.input.err,textarea.input.err{border-color:var(--danger);background:#FCF1EF}
.errmsg{color:var(--danger);font-size:14px;font-weight:600;margin-top:5px;display:none}
.errmsg.show{display:block}
.codbox{display:flex;align-items:center;gap:12px;background:var(--green-soft);border:1px solid var(--green-line);border-radius:14px;padding:15px;margin-top:18px;color:var(--green-dark);font-weight:600}
.codbox svg{width:30px;height:30px;flex:none}
.bigbtn{width:100%;background:var(--amber);color:#fff;border:0;border-radius:14px;min-height:58px;font-weight:800;font-size:20px;margin-top:18px;box-shadow:var(--sh-md)}
.bigbtn:active{background:var(--amber-dark)}
.ghostbtn{width:100%;background:transparent;color:var(--ink);border:1px solid var(--line);border-radius:14px;min-height:52px;font-weight:600;font-size:17px;margin-top:10px}

.success{text-align:center;padding:24px 8px}
.check{width:88px;height:88px;border-radius:50%;background:var(--green);display:flex;align-items:center;justify-content:center;margin:8px auto 16px;box-shadow:var(--sh-md)}
.check svg{width:46px;height:46px;color:#fff}
.ordno{display:inline-block;background:var(--amber-soft);border:1px solid #F4DDB6;color:#7a5a1c;font-weight:800;border-radius:999px;padding:7px 18px;margin:6px 0 4px}

/* footer */
.footer{background:#141C3A;color:#cfe0d8;margin-top:54px;padding:38px 0 26px}
.footer .cols{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:26px}
.footer h4{color:#fff;font-size:18px;margin:0 0 10px}
.footer p,.footer a{color:#a9c2b8;font-size:15px;text-decoration:none;line-height:1.7}
.footer a:hover{color:#fff}
.footer .fbrand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:22px;color:#fff;margin-bottom:8px}
.footer .fbrand .logo{width:36px;height:36px;border-radius:10px;background:var(--green);display:flex;align-items:center;justify-content:center}
.footer .fbrand .logo svg{width:20px;height:20px;color:#fff}
.copy{border-top:1px solid rgba(255,255,255,.12);margin-top:26px;padding-top:18px;font-size:13px;color:#86a497;text-align:center}

/* modal */
.modal{position:fixed;inset:0;background:rgba(12,30,24,.55);display:none;align-items:center;justify-content:center;z-index:60;padding:20px}
.modal.on{display:flex}
.mcard{background:var(--surface);border-radius:18px;padding:22px;width:100%;max-width:400px;box-shadow:var(--sh-lg)}
.mcard h3{font-size:21px;margin-bottom:4px}
.mcard p{color:var(--muted);font-size:14px;margin:0 0 12px}

.loading{text-align:center;padding:70px;color:var(--muted)}
@media (max-width:760px){.footer .cols{grid-template-columns:1fr 1fr}.footer .fcol-about{grid-column:1/-1}}
@media (max-width:640px){
  .slide{padding:30px 26px;min-height:260px}.slide h2{font-size:27px}.slide p{font-size:16px}
  .hero-arrow{display:none}.brand small{display:none}
}
@media (max-width:440px){.grid{grid-template-columns:repeat(2,1fr);gap:12px}.brand{font-size:21px}}
@media (prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}
.logo-img{width:44px;height:44px;border-radius:50%;object-fit:cover;background:#fff;box-shadow:var(--sh-sm);flex:none}
.logo-img-ftr{width:38px;height:38px;border-radius:50%;object-fit:cover;background:#fff;flex:none}
</style>
</head>
<body>
<div class="announce" data-t="announce">Free delivery on orders above ₹500 · Cash on delivery available</div>
<header class="hdr" id="hdr">
  <div class="hdr-in">
    <div class="brand">
      <img id="logoHdr" class="logo-img" alt="Sathee logo">
      <span>MySathee<small data-t="tagline">Trusted cleaning products</small></span>
    </div>
    <div class="spacer"></div>
    <div class="lang" role="group" aria-label="Language">
      <button id="langEn" class="on" onclick="setLang('en')">EN</button>
      <button id="langGu" onclick="setLang('gu')">ગુ</button>
      <button id="langHi" onclick="setLang('hi')">हिं</button>
    </div>
    <button class="cartbtn" onclick="go('cart')" aria-label="Cart">
      <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
      <span class="ct" id="cartCount">0</span>
    </button>
  </div>
</header>

<!-- SHOP -->
<section id="view-shop" class="view on">
  <!-- hero slider -->
  <div class="hero">
    <div class="hero-frame" id="heroFrame">
      <div class="hero-track" id="heroTrack"></div>
      <button class="hero-arrow prev" aria-label="Previous" onclick="heroGo(heroCur-1,true)">‹</button>
      <button class="hero-arrow next" aria-label="Next" onclick="heroGo(heroCur+1,true)">›</button>
      <div class="hero-dots" id="heroDots"></div>
    </div>
  </div>

  <!-- trust -->
  <div class="section">
    <div class="trust" id="trustRow"></div>
  </div>

  <!-- featured -->
  <div class="section">
    <div class="sec-head">
      <div><h3 data-t="popular">Popular products</h3><div class="sub" data-t="popularSub">Best-selling items, ready to order</div></div>
      <div class="sec-arrows"><button onclick="slideFeatured(-1)" aria-label="Scroll left">‹</button><button onclick="slideFeatured(1)" aria-label="Scroll right">›</button></div>
    </div>
    <div class="fslider" id="featured"></div>
  </div>

  <!-- catalog -->
  <div class="section">
    <div class="sec-head"><div><h3 data-t="allProducts">All products</h3><div class="sub" id="catCount"></div></div></div>
    <div class="toolbar">
      <input id="search" class="search" type="search" data-tph="searchPh" placeholder="Search products" oninput="renderProducts()" aria-label="Search products">
      <div class="chips" id="priceChips">
        <button class="chip on" data-min="0" data-max="999999" data-t="chipAll" onclick="setChip(this)">All</button>
        <button class="chip" data-min="0" data-max="100" onclick="setChip(this)">&lt; ₹100</button>
        <button class="chip" data-min="100" data-max="300" onclick="setChip(this)">₹100–₹300</button>
        <button class="chip" data-min="300" data-max="999999" data-t="chipAbove" onclick="setChip(this)">₹300+</button>
      </div>
    </div>
    <div id="loading" class="loading">Loading products…</div>
    <div id="grid" class="grid" hidden></div>
  </div>

  <!-- footer -->
  <footer class="footer">
    <div class="wrap">
      <div class="cols">
        <div class="fcol-about">
          <div class="fbrand"><img id="logoFtr" class="logo-img-ftr" alt="Sathee logo">MySathee</div>
          <p data-t="footAbout">Quality household and cleaning products, delivered to your door with cash-on-delivery convenience.</p>
        </div>
        <div>
          <h4 data-t="footHelp">Help</h4>
          <p data-t="footHelp1">How to order</p>
          <p data-t="footHelp2">Delivery &amp; payment</p>
          <p data-t="footHelp3">Returns</p>
        </div>
        <div>
          <h4 data-t="footContact">Contact</h4>
          <p data-t="footPhone">Call: 1800-000-000</p>
          <p data-t="footHours">Mon–Sat, 9am–7pm</p>
        </div>
      </div>
      <div class="copy">© 2026 MySathee. <span data-t="footRights">All rights reserved.</span></div>
    </div>
  </footer>
</section>

<!-- CART -->
<section id="view-cart" class="view"><div class="section">
  <div class="backrow"><button class="back" onclick="go('shop')">‹ <span data-t="continueShop">Continue shopping</span></button></div>
  <h2 class="vtitle" data-t="yourCart">Your cart</h2>
  <div id="cartList"></div><div id="cartFooter"></div>
</div></section>

<!-- CHECKOUT -->
<section id="view-checkout" class="view"><div class="section" style="max-width:640px">
  <div class="backrow"><button class="back" onclick="go('cart')">‹ <span data-t="back">Back</span></button></div>
  <h2 class="vtitle" data-t="yourDetails">Your details</h2>
  <label class="fld"><span class="lb" data-t="fName">Your name</span>
    <input id="f-name" class="input" type="text" autocomplete="name" data-tph="phName" placeholder="e.g. Ramesh Patel">
    <span class="errmsg" data-t="errName">Please enter your name</span></label>
  <label class="fld"><span class="lb" data-t="fMobile">Mobile number</span>
    <input id="f-mobile" class="input" type="tel" inputmode="numeric" maxlength="10" autocomplete="tel" data-tph="phMobile" placeholder="10-digit mobile number">
    <span class="errmsg" data-t="errMobile">Please enter a valid 10-digit number</span></label>
  <label class="fld"><span class="lb" data-t="fAddr">Full address</span>
    <textarea id="f-addr" class="input" autocomplete="street-address" data-tph="phAddr" placeholder="House, street, village/area"></textarea>
    <span class="errmsg" data-t="errAddr">Please enter your address</span></label>
  <div style="display:flex;gap:12px">
    <label class="fld" style="flex:1;margin-top:16px"><span class="lb" data-t="fCity">City / village</span><input id="f-city" class="input" type="text" autocomplete="address-level2"></label>
    <label class="fld" style="width:130px;margin-top:16px"><span class="lb" data-t="fPin">Pincode</span><input id="f-pin" class="input" type="tel" inputmode="numeric" maxlength="6" autocomplete="postal-code"></label>
  </div>
  <div class="codbox"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg><span data-t="codText">Pay cash on delivery — no online payment needed</span></div>
  <div class="summary" id="checkoutSummary"></div>
  <button class="bigbtn" onclick="placeOrder()" data-t="placeOrder">Place order</button>
  <button class="ghostbtn" onclick="go('cart')" data-t="back">Back</button>
</div></section>

<!-- CONFIRM -->
<section id="view-confirm" class="view"><div class="section" style="max-width:640px">
  <div class="success">
    <div class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>
    <h2 style="font-size:30px" data-t="orderPlaced">Order placed!</h2>
    <div class="ordno" id="confOrdNo">—</div>
    <p id="confMsg" style="color:var(--muted);max-width:340px;margin:10px auto 0"></p>
  </div>
  <div class="summary" id="confSummary"></div>
  <button class="bigbtn" onclick="resetToShop()" data-t="another">Place another order</button>
</div></section>

<!-- ORDERS -->
<section id="view-orders" class="view"><div class="section">
  <div class="backrow"><button class="back" onclick="go('shop')">‹ <span data-t="back">Back</span></button></div>
  <h2 class="vtitle" data-t="ordersTitle">Orders received</h2>
  <div id="ordersList"></div>
</div></section>

<div class="modal" id="renameModal"><div class="mcard">
  <h3 data-t="renameTitle">Rename product</h3>
  <p id="renameCode"></p>
  <input id="renameInput" class="input" type="text" data-tph="phRename" placeholder="e.g. Toilet cleaner 1 litre">
  <button class="bigbtn" onclick="saveRename()" data-t="save">Save</button>
  <button class="ghostbtn" onclick="closeRename()" data-t="cancel">Cancel</button>
</div></div>

<script>
const PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const LOGO="data:image/png;base64,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";
const money = n => '₹' + Math.round(n).toLocaleString('en-IN');
const esc = s => String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const SLIDES=[
 {cls:'s0',eyebrow:{en:'Trusted quality',gu:'વિશ્વાસપાત્ર ગુણવત્તા',hi:'भरोसेमंद गुणवत्ता'},h:{en:'Cleaning products for every home',gu:'દરેક ઘર માટે સફાઈ ઉત્પાદનો',hi:'हर घर के लिए सफाई उत्पाद'},p:{en:'Reliable quality, delivered straight to your door.',gu:'ભરોસાપાત્ર ગુણવત્તા, સીધી તમારા ઘર સુધી.',hi:'भरोसेमंद गुणवत्ता, सीधे आपके घर तक।'},cta:{en:'Shop now',gu:'હમણાં ખરીદો',hi:'अभी खरीदें'}},
 {cls:'s1',eyebrow:{en:'Easy & safe',gu:'સરળ અને સુરક્ષિત',hi:'आसान और सुरक्षित'},h:{en:'Pay cash on delivery',gu:'ડિલિવરી પર રોકડ ચુકવણી',hi:'डिलीवरी पर नकद भुगतान'},p:{en:'Order today and pay only when it arrives.',gu:'આજે ઑર્ડર કરો, સામાન આવે ત્યારે જ ચુકવણી કરો.',hi:'आज ऑर्डर करें, सामान आने पर ही भुगतान करें।'},cta:{en:'Start ordering',gu:'ઑર્ડર શરૂ કરો',hi:'ऑर्डर शुरू करें'}},
 {cls:'s2',eyebrow:{en:'Family & bulk packs',gu:'ફેમિલી અને બલ્ક પૅક',hi:'फैमिली और बल्क पैक'},h:{en:'From small bottles to 5 litre cans',gu:'નાની બોટલથી 5 લિટર કૅન સુધી',hi:'छोटी बोतल से 5 लीटर कैन तक'},p:{en:'The right size for every need and budget.',gu:'દરેક જરૂરિયાત અને બજેટ માટે યોગ્ય સાઇઝ.',hi:'हर ज़रूरत और बजट के लिए सही साइज़।'},cta:{en:'See products',gu:'ઉત્પાદનો જુઓ',hi:'उत्पाद देखें'}}
];
const TRUST=[
 {ic:'<path d="M2 6h20v12H2z"/><circle cx="12" cy="12" r="2.5"/>',t:{en:'Cash on delivery',gu:'ડિલિવરી પર રોકડ',hi:'डिलीवरी पर नकद'},s:{en:'No online payment needed',gu:'ઓનલાઈન ચુકવણીની જરૂર નથી',hi:'ऑनलाइन भुगतान ज़रूरी नहीं'}},
 {ic:'<path d="M3 13h13V6H1v9h2"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/><path d="M16 9h4l3 4v3h-3"/>',t:{en:'Fast delivery',gu:'ઝડપી ડિલિવરી',hi:'तेज़ डिलीवरी'},s:{en:'Right to your doorstep',gu:'સીધી તમારા ઘર સુધી',hi:'सीधे आपके दरवाज़े तक'}},
 {ic:'<path d="M12 2l3 6 6 .9-4.5 4.3L18 20l-6-3.2L6 20l1.5-6.8L3 8.9 9 8z"/>',t:{en:'Quality assured',gu:'ગુણવત્તાની ખાતરી',hi:'गुणवत्ता की गारंटी'},s:{en:'Products you can trust',gu:'ભરોસાપાત્ર ઉત્પાદનો',hi:'भरोसे के उत्पाद'}},
 {ic:'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',t:{en:'Easy help',gu:'સરળ મદદ',hi:'आसान सहायता'},s:{en:'Call us anytime to order',gu:'ઑર્ડર માટે ગમે ત્યારે કૉલ કરો',hi:'ऑर्डर के लिए कभी भी कॉल करें'}}
];

const I18N={
 en:{announce:"Free delivery on orders above ₹500 · Cash on delivery available",tagline:"Trusted cleaning products",popular:"Popular products",popularSub:"Best-selling items, ready to order",allProducts:"All products",searchPh:"Search products",chipAll:"All",chipAbove:"₹300+",ownerLabel:"Shop owner: rename products (saves automatically)",viewOrders:"View orders",add:"Add",yourCart:"Your cart",continueShop:"Continue shopping",cartEmpty:"Your cart is empty",browse:"Browse products",total:"Total",items:"items",placeOrder:"Place order",back:"Back",yourDetails:"Your details",fName:"Your name",fMobile:"Mobile number",fAddr:"Full address",fCity:"City / village",fPin:"Pincode",phName:"e.g. Ramesh Patel",phMobile:"10-digit mobile number",phAddr:"House, street, village/area",errName:"Please enter your name",errMobile:"Please enter a valid 10-digit number",errAddr:"Please enter your address",codText:"Pay cash on delivery — no online payment needed",orderPlaced:"Order placed!",another:"Place another order",confMsg:"We will call you on {m} to confirm. Pay cash when your order arrives.",ordersTitle:"Orders received",noOrders:"No orders yet. Place a test order to see it here.",renameTitle:"Rename product",phRename:"e.g. Toilet cleaner 1 litre",save:"Save",cancel:"Cancel",remove:"Remove",showing:"Showing {n} products",footAbout:"Quality household and cleaning products, delivered to your door with cash-on-delivery convenience.",footHelp:"Help",footHelp1:"How to order",footHelp2:"Delivery & payment",footHelp3:"Returns",footContact:"Contact",footPhone:"Call: 1800-000-000",footHours:"Mon–Sat, 9am–7pm",footRights:"All rights reserved."},
 gu:{announce:"₹500 થી વધુના ઑર્ડર પર મફત ડિલિવરી · રોકડ ચુકવણી ઉપલબ્ધ",tagline:"વિશ્વાસપાત્ર સફાઈ ઉત્પાદનો",popular:"લોકપ્રિય ઉત્પાદનો",popularSub:"સૌથી વધુ વેચાતી વસ્તુઓ, હમણાં ઑર્ડર કરો",allProducts:"બધા ઉત્પાદનો",searchPh:"ઉત્પાદનો શોધો",chipAll:"બધા",chipAbove:"₹300+",ownerLabel:"દુકાન માલિક: નામ બદલો (આપમેળે સાચવાય છે)",viewOrders:"ઑર્ડર જુઓ",add:"ઉમેરો",yourCart:"તમારી કાર્ટ",continueShop:"ખરીદી ચાલુ રાખો",cartEmpty:"તમારી કાર્ટ ખાલી છે",browse:"ઉત્પાદનો જુઓ",total:"કુલ",items:"વસ્તુઓ",placeOrder:"ઑર્ડર કરો",back:"પાછળ",yourDetails:"તમારી માહિતી",fName:"તમારું નામ",fMobile:"મોબાઇલ નંબર",fAddr:"પૂરું સરનામું",fCity:"શહેર / ગામ",fPin:"પિન કોડ",phName:"દા.ત. રમેશ પટેલ",phMobile:"10 અંકનો મોબાઇલ નંબર",phAddr:"ઘર, શેરી, ગામ/વિસ્તાર",errName:"કૃપા કરી તમારું નામ લખો",errMobile:"કૃપા કરી સાચો 10 અંકનો નંબર લખો",errAddr:"કૃપા કરી તમારું સરનામું લખો",codText:"ડિલિવરી પર રોકડ ચુકવણી — ઓનલાઈન ચુકવણીની જરૂર નથી",orderPlaced:"ઑર્ડર થઈ ગયો!",another:"નવો ઑર્ડર કરો",confMsg:"અમે પુષ્ટિ માટે તમને {m} પર કૉલ કરીશું. સામાન આવે ત્યારે રોકડ ચુકવણી કરો.",ordersTitle:"મળેલા ઑર્ડર",noOrders:"હજી કોઈ ઑર્ડર નથી. એક ટેસ્ટ ઑર્ડર કરીને અહીં જુઓ.",renameTitle:"ઉત્પાદનનું નામ બદલો",phRename:"દા.ત. ટૉયલેટ ક્લીનર 1 લિટર",save:"સાચવો",cancel:"રદ કરો",remove:"દૂર કરો",showing:"{n} ઉત્પાદનો બતાવાય છે",footAbout:"ગુણવત્તાયુક્ત ઘરગથ્થુ અને સફાઈ ઉત્પાદનો, રોકડ ચુકવણીની સુવિધા સાથે તમારા ઘર સુધી.",footHelp:"મદદ",footHelp1:"ઑર્ડર કેવી રીતે કરવો",footHelp2:"ડિલિવરી અને ચુકવણી",footHelp3:"પરત",footContact:"સંપર્ક",footPhone:"કૉલ કરો: 1800-000-000",footHours:"સોમ–શનિ, સવારે 9 – સાંજે 7",footRights:"બધા હક્કો અમારી પાસે."},
 hi:{announce:"₹500 से ऊपर के ऑर्डर पर मुफ़्त डिलीवरी · नकद भुगतान उपलब्ध",tagline:"भरोसेमंद सफाई उत्पाद",popular:"लोकप्रिय उत्पाद",popularSub:"सबसे ज़्यादा बिकने वाले, अभी ऑर्डर करें",allProducts:"सभी उत्पाद",searchPh:"उत्पाद खोजें",chipAll:"सभी",chipAbove:"₹300+",ownerLabel:"दुकान मालिक: नाम बदलें (अपने आप सहेजा जाता है)",viewOrders:"ऑर्डर देखें",add:"जोड़ें",yourCart:"आपका कार्ट",continueShop:"और खरीदें",cartEmpty:"आपका कार्ट खाली है",browse:"उत्पाद देखें",total:"कुल",items:"वस्तुएँ",placeOrder:"ऑर्डर करें",back:"वापस",yourDetails:"आपकी जानकारी",fName:"आपका नाम",fMobile:"मोबाइल नंबर",fAddr:"पूरा पता",fCity:"शहर / गाँव",fPin:"पिन कोड",phName:"जैसे रमेश पटेल",phMobile:"10 अंकों का मोबाइल नंबर",phAddr:"घर, गली, गाँव/क्षेत्र",errName:"कृपया अपना नाम लिखें",errMobile:"कृपया सही 10 अंकों का नंबर लिखें",errAddr:"कृपया अपना पता लिखें",codText:"डिलीवरी पर नकद भुगतान — ऑनलाइन भुगतान ज़रूरी नहीं",orderPlaced:"ऑर्डर हो गया!",another:"नया ऑर्डर करें",confMsg:"हम पुष्टि के लिए आपको {m} पर कॉल करेंगे। सामान आने पर नकद भुगतान करें।",ordersTitle:"मिले हुए ऑर्डर",noOrders:"अभी कोई ऑर्डर नहीं। एक टेस्ट ऑर्डर करके यहाँ देखें।",renameTitle:"उत्पाद का नाम बदलें",phRename:"जैसे टॉयलेट क्लीनर 1 लीटर",save:"सहेजें",cancel:"रद्द करें",remove:"हटाएँ",showing:"{n} उत्पाद दिखाए जा रहे हैं",footAbout:"बढ़िया घरेलू और सफाई उत्पाद, डिलीवरी पर नकद भुगतान के साथ आपके घर तक।",footHelp:"सहायता",footHelp1:"ऑर्डर कैसे करें",footHelp2:"डिलीवरी और भुगतान",footHelp3:"वापसी",footContact:"संपर्क",footPhone:"कॉल करें: 1800-000-000",footHours:"सोम–शनि, सुबह 9 – शाम 7",footRights:"सर्वाधिकार सुरक्षित।"}
};
let lang='en';
const t=k=>(I18N[lang][k]??I18N.en[k]??k);

const mem={};
const store={
 async get(k){try{if(window.storage){const r=await window.storage.get(k);return r?JSON.parse(r.value):null;}}catch(e){}return mem[k]??null;},
 async set(k,v){mem[k]=v;try{if(window.storage){await window.storage.set(k,JSON.stringify(v));}}catch(e){}}
};

let names={}, cart={}, chip={min:0,max:999999}, renameId=null, heroCur=0, heroTimer=null;
const byId=id=>PRODUCTS.find(p=>p.id===id);
const nameOf=p=>p.name||p.code;
const boxIcon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>';
const pencil='<svg viewBox="0 0 24 24" fill="none" stroke="#7a5a1c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';

async function init(){
 var _lh=document.getElementById('logoHdr'); if(_lh)_lh.src=LOGO;
 var _lf=document.getElementById('logoFtr'); if(_lf)_lf.src=LOGO;
 names=(await store.get('sathee:names'))||{};
 buildHero(); buildTrust();
 setLang('en');
 document.getElementById('loading').hidden=true;
 document.getElementById('grid').hidden=false;
 renderAll();
 startHero();
 window.addEventListener('scroll',()=>{document.getElementById('hdr').classList.toggle('scrolled',window.scrollY>4);},{passive:true});
}

/* hero */
function buildHero(){
 const tr=document.getElementById('heroTrack');
 tr.innerHTML=SLIDES.map((s,i)=>`<div class="slide ${s.cls}">
   <svg class="deco" viewBox="0 0 100 100" fill="#fff"><circle cx="50" cy="50" r="50"/></svg>
   <svg class="deco2" viewBox="0 0 100 100" fill="#fff"><circle cx="50" cy="50" r="50"/></svg>
   <div class="slide-in"><span class="eyebrow" data-s="eyebrow" data-i="${i}"></span>
   <h2 data-s="h" data-i="${i}"></h2><p data-s="p" data-i="${i}"></p>
   <button class="cta" data-s="cta" data-i="${i}" onclick="document.getElementById('search').scrollIntoView({behavior:'smooth'})"></button></div></div>`).join('');
 const dots=document.getElementById('heroDots');
 dots.innerHTML=SLIDES.map((s,i)=>`<button aria-label="Slide ${i+1}" onclick="heroGo(${i},true)"></button>`).join('');
 const f=document.getElementById('heroFrame');
 f.addEventListener('mouseenter',stopHero); f.addEventListener('mouseleave',startHero);
 let sx=null; f.addEventListener('touchstart',e=>{sx=e.touches[0].clientX;stopHero();},{passive:true});
 f.addEventListener('touchend',e=>{if(sx!=null){const dx=e.changedTouches[0].clientX-sx;if(Math.abs(dx)>40)heroGo(heroCur+(dx<0?1:-1),true);}sx=null;startHero();},{passive:true});
 heroGo(0);
}
function fillHeroText(){
 document.querySelectorAll('[data-s]').forEach(el=>{const i=+el.dataset.i;el.textContent=SLIDES[i][el.dataset.s][lang];});
}
function heroGo(i,manual){
 const n=SLIDES.length; heroCur=(i%n+n)%n;
 document.getElementById('heroTrack').style.transform=`translateX(-${heroCur*100}%)`;
 [...document.getElementById('heroDots').children].forEach((d,k)=>d.classList.toggle('on',k===heroCur));
 if(manual){stopHero();startHero();}
}
function startHero(){ if(matchMedia('(prefers-reduced-motion:reduce)').matches)return; stopHero(); heroTimer=setInterval(()=>heroGo(heroCur+1),5000); }
function stopHero(){ if(heroTimer){clearInterval(heroTimer);heroTimer=null;} }

function buildTrust(){
 document.getElementById('trustRow').innerHTML=TRUST.map((x,i)=>`<div class="tcard">
   <span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${x.ic}</svg></span>
   <span><b data-tr="t" data-i="${i}"></b><span data-tr="s" data-i="${i}"></span></span></div>`).join('');
}
function fillTrust(){ document.querySelectorAll('[data-tr]').forEach(el=>{el.textContent=TRUST[+el.dataset.i][el.dataset.tr][lang];}); }

function setLang(l){
 lang=l;
 document.getElementById('langEn').classList.toggle('on',l==='en');
 document.getElementById('langGu').classList.toggle('on',l==='gu');
 document.getElementById('langHi').classList.toggle('on',l==='hi');
 document.documentElement.lang=l;
 document.querySelectorAll('[data-t]').forEach(el=>{el.textContent=t(el.getAttribute('data-t'));});
 document.querySelectorAll('[data-tph]').forEach(el=>{el.setAttribute('placeholder',t(el.getAttribute('data-tph')));});
 fillHeroText(); fillTrust(); renderAll(); renderCart(); updateHeader();
}

function setChip(btn){
 document.querySelectorAll('#priceChips .chip').forEach(c=>c.classList.remove('on'));
 btn.classList.add('on'); chip={min:+btn.dataset.min,max:+btn.dataset.max}; renderProducts();
}
function toggleOwner(on){ document.body.classList.toggle('owner',on); }

function cardHTML(p){
 const qty=cart[p.id]||0;
 const act=qty>0
  ?`<div class="stepper"><button onclick="setQty('${p.id}',${qty-1})" aria-label="Less">−</button><span class="q">${qty}</span><button onclick="setQty('${p.id}',${qty+1})" aria-label="More">+</button></div>`
  :`<button class="addbtn" onclick="setQty('${p.id}',1)">${t('add')}</button>`;
 const thumb=p.image?`<img src="${esc(p.image)}" alt="${esc(nameOf(p))}" style="width:100%;height:100%;object-fit:contain;border-radius:12px">`:boxIcon;
 return `<div class="card"><div class="thumb">${thumb}</div>
   <div class="pname"><span>${esc(nameOf(p))}</span><button class="edit" title="Rename" onclick="openRename('${p.id}')">${pencil}</button></div>
   <span class="unit">${esc(p.unit)}</span>
   <div class="price">${money(p.price)} <small>/ ${esc(p.unit)}</small></div>
   <div class="act">${act}</div></div>`;
}
function renderAll(){ renderProducts(); renderFeatured(); }
function renderFeatured(){
 const el=document.getElementById('featured');
 el.innerHTML=PRODUCTS.slice(0,12).map(p=>`<div class="fcard">${cardHTML(p)}</div>`).join('');
}
function renderProducts(){
 const q=(document.getElementById('search').value||'').trim().toLowerCase();
 const list=PRODUCTS.filter(p=>{
   const nm=nameOf(p).toLowerCase();
   const okq=!q||nm.includes(q)||p.code.toLowerCase().includes(q);
   return okq && p.price>=chip.min && p.price<chip.max;
 });
 document.getElementById('grid').innerHTML=list.map(cardHTML).join('')||`<div class="empty">${boxIcon}<div>No products found</div></div>`;
 document.getElementById('catCount').textContent=t('showing').replace('{n}',list.length);
}
function slideFeatured(dir){ document.getElementById('featured').scrollBy({left:dir*230,behavior:'smooth'}); }

function setQty(id,q){ if(q<=0)delete cart[id]; else cart[id]=q; renderAll(); renderCart(); updateHeader(); }
function cartItems(){ return Object.keys(cart).map(id=>({p:byId(id),qty:cart[id]})).filter(x=>x.p); }
function cartTotal(){ return cartItems().reduce((s,x)=>s+x.p.price*x.qty,0); }
function cartCount(){ return cartItems().reduce((s,x)=>s+x.qty,0); }
function updateHeader(){ document.getElementById('cartCount').textContent=cartCount(); }

function renderCart(){
 const list=document.getElementById('cartList'), foot=document.getElementById('cartFooter'), items=cartItems();
 if(!items.length){ list.innerHTML=`<div class="empty">${boxIcon}<div>${t('cartEmpty')}</div><button class="addbtn" style="max-width:240px;margin:16px auto 0" onclick="go('shop')">${t('browse')}</button></div>`; foot.innerHTML=''; return; }
 list.innerHTML=items.map(({p,qty})=>`<div class="crow">
   <div class="ci"><div class="cn">${esc(nameOf(p))}</div><div class="cp">${money(p.price)} / ${esc(p.unit)}</div><button class="remove" onclick="setQty('${p.id}',0)">${t('remove')}</button></div>
   <div class="mini"><button onclick="setQty('${p.id}',${qty-1})" aria-label="Less">−</button><span class="q">${qty}</span><button onclick="setQty('${p.id}',${qty+1})" aria-label="More">+</button></div>
   <div class="lt">${money(p.price*qty)}</div></div>`).join('');
 foot.innerHTML=`<div class="summary"><div class="sline total"><span>${t('total')} (${cartCount()} ${t('items')})</span><span>${money(cartTotal())}</span></div></div><button class="bigbtn" onclick="go('checkout')">${t('placeOrder')}</button>`;
}

function go(view){
 ['shop','cart','checkout','confirm','orders'].forEach(v=>document.getElementById('view-'+v).classList.toggle('on',v===view));
 if(view==='cart')renderCart();
 if(view==='checkout')renderCheckoutSummary();
 if(view==='orders')renderOrders();
 if(view==='shop')startHero(); else stopHero();
 window.scrollTo(0,0);
}
function renderCheckoutSummary(){
 document.getElementById('checkoutSummary').innerHTML=cartItems().map(({p,qty})=>`<div class="sline"><span>${esc(nameOf(p))} × ${qty}</span><span>${money(p.price*qty)}</span></div>`).join('')+`<div class="sline total"><span>${t('total')}</span><span>${money(cartTotal())}</span></div>`;
}
function field(id){return document.getElementById(id);}
function showErr(id,on){const f=field(id);f.classList.toggle('err',on);const m=f.parentElement.querySelector('.errmsg');if(m)m.classList.toggle('show',on);}

async function placeOrder(){
 const name=field('f-name').value.trim();
 const mob=(field('f-mobile').value||'').replace(/\D/g,'');
 const addr=field('f-addr').value.trim();
 let ok=true;
 showErr('f-name',!name); if(!name)ok=false;
 const mobOk=/^[6-9]\d{9}$/.test(mob); showErr('f-mobile',!mobOk); if(!mobOk)ok=false;
 showErr('f-addr',!addr); if(!addr)ok=false;
 if(!cartItems().length){ alert(t('cartEmpty')); go('shop'); return; }
 if(!ok){const fst=document.querySelector('.input.err');if(fst)fst.focus();return;}

 const payload={
   action:'place_order',
   name:name,
   mobile:mob,
   address:addr,
   city:field('f-city').value.trim(),
   pincode:field('f-pin').value.trim(),
   items:cartItems().map(({p,qty})=>({product_id:p.id,quantity:qty}))
 };

 const btn=document.querySelector('#view-checkout .bigbtn');
 const oldText=btn.textContent;
 btn.disabled=true; btn.textContent='Saving order...';
 try{
   const res=await fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
   const out=await res.json();
   if(!out.success){ alert(out.message||'Order save failed.'); return; }
   document.getElementById('confOrdNo').textContent=out.order_no;
   document.getElementById('confMsg').textContent=t('confMsg').replace('{m}',mob);
   document.getElementById('confSummary').innerHTML=cartItems().map(({p,qty})=>`<div class="sline"><span>${esc(nameOf(p))} × ${qty}</span><span>${money(p.price*qty)}</span></div>`).join('')+`<div class="sline total"><span>${t('total')}</span><span>${money(cartTotal())}</span></div>`;
   cart={}; updateHeader();
   ['f-name','f-mobile','f-addr','f-city','f-pin'].forEach(id=>field(id).value='');
   go('confirm');
 }catch(e){
   alert('Network/server error. Please try again.');
 }finally{
   btn.disabled=false; btn.textContent=oldText;
 }
}
function resetToShop(){ go('shop'); renderAll(); }

async function renderOrders(){ document.getElementById('ordersList').innerHTML='<div class="empty">Orders are saved in database table ecom_orders.</div>'; }

function openRename(id){ renameId=id; const p=byId(id); document.getElementById('renameCode').textContent='Code: '+p.code+'  ·  '+p.unit+'  ·  '+money(p.price); document.getElementById('renameInput').value=names[id]||''; document.getElementById('renameModal').classList.add('on'); document.getElementById('renameInput').focus(); }
function closeRename(){ document.getElementById('renameModal').classList.remove('on'); renameId=null; }
async function saveRename(){ const v=document.getElementById('renameInput').value.trim(); if(v)names[renameId]=v; else delete names[renameId]; await store.set('sathee:names',names); closeRename(); renderAll(); }

init();
</script>
</body>
</html>
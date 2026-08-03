<?php
require_once 'include/require_permission.php';
requirePermissionAjax('DISTRIBUTORS', 'view');
include('include/require_login.php');
header('Content-Type: application/json');
if (!isset($mysqli)) { include('include/db.php'); }

// Debug helper (turn on by calling ?debug=1)
$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($DEBUG) { ini_set('display_errors', 1); error_reporting(E_ALL); }

try{

    $limit = 20; // fixed as requested
    $page = max(1, (int)($_GET['page'] ?? 1));
    $q = trim($_GET['q'] ?? '');

    $where = '';
    $params = [];
    $types = '';

    if ($q !== '') {
        $where = "WHERE (distributor_code LIKE ? OR distributor_name LIKE ? OR contact_person LIKE ? OR mobile_number LIKE ?)";
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like];
        $types = 'ssss';
    }

    // -------- COUNT TOTAL --------
    $sqlCount = "SELECT COUNT(*) AS cnt FROM distributors " . $where;
    $stmt = $mysqli->prepare($sqlCount);
    if(!$stmt){ throw new Exception('Prepare(count) failed: ' . $mysqli->error); }
    if($where !== ''){ $stmt->bind_param($types, ...$params); }
    if(!$stmt->execute()){ throw new Exception('Execute(count) failed: ' . $stmt->error); }
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    $total = (int)($cnt ?? 0);

    $pages = max(1, (int)ceil($total / $limit));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $limit;

    // -------- FETCH PAGE --------
    // Important: Avoid placeholders for LIMIT/OFFSET on some MySQL versions.
    $limit_i = (int)$limit; $offset_i = (int)$offset;
    //$where = "WHERE (distributor_code LIKE ? OR distributor_name LIKE ? OR contact_person LIKE ? OR mobile_number LIKE ?)";
    $sql = "SELECT
  d.distributor_id,
  d.distributor_code,
  d.distributor_name,
  d.contact_person,
  d.mobile_number,
  d.email,
  d.address,
  d.created_at,
  COALESCE(p.pending_count, 0) AS pending_orders
FROM distributors d
LEFT JOIN (
  SELECT distributor_id, COUNT(*) AS pending_count
  FROM orders
  WHERE distributor_status = 'pending'   -- OR order_status = 'pending' if you use that
    AND distributor_id IS NOT NULL
  GROUP BY distributor_id
) p ON p.distributor_id = d.distributor_id
" . $where . "
ORDER BY d.distributor_id DESC
 LIMIT $limit_i OFFSET $offset_i";

    /*$sql = "SELECT distributor_id, distributor_code, distributor_name, contact_person, mobile_number, email, address, created_at
            FROM distributors " . $where . " ORDER BY distributor_id DESC LIMIT $limit_i OFFSET $offset_i";*/

    $stmt = $mysqli->prepare($sql);
    if(!$stmt){ throw new Exception('Prepare(list) failed: ' . $mysqli->error); }
    if ($where !== '') { $stmt->bind_param($types, ...$params); }
    if(!$stmt->execute()){ throw new Exception('Execute(list) failed: ' . $stmt->error); }

    $stmt->bind_result($id, $code, $name, $person, $mobile, $email, $addr, $created, $pending_orders);

    $rows = '';
    while($stmt->fetch()){
        $id_i = (int)$id;
        $rows .= "<tr>".
                 "<td>".$id_i."</td>".
                 "<td>".htmlspecialchars((string)$code)."</td>".
                 "<td>".htmlspecialchars((string)$name)."</td>".
                 "<td>".htmlspecialchars((string)$person)."</td>".
                 "<td>".htmlspecialchars((string)$mobile)."</td>".
                 "<td>".htmlspecialchars((string)$pending_orders)."</td>".
                 "<td>".htmlspecialchars((string)$addr)."</td>".
                 "<td><small>".htmlspecialchars((string)$created)."</small></td>".
                 "<td>".
                   "<div class='btn-group'>".
                     "<a href='distributor_edit.php?id=".$id_i."' class='btn btn-sm btn-outline-primary'>Edit</a>".
                     "<a href='distributor_view.php?id=".$id_i."' class='btn btn-sm btn-outline-primary'>View</a>".
                   "</div>".
                 "</td>".
               "</tr>";
    }
    $stmt->close();

    if ($rows === '') {
        $rows = "<tr><td colspan='9' class='text-center py-4'>No records found.</td></tr>";
    }

    // Pagination HTML (no intdiv to support older PHP)
    $pagination = '';
    if ($pages > 1) {
        $prevDisabled = ($page <= 1) ? ' disabled' : '';
        $nextDisabled = ($page >= $pages) ? ' disabled' : '';
        $prevPage = max(1, $page - 1);
        $nextPage = min($pages, $page + 1);

        $pagination .= "<li class='page-item$prevDisabled'><a class='page-link' href='#' data-page='$prevPage'>&laquo;</a></li>";

        $window = 7; // show up to 7 page links
        $half = (int)floor(($window - 1) / 2);
        $start = max(1, $page - $half);
        $end = min($pages, $start + $window - 1);
        if (($end - $start + 1) < $window) { $start = max(1, $end - $window + 1); }

        for ($i=$start; $i<=$end; $i++) {
            $active = ($i === $page) ? ' active' : '';
            $pagination .= "<li class='page-item$active'><a class='page-link' href='#' data-page='$i'>$i</a></li>";
        }

        $pagination .= "<li class='page-item$nextDisabled'><a class='page-link' href='#' data-page='$nextPage'>&raquo;</a></li>";
    }

    $from = ($total === 0) ? 0 : ($offset + 1);
    $to = min($total, $offset + $limit);
    $meta = "Showing ".$from-$to." of $total" . ($q !== '' ? " (filtered)" : "");

    echo json_encode([
        'success' => true,
        'rows_html' => $rows,
        'pagination_html' => $pagination,
        'meta' => $meta,
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    $msg = 'Server error';
    if ($DEBUG) { $msg .= ': ' . $e->getMessage(); }
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
?>

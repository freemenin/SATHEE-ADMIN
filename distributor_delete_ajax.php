<?php
require_once 'include/require_permission.php';
requirePermissionAjax('DISTRIBUTORS', 'delete');
include('include/require_login.php');
header('Content-Type: application/json');
if (!isset($mysqli)) { include('include/db.php'); }

$DELETE_PINCODE = '1234'; // change if needed

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

    $id  = (int)($_POST['distributor_id'] ?? 0);
    $pin = trim($_POST['pincode'] ?? '');

    if ($id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    if ($pin !== $DELETE_PINCODE) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'You are not authorized to delete this distributor.']); exit; }

    $stmt = $mysqli->prepare("DELETE FROM distributors WHERE distributor_id=? LIMIT 1");
    if(!$stmt){ throw new Exception('Prepare failed: ' . $mysqli->error); }
    $stmt->bind_param('i', $id);
    if(!$stmt->execute()){ throw new Exception('Execute failed: ' . $stmt->error); }

    echo json_encode(['success'=>true,'message'=>'Distributor deleted successfully.']);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
    exit;
}
?>


# OPTIONAL: hook a Delete button into your list page (distributor_list.php)
<!-- Add this inside the Actions column rendering (server HTML) next to Edit/View -->
<!-- <a href="#" class='btn btn-sm btn-outline-danger btn-del' data-id="<?= $id ?>">Delete</a> -->

<!-- Add this JS in distributor_list.php after loadPage() wiring -->
<script>
// delegate click for dynamically loaded rows
addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-del');
  if (!btn) return;
  e.preventDefault();
  const id = btn.getAttribute('data-id');
  const pin = prompt('Enter delete PIN to confirm (e.g., 1234):');
  if (!pin) return;
  try{
    const fd = new FormData(); fd.append('distributor_id', id); fd.append('pincode', pin);
    const res = await fetch('distributor_delete_ajax.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }});
    const data = await res.json();
    if(res.ok && data.success){
      // reload current page
      document.querySelector('#pageNav .page-item.active .page-link')?.click();
    } else {
      alert(data.message || 'Delete failed');
    }
  }catch(err){ alert('Network error'); }
});
</script>
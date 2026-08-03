<?php
require_once 'include/require_permission.php';
requirePermission('DISTRIBUTORS', 'view');
include('include/require_login.php');
include('include/header.php');
if (!isset($mysqli)) { include('include/db.php'); }
?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Distributors</h4>
    <a href="distributor_add.php" class="btn btn-primary">+ Add Distributor</a>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="row g-2 align-items-center mb-3">
        <div class="col-md-6">
          <input type="text" id="searchInput" class="form-control" placeholder="Search: code, name, contact person, mobile">
        </div>
        <div class="col-auto">
          <button id="btnClear" class="btn btn-outline-secondary">Clear</button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width: 90px;">ID</th>
              <th style="width: 130px;">Code</th>
              <th>Name</th>
              <th>Contact Person</th>
              <th style="width: 130px;">Mobile</th>
              <th style="width: 220px;">Pending orders</th>
              <th>Address</th>
              <th style="width: 160px;">Created</th>
              <th style="width: 120px;">Actions</th>
            </tr>
          </thead>
          <tbody id="listBody">
            <tr><td colspan="9" class="text-center py-4">Loading...</td></tr>
          </tbody>
        </table>
      </div>

      <div id="pagination" class="d-flex justify-content-between align-items-center border-top pt-3 mt-3">
        <div id="resultMeta" class="small text-muted">&nbsp;</div>
        <nav>
          <ul class="pagination mb-0" id="pageNav"></ul>
        </nav>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 1080">
  <div id="liveToast" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastMessage">Done.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script>
(() => {
  const tbody = document.getElementById('listBody');
  const pageNav = document.getElementById('pageNav');
  const resultMeta = document.getElementById('resultMeta');
  const searchInput = document.getElementById('searchInput');
  const btnClear = document.getElementById('btnClear');
  const toastEl = document.getElementById('liveToast');
  const toastMsg = document.getElementById('toastMessage');
  let bsToast;

  document.addEventListener('DOMContentLoaded', () => {
    bsToast = new bootstrap.Toast(toastEl, { delay: 2000 });
    loadPage(1);
  });

  function showToast(message, variant = 'primary') {
    toastEl.className = `toast align-items-center text-bg-${variant} border-0`;
    toastMsg.textContent = message;
    bsToast.show();
  }

  function debounce(fn, d=350){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn.apply(this,a), d); }; }

  const runSearch = debounce(()=>{ loadPage(1); }, 400);
  searchInput.addEventListener('input', runSearch);
  btnClear.addEventListener('click', ()=>{ searchInput.value=''; loadPage(1); });

  async function loadPage(page=1){
    tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4">Loading...</td></tr>`;
    try{
      const params = new URLSearchParams({ page: String(page), q: searchInput.value.trim() });
      const res = await fetch('distributor_list_ajax.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await res.json();
      if(!res.ok || !data.success){ throw new Error(data.message || 'Failed to load'); }

      tbody.innerHTML = data.rows_html || `<tr><td colspan="9" class="text-center py-4">No records found.</td></tr>`;
      pageNav.innerHTML = data.pagination_html || '';
      resultMeta.textContent = data.meta || '';

      // Wire up pagination clicks
      pageNav.querySelectorAll('a.page-link[data-page]').forEach(a=>{
        a.addEventListener('click', (e)=>{ e.preventDefault(); const p = parseInt(a.dataset.page, 10)||1; loadPage(p); });
      });
    }catch(err){
      console.error(err);
      tbody.innerHTML = `<tr><td colspan="9" class="text-center text-danger py-4">Error loading data.</td></tr>`;
      showToast('Error loading list', 'danger');
    }
  }
})();
</script>

<?php include('include/footer.php'); ?>
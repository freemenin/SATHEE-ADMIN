<?php
require_once 'include/require_permission.php';
requirePermission('PURCHASE_REQUESTS', 'view');
include('include/header.php');

/*
Assumption:
$mysqli connection already available
*/

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20; // records per page
$offset = ($page - 1) * $limit;

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($q !== '') {
    $where .= " AND (
        pm.purchase_no LIKE ?
        OR d.distributor_name LIKE ?
        OR d.distributor_code LIKE ?
        OR d.mobile_number LIKE ?
    ) ";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

if ($status !== '') {
    $where .= " AND pm.status = ? ";
    $params[] = $status;
    $types .= "s";
}

/* =========================
   Total Records Count
========================= */
$count_sql = "
    SELECT COUNT(*) AS total
    FROM distributor_purchase_master pm
    INNER JOIN distributors d ON d.distributor_id = pm.distributor_id
    $where
";

$count_stmt = $mysqli->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = (int)($count_result->fetch_assoc()['total'] ?? 0);
$total_pages = max(1, ceil($total_records / $limit));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

/* =========================
   Main Data Query
========================= */
$sql = "
    SELECT
        pm.purchase_id,
        pm.purchase_no,
        pm.purchase_date,
        pm.status,
        pm.total_qty,
        pm.total_amount,
        pm.created_at,
        d.distributor_name,
        d.distributor_code,
        d.mobile_number
    FROM distributor_purchase_master pm
    INNER JOIN distributors d ON d.distributor_id = pm.distributor_id
    $where
    ORDER BY pm.purchase_id DESC
    LIMIT ? OFFSET ?
";

$stmt = $mysqli->prepare($sql);

$main_params = $params;
$main_types = $types . "ii";
$main_params[] = $limit;
$main_params[] = $offset;

$stmt->bind_param($main_types, ...$main_params);
$stmt->execute();
$result = $stmt->get_result();

/* =========================
   Pagination URL Helper
========================= */
function buildPageUrl($pageNo)
{
    $query = $_GET;
    $query['page'] = $pageNo;
    return 'purchase_requests.php?' . http_build_query($query);
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h3 class="mb-1">Distributor Purchase Requests</h3>
            <p class="text-muted mb-0">View distributor purchase bills and update their status.</p>
        </div>
        <div class="text-muted small">
            Total Records: <strong><?php echo $total_records; ?></strong>
        </div>
    </div>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Status updated successfully.</div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form method="get" class="row g-2 mb-3">
                <div class="col-md-5">
                    <input type="text" name="q" class="form-control"
                        placeholder="Search purchase no / distributor / mobile"
                        value="<?php echo htmlspecialchars($q); ?>">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo ($status === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="ready" <?php echo ($status === 'ready') ? 'selected' : ''; ?>>Ready</option>
                        <option value="dispatched" <?php echo ($status === 'dispatched') ? 'selected' : ''; ?>>Dispatched</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Search</button>
                </div>
                <div class="col-md-2">
                    <a href="purchase_requests.php" class="btn btn-light border w-100">Reset</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th width="60">#</th>
                            <th>Purchase No</th>
                            <th>Date</th>
                            <th>Distributor</th>
                            <th>Mobile</th>
                            <th>Total Qty</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                            <th width="120">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php $i = $offset + 1; ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['purchase_no']); ?></strong>
                                    </td>
                                    <td><?php echo date('d-m-Y', strtotime($row['purchase_date'])); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($row['distributor_name']); ?><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($row['distributor_code']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                    <td><?php echo (int)$row['total_qty']; ?></td>
                                    <td>₹<?php echo number_format((float)$row['total_amount'], 2); ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'draft'): ?>
                                            <span class="badge bg-warning text-dark">Draft</span>
                                        <?php elseif ($row['status'] === 'ready'): ?>
                                            <span class="badge bg-info text-dark">Ready</span>
                                        <?php elseif ($row['status'] === 'dispatched'): ?>
                                            <span class="badge bg-success">Dispatched</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="purchase_invoice.php?id=<?php echo (int)$row['purchase_id']; ?>" class="btn btn-sm btn-primary">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">No purchase request found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                    <div class="text-muted small">
                        Showing
                        <strong><?php echo ($total_records > 0) ? ($offset + 1) : 0; ?></strong>
                        to
                        <strong><?php echo min($offset + $limit, $total_records); ?></strong>
                        of
                        <strong><?php echo $total_records; ?></strong>
                        entries
                    </div>

                    <nav>
                        <ul class="pagination mb-0">

                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($page > 1) ? buildPageUrl($page - 1) : '#'; ?>">Previous</a>
                            </li>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);

                            if ($start > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo buildPageUrl(1); ?>">1</a>
                                </li>
                                <?php if ($start > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $start; $p <= $end; $p++): ?>
                                <li class="page-item <?php echo ($p == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPageUrl($p); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($end < $total_pages): ?>
                                <?php if ($end < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo buildPageUrl($total_pages); ?>"><?php echo $total_pages; ?></a>
                                </li>
                            <?php endif; ?>

                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($page < $total_pages) ? buildPageUrl($page + 1) : '#'; ?>">Next</a>
                            </li>

                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include('include/footer.php'); ?>
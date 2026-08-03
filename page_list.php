<?php
require_once 'include/require_permission.php';
requirePermission('PAGES', 'view');

require_once 'include/csrf_helper.php';

include('include/header.php');

// Get all pages
$search = trim($_GET['search'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$query = "
    SELECT
        p.page_id,
        p.parent_id,
        p.page_name,
        p.page_code,
        p.page_url,
        p.icon_class,
        p.menu_group,
        p.display_order,
        p.show_in_menu,
        p.status,
        p.created_at,
        (SELECT page_name FROM system_pages WHERE page_id = p.parent_id) as parent_name,
        COUNT(DISTINCT rpp.role_id) as total_roles
    FROM system_pages p
    LEFT JOIN role_page_permissions rpp ON rpp.page_id = p.page_id
    WHERE 1=1
";

$params = [];
$types = "";

if ($search !== '') {
    $query .= " AND (p.page_name LIKE ? OR p.page_code LIKE ? OR p.page_url LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

$query .= " GROUP BY p.page_id ORDER BY p.menu_group ASC, p.display_order ASC LIMIT ?, ?";
$params[] = $offset;
$params[] = $per_page;
$types .= "ii";

// Count total
$countQuery = "SELECT COUNT(DISTINCT p.page_id) as total FROM system_pages p";
if ($search !== '') {
    $countQuery .= " WHERE (p.page_name LIKE ? OR p.page_code LIKE ? OR p.page_url LIKE ?)";
}

$countStmt = $mysqli->prepare($countQuery);
if ($search !== '') {
    $searchTerm = "%$search%";
    $countStmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();
$total_count = (int)$countRow['total'];
$countStmt->close();

$total_pages = ceil($total_count / $per_page);

// Execute main query
$stmt = $mysqli->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$pages = [];
while ($row = $result->fetch_assoc()) {
    $pages[] = $row;
}
$stmt->close();
?>

<div class="sa-page__body">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="sa-page-header row align-items-center mb-4">
            <div class="col">
                <h1 class="sa-page-title">
                    <i class="bi bi-layout-text-window"></i>
                    System Pages
                </h1>
                <p class="sa-page-description">
                    Manage CRM pages and assign them to roles
                </p>
            </div>
            <?php if (hasPermission('PAGES', 'add')): ?>
                <div class="col-auto">
                    <a href="page_form.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i>
                        Add New Page
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Search -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
                    <div class="flex-grow-1" style="min-width: 250px;">
                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            placeholder="Search pages..."
                            value="<?= htmlspecialchars($search) ?>"
                        >
                    </div>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-search"></i>
                        Search
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="page_list.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x"></i>
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Pages Table -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Page Name</th>
                            <th>Page Code</th>
                            <th>URL</th>
                            <th>Menu Group</th>
                            <th style="text-align: center;">Roles</th>
                            <th style="text-align: center;">Order</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($pages) > 0): ?>
                            <?php foreach ($pages as $pageItem): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($pageItem['page_name']) ?></strong>
                                        <?php if (!$pageItem['show_in_menu']): ?>
                                            <span class="badge bg-secondary ms-2" title="Hidden from menu">
                                                <i class="bi bi-eye-slash"></i> Hidden
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($pageItem['page_code']) ?></code>
                                    </td>
                                    <td>
                                        <code class="small"><?= htmlspecialchars($pageItem['page_url'] ?? '') ?></code>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?= htmlspecialchars($pageItem['menu_group'] ?? 'Main') ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge bg-success">
                                            <?= (int)$pageItem['total_roles'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <?= (int)$pageItem['display_order'] ?>
                                    </td>
                                    <td>
                                        <?php if ($pageItem['status'] === 'active'): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-x-circle"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <div class="btn-group" role="group">
                                            <?php if (hasPermission('PAGES', 'edit')): ?>
                                                <a
                                                    href="page_form.php?id=<?= (int)$pageItem['page_id'] ?>"
                                                    class="btn btn-sm btn-outline-primary"
                                                >
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (hasPermission('PAGES', 'edit')): ?>
                                                <form method="post" action="page_status.php" class="d-inline" style="display:inline;">
                                                    <?= csrfTokenField() ?>
                                                    <input type="hidden" name="page_id" value="<?= (int)$pageItem['page_id'] ?>">
                                                    <input type="hidden" name="status" value="<?= $pageItem['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-outline-warning"
                                                        title="Toggle status"
                                                    >
                                                        <i class="bi bi-toggle-<?= $pageItem['status'] === 'active' ? 'off' : 'on' ?>"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (hasPermission('PAGES', 'delete')): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="confirmDelete(<?= (int)$pageItem['page_id'] ?>, '<?= htmlspecialchars($pageItem['page_name'], ENT_QUOTES) ?>')"
                                                >
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox" style="font-size: 32px;"></i>
                                    <p class="mt-3">No pages found</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=1<?= $search ? '&search=' . urlencode($search) : '' ?>">First</a>
                    </li>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $total_pages ?><?= $search ? '&search=' . urlencode($search) : '' ?>">Last</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Page</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="page_delete.php">
                <?= csrfTokenField() ?>
                <div class="modal-body">
                    <p>Are you sure you want to delete the page <strong id="pageName"></strong>?</p>
                    <p class="text-danger small">This action cannot be undone. All role permissions for this page will be removed.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <input type="hidden" name="page_id" id="deletePageId">
                    <button type="submit" class="btn btn-danger">Delete Page</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(pageId, pageName) {
    document.getElementById('pageName').textContent = pageName;
    document.getElementById('deletePageId').value = pageId;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php include('include/footer.php'); ?>

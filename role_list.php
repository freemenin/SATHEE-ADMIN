<?php
require_once 'include/require_permission.php';
requirePermission('ROLES', 'view');

require_once 'include/csrf_helper.php';

include('include/header.php');

// Get all roles
$search = trim($_GET['search'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$query = "
    SELECT
        r.role_id,
        r.role_name,
        r.role_code,
        r.description,
        r.is_system_role,
        r.status,
        r.created_at,
        COUNT(DISTINCT u.user_id) as total_users,
        COUNT(DISTINCT CASE WHEN rpp.can_view = 1 THEN rpp.page_id END) as total_pages
    FROM roles r
    LEFT JOIN users u ON u.role_id = r.role_id
    LEFT JOIN role_page_permissions rpp ON rpp.role_id = r.role_id
    WHERE 1=1
";

$params = [];
$types = "";

if ($search !== '') {
    $query .= " AND (r.role_name LIKE ? OR r.role_code LIKE ? OR r.description LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

$query .= " GROUP BY r.role_id ORDER BY r.is_system_role DESC, r.role_name ASC LIMIT ?, ?";
$params[] = $offset;
$params[] = $per_page;
$types .= "ii";

// Count total
$countQuery = "SELECT COUNT(*) as total FROM roles r";
if ($search !== '') {
    $countQuery .= " WHERE (r.role_name LIKE ? OR r.role_code LIKE ? OR r.description LIKE ?)";
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
$roles = [];
while ($row = $result->fetch_assoc()) {
    $roles[] = $row;
}
$stmt->close();
?>

<div class="sa-page__body">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="sa-page-header row align-items-center mb-4">
            <div class="col">
                <h1 class="sa-page-title">
                    <i class="bi bi-person-badge"></i>
                    Roles
                </h1>
                <p class="sa-page-description">
                    Manage system roles and see how many pages/users each role controls
                </p>
            </div>
            <?php if (hasPermission('ROLES', 'add')): ?>
                <div class="col-auto">
                    <a href="role_form.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i>
                        Add New Role
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
                            placeholder="Search roles..."
                            value="<?= htmlspecialchars($search) ?>"
                        >
                    </div>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-search"></i>
                        Search
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="role_list.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x"></i>
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Roles Table -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Role Name</th>
                            <th>Role Code</th>
                            <th>Description</th>
                            <th style="text-align: center;">Users</th>
                            <th style="text-align: center;">Pages Granted</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($roles) > 0): ?>
                            <?php foreach ($roles as $roleItem): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($roleItem['role_name']) ?></strong>
                                        <?php if ($roleItem['is_system_role']): ?>
                                            <span class="badge bg-secondary ms-2" title="Built-in system role">
                                                <i class="bi bi-shield-lock"></i> System
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($roleItem['role_code']) ?></code>
                                    </td>
                                    <td class="small text-muted">
                                        <?= htmlspecialchars($roleItem['description'] ?? '') ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge bg-info">
                                            <?= (int)$roleItem['total_users'] ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge bg-success">
                                            <?= (int)$roleItem['total_pages'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($roleItem['status'] === 'active'): ?>
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
                                            <?php if (hasPermission('ROLE_PERMISSIONS', 'view')): ?>
                                                <a
                                                    href="role_permissions.php?role_id=<?= (int)$roleItem['role_id'] ?>"
                                                    class="btn btn-sm btn-outline-success"
                                                    title="Assign page permissions"
                                                >
                                                    <i class="bi bi-shield-lock"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (hasPermission('ROLES', 'edit')): ?>
                                                <a
                                                    href="role_form.php?id=<?= (int)$roleItem['role_id'] ?>"
                                                    class="btn btn-sm btn-outline-primary"
                                                    title="Edit role"
                                                >
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (hasPermission('ROLES', 'edit') && !$roleItem['is_system_role']): ?>
                                                <form method="post" action="role_status.php" class="d-inline" style="display:inline;">
                                                    <?= csrfTokenField() ?>
                                                    <input type="hidden" name="role_id" value="<?= (int)$roleItem['role_id'] ?>">
                                                    <input type="hidden" name="status" value="<?= $roleItem['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                    <button
                                                        type="submit"
                                                        class="btn btn-sm btn-outline-warning"
                                                        title="Toggle status"
                                                    >
                                                        <i class="bi bi-toggle-<?= $roleItem['status'] === 'active' ? 'off' : 'on' ?>"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (hasPermission('ROLES', 'delete') && !$roleItem['is_system_role']): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="confirmDelete(<?= (int)$roleItem['role_id'] ?>, '<?= htmlspecialchars($roleItem['role_name'], ENT_QUOTES) ?>')"
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
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="bi bi-inbox" style="font-size: 32px;"></i>
                                    <p class="mt-3">No roles found</p>
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
                <h5 class="modal-title">Delete Role</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="role_delete.php">
                <?= csrfTokenField() ?>
                <div class="modal-body">
                    <p>Are you sure you want to delete the role <strong id="roleName"></strong>?</p>
                    <p class="text-danger small">This action cannot be undone. All page permissions for this role will be removed. Roles with active users assigned cannot be deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <input type="hidden" name="role_id" id="deleteRoleId">
                    <button type="submit" class="btn btn-danger">Delete Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(roleId, roleName) {
    document.getElementById('roleName').textContent = roleName;
    document.getElementById('deleteRoleId').value = roleId;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php include('include/footer.php'); ?>

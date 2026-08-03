<?php
require_once 'include/require_permission.php';
requirePermission('USERS', 'view');

require_once 'include/csrf_helper.php';

include('include/header.php');

$search = trim($_GET['search'] ?? '');
$page = (int)($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$query = "
    SELECT
        u.user_id,
        u.name,
        u.email,
        u.phone,
        u.username,
        u.role,
        u.role_id,
        u.status,
        u.created_at,
        r.role_name,
        r.role_code
    FROM users u
    LEFT JOIN roles r ON r.role_id = u.role_id
    WHERE 1=1
";

$params = [];
$types = "";

if ($search !== '') {
    $query .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ssss";
}

$query .= " ORDER BY u.name ASC LIMIT ?, ?";
$params[] = $offset;
$params[] = $per_page;
$types .= "ii";

// Count total
$countQuery = "SELECT COUNT(*) as total FROM users u";
if ($search !== '') {
    $countQuery .= " WHERE (u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)";
}

$countStmt = $mysqli->prepare($countQuery);
if ($search !== '') {
    $searchTerm = "%$search%";
    $countStmt->bind_param("ssss", $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$countRow = $countResult->fetch_assoc();
$total_count = (int)$countRow['total'];
$countStmt->close();

$total_pages = ceil($total_count / $per_page);

$stmt = $mysqli->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
?>

<div class="sa-page__body">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="sa-page-header row align-items-center mb-4">
            <div class="col">
                <h1 class="sa-page-title">
                    <i class="bi bi-person-gear"></i>
                    Users
                </h1>
                <p class="sa-page-description">
                    Manage system users and their assigned role. Roles control page access -
                    manage those from <a href="role_list.php">Role Management</a>.
                </p>
            </div>
            <?php if (hasPermission('USERS', 'add')): ?>
                <div class="col-auto">
                    <a href="add_user.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i>
                        Add New User
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
                            placeholder="Search by name, email, username, phone..."
                            value="<?= htmlspecialchars($search) ?>"
                        >
                    </div>
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-search"></i>
                        Search
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="user_list.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x"></i>
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Username</th>
                            <th>Phone</th>
                            <th>System Role</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($users) > 0): ?>
                            <?php foreach ($users as $userItem): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($userItem['name']) ?></strong>
                                        <?php if ((int)$userItem['user_id'] === $currentUserId): ?>
                                            <span class="badge bg-info ms-1">You</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($userItem['email']) ?></td>
                                    <td><code><?= htmlspecialchars($userItem['username'] ?? '') ?></code></td>
                                    <td><?= htmlspecialchars($userItem['phone'] ?? '') ?></td>
                                    <td>
                                        <?php if ($userItem['role_name']): ?>
                                            <span class="badge bg-success"><?= htmlspecialchars($userItem['role_name']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary" title="No system role assigned - this user cannot access any page">
                                                <i class="bi bi-exclamation-triangle"></i> Unassigned
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($userItem['status'] === 'active'): ?>
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
                                            <?php if (hasPermission('USERS', 'edit')): ?>
                                                <a
                                                    href="edit_user.php?uid=<?= (int)$userItem['user_id'] ?>"
                                                    class="btn btn-sm btn-outline-primary"
                                                    title="Edit user"
                                                >
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (hasPermission('USERS', 'delete') && (int)$userItem['user_id'] !== $currentUserId): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger"
                                                    onclick="confirmDelete(<?= (int)$userItem['user_id'] ?>, '<?= htmlspecialchars($userItem['name'], ENT_QUOTES) ?>')"
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
                                    <p class="mt-3">No users found</p>
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
                <h5 class="modal-title">Delete User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="delete_user.php">
                <?= csrfTokenField() ?>
                <div class="modal-body">
                    <p>Are you sure you want to delete the user <strong id="userName"></strong>?</p>
                    <p class="text-danger small">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <input type="hidden" name="delete_id" id="deleteUserId">
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(userId, userName) {
    document.getElementById('userName').textContent = userName;
    document.getElementById('deleteUserId').value = userId;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php include('include/footer.php'); ?>

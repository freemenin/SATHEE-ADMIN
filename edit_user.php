<?php
require_once 'include/require_permission.php';
requirePermission('USERS', 'edit');
require_once 'include/csrf_helper.php';

// Get user ID safely from GET or POST
if (isset($_GET['uid'])) {
    $user_id = intval($_GET['uid']);
} elseif (isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
} else {
    include('include/header.php');
    echo "
    <div class='container text-center py-5'>
        <h1 class='display-4 text-danger'>🚫 404: Invalid Request</h1>
        <p class='lead'>You tried to access this page without selecting a user.</p>
        <a href='user_list.php' class='btn btn-primary mt-3'>🔙 Back to User List</a>
    </div>";
    include('include/footer.php');
    exit;
}

$activeRoles = getActiveRoles();
$updateMessage = null;

// Handle the update before any HTML output so we can safely redirect on
// success/failure, same pattern as role_form.php / page_form.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {

    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $updateMessage = ['type' => 'danger', 'text' => 'Invalid request token. Please try again.'];
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'active');

        $validRoleIds = array_map(static function ($r) { return (int)$r['role_id']; }, $activeRoles);

        if ($name === '' || $email === '' || $username === '' || $role === '') {
            $updateMessage = ['type' => 'danger', 'text' => 'Please fill all required fields.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $updateMessage = ['type' => 'danger', 'text' => 'Please enter a valid email address.'];
        } elseif ($roleId <= 0 || !in_array($roleId, $validRoleIds, true)) {
            $updateMessage = ['type' => 'danger', 'text' => 'Please select a valid, active system role.'];
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $updateMessage = ['type' => 'danger', 'text' => 'Invalid status.'];
        } else {

            $blocked = false;

            // Never allow the last active Administrator user to be deactivated or
            // reassigned away from the ADMIN role - that would lock every admin
            // tool (Roles, Pages, Permissions, Users) behind a role nobody can reach.
            if (isLastAdminUser($user_id)) {
                $adminRoleId = 0;
                foreach ($activeRoles as $r) {
                    if ($r['role_code'] === 'ADMIN') {
                        $adminRoleId = (int)$r['role_id'];
                        break;
                    }
                }

                if ($status !== 'active') {
                    $updateMessage = ['type' => 'danger', 'text' => 'Cannot deactivate the last active Administrator user.'];
                    $blocked = true;
                } elseif ($adminRoleId > 0 && $roleId !== $adminRoleId) {
                    $updateMessage = ['type' => 'danger', 'text' => 'Cannot change the role of the last active Administrator user.'];
                    $blocked = true;
                }
            }

            if (!$blocked) {
                mysqli_report(MYSQLI_REPORT_OFF);

                $stmt = $mysqli->prepare("UPDATE users SET name=?, email=?, phone=?, username=?, role=?, role_id=?, status=? WHERE user_id=?");
                $stmt->bind_param("sssssisi", $name, $email, $phone, $username, $role, $roleId, $status, $user_id);

                if ($stmt->execute()) {
                    $stmt->close();

                    // If the currently logged-in user's own role changed, force it to
                    // be reloaded from the DB on the next permission check.
                    if ((int)($_SESSION['user_id'] ?? 0) === $user_id) {
                        unset($_SESSION['role_id']);
                    }

                    $_SESSION['toast'] = ['type' => 'success', 'message' => 'User updated successfully.'];
                    header('Location: user_list.php');
                    exit;
                } else {
                    $error = strtolower($stmt->error);
                    $stmt->close();

                    if (strpos($error, 'email') !== false) {
                        $updateMessage = ['type' => 'danger', 'text' => 'Email already exists.'];
                    } elseif (strpos($error, 'username') !== false) {
                        $updateMessage = ['type' => 'danger', 'text' => 'Username already taken.'];
                    } else {
                        $updateMessage = ['type' => 'danger', 'text' => 'Failed to update user.'];
                    }
                }
            }
        }
    }
}

// Fetch user data to display (re-fetch after any update attempt so the form
// always reflects the current DB state, not just what was posted).
$stmt = $mysqli->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    include('include/header.php');
    echo "<div class='container text-center py-5'>
            <h1 class='display-4 text-danger'>❌ User Not Found</h1>
            <p>The selected user does not exist.</p>
            <a href='user_list.php' class='btn btn-primary mt-3'>🔙 Back</a>
          </div>";
    include('include/footer.php');
    exit;
}
$user = $result->fetch_assoc();
$stmt->close();

include('include/header.php');
?>
<style>
.toast-container-center {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 9999;
}
.toast {
    min-width: 320px;
}
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">✏️ Edit User</h4>
        <a href="user_list.php" class="btn btn-sm btn-secondary">👥 Back to User List</a>
    </div>

    <?php if ($updateMessage): ?>
        <div class="alert alert-<?= $updateMessage['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($updateMessage['text']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="">
                <?= csrfTokenField() ?>
                <input type="hidden" name="user_id" value="<?= (int)$user['user_id'] ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
                        <small class="text-muted">* Must be unique.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>
                        <small class="text-muted">* Must be unique.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="purchase" <?= $user['role'] === 'purchase' ? 'selected' : '' ?>>Purchase</option>
                            <option value="sales" <?= $user['role'] === 'sales' ? 'selected' : '' ?>>Sales</option>
                            <option value="production" <?= $user['role'] === 'production' ? 'selected' : '' ?>>Production</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">System Role (Page Access) <span class="text-danger">*</span></label>
                        <select name="role_id" class="form-select" required>
                            <option value="">Select System Role</option>
                            <?php foreach ($activeRoles as $activeRole): ?>
                                <option value="<?= (int)$activeRole['role_id'] ?>" <?= ((int)$user['role_id'] === (int)$activeRole['role_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($activeRole['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Controls which pages this user can access.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <button type="submit" name="update" class="btn btn-primary">💾 Update User</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include('include/footer.php'); ?>

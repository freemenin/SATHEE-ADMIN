<?php
require_once 'include/require_permission.php';
requirePermission('USERS', 'add');
require_once 'include/csrf_helper.php';
?>

<?php
// ===============================
// Add User Logic
// ===============================

$toastType = "";
$toastTitle = "";
$toastMessage = "";
$formData = [
    'name'     => '',
    'email'    => '',
    'phone'    => '',
    'username' => '',
    'role'     => '',
    'role_id'  => '',
    'status'   => 'active'
];

$activeRoles = getActiveRoles();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {

    mysqli_report(MYSQLI_REPORT_OFF);

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $passwordRaw = trim($_POST['password'] ?? '');
    $role     = trim($_POST['role'] ?? '');
    $roleId   = (int)($_POST['role_id'] ?? 0);
    $status   = trim($_POST['status'] ?? 'active');

    $formData = [
        'name'     => $name,
        'email'    => $email,
        'phone'    => $phone,
        'username' => $username,
        'role'     => $role,
        'role_id'  => $roleId,
        'status'   => $status
    ];

    $allowedRoles = ['admin', 'purchase', 'sales', 'production'];
    $allowedStatus = ['active', 'inactive'];

    $validRoleIds = array_map(static function ($r) { return (int)$r['role_id']; }, $activeRoles);

    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $toastType = "danger";
        $toastTitle = "Invalid Request";
        $toastMessage = "Your session token is invalid. Please try again.";

    } elseif ($name === '' || $email === '' || $username === '' || $passwordRaw === '' || $role === '') {
        $toastType = "danger";
        $toastTitle = "Missing Details";
        $toastMessage = "Please fill all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $toastType = "danger";
        $toastTitle = "Invalid Email";
        $toastMessage = "Please enter a valid email address.";

    } elseif (!in_array($role, $allowedRoles)) {
        $toastType = "danger";
        $toastTitle = "Invalid Role";
        $toastMessage = "Please select a valid user role.";

    } elseif ($roleId <= 0 || !in_array($roleId, $validRoleIds, true)) {
        $toastType = "danger";
        $toastTitle = "Invalid System Role";
        $toastMessage = "Please select a valid, active system role for page access.";

    } elseif (!in_array($status, $allowedStatus)) {
        $toastType = "danger";
        $toastTitle = "Invalid Status";
        $toastMessage = "Please select a valid user status.";

    } else {

        $password = password_hash($passwordRaw, PASSWORD_DEFAULT);

        $stmt = $mysqli->prepare("
            INSERT INTO users
            (
                name,
                email,
                phone,
                username,
                password,
                role,
                role_id,
                status,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->bind_param(
            "ssssssis",
            $name,
            $email,
            $phone,
            $username,
            $password,
            $role,
            $roleId,
            $status
        );

        if ($stmt->execute()) {
            $toastType = "success";
            $toastTitle = "Success";
            $toastMessage = "User added successfully. Redirecting...";

            echo "
            <script>
                setTimeout(function() {
                    window.location.href = 'user_list.php';
                }, 1800);
            </script>";
        } else {
            $error = strtolower($stmt->error);

            $toastType = "danger";
            $toastTitle = "Error";

            if (strpos($error, 'email') !== false) {
                $toastMessage = "Email already exists. Please use another email.";
            } elseif (strpos($error, 'username') !== false) {
                $toastMessage = "Username already exists. Please choose another username.";
            } else {
                $toastMessage = "Something went wrong. Please try again.";
            }
        }

        $stmt->close();
    }
}
?>

<?php include('include/header.php'); ?>

<style>
    body {
        background: #f5f7fb;
    }

    .sa-page-wrap {
        padding: 22px 12px;
    }

    .sa-page-header {
        background: #ffffff;
        border-radius: 18px;
        padding: 18px 20px;
        margin-bottom: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        border: 1px solid #eef0f5;
    }

    .sa-page-title {
        font-size: 22px;
        font-weight: 800;
        color: #111827;
        margin-bottom: 4px;
    }

    .sa-page-subtitle {
        color: #6b7280;
        font-size: 14px;
        margin: 0;
    }

    .sa-form-card {
        background: #ffffff;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        border: 1px solid #eef0f5;
        overflow: hidden;
    }

    .sa-card-header {
        padding: 18px 22px;
        border-bottom: 1px solid #eef0f5;
        background: linear-gradient(180deg, #ffffff, #fafbff);
    }

    .sa-card-title {
        font-size: 17px;
        font-weight: 800;
        color: #111827;
        margin-bottom: 3px;
    }

    .sa-card-subtitle {
        font-size: 13px;
        color: #6b7280;
        margin: 0;
    }

    .sa-card-body {
        padding: 22px;
    }

    .sa-section-title {
        font-size: 14px;
        font-weight: 800;
        color: #374151;
        padding-bottom: 9px;
        border-bottom: 1px solid #eef0f5;
        margin-bottom: 18px;
    }

    .form-label {
        font-size: 14px;
        font-weight: 700;
        color: #374151;
        margin-bottom: 7px;
    }

    .form-control,
    .form-select {
        min-height: 46px;
        border-radius: 12px;
        border: 1px solid #dbe1ea;
        color: #111827;
        font-size: 14px;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #6d5dfc;
        box-shadow: 0 0 0 3px rgba(109, 93, 252, 0.12);
    }

    .form-text {
        font-size: 12px;
        color: #7b8190;
    }

    .required-star {
        color: #dc3545;
    }

    .sa-password-wrap {
        position: relative;
    }

    .sa-toggle-password {
        position: absolute;
        top: 50%;
        right: 12px;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        font-size: 13px;
        font-weight: 700;
        color: #5b4ae6;
        padding: 4px 6px;
    }

    .sa-password-wrap input {
        padding-right: 72px;
    }

    .sa-role-note {
        background: #f8f9fc;
        border: 1px solid #eef0f5;
        border-radius: 14px;
        padding: 14px;
        color: #6b7280;
        font-size: 13px;
        height: 100%;
    }

    .sa-role-note strong {
        color: #111827;
        display: block;
        margin-bottom: 4px;
    }

    .sa-action-bar {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        padding-top: 18px;
        border-top: 1px solid #eef0f5;
        margin-top: 22px;
    }

    .btn-sa-main {
        min-height: 46px;
        border-radius: 12px;
        font-weight: 800;
        padding-left: 22px;
        padding-right: 22px;
    }

    .btn-sa-light {
        min-height: 46px;
        border-radius: 12px;
        font-weight: 700;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        color: #374151;
    }

    .sa-toast-center {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 99999;
        width: calc(100% - 30px);
        max-width: 420px;
    }

    .sa-custom-toast {
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 18px 45px rgba(0,0,0,.16);
        border: 0;
        background: #ffffff;
    }

    .sa-toast-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 13px 16px;
        border-bottom: 1px solid #eef0f5;
    }

    .sa-toast-title {
        font-weight: 800;
        color: #111827;
    }

    .sa-toast-body {
        padding: 16px;
        font-size: 14px;
        color: #374151;
    }

    .sa-badge-success,
    .sa-badge-danger {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #ffffff;
        font-weight: 800;
        margin-right: 8px;
    }

    .sa-badge-success {
        background: #16a34a;
    }

    .sa-badge-danger {
        background: #dc2626;
    }

    @media (max-width: 768px) {
        .sa-page-wrap {
            padding: 14px 10px 28px;
        }

        .sa-page-header {
            padding: 16px;
        }

        .sa-page-title {
            font-size: 19px;
        }

        .sa-card-body {
            padding: 16px;
        }

        .sa-card-header {
            padding: 16px;
        }

        .sa-action-bar {
            flex-direction: column-reverse;
        }

        .sa-action-bar .btn {
            width: 100%;
        }
    }
</style>

<div class="container-fluid sa-page-wrap">

    <div class="sa-page-header">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h4 class="sa-page-title">Add New User</h4>
                <p class="sa-page-subtitle">Create a system user and assign role-based access.</p>
            </div>

            <div>
                <a href="user_list.php" class="btn btn-sa-light">
                    👥 View All Users
                </a>
            </div>
        </div>
    </div>

    <div class="sa-form-card">
        <div class="sa-card-header">
            <div class="sa-card-title">User Information</div>
            <p class="sa-card-subtitle">Fill user login details carefully. Email and username should be unique.</p>
        </div>

        <div class="sa-card-body">

            <form method="POST" action="" autocomplete="off">
                <?= csrfTokenField() ?>

                <div class="sa-section-title">Basic Details</div>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">
                            Full Name <span class="required-star">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="name" 
                            class="form-control" 
                            placeholder="Enter full name"
                            value="<?= htmlspecialchars($formData['name']) ?>"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Phone
                        </label>
                        <input 
                            type="text" 
                            name="phone" 
                            class="form-control" 
                            placeholder="Enter mobile number"
                            value="<?= htmlspecialchars($formData['phone']) ?>"
                            maxlength="15"
                        >
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Email <span class="required-star">*</span>
                        </label>
                        <input 
                            type="email" 
                            name="email" 
                            class="form-control" 
                            placeholder="example@email.com"
                            value="<?= htmlspecialchars($formData['email']) ?>"
                            required
                        >
                        <div class="form-text">Email must be unique.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            Username <span class="required-star">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="username" 
                            class="form-control" 
                            placeholder="Login username"
                            value="<?= htmlspecialchars($formData['username']) ?>"
                            required
                        >
                        <div class="form-text">Username must be unique.</div>
                    </div>

                </div>

                <div class="sa-section-title mt-4">Login & Permission</div>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">
                            Password <span class="required-star">*</span>
                        </label>

                        <div class="sa-password-wrap">
                            <input 
                                type="password" 
                                name="password" 
                                id="passwordInput"
                                class="form-control" 
                                placeholder="Set password"
                                required
                            >
                            <button type="button" class="sa-toggle-password" onclick="togglePassword()">
                                Show
                            </button>
                        </div>

                        <div class="form-text">Use a strong password for better security.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            Role <span class="required-star">*</span>
                        </label>
                        <select name="role" class="form-select" required>
                            <option value="">Select Role</option>
                            <option value="admin" <?= ($formData['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
                            <option value="purchase" <?= ($formData['role'] === 'purchase') ? 'selected' : '' ?>>Purchase</option>
                            <option value="sales" <?= ($formData['role'] === 'sales') ? 'selected' : '' ?>>Sales</option>
                            <option value="production" <?= ($formData['role'] === 'production') ? 'selected' : '' ?>>Production</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">
                            Status
                        </label>
                        <select name="status" class="form-select">
                            <option value="active" <?= ($formData['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($formData['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">
                            System Role (Page Access) <span class="required-star">*</span>
                        </label>
                        <select name="role_id" class="form-select" required>
                            <option value="">Select System Role</option>
                            <?php foreach ($activeRoles as $activeRole): ?>
                                <option value="<?= (int)$activeRole['role_id'] ?>" <?= ((int)$formData['role_id'] === (int)$activeRole['role_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($activeRole['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Controls which pages and actions this user can access. Manage roles from <a href="role_list.php">Role Management</a>.</div>
                    </div>

                    <div class="col-12">
                        <div class="sa-role-note">
                            <strong>Role Guide</strong>
                            Admin can manage complete system. Purchase user can manage purchase-related pages. Sales user can manage customer/order flow. Production user can manage manufacturing and batch-related work.
                        </div>
                    </div>

                </div>

                <div class="sa-action-bar">
                    <a href="user_list.php" class="btn btn-sa-light">
                        Cancel
                    </a>

                    <button type="submit" name="submit" class="btn btn-primary btn-sa-main">
                        ✅ Add User
                    </button>
                </div>

            </form>

        </div>
    </div>

</div>

<?php if ($toastMessage !== ''): ?>
    <div class="sa-toast-center">
        <div class="sa-custom-toast">
            <div class="sa-toast-head">
                <div>
                    <?php if ($toastType === 'success'): ?>
                        <span class="sa-badge-success">✓</span>
                    <?php else: ?>
                        <span class="sa-badge-danger">!</span>
                    <?php endif; ?>

                    <span class="sa-toast-title">
                        <?= htmlspecialchars($toastTitle) ?>
                    </span>
                </div>

                <button type="button" class="btn-close" onclick="this.closest('.sa-toast-center').remove();"></button>
            </div>

            <div class="sa-toast-body">
                <?= htmlspecialchars($toastMessage) ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function togglePassword() {
    const input = document.getElementById('passwordInput');
    const btn = document.querySelector('.sa-toggle-password');

    if (input.type === 'password') {
        input.type = 'text';
        btn.innerText = 'Hide';
    } else {
        input.type = 'password';
        btn.innerText = 'Show';
    }
}
</script>

<?php include('include/footer.php'); ?>
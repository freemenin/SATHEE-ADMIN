<?php
session_start();
if (isset($_SESSION['user_id']) or isset($_COOKIE['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
include('include/head.php');
session_start();

$toast = "";
// Handle Login or Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $mode = $_POST['mode'] ?? 'login';

    if ($mode === 'register') {
        $name = trim($_POST['name']);
        $role = 'admin';
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $mysqli->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssss", $name, $email, $hashed, $role);
        if ($stmt->execute()) {
            $toast = '<div class="toast toast-sa-success show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">✅ Registered successfully. Please log in.</div></div>';
        } else {
            $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ Registration failed.</div></div>';
        }
    } else {
        $stmt = $mysqli->prepare("SELECT user_id, name, email, password, role, profile_image FROM users WHERE email = ? AND status = 'active'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $user = $res->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['profile_image'] =$user['profile_image'];
                $update = $mysqli->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                $update->bind_param("i", $user['user_id']);
                $update->execute();
                // Cookies for 1 month (2592000 seconds)
                $expiry = time() + 2592000;
                setcookie("user_id", $user['user_id'], $expiry, "/");
                setcookie("user_name", $user['name'], $expiry, "/");
                setcookie("user_email", $user['email'], $expiry, "/");
                setcookie("user_role", $user['role'], $expiry, "/");
                setcookie("profile_image", $user['profile_image'], $expiry, "/");

                $profileImg = $user['profile_image'] ? '<img src="https://app.mysathee.com/' . htmlspecialchars($user['profile_image']) . '" class="rounded-circle me-2" width="200" height="200" />' : '';

                $toast = '<div class="toast toast-sa-success show position-fixed top-50 start-50 translate-middle text-center z-3 p-10" role="alert"><div class="toast-body fs-6">' .
                         $profileImg .
                         '<div><strong>Welcome back, ' . htmlspecialchars($user['name']) . '!</strong></div><div class="text-muted small">Login successful.</div></div></div>';
                echo "<script>setTimeout(() => window.location.href='dashboard.php', 2000);</script>";
            } else {
                $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ Incorrect password.</div></div>';
            }
        } else {
            $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ User not found or inactive.</div></div>';
        }
    }
}
?>

<div class="min-h-100 p-0 p-sm-6 d-flex align-items-stretch">
    <div class="card w-25x flex-grow-1 flex-sm-grow-0 m-sm-auto">
        <div class="card-body p-sm-5 m-sm-3 flex-grow-0">
            <h1 class="mb-0 fs-3">User Access</h1>
            <div class="fs-exact-14 text-muted mt-2 pt-1 mb-4">Login or Register below.</div>
            <form method="POST">
                <input type="hidden" name="mode" id="mode" value="login">

                <div id="registerFields" style="display: none;">
                    <div class="mb-4">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control form-control-lg" name="name" />
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control form-control-lg" name="email" required />
                </div>
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control form-control-lg" name="password" required />
                </div>
                <div class="mb-4 row py-2 flex-wrap">
                    <div class="col-auto me-auto">
                        <label class="form-check mb-0">
                            <input type="checkbox" class="form-check-input" />
                            <span class="form-check-label">Remember me</span>
                        </label>
                    </div>
                    <div class="col-auto d-flex align-items-center"><a href="#">Forgot password?</a></div>
                </div>
                <div><button type="submit" class="btn btn-primary btn-lg w-100" id="formButton">Sign In</button></div>
            </form>
            <div class="text-center mt-3">
                <small><a href="#" onclick="switchMode(event)">Don't have an account? Register here</a></small>
            </div>
        </div>
    </div>
</div>

<?= $toast ?>

<script>
function switchMode(e) {
    e.preventDefault();
    const mode = document.getElementById('mode');
    const registerFields = document.getElementById('registerFields');
    const button = document.getElementById('formButton');

    if (mode.value === 'login') {
        mode.value = 'register';
        registerFields.style.display = 'block';
        button.innerText = 'Register';
        e.target.innerText = 'Already have an account? Login here';
    } else {
        mode.value = 'login';
        registerFields.style.display = 'none';
        button.innerText = 'Sign In';
        e.target.innerText = "Don't have an account? Register here";
    }
}
</script>

<?php include('include/footer.php'); ?>
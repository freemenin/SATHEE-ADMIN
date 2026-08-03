<?php include('include/require_login.php'); ?>
<?php
include('include/db.php');
session_start();
$toast = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $mysqli->prepare("SELECT id, name, email, password FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            header("Location: dashboard.php");
            exit;
        } else {
            $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ Invalid password.</div></div>';
        }
    } else {
        $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ User not found.</div></div>';
    }
}
?>

<?php include('include/head.php'); ?>
<div class="min-h-100 p-0 p-sm-6 d-flex align-items-stretch">
    <div class="card w-25x flex-grow-1 flex-sm-grow-0 m-sm-auto">
        <div class="card-body p-sm-5 m-sm-3 flex-grow-0">
            <h1 class="mb-0 fs-3">Sign In</h1>
            <div class="fs-exact-14 text-muted mt-2 pt-1 mb-5 pb-2">Log in to your account to continue.</div>
            <form method="POST">
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
                <div><button type="submit" class="btn btn-primary btn-lg w-100">Sign In</button></div>
            </form>
        </div>
    </div>
</div>
<?= $toast ?>
<?php include('include/footer.php'); ?>

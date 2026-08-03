<?php include('include/head.php');?>
        <div class="min-h-100 p-0 p-sm-6 d-flex align-items-stretch">
            <div class="card w-25x flex-grow-1 flex-sm-grow-0 m-sm-auto">
                <div class="card-body p-sm-5 m-sm-3 flex-grow-0">
                    <h1 class="mb-0 fs-3">Forgot password?</h1>
                    <div class="fs-exact-14 text-muted mt-2 pt-1 mb-5 pb-2">
                        Enter the email address associated with your account and we will send a link to reset your password.
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control form-control-lg" />
                    </div>
                    <div><button type="submit" class="btn btn-primary btn-lg w-100">Reset Password</button></div>
                    <div class="form-group mb-0 mt-4 pt-2 text-center text-muted">
                        Remember your password?
                        <a href="auth-sign-in.php">Sign in</a>
                    </div>
                </div>
            </div>
<?php include('include/footer.php');?>
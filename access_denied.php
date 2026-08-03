<?php
require_once 'include/require_login.php';
require_once 'include/permission_helper.php';

include('include/header.php');
?>

<div class="sa-page__body">
    <div class="container-fluid" style="padding: 40px 20px;">
        <div class="row justify-content-center align-items-center" style="min-height: 60vh;">
            <div class="col-lg-6 col-md-8">
                <div class="card border-0 shadow-lg">
                    <div class="card-body text-center p-5">
                        
                        <!-- 403 Icon -->
                        <div style="font-size: 120px; color: #dc3545; margin-bottom: 20px;">
                            🔒
                        </div>

                        <h1 class="fw-bold mb-3" style="font-size: 48px; color: #dc3545;">
                            403
                        </h1>

                        <h2 class="fw-bold mb-3" style="color: #333;">
                            Access Denied
                        </h2>

                        <p class="text-muted mb-4" style="font-size: 18px;">
                            You do not have permission to access this section.
                        </p>

                        <p class="text-muted mb-5" style="font-size: 14px;">
                            If you believe this is an error, please contact your administrator.
                        </p>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-center gap-3 flex-wrap">

                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                onclick="history.back()"
                                style="padding: 10px 30px; font-weight: 500;"
                            >
                                <i class="bi bi-arrow-left"></i> Go Back
                            </button>

                            <a
                                href="dashboard.php"
                                class="btn btn-primary"
                                style="padding: 10px 30px; font-weight: 500;"
                            >
                                <i class="bi bi-speedometer2"></i> Go to Dashboard
                            </a>

                        </div>

                        <!-- Help Section -->
                        <div class="mt-5 pt-5 border-top">
                            <p class="text-muted" style="font-size: 12px;">
                                <strong>Need help?</strong> Contact your system administrator if you need access to this section.
                            </p>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('include/footer.php'); ?>

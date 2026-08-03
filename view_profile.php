<?php
require_once 'include/require_login.php';
include('include/header.php');

$user_id = (int)$_SESSION['user_id'];
$stmt = $mysqli->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
?>

<div class="container mt-5">
    <div class="card shadow d-flex flex-row" style="border-radius: 12px; overflow: hidden;">
        <!-- Left Section -->
        <div class="bg-light text-center p-4" style="width: 30%; min-width: 220px;">
            <img src="<?= $user['profile_image'] ? 'https://app.mysathee.com/' . htmlspecialchars($user['profile_image']) : 'images/no-image.png' ?>" class="rounded-circle mb-3" width="100" height="100" alt="Profile">
            <h5 class="fw-bold mb-1"><?= htmlspecialchars($user['name']) ?></h5>
            <div class="text-muted mb-3">@<?= htmlspecialchars($user['username'] ?? '') ?></div>
            <a href="user_profile.php" class="btn btn-outline-primary btn-sm">✏️ Edit Profile</a>
        </div>

        <!-- Right Section -->
        <div class="flex-grow-1 p-4 bg-dark text-white">
            <h5 class="fw-semibold">Information</h5>
            <hr class="border-light">
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>Email:</strong><br>
                    <span><?= htmlspecialchars($user['email']) ?></span>
                </div>
                <div class="col-md-6">
                    <strong>Phone:</strong><br>
                    <span><?= htmlspecialchars($user['phone'] ?? '—') ?></span>
                </div>
            </div>

            <h5 class="fw-semibold mt-4">System Info</h5>
            <hr class="border-light">
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>Role:</strong><br>
                    <span><?= htmlspecialchars(ucfirst($user['role'] ?? '')) ?></span>
                </div>
                <div class="col-md-6">
                    <strong>Status:</strong><br>
                    <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($user['status'])) ?></span>
                </div>
            </div>

            <div class="mt-4">
                <strong>Last Login:</strong>
                <span><?= $user['last_login'] ? date('d M Y, h:i A', strtotime($user['last_login'])) : 'Never' ?></span>
            </div>
        </div>
    </div>
</div>

<?php include('include/footer.php'); ?>

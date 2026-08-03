<?php
require_once 'include/require_login.php';
require_once 'include/csrf_helper.php';
include('include/header.php');

$user_id = (int)$_SESSION['user_id'];
$toast = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ Invalid request token. Please try again.</div></div>';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ Please enter a valid name and email.</div></div>';
        } else {
            // Handle profile image upload - only allow known image types, never trust the
            // original filename or extension supplied by the browser.
            $profile_image = null;
            $uploadError = null;

            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $allowedMime = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                ];

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detectedMime = finfo_file($finfo, $_FILES['profile_image']['tmp_name']);
                finfo_close($finfo);

                if (!isset($allowedMime[$detectedMime])) {
                    $uploadError = 'Only JPG, PNG, GIF, or WEBP images are allowed.';
                } else {
                    $target_dir = 'uploads/profile/';
                    $target_file = $target_dir . bin2hex(random_bytes(16)) . '.' . $allowedMime[$detectedMime];

                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                        $profile_image = $target_file;
                    } else {
                        $uploadError = 'Failed to save the uploaded image.';
                    }
                }
            }

            if ($uploadError) {
                $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ ' . htmlspecialchars($uploadError) . '</div></div>';
            } else {
                $stmt = $mysqli->prepare("UPDATE users SET name=?, email=?, phone=?, username=?" . ($profile_image ? ", profile_image=?" : "") . " WHERE user_id=?");

                if ($profile_image) {
                    $stmt->bind_param("sssssi", $name, $email, $phone, $username, $profile_image, $user_id);
                } else {
                    $stmt->bind_param("ssssi", $name, $email, $phone, $username, $user_id);
                }

                if ($stmt->execute()) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    if ($profile_image) {
                        $_SESSION['profile_image'] = $profile_image;
                    }
                    $toast = '<div class="toast toast-sa-success show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">✅ Profile updated successfully.</div></div>';
                } else {
                    $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ Failed to update profile.</div></div>';
                }
                $stmt->close();
            }
        }
    }
}

// Fetch current user data
$stmt = $mysqli->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
?>

<div class="container p-5 m-5 card ">
    <h4 class="mb-4 card-header">👤 Update Profile</h4>
    <form method="POST" enctype="multipart/form-data">
        <?= csrfTokenField() ?>
        <div class="row ">
            <div class="col-md-6">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required />
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required />
            </div>
            <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" />
            </div>
            <div class="col-md-6">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username'] ?? '') ?>" />
            </div>
            <div class="col-md-4">
                <label class="form-label">Profile Image</label>
                <input type="file" name="profile_image" class="form-control" accept="image/png, image/jpeg, image/gif, image/webp" />
                <?php if ($user['profile_image']): ?>
                    <img src="https://app.mysathee.com/<?= htmlspecialchars($user['profile_image']) ?>" alt="Profile" class="mt-2 rounded" width="80">
                <?php endif; ?>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        </div>
    </form>
</div>

<?= $toast ?>

<?php include('include/footer.php'); ?>

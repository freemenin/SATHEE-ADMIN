<?php
session_start();
session_destroy();

// Clear cookies
setcookie("user_id", "", time() - 3600, "/");
setcookie("user_name", "", time() - 3600, "/");
setcookie("user_email", "", time() - 3600, "/");
setcookie("user_role", "", time() - 3600, "/");
setcookie("profile_image", "", time() - 3600, "/");

header("Location: login.php");
exit;
?>
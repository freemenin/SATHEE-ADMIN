<?php
require_once 'include/require_permission.php';
requirePermission('BATCHES', 'delete');
include('include/require_login.php');
include('include/db.php');

// ---- Configurable PIN ----
$REQUIRED_PIN = '1234';

// Only accept POST for deletion
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "Invalid request method.";
    exit;
}

$batch_id = isset($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
$pin      = isset($_POST['pin']) ? trim($_POST['pin']) : '';

if ($batch_id <= 0) {
    echo "Invalid batch id.";
    exit;
}

// Verify PIN
if ($pin !== $REQUIRED_PIN) {
    // Wrong PIN: show error and a back link
    echo "<!DOCTYPE html>
    <html>
    <head>
      <meta charset='UTF-8'><title>Delete Error</title>
      <style>body{font-family:system-ui,Arial;margin:2rem} .alert{padding:12px;border:1px solid #f5c2c7;background:#f8d7da;color:#842029;border-radius:6px}</style>
    </head>
    <body>
      <div class='alert'>You are not valid person for delete this batch.</div>
      <p><a href='batch_list.php'>Back to Batch List</a></p>
    </body>
    </html>";
    exit;
}

$ok = false;
$errors = [];

$mysqli->begin_transaction();
try {
    // Delete children first
    $del1 = $mysqli->prepare("DELETE FROM batch_raw_materials WHERE batch_id = ?");
    $del1->bind_param("i", $batch_id);
    if (!$del1->execute()) throw new Exception($del1->error);
    $del1->close();

    // Delete batch
    $del2 = $mysqli->prepare("DELETE FROM batches WHERE batch_id = ?");
    $del2->bind_param("i", $batch_id);
    if (!$del2->execute()) throw new Exception($del2->error);
    $del2->close();

    $mysqli->commit();
    $ok = true;
} catch (Exception $e) {
    $mysqli->rollback();
    $errors[] = $e->getMessage();
}

// HTML Redirect
if ($ok) {
    echo "<!DOCTYPE html>
    <html>
    <head>
      <meta charset='UTF-8'>
      <title>Redirecting...</title>
      <meta http-equiv='refresh' content='0;url=batch_list.php?deleted=1'>
    </head>
    <body>
      <p>Batch deleted. Redirecting to <a href='batch_list.php?deleted=1'>Batch List</a>...</p>
      <script>window.location.href = 'batch_list.php?deleted=1';</script>
    </body>
    </html>";
} else {
    echo "<div class='alert alert-danger'>Delete failed: ".htmlspecialchars(implode(', ', $errors))."</div>";
}

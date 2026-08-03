<?php include('include/require_login.php'); ?>
<?php include('include/header.php'); ?>
<?php
$purchase_id = (int)($_GET['id'] ?? $_POST['purchase_id'] ?? 0);

if ($purchase_id <= 0) {
    $_SESSION['error_message'] = "Invalid purchase ID.";
    header("Location: purchase_invoice.php?id" . $purchase_id);
    exit;
}

/* -----------------------------
   Check purchase ownership
----------------------------- */
$stmt = $mysqli->prepare("
    SELECT purchase_id, distributor_id, purchase_no, status
    FROM distributor_purchase_master
    WHERE purchase_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$purchase) {
    $_SESSION['error_message'] = "Purchase request not found.";
    header("Location: purchase_invoice.php?id=" . $purchase_id);
    exit;
}

/* -----------------------------
   Optional status restriction
   Uncomment if needed
----------------------------- */
/*
if (!in_array($purchase['status'], ['ready', 'dispatched'])) {
    $_SESSION['error_message'] = "Thermal print allowed only for Ready or Dispatched requests.";
    header("Location: distributor_purchase_view.php?id=" . $purchase_id);
    exit;
}
*/

/* -----------------------------
   Check table exists / insert job
----------------------------- */

/* Duplicate pending check */
$dup = $mysqli->prepare("
    SELECT job_id
    FROM thermal_print_jobs
    WHERE purchase_id = ?
      AND distributor_id = ?
      AND status IN ('pending', 'printing')
    ORDER BY job_id DESC
    LIMIT 1
");
$dup->bind_param("ii", $purchase_id, $distId);
$dup->execute();
$existing = $dup->get_result()->fetch_assoc();
$dup->close();

if ($existing) {
    $_SESSION['success_message'] = "Thermal print request already pending.";
    header("Location: distributor_purchase_view.php?id=" . $purchase_id);
    exit;
}
$distName=$user_name;
/* Insert new print job */
$requested_by = ($distName !== '') ? $distName : ('Distributor #' . $distId);

$insert = $mysqli->prepare("
    INSERT INTO thermal_print_jobs
    (purchase_id, distributor_id, requested_by, source_panel, status, created_at)
    VALUES (?, ?, ?, 'admin', 'pending', NOW())
");
$insert->bind_param("iis", $purchase_id, $distId, $requested_by);

if ($insert->execute()) {
    $_SESSION['success_message'] = "Thermal print request sent successfully.";
} else {
    $_SESSION['error_message'] = "Failed to create thermal print request: " . $mysqli->error;
}
$insert->close();

/* Redirect back */
echo '<script>window.location.href="purchase_invoice.php?id=' . $purchase_id . '";</script>';
exit;
?>
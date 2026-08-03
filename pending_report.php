<?php
include('include/require_login.php');
include('include/header.php');
require_once __DIR__ . '/include/db.php';

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function columnExists($mysqli, $tableName, $columnName) {
    $tableName = $mysqli->real_escape_string($tableName);
    $columnName = $mysqli->real_escape_string($columnName);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
    return $res && $res->num_rows > 0;
}

function tableExists($mysqli, $tableName) {
    $tableName = $mysqli->real_escape_string($tableName);
    $res = $mysqli->query("SHOW TABLES LIKE '{$tableName}'");
    return $res && $res->num_rows > 0;
}

function getPriorityLabel($days) {
    if ($days <= 10) {
        return ['Normal', 'priority-normal'];
    } elseif ($days <= 30) {
        return ['Follow-up', 'priority-followup'];
    } elseif ($days <= 60) {
        return ['High', 'priority-high'];
    }

    return ['Urgent', 'priority-urgent'];
}

function getActionText($row) {
    $orderStatus = strtolower((string)$row['order_status']);
    $deliveryStatus = strtolower((string)$row['delivery_status']);
    $distributorName = strtolower((string)$row['distributor_name']);

    if ($orderStatus === 'change distributor') {
        return 'Change distributor now';
    }

    if ($deliveryStatus === 'ready to delivery' || $orderStatus === 'ready to delivery') {
        return 'Ready but not delivered';
    }

    if ($distributorName === 'not assigned') {
        return 'Assign distributor';
    }

    if ($deliveryStatus === 'assigned' || $orderStatus === 'assigned') {
        return 'Follow-up distributor';
    }

    if ($orderStatus === 'open' || $orderStatus === 'new' || $deliveryStatus === 'new') {
        return 'Process order';
    }

    return 'Check order';
}

function cleanWhatsappMobile($mobile) {
    $mobile = preg_replace('/\D/', '', (string)$mobile);

    if (strlen($mobile) === 10) {
        return '91' . $mobile;
    }

    if (strlen($mobile) === 12 && substr($mobile, 0, 2) === '91') {
        return $mobile;
    }

    return $mobile;
}

function buildUrl($task, $age, $search, $distributorFilter, $distFilter = '') {
    $q = [
        'task' => $task,
        'age' => $age,
    ];

    if ($search !== '') {
        $q['search'] = $search;
    }

    if ($distFilter === 'not_assigned') {
        $q['distributor_id'] = 0;
        $q['dist_filter'] = 'not_assigned';
    } elseif ((int)$distributorFilter > 0) {
        $q['distributor_id'] = (int)$distributorFilter;
    }

    return '?' . http_build_query($q);
}

/*
|--------------------------------------------------------------------------
| Current Logged-in User ID
|--------------------------------------------------------------------------
| Change this if your login session key is different.
|--------------------------------------------------------------------------
*/

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

$hasSubDistributorId = columnExists($mysqli, 'orders', 'sub_distributor_id');
$hasSubDistributorTable = tableExists($mysqli, 'sub_distributors');
$hasUsersTable = tableExists($mysqli, 'users');

$toastType = '';
$toastMessage = '';

/*
|--------------------------------------------------------------------------
| Save Follow-up
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_followup') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $followupType = $_POST['followup_type'] ?? 'remark';
    $faultType = $_POST['fault_type'] ?? null;
    $remark = trim($_POST['remark'] ?? '');
    $nextFollowupDate = trim($_POST['next_followup_date'] ?? '');
    $duration = (int)($_POST['followup_duration_minutes'] ?? 0);

    $allowedFollowupTypes = [
        'customer_call',
        'distributor_call',
        'whatsapp',
        'status_check',
        'change_distributor',
        'remark'
    ];

    $allowedFaultTypes = [
        'customer_not_available',
        'distributor_delay',
        'stock_issue',
        'wrong_distributor',
        'payment_issue',
        'address_issue',
        'area_not_serviceable',
        'other'
    ];

    if (!in_array($followupType, $allowedFollowupTypes, true)) {
        $followupType = 'remark';
    }

    if ($faultType === '') {
        $faultType = null;
    }

    if ($faultType !== null && !in_array($faultType, $allowedFaultTypes, true)) {
        $faultType = null;
    }

    if ($nextFollowupDate === '') {
        $nextFollowupDate = null;
    }

    if ($duration < 0) {
        $duration = 0;
    }

    if ($orderId <= 0) {
        $toastType = 'danger';
        $toastMessage = 'Invalid order selected.';
    } elseif ($remark === '') {
        $toastType = 'danger';
        $toastMessage = 'Please enter follow-up remark.';
    } else {
        $stmtIns = $mysqli->prepare("
            INSERT INTO order_followups
            (
                order_id,
                followup_type,
                fault_type,
                remark,
                next_followup_date,
                followup_duration_minutes,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmtIns->bind_param(
            "issssii",
            $orderId,
            $followupType,
            $faultType,
            $remark,
            $nextFollowupDate,
            $duration,
            $currentUserId
        );

        if ($stmtIns->execute()) {
            $toastType = 'success';
            $toastMessage = 'Follow-up added successfully.';
        } else {
            $toastType = 'danger';
            $toastMessage = 'Follow-up not saved. Error: ' . $stmtIns->error;
        }

        $stmtIns->close();
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$task = $_GET['task'] ?? 'all_pending';
$age = $_GET['age'] ?? '5-10';
$search = trim($_GET['search'] ?? '');
$distributorFilter = (int)($_GET['distributor_id'] ?? 0);
$distFilter = $_GET['dist_filter'] ?? '';

$baseWhere = "
    o.order_date IS NOT NULL
    AND o.order_date != '0000-00-00'
    AND DATEDIFF(CURDATE(), o.order_date) >= 5
";

$pendingWhere = "
    (
        o.delivery_status IS NULL 
        OR o.delivery_status NOT IN ('Delivered', 'Cancelled')
    )
    AND (
        o.order_status IS NULL 
        OR o.order_status NOT IN ('Delivered', 'Cancelled')
    )
";

/*
|--------------------------------------------------------------------------
| Task Tabs
|--------------------------------------------------------------------------
*/

$taskTabs = [
    'all_pending' => [
        'label' => 'All Pending',
        'short' => 'All not delivered',
        'class' => 'task-blue',
        'where' => $pendingWhere
    ],

    'change_distributor' => [
        'label' => 'Change Distributor',
        'short' => 'Most important',
        'class' => 'task-red',
        'where' => "
            o.order_status = 'change distributor'
            AND (
                o.delivery_status IS NULL 
                OR o.delivery_status NOT IN ('Delivered', 'Cancelled')
            )
        "
    ],

    'ready_not_delivered' => [
        'label' => 'Ready Not Delivered',
        'short' => 'Distributor fault check',
        'class' => 'task-orange',
        'where' => "
            (
                o.delivery_status = 'Ready to Delivery'
                OR o.order_status = 'Ready to Delivery'
            )
            AND (
                o.delivery_status IS NULL 
                OR o.delivery_status != 'Delivered'
            )
            AND (
                o.order_status IS NULL 
                OR o.order_status != 'Delivered'
            )
        "
    ],

    'assigned_pending' => [
        'label' => 'Assigned Pending',
        'short' => 'Distributor follow-up',
        'class' => 'task-yellow',
        'where' => "
            (
                o.delivery_status = 'Assigned'
                OR o.order_status = 'Assigned'
            )
            AND {$pendingWhere}
        "
    ],

    'new_open' => [
        'label' => 'New / Open',
        'short' => 'Not processed',
        'class' => 'task-purple',
        'where' => "
            (
                o.delivery_status = 'New'
                OR o.order_status IN ('Open', 'New')
            )
            AND {$pendingWhere}
        "
    ],

    'no_distributor' => [
        'label' => 'No Distributor',
        'short' => 'Assign required',
        'class' => 'task-dark',
        'where' => "
            (
                o.distributor_id IS NULL 
                OR o.distributor_id = 0
            )
            AND {$pendingWhere}
        "
    ],

    'today_followup' => [
        'label' => 'Today Follow-up',
        'short' => 'Due follow-up',
        'class' => 'task-green',
        'where' => "
            {$pendingWhere}
            AND EXISTS (
                SELECT 1
                FROM order_followups f_due
                WHERE f_due.order_id = o.order_id
                AND f_due.next_followup_date IS NOT NULL
                AND f_due.next_followup_date <= CURDATE()
            )
        "
    ],

    'no_followup' => [
        'label' => 'No Follow-up',
        'short' => 'Not touched yet',
        'class' => 'task-pink',
        'where' => "
            {$pendingWhere}
            AND NOT EXISTS (
                SELECT 1
                FROM order_followups f_none
                WHERE f_none.order_id = o.order_id
            )
        "
    ],
];

if (!isset($taskTabs[$task])) {
    $task = 'all_pending';
}

/*
|--------------------------------------------------------------------------
| Age Tabs
|--------------------------------------------------------------------------
*/

$ageTabs = [
    '5-10' => [
        'label' => '5 - 10 Days',
        'short' => 'Normal',
        'min' => 5,
        'max' => 10,
    ],
    '11-30' => [
        'label' => '11 - 30 Days',
        'short' => 'Follow-up',
        'min' => 11,
        'max' => 30,
    ],
    '31-40' => [
        'label' => '31 - 40 Days',
        'short' => 'High delay',
        'min' => 31,
        'max' => 40,
    ],
    '41-60' => [
        'label' => '41 - 60 Days',
        'short' => 'Critical',
        'min' => 41,
        'max' => 60,
    ],
    '60+' => [
        'label' => '60+ Days',
        'short' => 'Urgent',
        'min' => 61,
        'max' => null,
    ],
];

if (!isset($ageTabs[$age])) {
    $age = '5-10';
}

$selectedTaskWhere = $taskTabs[$task]['where'];
$selectedAge = $ageTabs[$age];

if ($selectedAge['max'] === null) {
    $ageWhere = "DATEDIFF(CURDATE(), o.order_date) >= {$selectedAge['min']}";
} else {
    $ageWhere = "DATEDIFF(CURDATE(), o.order_date) BETWEEN {$selectedAge['min']} AND {$selectedAge['max']}";
}

/*
|--------------------------------------------------------------------------
| Task Counts
|--------------------------------------------------------------------------
*/

$taskCounts = [];

foreach ($taskTabs as $key => $tab) {
    $sqlCount = "
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(o.grand_total), 0) AS amount
        FROM orders o
        WHERE {$baseWhere}
        AND {$tab['where']}
    ";

    $resCount = $mysqli->query($sqlCount);
    $rowCount = $resCount ? $resCount->fetch_assoc() : ['total' => 0, 'amount' => 0];

    $taskCounts[$key] = [
        'total' => (int)$rowCount['total'],
        'amount' => (float)$rowCount['amount'],
    ];
}

/*
|--------------------------------------------------------------------------
| Age Counts Under Selected Task
|--------------------------------------------------------------------------
*/

$ageCounts = [];

foreach ($ageTabs as $key => $a) {
    if ($a['max'] === null) {
        $thisAgeWhere = "DATEDIFF(CURDATE(), o.order_date) >= {$a['min']}";
    } else {
        $thisAgeWhere = "DATEDIFF(CURDATE(), o.order_date) BETWEEN {$a['min']} AND {$a['max']}";
    }

    $sqlAge = "
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(o.grand_total), 0) AS amount
        FROM orders o
        WHERE {$baseWhere}
        AND {$selectedTaskWhere}
        AND {$thisAgeWhere}
    ";

    $resAge = $mysqli->query($sqlAge);
    $rowAge = $resAge ? $resAge->fetch_assoc() : ['total' => 0, 'amount' => 0];

    $ageCounts[$key] = [
        'total' => (int)$rowAge['total'],
        'amount' => (float)$rowAge['amount'],
    ];
}

/*
|--------------------------------------------------------------------------
| Distributor-wise Pending Count
|--------------------------------------------------------------------------
*/

$distributorWiseSql = "
    SELECT 
        COALESCE(o.distributor_id, 0) AS distributor_id,
        COALESCE(d.distributor_name, 'Not Assigned') AS distributor_name,
        COALESCE(d.mobile_number, '') AS distributor_mobile,
        COUNT(*) AS total_pending,
        COALESCE(SUM(o.grand_total), 0) AS total_amount,
        SUM(CASE WHEN DATEDIFF(CURDATE(), o.order_date) >= 31 THEN 1 ELSE 0 END) AS high_pending
    FROM orders o
    LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
    WHERE {$baseWhere}
    AND {$pendingWhere}
    GROUP BY COALESCE(o.distributor_id, 0), d.distributor_name, d.mobile_number
    ORDER BY total_pending DESC
    LIMIT 20
";

$distributorWise = $mysqli->query($distributorWiseSql);

/*
|--------------------------------------------------------------------------
| Order Status Count
|--------------------------------------------------------------------------
*/

$orderStatusCountSql = "
    SELECT 
        COALESCE(o.order_status, 'Blank') AS order_status,
        COUNT(*) AS total
    FROM orders o
    WHERE {$baseWhere}
    AND {$pendingWhere}
    GROUP BY COALESCE(o.order_status, 'Blank')
    ORDER BY total DESC
";

$orderStatusCount = $mysqli->query($orderStatusCountSql);

/*
|--------------------------------------------------------------------------
| Main List Query With Last Follow-up
|--------------------------------------------------------------------------
*/

$params = [];
$types = '';
$whereExtra = '';

if ($distributorFilter > 0) {
    $whereExtra .= " AND o.distributor_id = ? ";
    $params[] = $distributorFilter;
    $types .= 'i';
} elseif ($distFilter === 'not_assigned') {
    $whereExtra .= " AND (o.distributor_id IS NULL OR o.distributor_id = 0) ";
}

if ($search !== '') {
    $whereExtra .= "
        AND (
            o.invoice_number LIKE ?
            OR c.full_name LIKE ?
            OR c.mobile_number LIKE ?
            OR d.distributor_name LIKE ?
            OR o.order_status LIKE ?
            OR o.delivery_status LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssssss';
}

$subJoin = '';
$subSelect = "'' AS sub_distributor_name";

if ($hasSubDistributorId && $hasSubDistributorTable) {
    $subJoin = "
        LEFT JOIN sub_distributors sd 
            ON sd.sub_distributor_id = o.sub_distributor_id
    ";

    $subSelect = "COALESCE(sd.sub_distributor_name, '') AS sub_distributor_name";
}

$userJoin = '';
$userSelect = "lf.created_by AS last_followup_user_id, CONCAT('User ID ', lf.created_by) AS last_followup_by";

if ($hasUsersTable) {
    $userJoin = "LEFT JOIN users fu ON fu.user_id = lf.created_by";
    $userSelect = "
        lf.created_by AS last_followup_user_id,
        COALESCE(fu.username, CONCAT('User ID ', lf.created_by)) AS last_followup_by
    ";
}

$sql = "
    SELECT 
        o.order_id,
        o.invoice_number,
        o.order_date,
        o.distributor_assigned_at,
        o.delivery_status,
        o.order_status,
        o.payment_mode,
        o.grand_total,
        DATEDIFF(CURDATE(), o.order_date) AS pending_days,

        c.full_name,
        c.mobile_number,
        c.city,
        c.pincode,

        COALESCE(d.distributor_name, 'Not Assigned') AS distributor_name,
        COALESCE(d.mobile_number, '') AS distributor_mobile,

        {$subSelect},

        lf.followup_type AS last_followup_type,
        lf.fault_type AS last_fault_type,
        lf.remark AS last_followup_remark,
        lf.next_followup_date AS next_followup_date,
        lf.followup_duration_minutes AS last_followup_duration,
        lf.created_at AS last_followup_at,
        {$userSelect}

    FROM orders o

    LEFT JOIN customers c ON c.customer_id = o.customer_id
    LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
    {$subJoin}

    LEFT JOIN (
        SELECT 
            of1.order_id,
            of1.followup_type,
            of1.fault_type,
            of1.remark,
            of1.next_followup_date,
            of1.followup_duration_minutes,
            of1.created_by,
            of1.created_at
        FROM order_followups of1
        INNER JOIN (
            SELECT order_id, MAX(followup_id) AS max_followup_id
            FROM order_followups
            GROUP BY order_id
        ) latest 
            ON latest.max_followup_id = of1.followup_id
    ) lf ON lf.order_id = o.order_id

    {$userJoin}

    WHERE {$baseWhere}
    AND {$selectedTaskWhere}
    AND {$ageWhere}
    {$whereExtra}

    ORDER BY 
        CASE 
            WHEN o.order_status = 'change distributor' THEN 1
            WHEN o.delivery_status = 'Ready to Delivery' OR o.order_status = 'Ready to Delivery' THEN 2
            WHEN o.distributor_id IS NULL OR o.distributor_id = 0 THEN 3
            WHEN lf.next_followup_date IS NOT NULL AND lf.next_followup_date <= CURDATE() THEN 4
            WHEN lf.created_at IS NULL THEN 5
            ELSE 6
        END ASC,
        pending_days DESC,
        o.order_date ASC,
        o.order_id DESC
";

$stmt = $mysqli->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$orders = $stmt->get_result();

$distributorList = $mysqli->query("
    SELECT distributor_id, distributor_name 
    FROM distributors 
    ORDER BY distributor_name ASC
");

$totalListOrders = $orders ? $orders->num_rows : 0;

$followupTypes = [
    'customer_call' => 'Customer Call',
    'distributor_call' => 'Distributor Call',
    'whatsapp' => 'WhatsApp',
    'status_check' => 'Status Check',
    'change_distributor' => 'Change Distributor',
    'remark' => 'Remark'
];

$faultTypes = [
    '' => 'No Fault / Not Selected',
    'customer_not_available' => 'Customer Not Available',
    'distributor_delay' => 'Distributor Delay',
    'stock_issue' => 'Stock Issue',
    'wrong_distributor' => 'Wrong Distributor',
    'payment_issue' => 'Payment Issue',
    'address_issue' => 'Address Issue',
    'area_not_serviceable' => 'Area Not Serviceable',
    'other' => 'Other'
];
?>

<style>
body {
    background: #f4f6f9;
}

.page-title {
    font-size: 26px;
    font-weight: 900;
    color: #111;
}

.page-subtitle {
    color: #666;
    font-size: 14px;
}

.staff-help {
    background: #fff8e1;
    border-left: 6px solid #ffc107;
    border-radius: 14px;
    padding: 14px 16px;
    font-size: 15px;
    color: #5d4600;
}

.task-card {
    display: block;
    background: #fff;
    color: #111;
    text-decoration: none;
    border-radius: 18px;
    padding: 15px;
    min-height: 138px;
    border: 3px solid transparent;
    box-shadow: 0 4px 14px rgba(0,0,0,0.06);
    transition: 0.2s;
}

.task-card:hover {
    color: #111;
    text-decoration: none;
    transform: translateY(-2px);
}

.task-card.active {
    border-color: #0d6efd;
    background: #eaf3ff;
}

.task-label {
    font-weight: 900;
    font-size: 15px;
    line-height: 1.2;
}

.task-short {
    color: #666;
    font-size: 12px;
    margin-top: 4px;
}

.task-count {
    font-weight: 900;
    font-size: 34px;
    line-height: 1;
    margin-top: 12px;
}

.task-amount {
    color: #555;
    font-size: 12px;
    margin-top: 8px;
}

.task-blue { border-top: 6px solid #0d6efd; }
.task-red { border-top: 6px solid #dc3545; }
.task-orange { border-top: 6px solid #fd7e14; }
.task-yellow { border-top: 6px solid #ffc107; }
.task-purple { border-top: 6px solid #6f42c1; }
.task-dark { border-top: 6px solid #212529; }
.task-green { border-top: 6px solid #198754; }
.task-pink { border-top: 6px solid #d63384; }

.age-card {
    display: block;
    background: #fff;
    color: #111;
    text-decoration: none;
    border-radius: 14px;
    padding: 14px;
    border: 2px solid #e5e7eb;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.age-card:hover {
    color: #111;
    text-decoration: none;
    border-color: #0d6efd;
}

.age-card.active {
    background: #111;
    color: #fff;
    border-color: #111;
}

.age-title {
    font-weight: 900;
    font-size: 14px;
}

.age-count {
    font-size: 26px;
    font-weight: 900;
    line-height: 1;
    margin-top: 8px;
}

.age-small {
    font-size: 12px;
    opacity: 0.75;
}

.filter-box {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 3px 12px rgba(0,0,0,0.05);
    padding: 16px;
}

.filter-box label {
    font-weight: 800;
}

.filter-box .form-control,
.filter-box .form-select {
    height: 48px;
    font-size: 16px;
    border-radius: 12px;
}

.btn-staff {
    height: 48px;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 900;
}

.summary-chip {
    background: #fff;
    display: inline-block;
    padding: 9px 13px;
    margin: 0 6px 8px 0;
    border-radius: 50px;
    border: 1px solid #e5e7eb;
    font-size: 13px;
    font-weight: 800;
}

.dist-box {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    padding: 12px;
    min-height: 112px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.dist-clickable {
    display: block;
    text-decoration: none;
    color: #111;
    transition: 0.2s;
    border: 2px solid #e5e7eb;
}

.dist-clickable:hover {
    color: #111;
    text-decoration: none;
    transform: translateY(-2px);
    border-color: #0d6efd;
    background: #eaf3ff;
}

.dist-clickable.active {
    border: 3px solid #0d6efd;
    background: #eaf3ff;
}

.dist-name {
    font-size: 14px;
    font-weight: 900;
}

.dist-count {
    font-size: 25px;
    font-weight: 900;
    color: #111;
}

.dist-small {
    font-size: 12px;
    color: #666;
}

.priority-normal,
.priority-followup,
.priority-high,
.priority-urgent {
    display: inline-block;
    padding: 8px 12px;
    border-radius: 50px;
    font-size: 12px;
    font-weight: 900;
}

.priority-normal {
    background: #d1e7dd;
    color: #0f5132;
}

.priority-followup {
    background: #fff3cd;
    color: #856404;
}

.priority-high {
    background: #f8d7da;
    color: #842029;
}

.priority-urgent {
    background: #212529;
    color: #fff;
}

.action-badge {
    display: inline-block;
    padding: 8px 12px;
    border-radius: 10px;
    background: #eaf3ff;
    color: #084298;
    font-weight: 900;
    font-size: 12px;
}

.action-danger {
    background: #f8d7da;
    color: #842029;
}

.main-value {
    font-weight: 900;
    color: #111;
}

.small-muted {
    font-size: 12px;
    color: #666;
}

.simple-table th {
    background: #f1f3f5;
    font-size: 13px;
    font-weight: 900;
    white-space: nowrap;
}

.simple-table td {
    font-size: 13px;
    vertical-align: middle;
}

.followup-box {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 8px;
    font-size: 12px;
    min-width: 220px;
}

.mobile-card {
    display: none;
}

.toast-area {
    position: fixed;
    right: 20px;
    top: 80px;
    z-index: 9999;
    min-width: 300px;
}

@media(max-width: 768px) {
    .desktop-table {
        display: none;
    }

    .mobile-card {
        display: block;
    }

    .task-card {
        min-height: 125px;
        padding: 13px;
    }

    .task-label {
        font-size: 13px;
    }

    .task-count {
        font-size: 28px;
    }

    .mobile-order-box {
        background: #fff;
        border-radius: 18px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 3px 12px rgba(0,0,0,0.05);
        padding: 15px;
        margin-bottom: 14px;
    }

    .mobile-title {
        font-weight: 900;
        font-size: 17px;
    }

    .mobile-row {
        font-size: 14px;
        margin-bottom: 7px;
    }

    .mobile-label {
        font-weight: 900;
        color: #333;
    }
}
</style>

<?php if ($toastMessage !== ''): ?>
<div class="toast-area">
    <div class="alert alert-<?= h($toastType) ?> alert-dismissible fade show shadow" role="alert">
        <?= h($toastMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<div class="container-fluid mt-4 mb-5">

    <div class="mb-3">
        <div class="page-title">Pending Order Management</div>
        <div class="page-subtitle">
            Task wise pending orders, follow-up, distributor count, and priority report.
        </div>
    </div>

    <div class="staff-help mb-4">
        <strong>Staff Working Rule:</strong>
        First check <strong>Change Distributor</strong>, then <strong>Ready Not Delivered</strong>, then <strong>Today Follow-up</strong>, then <strong>No Follow-up</strong>.
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ($taskTabs as $key => $tab): ?>
            <div class="col-6 col-md-4 col-xl-3">
                <a class="task-card <?= h($tab['class']) ?> <?= $task === $key ? 'active' : '' ?>"
                   href="<?= h(buildUrl($key, $age, $search, $distributorFilter, $distFilter)) ?>">
                    <div class="task-label"><?= h($tab['label']) ?></div>
                    <div class="task-short"><?= h($tab['short']) ?></div>
                    <div class="task-count"><?= (int)$taskCounts[$key]['total'] ?></div>
                    <div class="task-amount">
                        ₹<?= number_format($taskCounts[$key]['amount'], 0) ?>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mb-4">
        <h6 class="fw-bold mb-2">Order Status Count</h6>
        <?php if ($orderStatusCount && $orderStatusCount->num_rows > 0): ?>
            <?php while ($s = $orderStatusCount->fetch_assoc()): ?>
                <span class="summary-chip">
                    <?= h($s['order_status']) ?>: <?= (int)$s['total'] ?>
                </span>
            <?php endwhile; ?>
        <?php else: ?>
            <span class="text-muted">No pending status found.</span>
        <?php endif; ?>
    </div>

    <div class="mb-4">
        <h6 class="fw-bold mb-2">Distributor-wise Pending Count</h6>

        <?php if ($distributorFilter > 0 || $distFilter === 'not_assigned'): ?>
            <div class="mb-2">
                <a href="<?= h(buildUrl($task, $age, $search, 0, '')) ?>" class="btn btn-sm btn-secondary fw-bold">
                    Show All Distributors
                </a>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <?php if ($distributorWise && $distributorWise->num_rows > 0): ?>
                <?php while ($dw = $distributorWise->fetch_assoc()): ?>
                    <?php
                    $dwDistributorId = (int)($dw['distributor_id'] ?? 0);
                    $isActiveDist = false;

                    if ($dwDistributorId > 0 && $distributorFilter === $dwDistributorId) {
                        $isActiveDist = true;
                    }

                    if ($dwDistributorId === 0 && $distFilter === 'not_assigned') {
                        $isActiveDist = true;
                    }

                    if ($dwDistributorId > 0) {
                        $distUrl = buildUrl($task, $age, $search, $dwDistributorId, '');
                    } else {
                        $distUrl = buildUrl($task, $age, $search, 0, 'not_assigned');
                    }
                    ?>

                    <div class="col-6 col-md-3 col-xl-2">
                        <a class="dist-box dist-clickable <?= $isActiveDist ? 'active' : '' ?>"
                           href="<?= h($distUrl) ?>">
                            <div class="dist-name"><?= h($dw['distributor_name']) ?></div>

                            <div class="dist-count"><?= (int)$dw['total_pending'] ?></div>

                            <div class="dist-small">
                                High: <?= (int)$dw['high_pending'] ?>
                            </div>

                            <div class="dist-small">
                                ₹<?= number_format((float)$dw['total_amount'], 0) ?>
                            </div>

                            <div class="dist-small mt-1">
                                Click to view
                            </div>
                        </a>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-muted">No distributor pending count found.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ($ageTabs as $key => $a): ?>
            <div class="col-6 col-md">
                <a class="age-card <?= $age === $key ? 'active' : '' ?>"
                   href="<?= h(buildUrl($task, $key, $search, $distributorFilter, $distFilter)) ?>">
                    <div class="age-title"><?= h($a['label']) ?></div>
                    <div class="age-small"><?= h($a['short']) ?></div>
                    <div class="age-count"><?= (int)$ageCounts[$key]['total'] ?></div>
                    <div class="age-small">
                        ₹<?= number_format($ageCounts[$key]['amount'], 0) ?>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="filter-box mb-4">
        <input type="hidden" name="task" value="<?= h($task) ?>">
        <input type="hidden" name="age" value="<?= h($age) ?>">

        <?php if ($distFilter === 'not_assigned'): ?>
            <input type="hidden" name="dist_filter" value="not_assigned">
        <?php endif; ?>

        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text"
                       name="search"
                       value="<?= h($search) ?>"
                       class="form-control"
                       placeholder="Invoice, customer, mobile, status">
            </div>

            <div class="col-md-4">
                <label class="form-label">Distributor</label>
                <select name="distributor_id" class="form-select">
                    <option value="0">All Distributor</option>
                    <?php if ($distributorList): ?>
                        <?php while ($d = $distributorList->fetch_assoc()): ?>
                            <option value="<?= (int)$d['distributor_id'] ?>"
                                <?= $distributorFilter === (int)$d['distributor_id'] ? 'selected' : '' ?>>
                                <?= h($d['distributor_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-staff w-100">
                    Search
                </button>

                <a href="?task=<?= h($task) ?>&age=<?= h($age) ?>"
                   class="btn btn-secondary btn-staff">
                    Reset
                </a>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm" style="border-radius:18px; overflow:hidden;">
        <div class="card-header bg-white p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong style="font-size:18px;">
                    <?= h($taskTabs[$task]['label']) ?> — <?= h($ageTabs[$age]['label']) ?>
                </strong>
                <div class="small-muted">
                    Total Orders: <?= (int)$totalListOrders ?>
                </div>
            </div>
        </div>

        <div class="card-body p-0">

            <div class="table-responsive desktop-table">
                <table class="table table-bordered table-hover mb-0 simple-table">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Order</th>
                            <th>Priority</th>
                            <th>Customer</th>
                            <th>Distributor</th>
                            <?php if ($hasSubDistributorId && $hasSubDistributorTable): ?>
                                <th>Sub Distributor</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <th>Last Follow-up</th>
                            <th>Add</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($orders && $orders->num_rows > 0): ?>
                        <?php while ($o = $orders->fetch_assoc()): ?>
                            <?php
                            $days = (int)$o['pending_days'];
                            [$priorityText, $priorityClass] = getPriorityLabel($days);
                            $actionText = getActionText($o);
                            $actionClass = ($o['order_status'] === 'change distributor') ? 'action-danger' : '';
                            $waMobile = cleanWhatsappMobile($o['distributor_mobile']);
                            $modalId = 'followupModal_' . (int)$o['order_id'];
                            ?>

                            <tr>
                                <td>
                                    <span class="action-badge <?= h($actionClass) ?>">
                                        <?= h($actionText) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="main-value"><?= h($o['invoice_number']) ?></div>
                                    <div class="small-muted">
                                        Date: <?= h(date('d-m-Y', strtotime($o['order_date']))) ?>
                                    </div>
                                    <div class="small-muted">
                                        ID: <?= (int)$o['order_id'] ?>
                                    </div>
                                    <div class="main-value mt-1">
                                        ₹<?= number_format((float)$o['grand_total'], 2) ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="<?= h($priorityClass) ?>">
                                        <?= h($priorityText) ?>
                                    </span>
                                    <div class="small-muted mt-1">
                                        <?= $days ?> Days
                                    </div>
                                </td>

                                <td>
                                    <div class="main-value"><?= h($o['full_name']) ?></div>
                                    <div class="small-muted"><?= h($o['mobile_number']) ?></div>
                                    <div class="small-muted">
                                        <?= h($o['city']) ?>
                                        <?= !empty($o['pincode']) ? ' - ' . h($o['pincode']) : '' ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="main-value"><?= h($o['distributor_name']) ?></div>

                                    <?php if (!empty($o['distributor_mobile'])): ?>
                                        <div class="small-muted"><?= h($o['distributor_mobile']) ?></div>
                                        <div class="mt-1">
                                            <a class="btn btn-sm btn-success"
                                               target="_blank"
                                               href="https://wa.me/<?= h($waMobile) ?>">
                                                WhatsApp
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <?php if ($hasSubDistributorId && $hasSubDistributorTable): ?>
                                    <td>
                                        <?= !empty($o['sub_distributor_name']) ? h($o['sub_distributor_name']) : '-' ?>
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <div>
                                        <span class="badge bg-warning text-dark">
                                            Delivery: <?= h($o['delivery_status']) ?>
                                        </span>
                                    </div>
                                    <div class="mt-1">
                                        <span class="badge bg-info text-dark">
                                            Order: <?= h($o['order_status']) ?>
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <div class="followup-box">
                                        <?php if (!empty($o['last_followup_at'])): ?>
                                            <div><strong>Done:</strong> <?= h(date('d-m-Y h:i A', strtotime($o['last_followup_at']))) ?></div>
                                            <div><strong>By:</strong> <?= h($o['last_followup_by']) ?> / ID <?= (int)$o['last_followup_user_id'] ?></div>
                                            <div><strong>Type:</strong> <?= h($o['last_followup_type']) ?></div>
                                            <div><strong>Time:</strong> <?= (int)$o['last_followup_duration'] ?> min</div>
                                            <div><strong>Next:</strong> <?= !empty($o['next_followup_date']) ? h(date('d-m-Y', strtotime($o['next_followup_date']))) : '-' ?></div>
                                            <div><strong>Remark:</strong> <?= h(mb_strimwidth((string)$o['last_followup_remark'], 0, 80, '...')) ?></div>
                                        <?php else: ?>
                                            <div class="text-danger fw-bold">No follow-up done</div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td>
                                    <button type="button"
                                            class="btn btn-primary btn-sm fw-bold"
                                            data-bs-toggle="modal"
                                            data-bs-target="#<?= h($modalId) ?>">
                                        Add Follow-up
                                    </button>
                                </td>
                            </tr>

                            <div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="add_followup">
                                            <input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>">

                                            <div class="modal-header">
                                                <h5 class="modal-title">
                                                    Add Follow-up — <?= h($o['invoice_number']) ?>
                                                </h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>

                                            <div class="modal-body">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label fw-bold">Follow-up Type</label>
                                                        <select name="followup_type" class="form-select" required>
                                                            <?php foreach ($followupTypes as $value => $label): ?>
                                                                <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label fw-bold">Fault Type</label>
                                                        <select name="fault_type" class="form-select">
                                                            <?php foreach ($faultTypes as $value => $label): ?>
                                                                <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label fw-bold">Next Follow-up Date</label>
                                                        <input type="date"
                                                               name="next_followup_date"
                                                               class="form-control">
                                                    </div>

                                                    <div class="col-md-6">
                                                        <label class="form-label fw-bold">Time Taken / Duration Minutes</label>
                                                        <input type="number"
                                                               name="followup_duration_minutes"
                                                               class="form-control"
                                                               min="0"
                                                               value="0">
                                                    </div>

                                                    <div class="col-12">
                                                        <label class="form-label fw-bold">Remark</label>
                                                        <textarea name="remark"
                                                                  class="form-control"
                                                                  rows="4"
                                                                  required
                                                                  placeholder="Example: Distributor said order will deliver tomorrow."></textarea>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                    Close
                                                </button>
                                                <button type="submit" class="btn btn-primary fw-bold">
                                                    Save Follow-up
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= ($hasSubDistributorId && $hasSubDistributorTable) ? 9 : 8 ?>"
                                class="text-center text-muted py-5">
                                No pending order found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-card p-3">
                <?php if ($orders) { $orders->data_seek(0); } ?>

                <?php if ($orders && $orders->num_rows > 0): ?>
                    <?php while ($o = $orders->fetch_assoc()): ?>
                        <?php
                        $days = (int)$o['pending_days'];
                        [$priorityText, $priorityClass] = getPriorityLabel($days);
                        $actionText = getActionText($o);
                        $actionClass = ($o['order_status'] === 'change distributor') ? 'action-danger' : '';
                        $waMobile = cleanWhatsappMobile($o['distributor_mobile']);
                        $modalIdMobile = 'followupMobileModal_' . (int)$o['order_id'];
                        ?>

                        <div class="mobile-order-box">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="mobile-title"><?= h($o['invoice_number']) ?></div>
                                    <div class="small-muted">
                                        <?= h(date('d-m-Y', strtotime($o['order_date']))) ?>
                                    </div>
                                </div>

                                <div>
                                    <span class="<?= h($priorityClass) ?>">
                                        <?= h($priorityText) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="mt-2">
                                <span class="action-badge <?= h($actionClass) ?>">
                                    <?= h($actionText) ?>
                                </span>
                            </div>

                            <hr>

                            <div class="mobile-row">
                                <span class="mobile-label">Pending:</span>
                                <?= $days ?> Days
                            </div>

                            <div class="mobile-row">
                                <span class="mobile-label">Customer:</span>
                                <?= h($o['full_name']) ?>
                            </div>

                            <div class="mobile-row">
                                <span class="mobile-label">Mobile:</span>
                                <?= h($o['mobile_number']) ?>
                            </div>

                            <div class="mobile-row">
                                <span class="mobile-label">Distributor:</span>
                                <?= h($o['distributor_name']) ?>
                            </div>

                            <?php if (!empty($o['distributor_mobile'])): ?>
                                <div class="mobile-row">
                                    <span class="mobile-label">Dist. Mobile:</span>
                                    <?= h($o['distributor_mobile']) ?>
                                </div>

                                <a class="btn btn-success btn-sm mb-2"
                                   target="_blank"
                                   href="https://wa.me/<?= h($waMobile) ?>">
                                    WhatsApp Distributor
                                </a>
                            <?php endif; ?>

                            <div class="mobile-row">
                                <span class="mobile-label">Delivery:</span>
                                <?= h($o['delivery_status']) ?>
                            </div>

                            <div class="mobile-row">
                                <span class="mobile-label">Order:</span>
                                <?= h($o['order_status']) ?>
                            </div>

                            <div class="mobile-row">
                                <span class="mobile-label">Amount:</span>
                                ₹<?= number_format((float)$o['grand_total'], 2) ?>
                            </div>

                            <hr>

                            <?php if (!empty($o['last_followup_at'])): ?>
                                <div class="mobile-row">
                                    <span class="mobile-label">Last Follow-up:</span>
                                    <?= h(date('d-m-Y h:i A', strtotime($o['last_followup_at']))) ?>
                                </div>
                                <div class="mobile-row">
                                    <span class="mobile-label">By:</span>
                                    <?= h($o['last_followup_by']) ?> / ID <?= (int)$o['last_followup_user_id'] ?>
                                </div>
                                <div class="mobile-row">
                                    <span class="mobile-label">Next:</span>
                                    <?= !empty($o['next_followup_date']) ? h(date('d-m-Y', strtotime($o['next_followup_date']))) : '-' ?>
                                </div>
                                <div class="mobile-row">
                                    <span class="mobile-label">Remark:</span>
                                    <?= h(mb_strimwidth((string)$o['last_followup_remark'], 0, 100, '...')) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-danger fw-bold mb-2">No follow-up done</div>
                            <?php endif; ?>

                            <button type="button"
                                    class="btn btn-primary btn-sm fw-bold"
                                    data-bs-toggle="modal"
                                    data-bs-target="#<?= h($modalIdMobile) ?>">
                                Add Follow-up
                            </button>
                        </div>

                        <div class="modal fade" id="<?= h($modalIdMobile) ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="add_followup">
                                        <input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>">

                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                Add Follow-up — <?= h($o['invoice_number']) ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold">Follow-up Type</label>
                                                    <select name="followup_type" class="form-select" required>
                                                        <?php foreach ($followupTypes as $value => $label): ?>
                                                            <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold">Fault Type</label>
                                                    <select name="fault_type" class="form-select">
                                                        <?php foreach ($faultTypes as $value => $label): ?>
                                                            <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold">Next Follow-up Date</label>
                                                    <input type="date"
                                                           name="next_followup_date"
                                                           class="form-control">
                                                </div>

                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold">Time Taken Minutes</label>
                                                    <input type="number"
                                                           name="followup_duration_minutes"
                                                           class="form-control"
                                                           min="0"
                                                           value="0">
                                                </div>

                                                <div class="col-12">
                                                    <label class="form-label fw-bold">Remark</label>
                                                    <textarea name="remark"
                                                              class="form-control"
                                                              rows="4"
                                                              required></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                Close
                                            </button>
                                            <button type="submit" class="btn btn-primary fw-bold">
                                                Save Follow-up
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        No pending order found.
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>

<?php
$stmt->close();
include('include/footer.php');
?>
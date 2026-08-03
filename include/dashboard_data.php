<?php

require_once __DIR__ . '/dashboard_helper.php';

function dashboardScalar(string $sql, string $types = '', array $params = [], bool $asFloat = false)
{
    global $mysqli;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log('Dashboard Data: prepare failed - ' . $mysqli->error);
        return $asFloat ? 0.0 : 0;
    }
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        error_log('Dashboard Data: execute failed - ' . $stmt->error);
        $stmt->close();
        return $asFloat ? 0.0 : 0;
    }
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $asFloat ? (float)($row[0] ?? 0) : (int)($row[0] ?? 0);
}

function dashboardRows(string $sql, string $types = '', array $params = []): array
{
    global $mysqli;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        error_log('Dashboard Data: prepare failed - ' . $mysqli->error);
        return [];
    }
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        error_log('Dashboard Data: execute failed - ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function dashboardDateColumnExpr(string $tableAlias, string $table, array $candidates): ?string
{
    $column = dashboardFirstExistingColumn($table, $candidates);
    return $column ? "$tableAlias.`$column`" : null;
}

function dashboardStatusCondition(string $alias, string $table, array $columns, array $statuses): string
{
    $parts = [];
    $normalized = array_map(static fn($status) => strtolower(trim($status)), $statuses);
    $quoted = "'" . implode("','", array_map('addslashes', $normalized)) . "'";

    foreach ($columns as $column) {
        if (dashboardColumnExists($table, $column)) {
            $parts[] = "LOWER(TRIM($alias.`$column`)) IN ($quoted)";
        }
    }

    return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
}

function dashboardOrderDateExpr(): string
{
    return dashboardDateColumnExpr('o', 'orders', ['order_date', 'created_at', 'created_on', 'date']) ?: 'o.order_id';
}

function dashboardOrderCreatedExpr(): string
{
    return dashboardDateColumnExpr('o', 'orders', ['created_at', 'order_created_at', 'created_on', 'order_date']) ?: dashboardOrderDateExpr();
}

function getSalesDashboardData(?int $filterByUserId = null): array
{
    if (!dashboardTableExists('orders')) {
        return [
            'total_orders' => 0, 'today_orders' => 0, 'month_orders' => 0, 'new_orders' => 0,
            'assigned_orders' => 0, 'unassigned_orders' => 0, 'change_distributor_pending' => 0,
            'cancelled_orders' => 0, 'delivered_orders' => 0, 'ready_delivery_orders' => 0,
            'repeat_orders' => 0, 'new_customer_orders' => 0, 'current_month_orders' => 0,
            'last_month_same_period_orders' => 0, 'order_growth_percentage' => 0,
            'status_chart' => [], 'daily_trend' => [], 'recent_orders' => [],
        ];
    }

    $dateExpr = dashboardOrderDateExpr();
    $createdExpr = dashboardOrderCreatedExpr();
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
    $lastMonthSameDate = date('Y-m-d', strtotime('-1 month'));

    // Scope every query to one user's own orders (e.g. a Sales-department
    // user should only see their own numbers on the dashboard; only Admin
    // gets the company-wide totals). Falls back to unfiltered if the
    // orders.created_by column hasn't been migrated yet.
    $scopeToUser = $filterByUserId !== null && dashboardColumnExists('orders', 'created_by');
    $userFilterSql = $scopeToUser ? ' AND o.created_by = ?' : '';
    $userFilterType = $scopeToUser ? 'i' : '';
    $userFilterParam = $scopeToUser ? [$filterByUserId] : [];

    $newWhere = dashboardStatusCondition('o', 'orders', ['order_status', 'delivery_status'], ['New', 'Open']);
    $assignedWhere = dashboardColumnExists('orders', 'distributor_id')
        ? '(o.distributor_id IS NOT NULL AND o.distributor_id <> 0)'
        : dashboardStatusCondition('o', 'orders', ['order_status', 'delivery_status'], ['Assigned']);
    $deliveredWhere = dashboardStatusCondition('o', 'orders', ['order_status', 'delivery_status', 'distributor_status'], ['Delivered']);
    $cancelledWhere = dashboardStatusCondition('o', 'orders', ['order_status', 'delivery_status', 'distributor_status'], ['Cancelled']);
    $readyWhere = dashboardStatusCondition('o', 'orders', ['order_status', 'delivery_status', 'distributor_status'], ['Ready to Delivery']);
    $changeWhere = dashboardStatusCondition('o', 'orders', ['order_status'], ['change distributor']);

    $unassignedParts = [];
    if (dashboardColumnExists('orders', 'distributor_assigned_at')) {
        $unassignedParts[] = 'o.distributor_assigned_at IS NULL';
    }
    if (dashboardColumnExists('orders', 'distributor_id')) {
        $unassignedParts[] = '(o.distributor_id IS NULL OR o.distributor_id = 0)';
    }
    $unassignedWhere = $unassignedParts ? '(' . implode(' OR ', $unassignedParts) . ')' : $newWhere;
    $unassignedWhere .= " AND NOT ($deliveredWhere) AND NOT ($cancelledWhere)";

    $counts = dashboardRows("
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN DATE($dateExpr) = CURDATE() THEN 1 ELSE 0 END) AS today_orders,
            SUM(CASE WHEN DATE($dateExpr) >= ? THEN 1 ELSE 0 END) AS month_orders,
            SUM(CASE WHEN $newWhere THEN 1 ELSE 0 END) AS new_orders,
            SUM(CASE WHEN $assignedWhere THEN 1 ELSE 0 END) AS assigned_orders,
            SUM(CASE WHEN $unassignedWhere THEN 1 ELSE 0 END) AS unassigned_orders,
            SUM(CASE WHEN $changeWhere THEN 1 ELSE 0 END) AS change_distributor_pending,
            SUM(CASE WHEN $cancelledWhere THEN 1 ELSE 0 END) AS cancelled_orders,
            SUM(CASE WHEN $deliveredWhere THEN 1 ELSE 0 END) AS delivered_orders,
            SUM(CASE WHEN $readyWhere THEN 1 ELSE 0 END) AS ready_delivery_orders
        FROM orders o
        WHERE 1=1 $userFilterSql
    ", 's' . $userFilterType, array_merge([$monthStart], $userFilterParam));

    $row = $counts[0] ?? [];
    $currentMonth = dashboardScalar(
        "SELECT COUNT(*) FROM orders o WHERE DATE($dateExpr) >= ? $userFilterSql",
        's' . $userFilterType,
        array_merge([$monthStart], $userFilterParam)
    );
    $lastMonthSame = dashboardScalar(
        "SELECT COUNT(*) FROM orders o WHERE DATE($dateExpr) >= ? AND DATE($dateExpr) <= ? $userFilterSql",
        'ss' . $userFilterType,
        array_merge([$lastMonthStart, $lastMonthSameDate], $userFilterParam)
    );
    $growth = $lastMonthSame > 0 ? (($currentMonth - $lastMonthSame) / $lastMonthSame) * 100 : ($currentMonth > 0 ? 100 : 0);

    $repeatOrders = 0;
    $newCustomerOrders = 0;
    if (dashboardColumnExists('orders', 'customer_id')) {
        $repeatOrders = dashboardScalar("
            SELECT COUNT(*) FROM orders o
            WHERE o.customer_id IN (
                SELECT customer_id FROM orders
                WHERE customer_id IS NOT NULL
                GROUP BY customer_id
                HAVING COUNT(*) > 1
            )
            $userFilterSql
        ", $userFilterType, $userFilterParam);
        $newCustomerOrders = dashboardScalar("
            SELECT COUNT(*) FROM orders o
            WHERE DATE($dateExpr) >= ?
              AND o.customer_id IN (
                  SELECT customer_id FROM orders
                  WHERE customer_id IS NOT NULL
                  GROUP BY customer_id
                  HAVING COUNT(*) = 1
              )
              $userFilterSql
        ", 's' . $userFilterType, array_merge([$monthStart], $userFilterParam));
    }

    $statusCol = dashboardFirstExistingColumn('orders', ['order_status', 'delivery_status', 'distributor_status']);
    $statusChart = $statusCol ? dashboardRows("
        SELECT COALESCE(NULLIF(TRIM(o.`$statusCol`), ''), 'Unknown') AS label, COUNT(*) AS total
        FROM orders o
        WHERE 1=1 $userFilterSql
        GROUP BY COALESCE(NULLIF(TRIM(o.`$statusCol`), ''), 'Unknown')
        ORDER BY total DESC
        LIMIT 8
    ", $userFilterType, $userFilterParam) : [];

    $dailyTrend = dashboardRows("
        SELECT DATE($dateExpr) AS label, COUNT(*) AS total
        FROM orders o
        WHERE DATE($dateExpr) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        $userFilterSql
        GROUP BY DATE($dateExpr)
        ORDER BY label ASC
    ", $userFilterType, $userFilterParam);

    $customerJoin = dashboardTableExists('customers') && dashboardColumnExists('orders', 'customer_id')
        ? 'LEFT JOIN customers c ON c.customer_id = o.customer_id'
        : '';
    $customerName = $customerJoin && dashboardColumnExists('customers', 'full_name') ? 'c.full_name' : "''";
    $distributorJoin = dashboardTableExists('distributors') && dashboardColumnExists('orders', 'distributor_id')
        ? 'LEFT JOIN distributors d ON d.distributor_id = o.distributor_id'
        : '';
    $distributorName = $distributorJoin && dashboardColumnExists('distributors', 'distributor_name') ? 'd.distributor_name' : "''";
    $invoiceCol = dashboardColumnExists('orders', 'invoice_number') ? 'o.invoice_number' : 'o.order_id';
    $orderStatusCol = dashboardColumnExists('orders', 'order_status') ? 'o.order_status' : "''";
    $deliveryStatusCol = dashboardColumnExists('orders', 'delivery_status') ? 'o.delivery_status' : "''";

    $recentOrders = dashboardRows("
        SELECT
            o.order_id,
            $invoiceCol AS invoice_number,
            $customerName AS customer_name,
            DATE($dateExpr) AS order_date,
            $orderStatusCol AS order_status,
            $deliveryStatusCol AS delivery_status,
            $distributorName AS distributor_name,
            $createdExpr AS created_at
        FROM orders o
        $customerJoin
        $distributorJoin
        WHERE 1=1 $userFilterSql
        ORDER BY o.order_id DESC
        LIMIT 10
    ", $userFilterType, $userFilterParam);

    return [
        'total_orders' => (int)($row['total_orders'] ?? 0),
        'today_orders' => (int)($row['today_orders'] ?? 0),
        'month_orders' => (int)($row['month_orders'] ?? 0),
        'new_orders' => (int)($row['new_orders'] ?? 0),
        'assigned_orders' => (int)($row['assigned_orders'] ?? 0),
        'unassigned_orders' => (int)($row['unassigned_orders'] ?? 0),
        'change_distributor_pending' => (int)($row['change_distributor_pending'] ?? 0),
        'cancelled_orders' => (int)($row['cancelled_orders'] ?? 0),
        'delivered_orders' => (int)($row['delivered_orders'] ?? 0),
        'ready_delivery_orders' => (int)($row['ready_delivery_orders'] ?? 0),
        'repeat_orders' => $repeatOrders,
        'new_customer_orders' => $newCustomerOrders,
        'current_month_orders' => $currentMonth,
        'last_month_same_period_orders' => $lastMonthSame,
        'order_growth_percentage' => $growth,
        'status_chart' => $statusChart,
        'daily_trend' => $dailyTrend,
        'recent_orders' => $recentOrders,
    ];
}

function getProductionDashboardData(): array
{
    $defaults = [
        'active_products' => 0, 'total_batches' => 0, 'today_batches' => 0,
        'month_batches' => 0, 'today_production_qty' => 0, 'month_production_qty' => 0,
        'sathee_batches' => 0, 'cmd_batches' => 0, 'daily_production_trend' => [],
        'product_wise_production' => [], 'recent_batches' => [],
    ];
    if (!dashboardTableExists('batches')) {
        return $defaults;
    }

    $productStatus = dashboardTableExists('products') && dashboardColumnExists('products', 'status')
        ? "WHERE status = 'active'"
        : '';
    $activeProducts = dashboardTableExists('products') ? dashboardScalar("SELECT COUNT(*) FROM products $productStatus") : 0;

    $dateExpr = dashboardDateColumnExpr('b', 'batches', ['production_date', 'created_at', 'created_on', 'date']) ?: 'b.batch_id';
    $qtyCol = dashboardFirstExistingColumn('batches', ['product_qty', 'quantity', 'qty', 'batch_qty']);
    $qtyExpr = $qtyCol ? "COALESCE(b.`$qtyCol`, 0)" : '0';
    $ownerCol = dashboardFirstExistingColumn('batches', ['batch_owner', 'owner']);
    $monthStart = date('Y-m-01');

    $counts = dashboardRows("
        SELECT
            COUNT(*) AS total_batches,
            SUM(CASE WHEN DATE($dateExpr) = CURDATE() THEN 1 ELSE 0 END) AS today_batches,
            SUM(CASE WHEN DATE($dateExpr) >= ? THEN 1 ELSE 0 END) AS month_batches,
            SUM(CASE WHEN DATE($dateExpr) = CURDATE() THEN $qtyExpr ELSE 0 END) AS today_production_qty,
            SUM(CASE WHEN DATE($dateExpr) >= ? THEN $qtyExpr ELSE 0 END) AS month_production_qty
        FROM batches b
    ", 'ss', [$monthStart, $monthStart]);
    $row = $counts[0] ?? [];

    $satheeBatches = 0;
    $cmdBatches = 0;
    if ($ownerCol) {
        $satheeBatches = dashboardScalar("SELECT COUNT(*) FROM batches b WHERE LOWER(TRIM(b.`$ownerCol`)) = 'sathee'");
        $cmdBatches = dashboardScalar("SELECT COUNT(*) FROM batches b WHERE LOWER(TRIM(b.`$ownerCol`)) = 'cmd'");
    }

    $trend = dashboardRows("
        SELECT DATE($dateExpr) AS label, SUM($qtyExpr) AS total
        FROM batches b
        WHERE DATE($dateExpr) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE($dateExpr)
        ORDER BY label ASC
    ");

    $productJoin = dashboardTableExists('products') && dashboardColumnExists('batches', 'product_id')
        ? 'LEFT JOIN products p ON p.product_id = b.product_id'
        : '';
    $productTitle = $productJoin && dashboardColumnExists('products', 'title') ? 'p.title' : "'Unknown'";
    $productWise = dashboardRows("
        SELECT $productTitle AS label, SUM($qtyExpr) AS total
        FROM batches b
        $productJoin
        GROUP BY $productTitle
        ORDER BY total DESC
        LIMIT 10
    ");

    $batchCode = dashboardFirstExistingColumn('batches', ['batch_code', 'code']) ?: 'batch_id';
    $createdByJoin = dashboardTableExists('users') && dashboardColumnExists('batches', 'created_by')
        ? 'LEFT JOIN users u ON u.user_id = b.created_by'
        : '';
    $createdByName = $createdByJoin && dashboardColumnExists('users', 'name') ? 'u.name' : "''";
    $ownerSelect = $ownerCol ? "b.`$ownerCol`" : "''";
    $recent = dashboardRows("
        SELECT
            b.batch_id,
            b.`$batchCode` AS batch_code,
            $productTitle AS product_name,
            $qtyExpr AS product_qty,
            DATE($dateExpr) AS production_date,
            $ownerSelect AS batch_owner,
            $createdByName AS created_by_name
        FROM batches b
        $productJoin
        $createdByJoin
        ORDER BY b.batch_id DESC
        LIMIT 10
    ");

    return [
        'active_products' => $activeProducts,
        'total_batches' => (int)($row['total_batches'] ?? 0),
        'today_batches' => (int)($row['today_batches'] ?? 0),
        'month_batches' => (int)($row['month_batches'] ?? 0),
        'today_production_qty' => (float)($row['today_production_qty'] ?? 0),
        'month_production_qty' => (float)($row['month_production_qty'] ?? 0),
        'sathee_batches' => $satheeBatches,
        'cmd_batches' => $cmdBatches,
        'daily_production_trend' => $trend,
        'product_wise_production' => $productWise,
        'recent_batches' => $recent,
    ];
}

function getPurchaseDashboardData(): array
{
    $defaults = [
        'total_raw_materials' => 0, 'active_raw_materials' => 0, 'low_stock_materials' => 0,
        'out_of_stock_materials' => 0, 'today_usage_qty' => 0, 'month_usage_qty' => 0,
        'usage_trend' => [], 'top_used_materials' => [], 'recent_usage' => [], 'low_stock_list' => [],
    ];
    if (!dashboardTableExists('raw_materials')) {
        return $defaults;
    }

    $statusCol = dashboardColumnExists('raw_materials', 'status') ? 'status' : null;
    $stockCol = dashboardFirstExistingColumn('raw_materials', ['current_stock', 'stock', 'quantity', 'qty']);
    $minimumCol = dashboardFirstExistingColumn('raw_materials', ['minimum_stock', 'min_stock', 'reorder_level']);
    $nameCol = dashboardFirstExistingColumn('raw_materials', ['material_name', 'name', 'title']);
    $unitCol = dashboardFirstExistingColumn('raw_materials', ['unit', 'uom']);

    $totalRaw = dashboardScalar('SELECT COUNT(*) FROM raw_materials');
    $activeRaw = $statusCol ? dashboardScalar("SELECT COUNT(*) FROM raw_materials WHERE `$statusCol` = 'active'") : $totalRaw;
    $lowStock = ($stockCol && $minimumCol)
        ? dashboardScalar("SELECT COUNT(*) FROM raw_materials WHERE `$stockCol` > 0 AND `$stockCol` <= `$minimumCol`")
        : 0;
    $outStock = $stockCol ? dashboardScalar("SELECT COUNT(*) FROM raw_materials WHERE `$stockCol` <= 0") : 0;

    $usageQtyCol = dashboardFirstExistingColumn('batch_raw_materials', ['quantity_used', 'qty_used', 'quantity', 'qty']);
    $usageQtyExpr = $usageQtyCol ? "COALESCE(brm.`$usageQtyCol`, 0)" : '0';
    $batchDateExpr = dashboardTableExists('batches')
        ? (dashboardDateColumnExpr('b', 'batches', ['production_date', 'created_at', 'created_on', 'date']) ?: 'b.batch_id')
        : null;
    $monthStart = date('Y-m-01');

    $todayUsage = 0;
    $monthUsage = 0;
    $usageTrend = [];
    $topUsed = [];
    $recentUsage = [];
    if (dashboardTableExists('batch_raw_materials') && dashboardTableExists('batches') && $batchDateExpr) {
        $todayUsage = dashboardScalar("
            SELECT COALESCE(SUM($usageQtyExpr), 0)
            FROM batch_raw_materials brm
            INNER JOIN batches b ON b.batch_id = brm.batch_id
            WHERE DATE($batchDateExpr) = CURDATE()
        ", '', [], true);
        $monthUsage = dashboardScalar("
            SELECT COALESCE(SUM($usageQtyExpr), 0)
            FROM batch_raw_materials brm
            INNER JOIN batches b ON b.batch_id = brm.batch_id
            WHERE DATE($batchDateExpr) >= ?
        ", 's', [$monthStart], true);
        $usageTrend = dashboardRows("
            SELECT DATE($batchDateExpr) AS label, COALESCE(SUM($usageQtyExpr), 0) AS total
            FROM batch_raw_materials brm
            INNER JOIN batches b ON b.batch_id = brm.batch_id
            WHERE DATE($batchDateExpr) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY DATE($batchDateExpr)
            ORDER BY label ASC
        ");

        $materialName = $nameCol ? "rm.`$nameCol`" : "'Unknown'";
        $topUsed = dashboardRows("
            SELECT $materialName AS label, COALESCE(SUM($usageQtyExpr), 0) AS total
            FROM batch_raw_materials brm
            LEFT JOIN raw_materials rm ON rm.raw_material_id = brm.raw_material_id
            GROUP BY $materialName
            ORDER BY total DESC
            LIMIT 10
        ");

        $batchCode = dashboardFirstExistingColumn('batches', ['batch_code', 'code']) ?: 'batch_id';
        $ownerCol = dashboardFirstExistingColumn('batches', ['batch_owner', 'owner']);
        $ownerSelect = $ownerCol ? "b.`$ownerCol`" : "''";
        $unitSelect = $unitCol ? "rm.`$unitCol`" : "''";
        $usageOrderCol = dashboardFirstExistingColumn('batch_raw_materials', ['batch_raw_material_id', 'id']) ?: 'batch_id';
        $recentUsage = dashboardRows("
            SELECT
                b.`$batchCode` AS batch_code,
                $materialName AS material_name,
                $usageQtyExpr AS quantity_used,
                $unitSelect AS unit,
                DATE($batchDateExpr) AS production_date,
                $ownerSelect AS batch_owner
            FROM batch_raw_materials brm
            INNER JOIN batches b ON b.batch_id = brm.batch_id
            LEFT JOIN raw_materials rm ON rm.raw_material_id = brm.raw_material_id
            ORDER BY brm.`$usageOrderCol` DESC
            LIMIT 10
        ");
    }

    $lowStockList = [];
    if ($stockCol && $minimumCol) {
        $materialName = $nameCol ? "`$nameCol`" : "'Unknown'";
        $unitSelect = $unitCol ? "`$unitCol`" : "''";
        $lowStockList = dashboardRows("
            SELECT $materialName AS material_name, `$stockCol` AS stock, `$minimumCol` AS minimum_stock, $unitSelect AS unit
            FROM raw_materials
            WHERE `$stockCol` <= `$minimumCol`
            ORDER BY `$stockCol` ASC
            LIMIT 8
        ");
    }

    return [
        'total_raw_materials' => $totalRaw,
        'active_raw_materials' => $activeRaw,
        'low_stock_materials' => $lowStock,
        'out_of_stock_materials' => $outStock,
        'today_usage_qty' => $todayUsage,
        'month_usage_qty' => $monthUsage,
        'usage_trend' => $usageTrend,
        'top_used_materials' => $topUsed,
        'recent_usage' => $recentUsage,
        'low_stock_list' => $lowStockList,
    ];
}

function getAdminDashboardData(): array
{
    $data = [
        'total_order_amount' => 0.0,
        'month_order_amount' => 0.0,
        'delivered_order_amount' => 0.0,
        'total_users' => dashboardTableExists('users') ? dashboardScalar('SELECT COUNT(*) FROM users') : 0,
        'total_distributors' => dashboardTableExists('distributors') ? dashboardScalar('SELECT COUNT(*) FROM distributors') : 0,
    ];

    if (!canViewFinancialAmounts() || !dashboardTableExists('orders')) {
        return $data;
    }

    $amountCol = dashboardFirstExistingColumn('orders', ['grand_total', 'total_amount', 'amount', 'net_total', 'total']);
    if (!$amountCol) {
        return $data;
    }

    $dateExpr = dashboardOrderDateExpr();
    $monthStart = date('Y-m-01');
    $deliveredWhere = dashboardStatusCondition('o', 'orders', ['order_status', 'delivery_status', 'distributor_status'], ['Delivered']);

    $data['total_order_amount'] = dashboardScalar("SELECT COALESCE(SUM(o.`$amountCol`), 0) FROM orders o", '', [], true);
    $data['month_order_amount'] = dashboardScalar("SELECT COALESCE(SUM(o.`$amountCol`), 0) FROM orders o WHERE DATE($dateExpr) >= ?", 's', [$monthStart], true);
    $data['delivered_order_amount'] = dashboardScalar("SELECT COALESCE(SUM(o.`$amountCol`), 0) FROM orders o WHERE $deliveredWhere", '', [], true);

    return $data;
}

function getGeneralDashboardData(): array
{
    return [
        'shortcuts' => dashboardPermittedShortcuts(),
        'message' => 'Your dashboard is ready. Use the shortcuts below to access your assigned modules.',
    ];
}

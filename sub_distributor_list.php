<?php
/*
|--------------------------------------------------------------------------
| File: sub_distributor_list.php
|--------------------------------------------------------------------------
| Required database columns:
|
| parent_distributor_id  INT NULL
| parent_distributor_ids JSON NULL
|--------------------------------------------------------------------------
*/

$ajaxAction = $_GET['ajax'] ?? $_POST['ajax'] ?? '';

if ($ajaxAction !== '') {
    ob_start();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'include/require_permission.php';

if ($ajaxAction === 'save_assignment') {
    if (!hasPermission('SUB_DISTRIBUTORS', 'edit')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'You do not have permission to perform this action.']);
        exit;
    }
} else {
    requirePermission('SUB_DISTRIBUTORS', 'view');
}

if (!isset($mysqli)) {
    include('include/db.php');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['sub_distributor_csrf'])) {
    $_SESSION['sub_distributor_csrf'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function jsonResponse(array $data, int $statusCode = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Get all assigned parent IDs
|--------------------------------------------------------------------------
| Supports:
| [2,4,5]
| ["2","4","5"]
| "2,4,5"
|--------------------------------------------------------------------------
*/

function getParentIds($jsonValue, $primaryParentId): array
{
    $ids = [];

    if ($jsonValue !== null && trim((string)$jsonValue) !== '') {
        $decoded = json_decode((string)$jsonValue, true);

        /*
         * Handle double encoded JSON
         */
        if (is_string($decoded)) {
            $secondDecoded = json_decode($decoded, true);

            if (is_array($secondDecoded)) {
                $decoded = $secondDecoded;
            }
        }

        if (is_array($decoded)) {
            foreach ($decoded as $id) {
                $id = (int)$id;

                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } else {
            /*
             * Fallback for old comma-separated data
             */
            $parts = explode(',', (string)$jsonValue);

            foreach ($parts as $part) {
                $id = (int)trim($part, " []\"'");

                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
    }

    /*
     * Always include primary distributor
     */
    $primaryParentId = (int)$primaryParentId;

    if (
        $primaryParentId > 0 &&
        !in_array($primaryParentId, $ids, true)
    ) {
        $ids[] = $primaryParentId;
    }

    return array_values(array_unique($ids));
}

function buildPagination(
    int $currentPage,
    int $totalPages
): string {
    if ($totalPages <= 1) {
        return '';
    }

    $html = '';

    $previousPage = max(1, $currentPage - 1);
    $nextPage = min($totalPages, $currentPage + 1);

    $previousDisabled = $currentPage <= 1
        ? ' disabled'
        : '';

    $nextDisabled = $currentPage >= $totalPages
        ? ' disabled'
        : '';

    $html .= '
        <li class="page-item' . $previousDisabled . '">
            <a class="page-link"
               href="#"
               data-page="' . $previousPage . '">
                Previous
            </a>
        </li>
    ';

    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);

    if ($startPage > 1) {
        $html .= '
            <li class="page-item">
                <a class="page-link"
                   href="#"
                   data-page="1">
                    1
                </a>
            </li>
        ';

        if ($startPage > 2) {
            $html .= '
                <li class="page-item disabled">
                    <span class="page-link">...</span>
                </li>
            ';
        }
    }

    for ($page = $startPage; $page <= $endPage; $page++) {
        $activeClass = $page === $currentPage
            ? ' active'
            : '';

        $html .= '
            <li class="page-item' . $activeClass . '">
                <a class="page-link"
                   href="#"
                   data-page="' . $page . '">
                    ' . $page . '
                </a>
            </li>
        ';
    }

    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '
                <li class="page-item disabled">
                    <span class="page-link">...</span>
                </li>
            ';
        }

        $html .= '
            <li class="page-item">
                <a class="page-link"
                   href="#"
                   data-page="' . $totalPages . '">
                    ' . $totalPages . '
                </a>
            </li>
        ';
    }

    $html .= '
        <li class="page-item' . $nextDisabled . '">
            <a class="page-link"
               href="#"
               data-page="' . $nextPage . '">
                Next
            </a>
        </li>
    ';

    return $html;
}

/*
|--------------------------------------------------------------------------
| AJAX: Load sub-distributor list
|--------------------------------------------------------------------------
*/

if ($ajaxAction === 'list') {
    try {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 15;
        $search = trim($_GET['q'] ?? '');

        /*
         * Load all main distributors
         */
        $mainDistributorMap = [];

        $mainResult = $mysqli->query("
            SELECT
                distributor_id,
                distributor_code,
                distributor_name,
                status
            FROM distributors
            WHERE distributor_type = 'main'
            ORDER BY distributor_name ASC
        ");

        while ($mainRow = $mainResult->fetch_assoc()) {
            $mainId = (int)$mainRow['distributor_id'];

            $mainDistributorMap[$mainId] = [
                'id'     => $mainId,
                'name'   => $mainRow['distributor_name'],
                'code'   => $mainRow['distributor_code'],
                'status' => $mainRow['status']
            ];
        }

        $whereSql = "
            WHERE d.distributor_type = 'sub'
        ";

        $like = '%' . $search . '%';

        if ($search !== '') {
            $whereSql .= "
                AND (
                    COALESCE(d.distributor_code, '') LIKE ?
                    OR COALESCE(d.distributor_name, '') LIKE ?
                    OR COALESCE(d.contact_person, '') LIKE ?
                    OR COALESCE(d.mobile_number, '') LIKE ?
                    OR COALESCE(d.address, '') LIKE ?
                )
            ";
        }

        /*
         * Count records
         */
        $countSql = "
            SELECT COUNT(*)
            FROM distributors d
            {$whereSql}
        ";

        $countStmt = $mysqli->prepare($countSql);

        if ($search !== '') {
            $countStmt->bind_param(
                'sssss',
                $like,
                $like,
                $like,
                $like,
                $like
            );
        }

        $countStmt->execute();
        $countStmt->bind_result($totalRecords);
        $countStmt->fetch();
        $countStmt->close();

        $totalRecords = (int)$totalRecords;

        $totalPages = max(
            1,
            (int)ceil($totalRecords / $limit)
        );

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $limit;

        /*
         * Get sub-distributors
         */
        $listSql = "
            SELECT
                d.distributor_id,
                d.distributor_code,
                d.distributor_name,
                d.contact_person,
                d.mobile_number,
                d.parent_distributor_id,
                d.parent_distributor_ids,
                d.status,
                d.created_at
            FROM distributors d
            {$whereSql}
            ORDER BY d.distributor_name ASC
            LIMIT {$limit}
            OFFSET {$offset}
        ";

        $listStmt = $mysqli->prepare($listSql);

        if ($search !== '') {
            $listStmt->bind_param(
                'sssss',
                $like,
                $like,
                $like,
                $like,
                $like
            );
        }

        $listStmt->execute();
        $result = $listStmt->get_result();

        $rowsHtml = '';

        while ($row = $result->fetch_assoc()) {
            $subDistributorId = (int)$row['distributor_id'];
            $primaryParentId = (int)$row['parent_distributor_id'];

            $assignedParentIds = getParentIds(
                $row['parent_distributor_ids'],
                $primaryParentId
            );

            /*
             * Assigned main distributor badges
             */
            $assignedHtml = '';

            foreach ($assignedParentIds as $parentId) {
                if (!isset($mainDistributorMap[$parentId])) {
                    $assignedHtml .= '
                        <span class="sd-main-badge sd-main-warning">
                            Unknown ID #' . $parentId . '
                        </span>
                    ';

                    continue;
                }

                $main = $mainDistributorMap[$parentId];
                $isPrimary = $parentId === $primaryParentId;

                $badgeClass = $isPrimary
                    ? 'sd-main-primary'
                    : 'sd-main-secondary';

                $primaryLabel = $isPrimary
                    ? '<span class="sd-primary-label">Primary</span>'
                    : '';

                $inactiveLabel = $main['status'] === 'inactive'
                    ? '<span class="sd-inactive-label">Inactive</span>'
                    : '';

                $codeText = !empty($main['code'])
                    ? '<span class="sd-main-code">' . e($main['code']) . '</span>'
                    : '';

                $assignedHtml .= '
                    <span class="sd-main-badge ' . $badgeClass . '">
                        <span class="sd-main-name">
                            ' . e($main['name']) . '
                        </span>

                        ' . $codeText . '
                        ' . $primaryLabel . '
                        ' . $inactiveLabel . '
                    </span>
                ';
            }

            if ($assignedHtml === '') {
                $assignedHtml = '
                    <span class="sd-not-assigned">
                        Not assigned
                    </span>
                ';
            }

            /*
             * Primary distributor column
             */
            $primaryHtml = '
                <span class="sd-not-assigned">
                    Not selected
                </span>
            ';

            if (
                $primaryParentId > 0 &&
                isset($mainDistributorMap[$primaryParentId])
            ) {
                $primaryMain = $mainDistributorMap[$primaryParentId];

                $primaryStatus = $primaryMain['status'] === 'inactive'
                    ? '<span class="sd-primary-status-inactive">Inactive</span>'
                    : '<span class="sd-primary-status-active">Active</span>';

                $primaryCode = !empty($primaryMain['code'])
                    ? e($primaryMain['code'])
                    : 'No code';

                $primaryHtml = '
                    <div class="sd-primary-card">
                        <div class="sd-primary-name">
                            ' . e($primaryMain['name']) . '
                        </div>

                        <div class="sd-primary-meta">
                            <span>' . $primaryCode . '</span>
                            ' . $primaryStatus . '
                        </div>
                    </div>
                ';
            }

            /*
             * Sub-distributor status
             */
            if ($row['status'] === 'active') {
                $statusHtml = '
                    <span class="sd-status-badge sd-status-active">
                        <span class="sd-status-dot"></span>
                        Active
                    </span>
                ';
            } else {
                $statusHtml = '
                    <span class="sd-status-badge sd-status-inactive">
                        <span class="sd-status-dot"></span>
                        Inactive
                    </span>
                ';
            }

            $selectedJson = e(
                json_encode(
                    array_values($assignedParentIds),
                    JSON_UNESCAPED_UNICODE
                )
            );

            $createdDate = '-';

            if (!empty($row['created_at'])) {
                $timestamp = strtotime($row['created_at']);

                if ($timestamp !== false) {
                    $createdDate = date('d M Y', $timestamp);
                }
            }

            $rowsHtml .= '
                <tr>
                    <td>
                        <span class="sd-id-text">
                            #' . $subDistributorId . '
                        </span>
                    </td>

                    <td>
                        <span class="sd-code-text">
                            ' . e($row['distributor_code'] ?: '-') . '
                        </span>
                    </td>

                    <td>
                        <div class="sd-sub-name">
                            ' . e($row['distributor_name']) . '
                        </div>
                    </td>

                    <td>
                        ' . e($row['contact_person'] ?: '-') . '
                    </td>

                    <td>
                        <a href="tel:' . e($row['mobile_number']) . '"
                           class="sd-mobile-link">
                            ' . e($row['mobile_number']) . '
                        </a>
                    </td>

                    <td class="sd-assigned-cell">
                        <div class="sd-badge-container">
                            ' . $assignedHtml . '
                        </div>
                    </td>

                    <td>
                        ' . $primaryHtml . '
                    </td>

                    <td>
                        ' . $statusHtml . '
                    </td>

                    <td class="sd-created-date">
                        ' . e($createdDate) . '
                    </td>

                    <td>
                        <div class="sd-action-buttons">
                            <a href="distributor_edit.php?id=' . $subDistributorId . '"
                               class="sd-btn-edit">
                                Edit
                            </a>

                            <button type="button"
                                    class="sd-btn-assign btn-assign"
                                    data-id="' . $subDistributorId . '"
                                    data-name="' . e($row['distributor_name']) . '"
                                    data-primary="' . $primaryParentId . '"
                                    data-selected="' . $selectedJson . '">
                                Assign
                            </button>
                        </div>
                    </td>
                </tr>
            ';
        }

        $listStmt->close();

        if ($rowsHtml === '') {
            $rowsHtml = '
                <tr>
                    <td colspan="10"
                        class="text-center py-5">

                        <div class="sd-empty-title">
                            No sub-distributors found
                        </div>

                        <div class="sd-empty-text">
                            Try changing your search keyword.
                        </div>
                    </td>
                </tr>
            ';
        }

        $showingFrom = $totalRecords > 0
            ? $offset + 1
            : 0;

        $showingTo = min(
            $offset + $limit,
            $totalRecords
        );

        jsonResponse([
            'success'         => true,
            'rows_html'       => $rowsHtml,
            'pagination_html' => buildPagination(
                $page,
                $totalPages
            ),
            'meta'            => "Showing {$showingFrom}-{$showingTo} of {$totalRecords}",
            'current_page'    => $page
        ]);
    } catch (Throwable $exception) {
        jsonResponse([
            'success' => false,
            'message' => $exception->getMessage()
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| AJAX: Save assignment
|--------------------------------------------------------------------------
*/

if ($ajaxAction === 'save_assignment') {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse([
                'success' => false,
                'message' => 'Invalid request method.'
            ], 405);
        }

        $csrfToken = $_POST['csrf_token'] ?? '';

        if (
            empty($csrfToken) ||
            !hash_equals(
                $_SESSION['sub_distributor_csrf'],
                $csrfToken
            )
        ) {
            jsonResponse([
                'success' => false,
                'message' => 'Security token expired. Refresh the page.'
            ], 419);
        }

        $subDistributorId = (int)(
            $_POST['sub_distributor_id'] ?? 0
        );

        $primaryDistributorId = (int)(
            $_POST['primary_distributor_id'] ?? 0
        );

        $mainDistributorIds = $_POST['main_distributor_ids'] ?? [];

        if (!is_array($mainDistributorIds)) {
            $mainDistributorIds = [];
        }

        $cleanIds = [];

        foreach ($mainDistributorIds as $id) {
            $id = (int)$id;

            if ($id > 0) {
                $cleanIds[] = $id;
            }
        }

        $mainDistributorIds = array_values(
            array_unique($cleanIds)
        );

        if ($subDistributorId <= 0) {
            jsonResponse([
                'success' => false,
                'message' => 'Invalid sub-distributor.'
            ], 422);
        }

        if (empty($mainDistributorIds)) {
            jsonResponse([
                'success' => false,
                'message' => 'Select at least one main distributor.'
            ], 422);
        }

        if ($primaryDistributorId <= 0) {
            jsonResponse([
                'success' => false,
                'message' => 'Select primary main distributor.'
            ], 422);
        }

        /*
         * Primary must also be inside selected IDs
         */
        if (
            !in_array(
                $primaryDistributorId,
                $mainDistributorIds,
                true
            )
        ) {
            $mainDistributorIds[] = $primaryDistributorId;
        }

        $mainDistributorIds = array_values(
            array_unique($mainDistributorIds)
        );

        /*
         * Verify sub-distributor
         */
        $subCheckStmt = $mysqli->prepare("
            SELECT distributor_id
            FROM distributors
            WHERE distributor_id = ?
              AND distributor_type = 'sub'
            LIMIT 1
        ");

        $subCheckStmt->bind_param(
            'i',
            $subDistributorId
        );

        $subCheckStmt->execute();
        $subCheckResult = $subCheckStmt->get_result();

        if ($subCheckResult->num_rows === 0) {
            $subCheckStmt->close();

            jsonResponse([
                'success' => false,
                'message' => 'Sub-distributor not found.'
            ], 404);
        }

        $subCheckStmt->close();

        /*
         * Verify all selected main distributor IDs
         */
        $idList = implode(
            ',',
            array_map('intval', $mainDistributorIds)
        );

        $validResult = $mysqli->query("
            SELECT distributor_id
            FROM distributors
            WHERE distributor_type = 'main'
              AND status = 'active'
              AND distributor_id IN ({$idList})
        ");

        $validMainIds = [];

        while ($validRow = $validResult->fetch_assoc()) {
            $validMainIds[] = (int)$validRow['distributor_id'];
        }

        sort($mainDistributorIds);
        sort($validMainIds);

        if ($mainDistributorIds !== $validMainIds) {
            jsonResponse([
                'success' => false,
                'message' => 'One or more selected main distributors are invalid or inactive.'
            ], 422);
        }

        if (
            !in_array(
                $primaryDistributorId,
                $validMainIds,
                true
            )
        ) {
            jsonResponse([
                'success' => false,
                'message' => 'Primary main distributor is invalid.'
            ], 422);
        }

        $parentIdsJson = json_encode(
            array_values($validMainIds),
            JSON_UNESCAPED_UNICODE
        );

        $updateStmt = $mysqli->prepare("
            UPDATE distributors
            SET
                parent_distributor_id = ?,
                parent_distributor_ids = ?
            WHERE distributor_id = ?
              AND distributor_type = 'sub'
        ");

        $updateStmt->bind_param(
            'isi',
            $primaryDistributorId,
            $parentIdsJson,
            $subDistributorId
        );

        $updateStmt->execute();
        $updateStmt->close();

        jsonResponse([
            'success' => true,
            'message' => 'Main distributor assignment updated successfully.'
        ]);
    } catch (Throwable $exception) {
        jsonResponse([
            'success' => false,
            'message' => $exception->getMessage()
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| Normal page: Load active main distributors for modal
|--------------------------------------------------------------------------
*/

$mainDistributors = [];

try {
    $mainDistributorResult = $mysqli->query("
        SELECT
            distributor_id,
            distributor_code,
            distributor_name,
            status
        FROM distributors
        WHERE distributor_type = 'main'
          AND status = 'active'
        ORDER BY distributor_name ASC
    ");

    while (
        $mainDistributor =
            $mainDistributorResult->fetch_assoc()
    ) {
        $mainDistributors[] = $mainDistributor;
    }
} catch (Throwable $exception) {
    $mainDistributors = [];
}

include('include/header.php');
?>

<style>
/* ==============================================================
   Page layout
============================================================== */

.sd-page-title {
    margin: 0;
    color: #20252b;
    font-size: 28px;
    font-weight: 700;
    line-height: 1.2;
}

.sd-page-description {
    margin-top: 5px;
    color: #6c757d;
    font-size: 14px;
}

.sd-card {
    overflow: hidden;
    border: 1px solid #e5e9ed;
    border-radius: 10px;
    background: #ffffff;
    box-shadow: 0 3px 14px rgba(25, 35, 45, 0.06);
}

.sd-card-body {
    padding: 20px;
}

/* ==============================================================
   Header buttons
============================================================== */

.sd-btn-add {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 9px 18px;
    border: 1px solid #ffc107;
    border-radius: 5px;
    background: #ffc928;
    color: #20252b !important;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none !important;
    transition: 0.2s ease;
}

.sd-btn-add:hover {
    border-color: #e7ad00;
    background: #f5bc08;
    color: #15191d !important;
}

/* ==============================================================
   Search
============================================================== */

.sd-search-input {
    min-height: 42px;
    border: 1px solid #ced4da;
    border-radius: 5px;
    color: #252a30;
    background: #ffffff;
}

.sd-search-input:focus {
    border-color: #f4bc13;
    box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.13);
}

.sd-btn-clear {
    min-height: 42px;
    padding: 8px 18px;
    border: 1px solid #adb5bd;
    border-radius: 5px;
    background: #ffffff;
    color: #495057 !important;
    font-weight: 600;
}

.sd-btn-clear:hover {
    border-color: #6c757d;
    background: #f5f6f7;
    color: #212529 !important;
}

/* ==============================================================
   Table
============================================================== */

.sd-table-wrapper {
    width: 100%;
    overflow-x: auto;
    border: 1px solid #dee2e6;
    border-radius: 5px;
}

.sd-table {
    width: 100%;
    min-width: 1350px;
    margin: 0;
    border-collapse: collapse;
    background: #ffffff;
}

.sd-table thead th {
    padding: 13px 12px;
    border-right: 1px solid #dee2e6;
    border-bottom: 1px solid #dee2e6;
    background: #f5f6f7;
    color: #171a1d;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.35;
    white-space: nowrap;
}

.sd-table thead th:last-child {
    border-right: 0;
}

.sd-table tbody td {
    padding: 13px 12px;
    border-right: 1px solid #e1e5e8;
    border-bottom: 1px solid #e1e5e8;
    color: #24292f;
    font-size: 14px;
    line-height: 1.45;
    vertical-align: middle;
}

.sd-table tbody tr:last-child td {
    border-bottom: 0;
}

.sd-table tbody td:last-child {
    border-right: 0;
}

.sd-table tbody tr:hover {
    background: #fafbfc;
}

.sd-id-text {
    color: #343a40;
    font-weight: 700;
}

.sd-code-text {
    color: #343a40;
    font-weight: 600;
}

.sd-sub-name {
    color: #20252b;
    font-weight: 700;
}

.sd-mobile-link {
    color: #0969da !important;
    font-weight: 600;
    text-decoration: none !important;
}

.sd-mobile-link:hover {
    text-decoration: underline !important;
}

.sd-created-date {
    color: #495057;
    white-space: nowrap;
}

/* ==============================================================
   Assigned distributor badges
============================================================== */

.sd-assigned-cell {
    min-width: 320px;
}

.sd-badge-container {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.sd-main-badge {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 5px;
    min-height: 29px;
    padding: 5px 8px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.2;
}

.sd-main-name {
    font-weight: 700;
}

.sd-main-primary {
    border: 1px solid #0d6efd;
    background: #0d6efd;
    color: #ffffff !important;
}

.sd-main-secondary {
    border: 1px solid #cbd3da;
    background: #f2f4f6;
    color: #2f353b !important;
}

.sd-main-warning {
    border: 1px solid #f4bd16;
    background: #fff4cd;
    color: #765b00 !important;
}

.sd-main-code {
    padding-left: 5px;
    border-left: 1px solid rgba(108, 117, 125, 0.35);
    font-size: 10px;
    opacity: 0.85;
}

.sd-main-primary .sd-main-code {
    border-left-color: rgba(255, 255, 255, 0.45);
}

.sd-primary-label {
    padding: 2px 5px;
    border-radius: 3px;
    background: rgba(255, 255, 255, 0.22);
    color: #ffffff !important;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
}

.sd-inactive-label {
    padding: 2px 5px;
    border-radius: 3px;
    background: #dc3545;
    color: #ffffff !important;
    font-size: 9px;
    text-transform: uppercase;
}

.sd-not-assigned {
    display: inline-block;
    color: #dc3545 !important;
    font-size: 12px;
    font-weight: 600;
}

/* ==============================================================
   Primary distributor column
============================================================== */

.sd-primary-card {
    min-width: 155px;
}

.sd-primary-name {
    margin-bottom: 4px;
    color: #20252b !important;
    font-size: 13px;
    font-weight: 700;
}

.sd-primary-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    color: #6c757d;
    font-size: 11px;
}

.sd-primary-status-active,
.sd-primary-status-inactive {
    display: inline-flex;
    align-items: center;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 9px;
    font-weight: 700;
}

.sd-primary-status-active {
    background: #d9f5e5;
    color: #147a44 !important;
}

.sd-primary-status-inactive {
    background: #f8d7da;
    color: #9c2531 !important;
}

/* ==============================================================
   Status badge
============================================================== */

.sd-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-width: 76px;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}

.sd-status-dot {
    width: 7px;
    height: 7px;
    flex: 0 0 7px;
    border-radius: 50%;
}

.sd-status-active {
    border: 1px solid #9fd9b9;
    background: #e3f7eb;
    color: #146c3c !important;
}

.sd-status-active .sd-status-dot {
    background: #198754;
}

.sd-status-inactive {
    border: 1px solid #d1d5d8;
    background: #eef0f2;
    color: #5f676e !important;
}

.sd-status-inactive .sd-status-dot {
    background: #6c757d;
}

/* ==============================================================
   Action buttons
============================================================== */

.sd-action-buttons {
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.sd-btn-edit,
.sd-btn-assign {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
    text-decoration: none !important;
    cursor: pointer;
    transition: 0.2s ease;
}

.sd-btn-edit {
    border: 1px solid #868e96;
    background: #ffffff;
    color: #495057 !important;
}

.sd-btn-edit:hover {
    border-color: #495057;
    background: #495057;
    color: #ffffff !important;
}

.sd-btn-assign {
    border: 1px solid #e7ad00;
    background: #ffc928;
    color: #20252b !important;
}

.sd-btn-assign:hover {
    border-color: #cc9900;
    background: #efb900;
    color: #111417 !important;
}

/* ==============================================================
   Modal distributor list
============================================================== */

.sd-main-assignment-list {
    max-height: 350px;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 7px;
    background: #ffffff;
}

.sd-main-assignment-item {
    display: flex;
    align-items: center;
    gap: 11px;
    margin: 0;
    padding: 12px;
    border-bottom: 1px solid #edf0f2;
    cursor: pointer;
}

.sd-main-assignment-item:last-child {
    border-bottom: 0;
}

.sd-main-assignment-item:hover {
    background: #f8f9fa;
}

.sd-main-assignment-item .form-check-input {
    flex-shrink: 0;
    margin: 0;
}

.sd-modal-main-name {
    display: block;
    color: #252a30;
    font-size: 13px;
    font-weight: 700;
}

.sd-modal-main-code {
    display: block;
    margin-top: 2px;
    color: #6c757d;
    font-size: 11px;
}

.sd-empty-title {
    color: #495057;
    font-size: 15px;
    font-weight: 700;
}

.sd-empty-text {
    margin-top: 4px;
    color: #8a9299;
    font-size: 12px;
}

/* ==============================================================
   Mobile
============================================================== */

@media (max-width: 767px) {
    .sd-page-title {
        font-size: 23px;
    }

    .sd-header-action {
        width: 100%;
        margin-top: 12px;
    }

    .sd-btn-add {
        width: 100%;
    }

    .sd-card-body {
        padding: 13px;
    }

    .sd-table thead th,
    .sd-table tbody td {
        padding: 11px 10px;
    }
}
</style>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h4 class="sd-page-title">
                Sub-Distributors
            </h4>

            <div class="sd-page-description">
                Manage sub-distributors and assign multiple main distributors.
            </div>
        </div>

        <div class="sd-header-action">
            <a href="distributor_add.php?type=sub"
               class="sd-btn-add">
                + Add Sub-Distributor
            </a>
        </div>
    </div>

    <div class="sd-card">
        <div class="sd-card-body">

            <div class="row g-2 align-items-center mb-3">
                <div class="col-lg-6 col-md-8">
                    <input type="search"
                           id="searchInput"
                           class="form-control sd-search-input"
                           placeholder="Search code, name, contact person or mobile">
                </div>

                <div class="col-auto">
                    <button type="button"
                            id="btnClear"
                            class="sd-btn-clear">
                        Clear
                    </button>
                </div>
            </div>

            <div class="sd-table-wrapper">
                <table class="sd-table">
                    <thead>
                        <tr>
                            <th style="width:75px;">ID</th>
                            <th style="width:110px;">Code</th>
                            <th style="min-width:170px;">
                                Sub-Distributor
                            </th>
                            <th style="min-width:155px;">
                                Contact Person
                            </th>
                            <th style="width:135px;">Mobile</th>
                            <th style="min-width:320px;">
                                Assigned Main Distributors
                            </th>
                            <th style="min-width:185px;">
                                Primary Distributor
                            </th>
                            <th style="width:105px;">Status</th>
                            <th style="width:125px;">Created</th>
                            <th style="width:155px;">Actions</th>
                        </tr>
                    </thead>

                    <tbody id="listBody">
                        <tr>
                            <td colspan="10"
                                class="text-center py-5">
                                Loading...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center border-top pt-3 mt-3">
                <div id="resultMeta"
                     class="small text-muted">
                    &nbsp;
                </div>

                <nav>
                    <ul class="pagination pagination-sm mb-0"
                        id="pageNav">
                    </ul>
                </nav>
            </div>

        </div>
    </div>
</div>

<!-- Assignment modal -->
<div class="modal fade"
     id="assignmentModal"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1">
                        Assign Main Distributors
                    </h5>

                    <div class="small text-muted"
                         id="selectedSubDistributorName">
                    </div>
                </div>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close">
                </button>
            </div>

            <div class="modal-body">

                <input type="hidden"
                       id="subDistributorId">

                <div class="mb-3">
                    <label for="mainDistributorSearch"
                           class="form-label">
                        Search Main Distributor
                    </label>

                    <input type="search"
                           id="mainDistributorSearch"
                           class="form-control"
                           placeholder="Search by name or code">
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0">
                        Select Main Distributors
                        <span class="text-danger">*</span>
                    </label>

                    <button type="button"
                            class="btn btn-sm btn-link text-decoration-none"
                            id="btnClearSelection">
                        Clear selection
                    </button>
                </div>

                <div class="sd-main-assignment-list"
                     id="mainDistributorList">

                    <?php if (empty($mainDistributors)): ?>

                        <div class="text-center text-muted py-4">
                            No active main distributors found.
                        </div>

                    <?php else: ?>

                        <?php foreach ($mainDistributors as $mainDistributor): ?>
                            <?php
                            $mainId = (int)$mainDistributor['distributor_id'];

                            $mainSearchText = strtolower(
                                ($mainDistributor['distributor_code'] ?? '') .
                                ' ' .
                                ($mainDistributor['distributor_name'] ?? '')
                            );
                            ?>

                            <label class="sd-main-assignment-item"
                                   data-search="<?= e($mainSearchText) ?>">

                                <input type="checkbox"
                                       class="form-check-input main-distributor-check"
                                       value="<?= $mainId ?>"
                                       data-name="<?= e($mainDistributor['distributor_name']) ?>">

                                <span>
                                    <span class="sd-modal-main-name">
                                        <?= e($mainDistributor['distributor_name']) ?>
                                    </span>

                                    <span class="sd-modal-main-code">
                                        Code:
                                        <?= e($mainDistributor['distributor_code'] ?: '-') ?>

                                        &nbsp;|&nbsp;

                                        ID: <?= $mainId ?>
                                    </span>
                                </span>
                            </label>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

                <div class="mt-3">
                    <label for="primaryDistributorSelect"
                           class="form-label">
                        Primary Main Distributor
                        <span class="text-danger">*</span>
                    </label>

                    <select id="primaryDistributorSelect"
                            class="form-select">
                        <option value="">
                            Select main distributors first
                        </option>
                    </select>

                    <div class="form-text">
                        Primary distributor ID
                        <code>parent_distributor_id</code>
                        में save होगी।
                    </div>
                </div>

                <div id="assignmentError"
                     class="alert alert-danger d-none mt-3 mb-0">
                </div>

            </div>

            <div class="modal-footer">
                <button type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">
                    Close
                </button>

                <button type="button"
                        class="btn btn-warning fw-bold"
                        id="btnSaveAssignment">
                    Save Assignment
                </button>
            </div>

        </div>
    </div>
</div>

<!-- Toast -->
<div class="position-fixed top-0 start-50 translate-middle-x p-3"
     style="z-index:1080;">

    <div id="liveToast"
         class="toast align-items-center border-0"
         role="alert"
         aria-live="assertive"
         aria-atomic="true">

        <div class="d-flex">
            <div class="toast-body"
                 id="toastMessage">
                Done.
            </div>

            <button type="button"
                    class="btn-close me-2 m-auto"
                    data-bs-dismiss="toast"
                    aria-label="Close">
            </button>
        </div>

    </div>
</div>

<script>
(() => {
    'use strict';

    const endpoint = <?= json_encode(
        basename($_SERVER['PHP_SELF']),
        JSON_UNESCAPED_SLASHES
    ) ?>;

    const csrfToken = <?= json_encode(
        $_SESSION['sub_distributor_csrf'],
        JSON_UNESCAPED_SLASHES
    ) ?>;

    const tbody = document.getElementById('listBody');
    const pageNav = document.getElementById('pageNav');
    const resultMeta = document.getElementById('resultMeta');
    const searchInput = document.getElementById('searchInput');
    const btnClear = document.getElementById('btnClear');

    const modalElement =
        document.getElementById('assignmentModal');

    const subDistributorIdInput =
        document.getElementById('subDistributorId');

    const selectedSubDistributorName =
        document.getElementById('selectedSubDistributorName');

    const primaryDistributorSelect =
        document.getElementById('primaryDistributorSelect');

    const mainDistributorSearch =
        document.getElementById('mainDistributorSearch');

    const btnClearSelection =
        document.getElementById('btnClearSelection');

    const btnSaveAssignment =
        document.getElementById('btnSaveAssignment');

    const assignmentError =
        document.getElementById('assignmentError');

    const toastElement =
        document.getElementById('liveToast');

    const toastMessage =
        document.getElementById('toastMessage');

    let assignmentModal;
    let toast;
    let currentPage = 1;

    document.addEventListener('DOMContentLoaded', () => {
        assignmentModal = new bootstrap.Modal(
            modalElement
        );

        toast = new bootstrap.Toast(
            toastElement,
            {
                delay: 2500
            }
        );

        loadPage(1);
    });

    function showToast(message, type = 'success') {
        toastElement.className =
            'toast align-items-center border-0';

        if (type === 'success') {
            toastElement.style.background = '#dff4e8';
            toastElement.style.color = '#146c3c';
        } else if (type === 'danger') {
            toastElement.style.background = '#f8d7da';
            toastElement.style.color = '#842029';
        } else {
            toastElement.style.background = '#e2e3e5';
            toastElement.style.color = '#41464b';
        }

        toastMessage.textContent = message;
        toast.show();
    }

    function showAssignmentError(message) {
        assignmentError.textContent = message;
        assignmentError.classList.remove('d-none');
    }

    function clearAssignmentError() {
        assignmentError.textContent = '';
        assignmentError.classList.add('d-none');
    }

    function debounce(callback, delay = 400) {
        let timer;

        return (...args) => {
            clearTimeout(timer);

            timer = setTimeout(() => {
                callback(...args);
            }, delay);
        };
    }

    const runSearch = debounce(() => {
        loadPage(1);
    }, 400);

    searchInput.addEventListener(
        'input',
        runSearch
    );

    btnClear.addEventListener('click', () => {
        searchInput.value = '';
        searchInput.focus();
        loadPage(1);
    });

    async function loadPage(page = 1) {
        currentPage = page;

        tbody.innerHTML = `
            <tr>
                <td colspan="10"
                    class="text-center py-5">

                    <span class="spinner-border spinner-border-sm me-2"
                          role="status"></span>

                    Loading...
                </td>
            </tr>
        `;

        try {
            const params = new URLSearchParams({
                ajax: 'list',
                page: String(page),
                q: searchInput.value.trim()
            });

            const response = await fetch(
                `${endpoint}?${params.toString()}`,
                {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                }
            );

            const responseText = await response.text();

            let data;

            try {
                data = JSON.parse(responseText);
            } catch (error) {
                console.error(
                    'Invalid JSON:',
                    responseText
                );

                throw new Error(
                    'Server returned invalid JSON. Check PHP error log.'
                );
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message ||
                    'Unable to load sub-distributors.'
                );
            }

            tbody.innerHTML =
                data.rows_html || '';

            pageNav.innerHTML =
                data.pagination_html || '';

            resultMeta.textContent =
                data.meta || '';

            currentPage = Number(
                data.current_page || page
            );
        } catch (error) {
            console.error(error);

            tbody.innerHTML = `
                <tr>
                    <td colspan="10"
                        class="text-center text-danger py-5">
                        ${escapeHtml(error.message)}
                    </td>
                </tr>
            `;

            pageNav.innerHTML = '';
            resultMeta.textContent = '';

            showToast(
                error.message,
                'danger'
            );
        }
    }

    pageNav.addEventListener('click', event => {
        const pageLink = event.target.closest(
            'a.page-link[data-page]'
        );

        if (!pageLink) {
            return;
        }

        event.preventDefault();

        const pageItem = pageLink.closest(
            '.page-item'
        );

        if (
            pageItem &&
            (
                pageItem.classList.contains('disabled') ||
                pageItem.classList.contains('active')
            )
        ) {
            return;
        }

        const page = parseInt(
            pageLink.dataset.page,
            10
        ) || 1;

        loadPage(page);
    });

    tbody.addEventListener('click', event => {
        const assignButton = event.target.closest(
            '.btn-assign'
        );

        if (!assignButton) {
            return;
        }

        openAssignmentModal(assignButton);
    });

    function openAssignmentModal(button) {
        clearAssignmentError();

        const subDistributorId =
            button.dataset.id;

        const subDistributorName =
            button.dataset.name || '';

        const primaryDistributorId =
            String(button.dataset.primary || '');

        let selectedIds = [];

        try {
            selectedIds = JSON.parse(
                button.dataset.selected || '[]'
            ).map(String);
        } catch (error) {
            console.error(error);
            selectedIds = [];
        }

        subDistributorIdInput.value =
            subDistributorId;

        selectedSubDistributorName.textContent =
            `Sub-Distributor: ${subDistributorName}`;

        document
            .querySelectorAll(
                '.main-distributor-check'
            )
            .forEach(checkbox => {
                checkbox.checked =
                    selectedIds.includes(
                        String(checkbox.value)
                    );
            });

        mainDistributorSearch.value = '';

        document
            .querySelectorAll(
                '.sd-main-assignment-item'
            )
            .forEach(item => {
                item.classList.remove('d-none');
            });

        syncPrimaryDistributorOptions(
            primaryDistributorId
        );

        assignmentModal.show();
    }

    function syncPrimaryDistributorOptions(
        preferredValue = ''
    ) {
        const currentValue =
            preferredValue ||
            primaryDistributorSelect.value;

        const selectedCheckboxes = Array.from(
            document.querySelectorAll(
                '.main-distributor-check:checked'
            )
        );

        primaryDistributorSelect.innerHTML = '';

        if (selectedCheckboxes.length === 0) {
            const option =
                document.createElement('option');

            option.value = '';
            option.textContent =
                'Select main distributors first';

            primaryDistributorSelect.appendChild(
                option
            );

            return;
        }

        const placeholder =
            document.createElement('option');

        placeholder.value = '';
        placeholder.textContent =
            'Select primary main distributor';

        primaryDistributorSelect.appendChild(
            placeholder
        );

        selectedCheckboxes.forEach(checkbox => {
            const option =
                document.createElement('option');

            option.value = checkbox.value;

            option.textContent =
                checkbox.dataset.name ||
                `Distributor #${checkbox.value}`;

            primaryDistributorSelect.appendChild(
                option
            );
        });

        const exists = selectedCheckboxes.some(
            checkbox =>
                String(checkbox.value) ===
                String(currentValue)
        );

        if (exists) {
            primaryDistributorSelect.value =
                String(currentValue);
        } else if (selectedCheckboxes.length === 1) {
            primaryDistributorSelect.value =
                selectedCheckboxes[0].value;
        }
    }

    document
        .querySelectorAll(
            '.main-distributor-check'
        )
        .forEach(checkbox => {
            checkbox.addEventListener(
                'change',
                () => {
                    clearAssignmentError();
                    syncPrimaryDistributorOptions();
                }
            );
        });

    mainDistributorSearch.addEventListener(
        'input',
        () => {
            const searchValue =
                mainDistributorSearch.value
                    .trim()
                    .toLowerCase();

            document
                .querySelectorAll(
                    '.sd-main-assignment-item'
                )
                .forEach(item => {
                    const searchText =
                        item.dataset.search || '';

                    item.classList.toggle(
                        'd-none',
                        searchValue !== '' &&
                        !searchText.includes(
                            searchValue
                        )
                    );
                });
        }
    );

    btnClearSelection.addEventListener(
        'click',
        () => {
            document
                .querySelectorAll(
                    '.main-distributor-check'
                )
                .forEach(checkbox => {
                    checkbox.checked = false;
                });

            syncPrimaryDistributorOptions();
            clearAssignmentError();
        }
    );

    btnSaveAssignment.addEventListener(
        'click',
        async () => {
            clearAssignmentError();

            const subDistributorId =
                subDistributorIdInput.value;

            const selectedIds = Array.from(
                document.querySelectorAll(
                    '.main-distributor-check:checked'
                )
            ).map(
                checkbox => checkbox.value
            );

            const primaryDistributorId =
                primaryDistributorSelect.value;

            if (!subDistributorId) {
                showAssignmentError(
                    'Invalid sub-distributor.'
                );

                return;
            }

            if (selectedIds.length === 0) {
                showAssignmentError(
                    'Select at least one main distributor.'
                );

                return;
            }

            if (!primaryDistributorId) {
                showAssignmentError(
                    'Select primary main distributor.'
                );

                return;
            }

            const originalButtonHtml =
                btnSaveAssignment.innerHTML;

            btnSaveAssignment.disabled = true;

            btnSaveAssignment.innerHTML = `
                <span class="spinner-border spinner-border-sm me-1"
                      role="status"></span>
                Saving...
            `;

            try {
                const formData = new FormData();

                formData.append(
                    'ajax',
                    'save_assignment'
                );

                formData.append(
                    'csrf_token',
                    csrfToken
                );

                formData.append(
                    'sub_distributor_id',
                    subDistributorId
                );

                formData.append(
                    'primary_distributor_id',
                    primaryDistributorId
                );

                selectedIds.forEach(id => {
                    formData.append(
                        'main_distributor_ids[]',
                        id
                    );
                });

                const response = await fetch(
                    endpoint,
                    {
                        method: 'POST',
                        headers: {
                            'X-Requested-With':
                                'XMLHttpRequest',
                            'Accept':
                                'application/json'
                        },
                        body: formData
                    }
                );

                const responseText =
                    await response.text();

                let data;

                try {
                    data = JSON.parse(
                        responseText
                    );
                } catch (error) {
                    console.error(
                        'Invalid JSON:',
                        responseText
                    );

                    throw new Error(
                        'Server returned invalid JSON. Check PHP error log.'
                    );
                }

                if (
                    !response.ok ||
                    !data.success
                ) {
                    throw new Error(
                        data.message ||
                        'Assignment could not be saved.'
                    );
                }

                assignmentModal.hide();

                showToast(
                    data.message ||
                    'Assignment updated successfully.',
                    'success'
                );

                await loadPage(currentPage);
            } catch (error) {
                console.error(error);
                showAssignmentError(error.message);
            } finally {
                btnSaveAssignment.disabled = false;
                btnSaveAssignment.innerHTML =
                    originalButtonHtml;
            }
        }
    );

    function escapeHtml(value) {
        const element =
            document.createElement('div');

        element.textContent = value;

        return element.innerHTML;
    }
})();
</script>

<?php include('include/footer.php'); ?>
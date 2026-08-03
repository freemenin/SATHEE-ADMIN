<?php
// distributor_edit.php

require_once 'include/require_permission.php';
requirePermission('DISTRIBUTORS', 'edit');
include('include/require_login.php');
include('include/db.php');
include('include/header.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Safe schema check
|--------------------------------------------------------------------------
*/
function distributor_column_exists(mysqli $mysqli, string $column): bool
{
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM distributors LIKE '{$column}'");
    return ($res && $res->num_rows > 0);
}

if (!distributor_column_exists($mysqli, 'distributor_type')) {
    @$mysqli->query("ALTER TABLE distributors ADD COLUMN distributor_type ENUM('main','sub') NOT NULL DEFAULT 'main' AFTER distributor_code");
}

if (!distributor_column_exists($mysqli, 'parent_distributor_id')) {
    @$mysqli->query("ALTER TABLE distributors ADD COLUMN parent_distributor_id INT(11) NULL AFTER distributor_type");
}

if (!distributor_column_exists($mysqli, 'status')) {
    @$mysqli->query("ALTER TABLE distributors ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER parent_distributor_id");
}

$idx = $mysqli->query("SHOW INDEX FROM distributors WHERE Key_name = 'idx_parent_distributor_id'");
if (!$idx || $idx->num_rows === 0) {
    @$mysqli->query("ALTER TABLE distributors ADD INDEX idx_parent_distributor_id (parent_distributor_id)");
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Validate ID
|--------------------------------------------------------------------------
*/
$distributor_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($distributor_id <= 0) {
    echo "<div class='container py-4'><div class='alert alert-danger'>Invalid distributor ID.</div></div>";
    include('include/footer.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch distributor
|--------------------------------------------------------------------------
*/
$stmt = $mysqli->prepare("
    SELECT 
        distributor_id,
        distributor_code,
        distributor_type,
        parent_distributor_id,
        status,
        distributor_name,
        contact_person,
        mobile_number,
        email,
        address,
        gstin,
        notes,
        pincode
    FROM distributors
    WHERE distributor_id = ?
    LIMIT 1
");

if (!$stmt) {
    echo "<div class='container py-4'><div class='alert alert-danger'>Database error: " . htmlspecialchars($mysqli->error) . "</div></div>";
    include('include/footer.php');
    exit;
}

$stmt->bind_param("i", $distributor_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$row = $res->fetch_assoc()) {
    echo "<div class='container py-4'><div class='alert alert-warning'>Distributor not found.</div></div>";
    include('include/footer.php');
    exit;
}

$stmt->close();

$currentType = $row['distributor_type'] ?: 'main';
$currentParentId = (int)($row['parent_distributor_id'] ?? 0);
$currentStatus = $row['status'] ?: 'active';

/*
|--------------------------------------------------------------------------
| Fetch main distributors for parent dropdown
|--------------------------------------------------------------------------
| Current distributor is excluded, so it cannot become its own parent.
|--------------------------------------------------------------------------
*/
$mainDistributors = [];

$stmt = $mysqli->prepare("
    SELECT distributor_id, distributor_code, distributor_name, mobile_number
    FROM distributors
    WHERE distributor_type = 'main'
      AND distributor_id <> ?
    ORDER BY distributor_name ASC
");

if ($stmt) {
    $stmt->bind_param("i", $distributor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($d = $result->fetch_assoc()) {
        $mainDistributors[] = $d;
    }

    $stmt->close();
}
?>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0">Edit Distributor / Sub Distributor</h3>
            <div class="text-muted small">
                Mobile number and 4 digit PIN are used for login.
            </div>
        </div>

        <a href="distributor_list.php" class="btn btn-secondary">
            ← Back to List
        </a>
    </div>

    <div id="resp" class="my-2" style="display:none;"></div>

    <div class="card shadow-sm">
        <div class="card-body">

            <form id="editForm" autocomplete="off" novalidate>

                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="distributor_id" value="<?php echo (int)$row['distributor_id']; ?>">

                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">
                            Distributor Type <span class="text-danger">*</span>
                        </label>

                        <select name="distributor_type" id="distributor_type" class="form-select" required>
                            <option value="main" <?php echo ($currentType === 'main') ? 'selected' : ''; ?>>
                                Main Distributor
                            </option>
                            <option value="sub" <?php echo ($currentType === 'sub') ? 'selected' : ''; ?>>
                                Sub Distributor
                            </option>
                        </select>

                        <div class="form-text">
                            If sub-distributor is selected, parent main distributor is required.
                        </div>
                    </div>

                    <div class="col-md-4" id="parentDistributorBox" style="display:none;">
                        <label class="form-label">
                            Parent Main Distributor <span class="text-danger">*</span>
                        </label>

                        <select name="parent_distributor_id" id="parent_distributor_id" class="form-select">
                            <option value="">Select Main Distributor</option>

                            <?php foreach ($mainDistributors as $d): ?>
                                <option 
                                    value="<?php echo (int)$d['distributor_id']; ?>"
                                    <?php echo ((int)$d['distributor_id'] === $currentParentId) ? 'selected' : ''; ?>
                                >
                                    <?php
                                    echo htmlspecialchars(
                                        ($d['distributor_name'] ?? '') .
                                        ' - ' .
                                        ($d['mobile_number'] ?? '') .
                                        ' - ' .
                                        ($d['distributor_code'] ?? '')
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="form-text">
                            Sub-distributor will see this main distributor's data after login.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            Status <span class="text-danger">*</span>
                        </label>

                        <select name="status" class="form-select" required>
                            <option value="active" <?php echo ($currentStatus === 'active') ? 'selected' : ''; ?>>
                                Active
                            </option>
                            <option value="inactive" <?php echo ($currentStatus === 'inactive') ? 'selected' : ''; ?>>
                                Inactive
                            </option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Distributor Code</label>

                        <input
                            type="text"
                            name="distributor_code"
                            id="distributor_code"
                            class="form-control"
                            value="<?php echo htmlspecialchars($row['distributor_code'] ?? ''); ?>"
                            readonly
                        >

                        <div class="form-text">
                            Main: D series, Sub: SDB series.
                        </div>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label">
                            Distributor Name <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            name="distributor_name"
                            required
                            class="form-control"
                            value="<?php echo htmlspecialchars($row['distributor_name'] ?? ''); ?>"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Contact Person</label>

                        <input
                            type="text"
                            name="contact_person"
                            class="form-control"
                            value="<?php echo htmlspecialchars($row['contact_person'] ?? ''); ?>"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">
                            Mobile Number <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            name="mobile_number"
                            required
                            class="form-control"
                            maxlength="10"
                            inputmode="numeric"
                            value="<?php echo htmlspecialchars($row['mobile_number'] ?? ''); ?>"
                        >

                        <div class="form-text">
                            Login mobile number. Must be 10 digits.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Email</label>

                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            value="<?php echo htmlspecialchars($row['email'] ?? ''); ?>"
                        >
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Pincode</label>

                        <input
                            type="text"
                            name="pincode"
                            class="form-control"
                            maxlength="10"
                            inputmode="numeric"
                            value="<?php echo htmlspecialchars($row['pincode'] ?? ''); ?>"
                        >
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">Address</label>

                        <textarea name="address" rows="2" class="form-control"><?php
                            echo htmlspecialchars($row['address'] ?? '');
                        ?></textarea>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">GSTIN</label>

                        <input
                            type="text"
                            name="gstin"
                            class="form-control"
                            maxlength="15"
                            value="<?php echo htmlspecialchars($row['gstin'] ?? ''); ?>"
                        >
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">Notes</label>

                        <textarea name="notes" rows="2" class="form-control"><?php
                            echo htmlspecialchars($row['notes'] ?? '');
                        ?></textarea>
                    </div>

                    <hr class="mt-2 mb-0">

                    <div class="col-md-4 mt-3">
                        <label class="form-label">New 4-digit Login PIN</label>

                        <input
                            type="password"
                            name="new_pin"
                            class="form-control"
                            inputmode="numeric"
                            pattern="\d{4}"
                            maxlength="4"
                            placeholder="••••"
                        >

                        <div class="form-text">
                            Leave blank to keep existing PIN.
                        </div>
                    </div>

                    <div class="col-md-4 mt-3">
                        <label class="form-label">Confirm PIN</label>

                        <input
                            type="password"
                            name="confirm_pin"
                            class="form-control"
                            inputmode="numeric"
                            pattern="\d{4}"
                            maxlength="4"
                            placeholder="••••"
                        >
                    </div>

                    <div class="col-12 mt-3">
                        <div class="alert alert-info mb-0">
                            <strong>Important:</strong>
                            If you change a main distributor to sub-distributor, you must select a parent main distributor.
                            If you change a sub-distributor to main distributor, its parent will be removed automatically.
                        </div>
                    </div>

                    <div class="col-12 mt-4">
                        <button type="submit" class="btn btn-primary" id="btnSave">
                            Save Changes
                        </button>

                        <button type="reset" class="btn btn-outline-secondary ms-2" id="btnReset">
                            Reset
                        </button>
                    </div>

                </div>

            </form>

        </div>
    </div>

</div>

<script>
const originalType = <?php echo json_encode($currentType); ?>;
const originalCode = <?php echo json_encode($row['distributor_code'] ?? ''); ?>;

const typeSelect = document.getElementById('distributor_type');
const parentBox = document.getElementById('parentDistributorBox');
const parentSelect = document.getElementById('parent_distributor_id');
const codeInput = document.getElementById('distributor_code');

function toggleDistributorType() {
    const type = typeSelect.value;

    if (type === 'sub') {
        parentBox.style.display = '';
        parentSelect.setAttribute('required', 'required');
    } else {
        parentBox.style.display = 'none';
        parentSelect.removeAttribute('required');
        parentSelect.value = '';
    }

    /*
    |--------------------------------------------------------------------------
    | Code field rule
    |--------------------------------------------------------------------------
    | Existing code is kept readonly.
    | If type changes, update_ajax will regenerate correct code.
    |--------------------------------------------------------------------------
    */
    if (type !== originalType) {
        codeInput.value = 'Will auto-generate on save';
    } else {
        codeInput.value = originalCode;
    }
}

typeSelect.addEventListener('change', toggleDistributorType);

document.addEventListener('DOMContentLoaded', toggleDistributorType);

document.getElementById('btnReset').addEventListener('click', function () {
    setTimeout(toggleDistributorType, 0);
});

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const form = e.target;

    toggleDistributorType();

    const type = form.distributor_type.value.trim();
    const parentId = form.parent_distributor_id.value.trim();

    if (type === 'sub' && parentId === '') {
        return showResp('Please select parent main distributor.', 'danger');
    }

    const mobile = form.mobile_number.value.replace(/\D+/g, '');

    if (!/^[0-9]{10}$/.test(mobile)) {
        return showResp('Mobile number must be exactly 10 digits.', 'danger');
    }

    const pin = form.new_pin.value.trim();
    const pin2 = form.confirm_pin.value.trim();

    if (pin || pin2) {
        if (!/^\d{4}$/.test(pin)) {
            return showResp('PIN must be exactly 4 digits.', 'danger');
        }

        if (pin !== pin2) {
            return showResp('PIN and Confirm PIN do not match.', 'danger');
        }
    }

    const btn = document.getElementById('btnSave');
    const oldText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = 'Saving...';

    const data = new FormData(form);

    try {
        const res = await fetch('distributor_update_ajax.php', {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const ct = res.headers.get('content-type') || '';

        if (ct.includes('application/json')) {
            const json = await res.json();

            showResp(
                json.message || (json.success ? 'Updated successfully.' : 'Update failed.'),
                json.success ? 'success' : 'danger'
            );

            if (json.success && json.distributor_code) {
                codeInput.value = json.distributor_code;
            }

        } else {
            const txt = await res.text();
            showResp('Server response: ' + txt.slice(0, 500), res.ok ? 'warning' : 'danger');
        }

    } catch (err) {
        showResp('Network/JS error: ' + (err.message || err), 'danger');

    } finally {
        btn.disabled = false;
        btn.innerHTML = oldText;
    }
});

function showResp(msg, type) {
    const el = document.getElementById('resp');

    el.className = '';
    el.classList.add('alert', `alert-${type}`);
    el.textContent = msg;
    el.style.display = 'block';

    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}
</script>

<?php include('include/footer.php'); ?>
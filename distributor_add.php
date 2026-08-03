<?php
// FILE: distributor_add.php

require_once 'include/require_permission.php';
requirePermission('DISTRIBUTORS', 'add');
include('include/require_login.php');
include('include/header.php');

if (!isset($mysqli)) {
    include('include/db.php');
}

/*
|--------------------------------------------------------------------------
| Safe column check
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
| Code preview generator
|--------------------------------------------------------------------------
| Main Distributor: D1000, D1001...
| Sub Distributor:  SD100, SD101...
|--------------------------------------------------------------------------
*/
function get_next_distributor_code_preview(mysqli $mysqli, string $type = 'main'): string
{
    if ($type === 'sub') {
        $prefix = 'SD';
        $base = 100;
        $regex = '^SD[0-9]+$';
        $substringStart = 4; // SD + number, MySQL SUBSTRING starts from 1
    } else {
        $prefix = 'D';
        $base = 1000;
        $regex = '^D[0-9]+$';
        $substringStart = 2; // D + number
    }

    $stmt = $mysqli->prepare("
        SELECT distributor_code
        FROM distributors
        WHERE distributor_code REGEXP ?
        ORDER BY CAST(SUBSTRING(distributor_code, ?) AS UNSIGNED) DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return $prefix . $base;
    }

    $stmt->bind_param('si', $regex, $substringStart);
    $stmt->execute();

    $res = $stmt->get_result();
    $next = $base;

    if ($res && $row = $res->fetch_assoc()) {
        $code = (string)$row['distributor_code'];

        if ($type === 'sub') {
            if (preg_match('/^SD(\d+)$/', $code, $m)) {
                $next = max($base, (int)$m[1] + 1);
            }
        } else {
            if (preg_match('/^D(\d+)$/', $code, $m)) {
                $next = max($base, (int)$m[1] + 1);
            }
        }
    }

    $stmt->close();

    return $prefix . $next;
}

$preview_main_code = get_next_distributor_code_preview($mysqli, 'main');
$preview_sub_code  = get_next_distributor_code_preview($mysqli, 'sub');

/*
|--------------------------------------------------------------------------
| Parent main distributor dropdown
|--------------------------------------------------------------------------
*/
$mainDistributors = [];

$stmt = $mysqli->prepare("
    SELECT distributor_id, distributor_code, distributor_name, mobile_number
    FROM distributors
    WHERE distributor_type = 'main'
    ORDER BY distributor_name ASC
");

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $mainDistributors[] = $row;
    }

    $stmt->close();
}
?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Add Distributor / Sub Distributor</h4>
            <div class="text-muted small">
                Main distributor and sub-distributor both login with mobile number and 4 digit PIN.
            </div>
        </div>

        <a href="distributor_list.php" class="btn btn-outline-secondary">
            Back to List
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">

            <form id="distributorForm" class="row g-3" autocomplete="off" novalidate>

                <div class="col-md-4">
                    <label class="form-label">
                        Distributor Type <span class="text-danger">*</span>
                    </label>

                    <select name="distributor_type" id="distributor_type" class="form-select" required>
                        <option value="main" selected>Main Distributor</option>
                        <option value="sub">Sub Distributor</option>
                    </select>
                </div>

                <div class="col-md-4" id="parentDistributorBox" style="display:none;">
                    <label class="form-label">
                        Parent Main Distributor <span class="text-danger">*</span>
                    </label>

                    <select name="parent_distributor_id" id="parent_distributor_id" class="form-select">
                        <option value="">Select Main Distributor</option>

                        <?php foreach ($mainDistributors as $d): ?>
                            <option value="<?php echo (int)$d['distributor_id']; ?>">
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
                        Sub-distributor will be added under this main distributor.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Distributor Code</label>

                    <input
                        type="text"
                        class="form-control"
                        name="distributor_code"
                        id="distributor_code"
                        value="<?php echo htmlspecialchars($preview_main_code); ?>"
                        readonly
                    >

                    <div class="form-text">
                        Main: D1000 series, Sub: SD100 series.
                    </div>
                </div>

                <div class="col-md-5">
                    <label class="form-label">
                        Distributor Name <span class="text-danger">*</span>
                    </label>

                    <input
                        type="text"
                        class="form-control"
                        name="distributor_name"
                        required
                        placeholder="Enter distributor name"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label">Contact Person</label>

                    <input
                        type="text"
                        class="form-control"
                        name="contact_person"
                        placeholder="Optional"
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label">
                        Mobile Number <span class="text-danger">*</span>
                    </label>

                    <input
                        type="tel"
                        class="form-control"
                        name="mobile_number"
                        inputmode="numeric"
                        pattern="[0-9]{10}"
                        maxlength="10"
                        placeholder="10-digit mobile"
                        required
                    >

                    <div class="form-text">
                        Used for distributor panel login.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Email</label>

                    <input
                        type="email"
                        class="form-control"
                        name="email"
                        placeholder="Optional"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label">GSTIN</label>

                    <input
                        type="text"
                        class="form-control"
                        name="gstin"
                        maxlength="15"
                        placeholder="Optional"
                    >
                </div>

                <div class="col-md-4">
                    <label class="form-label">Pincode</label>

                    <input
                        type="text"
                        class="form-control"
                        name="pincode"
                        maxlength="10"
                        placeholder="Optional"
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label">
                        Login PIN - 4 Digits <span class="text-danger">*</span>
                    </label>

                    <input
                        type="password"
                        class="form-control"
                        name="pin"
                        inputmode="numeric"
                        pattern="[0-9]{4}"
                        maxlength="4"
                        placeholder="••••"
                        required
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label">
                        Confirm PIN <span class="text-danger">*</span>
                    </label>

                    <input
                        type="password"
                        class="form-control"
                        name="pin_confirm"
                        inputmode="numeric"
                        pattern="[0-9]{4}"
                        maxlength="4"
                        placeholder="••••"
                        required
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label">
                        Status <span class="text-danger">*</span>
                    </label>

                    <select name="status" class="form-select" required>
                        <option value="active" selected>Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Address</label>

                    <textarea
                        class="form-control"
                        name="address"
                        rows="2"
                        placeholder="Optional"
                    ></textarea>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Notes</label>

                    <textarea
                        class="form-control"
                        name="notes"
                        rows="2"
                        placeholder="Optional"
                    ></textarea>
                </div>

                <div class="col-12">
                    <div class="alert alert-info mb-0">
                        <strong>Code Rule:</strong>
                        Main distributor code will be like <strong>D1000</strong>.
                        Sub-distributor code will be like <strong>SD100</strong>.
                    </div>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary" id="btnSave">
                        Save Distributor
                    </button>

                    <button type="reset" class="btn btn-light border" id="btnReset">
                        Reset
                    </button>
                </div>

            </form>

        </div>
    </div>

</div>

<div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 1080">
    <div
        id="liveToast"
        class="toast align-items-center text-bg-primary border-0"
        role="alert"
        aria-live="assertive"
        aria-atomic="true"
    >
        <div class="d-flex">
            <div class="toast-body" id="toastMessage">
                Saved successfully.
            </div>

            <button
                type="button"
                class="btn-close btn-close-white me-2 m-auto"
                data-bs-dismiss="toast"
                aria-label="Close"
            ></button>
        </div>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('distributorForm');
    const btn = document.getElementById('btnSave');
    const btnReset = document.getElementById('btnReset');

    const typeSelect = document.getElementById('distributor_type');
    const parentBox = document.getElementById('parentDistributorBox');
    const parentSelect = document.getElementById('parent_distributor_id');
    const codeInput = document.getElementById('distributor_code');

    const toastEl = document.getElementById('liveToast');
    const toastMsg = document.getElementById('toastMessage');

    let previewMainCode = <?php echo json_encode($preview_main_code); ?>;
    let previewSubCode  = <?php echo json_encode($preview_sub_code); ?>;

    let bsToast;

    document.addEventListener('DOMContentLoaded', () => {
        bsToast = new bootstrap.Toast(toastEl, { delay: 2500 });
        toggleDistributorType();
    });

    function showToast(message, variant = 'primary') {
        toastEl.className = `toast align-items-center text-bg-${variant} border-0`;
        toastMsg.textContent = message;

        if (!bsToast) {
            bsToast = new bootstrap.Toast(toastEl, { delay: 2500 });
        }

        bsToast.show();
    }

    function toggleDistributorType() {
        const type = typeSelect.value;

        if (type === 'sub') {
            parentBox.style.display = '';
            parentSelect.setAttribute('required', 'required');
            codeInput.value = previewSubCode;
        } else {
            parentBox.style.display = 'none';
            parentSelect.removeAttribute('required');
            parentSelect.value = '';
            codeInput.value = previewMainCode;
        }
    }

    typeSelect.addEventListener('change', toggleDistributorType);

    btnReset.addEventListener('click', () => {
        setTimeout(() => {
            typeSelect.value = 'main';
            toggleDistributorType();
            form.classList.remove('was-validated');
        }, 0);
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        toggleDistributorType();

        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            showToast('Please fill required fields correctly.', 'warning');
            return;
        }

        const type = typeSelect.value;
        const parentId = parentSelect.value.trim();

        if (type === 'sub' && parentId === '') {
            showToast('Please select parent main distributor.', 'warning');
            return;
        }

        const mobile = form.querySelector('[name="mobile_number"]').value.trim();

        if (!/^[0-9]{10}$/.test(mobile)) {
            showToast('Mobile number must be exactly 10 digits.', 'warning');
            return;
        }

        const pin = form.querySelector('[name="pin"]').value.trim();
        const pin2 = form.querySelector('[name="pin_confirm"]').value.trim();

        if (!/^[0-9]{4}$/.test(pin)) {
            showToast('PIN must be exactly 4 digits.', 'warning');
            return;
        }

        if (pin !== pin2) {
            showToast('PIN and Confirm PIN do not match.', 'warning');
            return;
        }

        btn.disabled = true;
        const oldBtnText = btn.innerHTML;
        btn.innerHTML = 'Saving...';

        const fd = new FormData(form);

        try {
            const res = await fetch('distributor_save_ajax.php', {
                method: 'POST',
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await res.json();

            if (res.ok && data.success) {
                showToast(data.message || 'Distributor saved successfully.', 'success');

                if (data.next_main_code) {
                    previewMainCode = data.next_main_code;
                }

                if (data.next_sub_code) {
                    previewSubCode = data.next_sub_code;
                }

                const keepType = type;
                const keepParent = parentId;

                form.reset();
                form.classList.remove('was-validated');

                typeSelect.value = keepType;

                if (keepType === 'sub') {
                    parentSelect.value = keepParent;
                }

                toggleDistributorType();

            } else {
                showToast(data.message || 'Failed to save distributor.', 'danger');
            }

        } catch (err) {
            console.error(err);
            showToast('Network error. Please try again.', 'danger');

        } finally {
            btn.disabled = false;
            btn.innerHTML = oldBtnText;
        }
    });
})();
</script>

<?php include('include/footer.php'); ?>
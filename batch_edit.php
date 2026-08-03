<?php
require_once 'include/require_permission.php';
requirePermission('BATCHES', 'edit');
include('include/require_login.php');
include('include/header.php');

date_default_timezone_set('Asia/Kolkata');

$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;

if ($batch_id <= 0) {
    die("Invalid Batch ID");
}

/*
    Fetch Batch Master
*/
$stmt = $mysqli->prepare("
    SELECT *
    FROM batches
    WHERE batch_id = ?
");
$stmt->bind_param("i", $batch_id);
$stmt->execute();
$batch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$batch) {
    die("Batch not found");
}

/*
    Fetch Products
*/
$products = $mysqli->query("
    SELECT product_id, title 
    FROM products 
    ORDER BY title
");

/*
    Fetch Raw Materials
*/
$materials = $mysqli->query("
    SELECT 
        raw_material_id,
        material_name,
        unit,
        owner_type,
        current_stock,
        average_price
    FROM raw_materials 
    ORDER BY material_name ASC
");

/*
    Store material options for repeated use
*/
$material_options = [];
while ($m = $materials->fetch_assoc()) {
    $material_options[] = $m;
}

/*
    Fetch Batch Raw Materials
*/
$stmt = $mysqli->prepare("
    SELECT *
    FROM batch_raw_materials
    WHERE batch_id = ?
    ORDER BY id ASC
");

if (!$stmt) {
    die("Batch raw material query error: " . $mysqli->error);
}

$stmt->bind_param("i", $batch_id);
$stmt->execute();
$item_result = $stmt->get_result();

$batch_items = [];
while ($row = $item_result->fetch_assoc()) {
    $batch_items[] = $row;
}
$stmt->close();

if (empty($batch_items)) {
    $batch_items[] = [
        'raw_material_id' => '',
        'material_owner_company' => '',
        'unit' => '',
        'quantity_used' => '',
        'rate' => '',
        'amount' => ''
    ];
}

$batch_owner = $batch['batch_owner'] ?? '';
$product_id = $batch['product_id'] ?? '';
$batch_code = $batch['batch_code'] ?? '';
$product_qty = $batch['product_qty'] ?? '';
$production_date = $batch['production_date'] ?? date('Y-m-d');

/*
    Your batches table does not have total_material_cost column.
    So calculate total from batch_raw_materials rows.
*/
$total_material_cost = 0;
foreach ($batch_items as $costItem) {
    $total_material_cost += floatval($costItem['amount'] ?? 0);
}
?>

<style>
body {
    background: #f5f6fa;
}

.page-wrap {
    padding: 18px;
}

.page-title-box {
    background: #fff;
    border-radius: 14px;
    padding: 16px 18px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.06);
    margin-bottom: 18px;
}

.page-title-box h4 {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
}

.sa-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.06);
    overflow: hidden;
    margin-bottom: 20px;
}

.sa-card-header {
    padding: 15px 18px;
    border-bottom: 1px solid #eef0f4;
    background: #fff;
    font-weight: 700;
}

.sa-card-body {
    padding: 18px;
}

.form-label {
    font-size: 13px;
    font-weight: 600;
    color: #444;
}

.form-control,
.form-select {
    border-radius: 10px;
    min-height: 42px;
    font-size: 14px;
}

.btn {
    border-radius: 10px;
    font-weight: 600;
}

.mobile-material-card {
    display: none;
}

.desktop-material-table {
    display: block;
}

.material-mobile-item {
    background: #fff;
    border: 1px solid #e8eaf0;
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 14px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.04);
}

.material-mobile-title {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 12px;
}

.total-box {
    background: #f8f9fc;
    border: 1px solid #e7e9f0;
    border-radius: 14px;
    padding: 14px;
}

.bottom-add-row-box {
    background: #f8f9fc;
    border: 1px dashed #28a745;
    border-radius: 14px;
    padding: 12px;
}

.bottom-add-row-box .btn {
    min-height: 44px;
    font-size: 14px;
    font-weight: 700;
}

.auto-formula-note {
    font-size: 11px;
    color: #0f766e;
    font-weight: 700;
    margin-top: 4px;
    display: none;
}

.qty-auto-suggested {
    background: #ecfdf5 !important;
    border-color: #10b981 !important;
}

.sticky-save-bar {
    display: none;
}

@media (max-width: 767px) {
    .page-wrap {
        padding: 12px;
    }

    .page-title-box {
        padding: 14px;
        border-radius: 12px;
    }

    .page-title-box h4 {
        font-size: 17px;
    }

    .page-title-actions {
        margin-top: 12px;
        width: 100%;
    }

    .page-title-actions .btn,
    .btn-mobile-full {
        width: 100%;
    }

    .sa-card-header,
    .sa-card-body {
        padding: 14px;
    }

    .desktop-material-table {
        display: none;
    }

    .mobile-material-card {
        display: block;
    }

    .desktop-save-btn {
        display: none;
    }

    .bottom-add-row-box {
        position: sticky;
        bottom: 68px;
        z-index: 45;
        background: #ffffff;
        box-shadow: 0 -3px 10px rgba(0,0,0,0.06);
    }

    .sticky-save-bar {
        display: block;
        position: sticky;
        bottom: 0;
        background: #fff;
        padding: 12px;
        border-top: 1px solid #e9ecef;
        box-shadow: 0 -4px 12px rgba(0,0,0,0.08);
        z-index: 50;
        margin: 15px -14px -14px;
    }

    .alert {
        font-size: 13px;
        border-radius: 12px;
    }
}
</style>

<div class="container-fluid page-wrap">

    <div class="page-title-box">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h4>Edit Manufacturing Batch</h4>
                <small class="text-muted">
                    Update batch and raw material usage details
                </small>
            </div>

            <div class="page-title-actions">
                <a href="batch_list.php" class="btn btn-secondary btn-sm">← Back to List</a>
            </div>
        </div>
    </div>

    <form action="batch_update.php" method="POST" id="batchForm">

        <input type="hidden" name="batch_id" value="<?= intval($batch_id); ?>">

        <div class="sa-card">
            <div class="sa-card-header">Batch Information</div>

            <div class="sa-card-body">
                <div class="row g-3">

                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label">Batch Owner Company <span class="text-danger">*</span></label>
                        <select name="batch_owner" id="batch_owner" class="form-select" required>
                            <option value="">Select Company</option>
                            <option value="SATHEE" <?= ($batch_owner === 'SATHEE') ? 'selected' : ''; ?>>
                                Sathee Enterprise
                            </option>
                            <option value="CMD" <?= ($batch_owner === 'CMD') ? 'selected' : ''; ?>>
                                CMD Enterprise
                            </option>
                        </select>
                    </div>

                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label">Product <span class="text-danger">*</span></label>
                        <select name="product_id" id="product_id" class="form-select" required>
                            <option value="">Select Product</option>
                            <?php while ($row = $products->fetch_assoc()): ?>
                                <option value="<?= intval($row['product_id']); ?>"
                                    <?= (intval($product_id) === intval($row['product_id'])) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($row['title']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label">Batch Code</label>
                        <input type="text" 
                               name="batch_code" 
                               id="batch_code" 
                               class="form-control" 
                               value="<?= htmlspecialchars($batch_code); ?>" 
                               required>
                    </div>

                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label">Quantity Produced <span class="text-danger">*</span></label>
                        <input type="number" 
                               step="0.01" 
                               min="0" 
                               name="product_qty" 
                               id="product_qty" 
                               class="form-control" 
                               value="<?= htmlspecialchars($product_qty); ?>" 
                               required>
                    </div>

                    <div class="col-12 col-md-4 col-lg-2">
                        <label class="form-label">Production Date</label>
                        <input type="date" 
                               name="production_date" 
                               id="production_date" 
                               class="form-control" 
                               value="<?= htmlspecialchars($production_date); ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Enter batch notes"><?= htmlspecialchars($batch['notes'] ?? ''); ?></textarea>
                    </div>

                </div>

                <div class="alert alert-light border mt-3 mb-0 py-2">
                    <strong>Auto Formula:</strong>
                    If raw material <strong>BLK</strong> quantity is entered, then <strong>KHUJLI</strong> quantity will auto calculate:
                    <code>KHUJLI Qty = Total BLK Qty × 0.165</code>.
                    KHUJLI quantity will remain editable.
                </div>

                <div class="row mt-3">
                    <div class="col-12 text-md-end">
                        <button type="button" class="btn btn-info btn-mobile-full" id="searchPreviousBtn">
                            Search / Load Previous Formula
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="formulaMessage"></div>

        <div class="sa-card" id="materialCard">
            <div class="sa-card-header">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <span>Raw Materials Used</span>
                    <button type="button" class="btn btn-sm btn-success" onclick="addRow()">+ Add Row</button>
                </div>
            </div>

            <div class="sa-card-body">

                <div id="settlementInfo" class="alert alert-info py-2">
                    Select material and quantity to calculate internal settlement.
                </div>

                <div class="desktop-material-table">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle" id="raw-material-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Raw Material</th>
                                    <th>Owner</th>
                                    <th>Unit</th>
                                    <th>Stock</th>
                                    <th>Qty Used</th>
                                    <th>Rate</th>
                                    <th>Amount</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($batch_items as $item): ?>
                                    <tr>
                                        <td>
                                            <select name="material_id[]" class="form-select material-select" required>
                                                <option value="">Select Material</option>

                                                <?php foreach ($material_options as $m): ?>
                                                    <?php
                                                        $selected_material = intval($item['raw_material_id'] ?? 0);
                                                        $current_material = intval($m['raw_material_id']);
                                                    ?>
                                                    <option 
                                                        value="<?= $current_material; ?>"
                                                        data-name="<?= htmlspecialchars(strtoupper($m['material_name'])); ?>"
                                                        data-owner="<?= htmlspecialchars(strtoupper($m['owner_type'])); ?>"
                                                        data-unit="<?= htmlspecialchars($m['unit']); ?>"
                                                        data-stock="<?= htmlspecialchars($m['current_stock']); ?>"
                                                        data-rate="<?= htmlspecialchars($m['average_price']); ?>"
                                                        <?= ($selected_material === $current_material) ? 'selected' : ''; ?>
                                                    >
                                                        <?= htmlspecialchars($m['material_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <input type="hidden" 
                                                   name="material_owner_company[]" 
                                                   class="material-owner-hidden"
                                                   value="<?= htmlspecialchars(strtoupper($item['material_owner_company'] ?? '')); ?>">
                                        </td>

                                        <td>
                                            <input type="text" 
                                                   class="form-control material-owner" 
                                                   value="<?= htmlspecialchars(strtoupper($item['material_owner_company'] ?? '')); ?>" 
                                                   readonly>
                                        </td>

                                        <td>
                                            <input type="text" 
                                                   name="unit[]" 
                                                   class="form-control material-unit" 
                                                   value="<?= htmlspecialchars($item['unit'] ?? ''); ?>" 
                                                   readonly>
                                        </td>

                                        <td>
                                            <input type="number" 
                                                   step="0.01" 
                                                   class="form-control current-stock" 
                                                   readonly>
                                        </td>

                                        <td>
                                            <input type="number" 
                                                   name="quantity[]" 
                                                   class="form-control qty-used" 
                                                   step="0.001" 
                                                   min="0" 
                                                   value="<?= htmlspecialchars($item['quantity_used'] ?? ''); ?>" 
                                                   required>
                                            <div class="auto-formula-note">Auto suggested: KHUJLI = BLK Qty × 0.165</div>
                                        </td>

                                        <td>
                                            <input type="number" 
                                                   name="rate[]" 
                                                   class="form-control rate" 
                                                   step="0.01" 
                                                   min="0" 
                                                   value="<?= htmlspecialchars($item['rate'] ?? ''); ?>" 
                                                   readonly>
                                        </td>

                                        <td>
                                            <input type="number" 
                                                   name="amount[]" 
                                                   class="form-control amount" 
                                                   step="0.01" 
                                                   value="<?= htmlspecialchars($item['amount'] ?? ''); ?>" 
                                                   readonly>
                                        </td>

                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)">X</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                            <tfoot>
                                <tr>
                                    <th colspan="6" class="text-end">Total Material Cost</th>
                                    <th>
                                        <input type="number" 
                                               name="total_material_cost" 
                                               id="total_material_cost" 
                                               class="form-control" 
                                               value="<?= htmlspecialchars($total_material_cost); ?>" 
                                               readonly>
                                    </th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="mobile-material-card" id="mobileMaterialList"></div>

                <div class="bottom-add-row-box mt-3">
                    <button type="button" class="btn btn-success w-100" onclick="addRow()">
                        + Add More Raw Material Row
                    </button>
                </div>

                <div class="total-box mt-3">
                    <label class="form-label mb-1">Total Material Cost</label>
                    <input type="number" 
                           id="mobile_total_material_cost" 
                           class="form-control" 
                           value="<?= htmlspecialchars($total_material_cost); ?>" 
                           readonly>
                </div>

                <div class="text-end mt-3 desktop-save-btn">
                    <button type="submit" class="btn btn-primary px-4">Update Batch</button>
                </div>

                <div class="sticky-save-bar">
                    <button type="submit" class="btn btn-primary w-100">Update Batch</button>
                </div>

            </div>
        </div>

    </form>
</div>

<script>
const KHUJLI_BLK_RATIO = 0.165;

function showMessage(type, message) {
    document.getElementById('formulaMessage').innerHTML =
        '<div class="alert alert-' + type + ' py-2">' + message + '</div>';
}

function showMaterialCard() {
    document.getElementById('materialCard').style.display = 'block';
}

function getMaterialName(row) {
    let select = row.querySelector('.material-select');

    if (!select) {
        return '';
    }

    let selected = select.options[select.selectedIndex];

    if (!selected) {
        return '';
    }

    return (selected.getAttribute('data-name') || selected.textContent || '').toUpperCase();
}

function isBlkRow(row) {
    return getMaterialName(row).includes('BLK');
}

function isKhujliRow(row) {
    return getMaterialName(row).includes('KHUJLI');
}

function getTotalBlkQty() {
    let totalBlkQty = 0;

    document.querySelectorAll('#raw-material-table tbody tr').forEach(function(row) {
        if (isBlkRow(row)) {
            totalBlkQty += parseFloat(row.querySelector('.qty-used').value) || 0;
        }
    });

    return totalBlkQty;
}

/*
    KHUJLI auto suggestion:
    - KHUJLI = Total BLK Qty × 0.165
    - It remains editable
    - If user manually edits KHUJLI, it will not overwrite again
*/
function applyKhujliAutoFormula() {
    let totalBlkQty = getTotalBlkQty();

    document.querySelectorAll('#raw-material-table tbody tr').forEach(function(row) {
        let qtyInput = row.querySelector('.qty-used');
        let note = row.querySelector('.auto-formula-note');

        if (!qtyInput) {
            return;
        }

        if (isKhujliRow(row) && totalBlkQty > 0) {
            let currentValue = parseFloat(qtyInput.value) || 0;
            let isManual = qtyInput.getAttribute('data-manual') === '1';

            if (!isManual || currentValue <= 0) {
                let khujliQty = totalBlkQty * KHUJLI_BLK_RATIO;
                qtyInput.value = khujliQty.toFixed(3);
                qtyInput.setAttribute('data-auto-value', khujliQty.toFixed(3));
            }

            qtyInput.classList.add('qty-auto-suggested');

            if (note) {
                note.style.display = 'block';
            }
        } else {
            qtyInput.classList.remove('qty-auto-suggested');
            qtyInput.removeAttribute('data-auto-value');

            if (note) {
                note.style.display = 'none';
            }
        }

        calculateRow(row);
    });
}

function calculateRow(row) {
    let qty = parseFloat(row.querySelector('.qty-used').value) || 0;
    let rate = parseFloat(row.querySelector('.rate').value) || 0;
    let amount = qty * rate;

    row.querySelector('.amount').value = amount.toFixed(2);
}

function calculateTotal() {
    let total = 0;

    document.querySelectorAll('#raw-material-table tbody .amount').forEach(function(input) {
        total += parseFloat(input.value) || 0;
    });

    document.getElementById('total_material_cost').value = total.toFixed(2);
    document.getElementById('mobile_total_material_cost').value = total.toFixed(2);

    updateSettlementInfo();
    renderMobileMaterialCards();
}

function updateSettlementInfo() {
    let batchOwner = document.getElementById('batch_owner').value.toUpperCase();
    let satheeToCmd = 0;
    let cmdToSathee = 0;

    document.querySelectorAll('#raw-material-table tbody tr').forEach(function(row) {
        let materialOwner = row.querySelector('.material-owner-hidden').value.toUpperCase();
        let amount = parseFloat(row.querySelector('.amount').value) || 0;

        if (materialOwner && batchOwner && batchOwner !== materialOwner) {
            if (batchOwner === 'SATHEE' && materialOwner === 'CMD') {
                satheeToCmd += amount;
            }

            if (batchOwner === 'CMD' && materialOwner === 'SATHEE') {
                cmdToSathee += amount;
            }
        }
    });

    let box = document.getElementById('settlementInfo');

    if (satheeToCmd > 0 || cmdToSathee > 0) {
        let html = '<strong>Internal Settlement Required:</strong><br>';

        if (satheeToCmd > 0) {
            html += 'Sathee Enterprise has to pay CMD Enterprise: ₹' + satheeToCmd.toFixed(2) + '<br>';
        }

        if (cmdToSathee > 0) {
            html += 'CMD Enterprise has to pay Sathee Enterprise: ₹' + cmdToSathee.toFixed(2);
        }

        box.className = 'alert alert-warning py-2';
        box.innerHTML = html;
    } else {
        box.className = 'alert alert-success py-2';
        box.innerHTML = 'No internal settlement required for selected materials.';
    }
}

function renderMobileMaterialCards() {
    const mobileList = document.getElementById('mobileMaterialList');
    mobileList.innerHTML = '';

    document.querySelectorAll('#raw-material-table tbody tr').forEach(function(row, index) {
        const materialSelect = row.querySelector('.material-select');
        const owner = row.querySelector('.material-owner').value || '-';
        const unit = row.querySelector('.material-unit').value || '-';
        const stock = row.querySelector('.current-stock').value || '0.00';
        const qty = row.querySelector('.qty-used').value || '';
        const rate = row.querySelector('.rate').value || '0.00';
        const amount = row.querySelector('.amount').value || '0.00';
        const isKhujliAuto = isKhujliRow(row) && getTotalBlkQty() > 0;

        let card = document.createElement('div');
        card.className = 'material-mobile-item';

        card.innerHTML = `
            <div class="material-mobile-title">Material Row ${index + 1}</div>

            <div class="mb-2 mobile-select-box">
                <label class="form-label">Raw Material</label>
            </div>

            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label">Owner</label>
                    <input type="text" class="form-control" value="${owner}" readonly>
                </div>

                <div class="col-6">
                    <label class="form-label">Unit</label>
                    <input type="text" class="form-control" value="${unit}" readonly>
                </div>

                <div class="col-6">
                    <label class="form-label">Current Stock</label>
                    <input type="text" class="form-control" value="${stock}" readonly>
                </div>

                <div class="col-6">
                    <label class="form-label">Qty Used</label>
                    <input type="number" 
                           class="form-control mobile-qty-input ${isKhujliAuto ? 'qty-auto-suggested' : ''}" 
                           data-row="${index}" 
                           value="${qty}" 
                           step="0.001" 
                           min="0">
                    ${isKhujliAuto ? '<div class="auto-formula-note" style="display:block;">Auto suggested: KHUJLI = BLK Qty × 0.165</div>' : ''}
                </div>

                <div class="col-6">
                    <label class="form-label">Rate</label>
                    <input type="text" class="form-control" value="${rate}" readonly>
                </div>

                <div class="col-6">
                    <label class="form-label">Amount</label>
                    <input type="text" class="form-control" value="${amount}" readonly>
                </div>

                <div class="col-12 mt-2">
                    <button type="button" class="btn btn-danger btn-sm w-100 mobile-remove-row" data-row="${index}">
                        Remove Row
                    </button>
                </div>
            </div>
        `;

        let selectClone = materialSelect.cloneNode(true);

        selectClone.classList.remove('material-select');
        selectClone.classList.add('mobile-material-select');

        selectClone.setAttribute('data-row', index);
        selectClone.value = materialSelect.value;

        selectClone.removeAttribute('name');
        selectClone.removeAttribute('required');

        card.querySelector('.mobile-select-box').appendChild(selectClone);
        mobileList.appendChild(card);
    });
}

document.addEventListener('change', function(e) {
    if (e.target.classList.contains('material-select')) {
        let selected = e.target.options[e.target.selectedIndex];
        let row = e.target.closest('tr');

        let owner = selected.getAttribute('data-owner') || '';
        let unit = selected.getAttribute('data-unit') || '';
        let stock = selected.getAttribute('data-stock') || '0';
        let rate = selected.getAttribute('data-rate') || '0';

        row.querySelector('.material-owner').value = owner;
        row.querySelector('.material-owner-hidden').value = owner;
        row.querySelector('.material-unit').value = unit;
        row.querySelector('.current-stock').value = parseFloat(stock || 0).toFixed(2);
        row.querySelector('.rate').value = parseFloat(rate || 0).toFixed(2);

        /*
            If material changed, reset manual flag.
        */
        row.querySelector('.qty-used').removeAttribute('data-manual');
        row.querySelector('.qty-used').removeAttribute('data-auto-value');

        applyKhujliAutoFormula();
        calculateTotal();
    }

    if (e.target.classList.contains('mobile-material-select')) {
        let rowIndex = parseInt(e.target.getAttribute('data-row'));
        let desktopRow = document.querySelectorAll('#raw-material-table tbody tr')[rowIndex];

        if (desktopRow) {
            let desktopSelect = desktopRow.querySelector('.material-select');
            desktopSelect.value = e.target.value;
            desktopSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    if (e.target.id === 'batch_owner') {
        updateSettlementInfo();
    }
});

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('qty-used')) {
        let row = e.target.closest('tr');

        if (isKhujliRow(row)) {
            let autoValue = parseFloat(e.target.getAttribute('data-auto-value') || 0);
            let currentValue = parseFloat(e.target.value || 0);

            if (Math.abs(autoValue - currentValue) > 0.001) {
                e.target.setAttribute('data-manual', '1');
            }
        }

        calculateRow(row);

        /*
            If BLK qty changes, update KHUJLI suggestion.
            But manually edited KHUJLI will not be overwritten.
        */
        if (isBlkRow(row)) {
            applyKhujliAutoFormula();
        }

        calculateTotal();
    }

    if (e.target.classList.contains('mobile-qty-input')) {
        let rowIndex = parseInt(e.target.getAttribute('data-row'));
        let desktopRow = document.querySelectorAll('#raw-material-table tbody tr')[rowIndex];

        if (desktopRow) {
            let desktopQty = desktopRow.querySelector('.qty-used');
            desktopQty.value = e.target.value;
            desktopQty.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('mobile-remove-row')) {
        let rowIndex = parseInt(e.target.getAttribute('data-row'));
        let desktopRows = document.querySelectorAll('#raw-material-table tbody tr');

        if (desktopRows.length > 1 && desktopRows[rowIndex]) {
            desktopRows[rowIndex].remove();
            applyKhujliAutoFormula();
            calculateTotal();
        }
    }
});

function addRow() {
    const tbody = document.querySelector("#raw-material-table tbody");
    const firstRow = tbody.querySelector("tr");
    const row = firstRow.cloneNode(true);

    row.querySelectorAll("input").forEach(function(el) {
        el.value = "";
        el.removeAttribute('data-manual');
        el.removeAttribute('data-auto-value');
        el.classList.remove('qty-auto-suggested');
    });

    row.querySelectorAll("select").forEach(function(el) {
        el.value = "";
    });

    let note = row.querySelector('.auto-formula-note');
    if (note) {
        note.style.display = 'none';
    }

    tbody.appendChild(row);

    applyKhujliAutoFormula();
    calculateTotal();
}

function removeRow(btn) {
    const tbody = document.querySelector("#raw-material-table tbody");

    if (tbody.rows.length > 1) {
        btn.closest("tr").remove();
        applyKhujliAutoFormula();
        calculateTotal();
    }
}

function clearMaterialRows() {
    const tbody = document.querySelector("#raw-material-table tbody");
    const firstRow = tbody.querySelector("tr");

    tbody.innerHTML = "";

    const row = firstRow.cloneNode(true);

    row.querySelectorAll("input").forEach(function(el) {
        el.value = "";
        el.removeAttribute('data-manual');
        el.removeAttribute('data-auto-value');
        el.classList.remove('qty-auto-suggested');
    });

    row.querySelectorAll("select").forEach(function(el) {
        el.value = "";
    });

    let note = row.querySelector('.auto-formula-note');
    if (note) {
        note.style.display = 'none';
    }

    tbody.appendChild(row);
    calculateTotal();
}

function loadRowsFromPrevious(items) {
    clearMaterialRows();

    const tbody = document.querySelector("#raw-material-table tbody");
    const firstRow = tbody.querySelector("tr");

    tbody.innerHTML = "";

    items.forEach(function(item) {
        let row = firstRow.cloneNode(true);

        row.querySelectorAll("input").forEach(function(el) {
            el.value = "";
            el.removeAttribute('data-manual');
            el.removeAttribute('data-auto-value');
            el.classList.remove('qty-auto-suggested');
        });

        let select = row.querySelector('.material-select');
        select.value = item.raw_material_id;

        let selected = select.options[select.selectedIndex];

        let owner = item.material_owner_company || item.owner_type || '';
        let unit  = item.unit || '';
        let stock = item.current_stock || '0';
        let rate  = item.rate || '0';

        if ((!owner || owner === '') && selected) {
            owner = selected.getAttribute('data-owner') || '';
        }

        if ((!unit || unit === '') && selected) {
            unit = selected.getAttribute('data-unit') || '';
        }

        if ((!stock || parseFloat(stock) <= 0) && selected) {
            stock = selected.getAttribute('data-stock') || '0';
        }

        if ((!rate || parseFloat(rate) <= 0) && selected) {
            rate = selected.getAttribute('data-rate') || '0';
        }

        owner = owner.toString().toUpperCase();

        row.querySelector('.material-owner').value = owner;
        row.querySelector('.material-owner-hidden').value = owner;
        row.querySelector('.material-unit').value = unit;
        row.querySelector('.current-stock').value = parseFloat(stock || 0).toFixed(2);
        row.querySelector('.qty-used').value = parseFloat(item.quantity_used || 0).toFixed(3);
        row.querySelector('.rate').value = parseFloat(rate || 0).toFixed(2);

        calculateRow(row);
        tbody.appendChild(row);
    });

    applyKhujliAutoFormula();
    calculateTotal();
}

document.getElementById('searchPreviousBtn').addEventListener('click', function() {
    let batchOwner = document.getElementById('batch_owner').value;
    let productId = document.getElementById('product_id').value;
    let productQty = document.getElementById('product_qty').value;

    if (!batchOwner || !productId || !productQty || parseFloat(productQty) <= 0) {
        showMessage('danger', 'Please select company, product and quantity first.');
        return;
    }

    fetch('get_previous_batch_materials.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 
            'batch_owner=' + encodeURIComponent(batchOwner) +
            '&product_id=' + encodeURIComponent(productId) +
            '&product_qty=' + encodeURIComponent(productQty)
    })
    .then(res => res.json())
    .then(data => {
        showMaterialCard();

        if (data.status === 'found') {
            loadRowsFromPrevious(data.items);
            showMessage('success', 'Previous batch formula loaded from Batch Code: <strong>' + data.batch_code + '</strong>');
        } else {
            clearMaterialRows();
            calculateTotal();
            showMessage('warning', data.message || 'No previous formula found. Please create a fresh raw material list.');
        }
    })
    .catch(error => {
        showMaterialCard();
        clearMaterialRows();
        showMessage('danger', 'Something went wrong while searching previous formula.');
    });
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.material-select').forEach(function(select) {
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    applyKhujliAutoFormula();
    calculateTotal();
});
</script>

<?php include('include/footer.php'); ?>
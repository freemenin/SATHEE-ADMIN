<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<?php
require_once 'include/require_permission.php';
requirePermission('ORDERS', 'add');
include('include/require_login.php');
include('include/header.php');
?>

<?php
$toast = "";

$distributor_stmt = $mysqli->prepare("
    SELECT distributor_id, distributor_name 
    FROM distributors 
    ORDER BY distributor_name ASC
");
$distributor_stmt->execute();
$distributor_result = $distributor_stmt->get_result();
?>

<style>
    .sa-order-page {
        max-width: 1200px;
        margin: auto;
    }

    .sa-card {
        border: 0;
        border-radius: 18px;
        box-shadow: 0 8px 30px rgba(0,0,0,0.06);
        overflow: hidden;
    }

    .sa-card-header {
        background: #fff;
        border-bottom: 1px solid #edf0f4;
        padding: 18px 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .sa-card-header h5 {
        margin: 0;
        font-weight: 700;
    }

    .sa-card-body {
        padding: 22px;
    }

    .sa-product-table th {
        background: #f8fafc;
        font-size: 13px;
        white-space: nowrap;
    }

    .sa-product-table td {
        vertical-align: middle;
    }

    .sa-summary-input {
        font-weight: 700;
        background: #f8fafc;
    }

    .sa-mobile-actions {
        display: flex;
        align-items: end;
        gap: 10px;
    }

    .sa-last-order-alert {
        border-radius: 14px;
        padding: 12px 14px;
        background: #ecfdf5;
        color: #065f46;
        font-size: 14px;
        display: none;
        margin-top: 15px;
    }

    @media (max-width: 768px) {
        .sa-card-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .sa-mobile-actions {
            display: block;
        }

        .sa-mobile-actions button {
            width: 100%;
            margin-top: 10px !important;
        }

        .sa-product-table,
        .sa-product-table thead,
        .sa-product-table tbody,
        .sa-product-table th,
        .sa-product-table td,
        .sa-product-table tr {
            display: block;
            width: 100%;
        }

        .sa-product-table thead {
            display: none;
        }

        .sa-product-table tr {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .sa-product-table td {
            border: 0 !important;
            padding: 7px 0 !important;
        }

        .sa-product-table td::before {
            content: attr(data-label);
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 4px;
        }

        .text-end {
            text-align: center !important;
        }

        .text-end button {
            width: 100%;
        }
    }
</style>

<div class="container-fluid py-4">
    <div class="sa-order-page">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">➕ Create New Order</h4>
                <small class="text-muted">Search customer by mobile and create order quickly</small>
            </div>
        </div>

        <form id="order-form" method="POST" action="save_order.php">

            <!-- Customer Mobile Search -->
            <div class="card sa-card mb-4">
                <div class="sa-card-header">
                    <h5>Customer Information</h5>
                </div>

                <div class="sa-card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Mobile Number</label>
                            <input 
                                type="text" 
                                name="mobile_number" 
                                id="mobile_number" 
                                class="form-control" 
                                required 
                                maxlength="10"
                                placeholder="Enter customer mobile number"
                            />
                        </div>

                        <div class="col-md-8 sa-mobile-actions">
                            <button type="button" class="btn btn-primary" id="check-customer">
                                Search / Add Customer
                            </button>
                        </div>
                    </div>

                    <div id="last-order-alert" class="sa-last-order-alert"></div>

                    <div id="customer-info" class="row g-3 mt-3 d-none">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" id="full_name" class="form-control" required />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control" />
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" id="address" class="form-control" />
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Landmark</label>
                            <input type="text" name="landmark" id="landmark" class="form-control" />
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">City</label>
                            <input type="text" name="city" id="city" class="form-control" />
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">State</label>
                            <input type="text" name="state" id="state" class="form-control" />
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Pincode</label>
                            <input type="text" name="pincode" id="pincode" class="form-control" />
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Distributor</label>
                            <select 
                                name="distributor" 
                                id="distributor" 
                                class="sa-select2 form-select" 
                                style="width: 100%;" 
                                data-live-search="true" 
                                required
                            >
                                <option value="">-- Select Distributor --</option>
                                <?php while ($row = $distributor_result->fetch_assoc()): ?>
                                    <option value="<?= htmlspecialchars($row['distributor_id']) ?>">
                                        <?= htmlspecialchars($row['distributor_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label d-block">Order Type</label>
                            <label class="form-check form-switch">
                                <input type="checkbox" name="data" id="repeat_order" class="form-check-input" />
                                <span class="form-check-label">Repeat order</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Selection -->
            <div class="card sa-card mb-4">
                <div class="sa-card-header">
                    <h5>Products</h5>
                    <button type="button" class="btn btn-secondary btn-sm" id="add-product-row">
                        + Add Product
                    </button>
                </div>

                <div class="sa-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered sa-product-table" id="product-table">
                            <thead>
                                <tr>
                                    <th style="width: 45%;">Product</th>
                                    <th style="width: 12%;">Qty</th>
                                    <th style="width: 16%;">Unit Price</th>
                                    <th style="width: 16%;">Total</th>
                                    <th style="width: 11%;"></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="card sa-card mb-4">
                <div class="sa-card-header">
                    <h5>Order Summary</h5>
                </div>

                <div class="sa-card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Subtotal</label>
                            <input type="text" name="subtotal" id="subtotal" class="form-control sa-summary-input" readonly />
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Tax Included 18%</label>
                            <input type="text" name="tax" id="tax" class="form-control sa-summary-input" readonly />
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Discount</label>
                            <input 
                                type="number" 
                                step="0.01" 
                                name="discount" 
                                id="discount" 
                                class="form-control" 
                                value="0" 
                            />
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Grand Total</label>
                            <input type="text" name="grand_total" id="grand_total" class="form-control sa-summary-input" readonly />
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-end mb-4">
                <button type="submit" class="btn btn-success px-5 py-2">
                    Submit Order
                </button>
            </div>

        </form>
    </div>
</div>

<script>
let productsData = {};
let productsLoaded = false;
let pendingLastOrderItems = null;

function escapeHtml(text) {
    return $('<div>').text(text || '').html();
}

/*
|--------------------------------------------------------------------------
| Distributor Select Fixed Function
|--------------------------------------------------------------------------
| This fixes Select2 / bootstrap-select visible text issue.
|--------------------------------------------------------------------------
*/
function setDistributorValue(distributorId) {
    distributorId = String(distributorId || '').trim();

    let $distributor = $('#distributor');

    if (!$distributor.length) {
        return;
    }

    if (!distributorId) {
        $distributor.val('');

        if ($distributor.hasClass('select2-hidden-accessible')) {
            $distributor.trigger('change.select2');
        }

        $distributor.trigger('change');

        if (typeof $distributor.selectpicker === 'function') {
            $distributor.selectpicker('val', '');
            $distributor.selectpicker('refresh');
        }

        return;
    }

    let optionExists = $distributor.find('option[value="' + distributorId + '"]').length > 0;

    if (!optionExists) {
        console.warn('Distributor option not found:', distributorId);
        return;
    }

    $distributor.val(distributorId);

    if ($distributor.hasClass('select2-hidden-accessible')) {
        $distributor.trigger('change.select2');
    }

    $distributor.trigger('change');

    if (typeof $distributor.selectpicker === 'function') {
        $distributor.selectpicker('val', distributorId);
        $distributor.selectpicker('refresh');
    }

    setTimeout(function () {
        $distributor.val(distributorId);

        if ($distributor.hasClass('select2-hidden-accessible')) {
            $distributor.trigger('change.select2');
        }

        $distributor.trigger('change');

        if (typeof $distributor.selectpicker === 'function') {
            $distributor.selectpicker('val', distributorId);
            $distributor.selectpicker('refresh');
        }
    }, 150);
}

/*
|--------------------------------------------------------------------------
| Load Products
|--------------------------------------------------------------------------
*/
function loadProducts(callback = null) {
    $.getJSON('ajax_get_products.php', function(products) {
        products.forEach(function(product) {
            productsData[product.product_id] = product;
        });

        productsLoaded = true;

        if (typeof callback === 'function') {
            callback();
        }

        if (pendingLastOrderItems) {
            loadLastOrderProducts(pendingLastOrderItems);
            pendingLastOrderItems = null;
        }
    });
}

loadProducts();

/*
|--------------------------------------------------------------------------
| Search Customer
|--------------------------------------------------------------------------
*/
$('#check-customer').on('click', function () {
    const mobile = $('#mobile_number').val().trim();

    if (mobile === '') {
        alert('Please enter mobile number.');
        return;
    }

    if (mobile.length < 10) {
        alert('Please enter valid 10 digit mobile number.');
        return;
    }

    $('#check-customer').prop('disabled', true).text('Searching...');

    $.ajax({
        url: 'ajax_get_customer.php',
        type: 'POST',
        data: { mobile_number: mobile },
        dataType: 'json',

        success: function (data) {
            $('#customer-info').removeClass('d-none');

            $('#full_name').val(data.full_name || '');
            $('#email').val(data.email || '');
            $('#address').val(data.address || '');
            $('#landmark').val(data.landmark || '');
            $('#city').val(data.city || '');
            $('#state').val(data.state || '');
            $('#pincode').val(data.pincode || '');

            $('#last-order-alert').hide().html('');
            $('#product-table tbody').html('');
            $('#discount').val(0);
            updateTotal();

            if (data.last_order && data.last_order.exists) {

                if (data.last_order.distributor_id) {
                    setDistributorValue(data.last_order.distributor_id);
                }

                $('#repeat_order').prop('checked', true);

                let alertText = 'Old order found. Last distributor and last ordered products are auto-selected.';

                if (data.last_order.invoice_number) {
                    alertText += ' Last Invoice: <b>' + escapeHtml(data.last_order.invoice_number) + '</b>';
                }

                $('#last-order-alert').html(alertText).show();

                if (data.last_order.items && data.last_order.items.length > 0) {
                    if (productsLoaded) {
                        loadLastOrderProducts(data.last_order.items);
                    } else {
                        pendingLastOrderItems = data.last_order.items;
                    }
                } else {
                    addProductRow();
                }

            } else {
                $('#repeat_order').prop('checked', false);
                setDistributorValue('');
                addProductRow();
            }
        },

        error: function () {
            $('#customer-info').removeClass('d-none');

            $('#full_name').val('');
            $('#email').val('');
            $('#address').val('');
            $('#landmark').val('');
            $('#city').val('');
            $('#state').val('');
            $('#pincode').val('');

            $('#repeat_order').prop('checked', false);
            setDistributorValue('');
            $('#product-table tbody').html('');
            $('#last-order-alert').hide().html('');

            addProductRow();
            updateTotal();
        },

        complete: function () {
            $('#check-customer').prop('disabled', false).text('Search / Add Customer');
        }
    });
});

/*
|--------------------------------------------------------------------------
| Add Product Row
|--------------------------------------------------------------------------
*/
$('#add-product-row').on('click', function() {
    addProductRow();
});

function addProductRow(selectedProductId = '', selectedQty = 1, selectedPrice = '') {
    let options = '<option value="">Select Product</option>';

    Object.values(productsData).forEach(function(p) {
        let productId = String(p.product_id);
        let selected = productId === String(selectedProductId) ? 'selected' : '';
        let price = p.retail_price || 0;
        let title = p.title || '';

        options += `
            <option value="${productId}" data-price="${price}" ${selected}>
                ${escapeHtml(title)}
            </option>
        `;
    });

    let row = `
        <tr>
            <td data-label="Product">
                <select class="form-select product-select" name="product_ids[]" required>
                    ${options}
                </select>
            </td>

            <td data-label="Qty">
                <input 
                    type="number" 
                    class="form-control qty" 
                    name="quantities[]" 
                    value="${selectedQty || 1}" 
                    min="1"
                    required
                >
            </td>

            <td data-label="Unit Price">
                <input 
                    type="number" 
                    class="form-control price" 
                    name="unit_prices[]" 
                    value="${selectedPrice || ''}" 
                    readonly
                >
            </td>

            <td data-label="Total">
                <input type="number" class="form-control total" readonly>
            </td>

            <td data-label="Action">
                <button type="button" class="btn btn-danger btn-sm remove-row">
                    ✖
                </button>
            </td>
        </tr>
    `;

    $('#product-table tbody').append(row);

    let lastRow = $('#product-table tbody tr:last');

    if (selectedProductId) {
        let selectedOptionPrice = lastRow.find('.product-select option:selected').data('price');

        if (!selectedPrice || selectedPrice == 0) {
            lastRow.find('.price').val(selectedOptionPrice || 0);
        }
    }

    updateRowTotal(lastRow);
}

/*
|--------------------------------------------------------------------------
| Load Last Order Products
|--------------------------------------------------------------------------
*/
function loadLastOrderProducts(items) {
    $('#product-table tbody').html('');

    items.forEach(function(item) {
        addProductRow(
            item.product_id,
            item.quantity,
            item.unit_price
        );
    });

    updateTotal();
}

/*
|--------------------------------------------------------------------------
| Product Events
|--------------------------------------------------------------------------
*/
$(document).on('change', '.product-select', function() {
    let price = $(this).find(':selected').data('price') || 0;
    let row = $(this).closest('tr');

    row.find('.price').val(price);
    updateRowTotal(row);
});

$(document).on('input', '.qty', function() {
    let row = $(this).closest('tr');
    updateRowTotal(row);
});

$(document).on('click', '.remove-row', function() {
    $(this).closest('tr').remove();
    updateTotal();
});

$('#discount').on('input', updateTotal);

/*
|--------------------------------------------------------------------------
| Calculation
|--------------------------------------------------------------------------
*/
function updateRowTotal(row) {
    let qty = parseFloat(row.find('.qty').val()) || 0;
    let price = parseFloat(row.find('.price').val()) || 0;
    let total = qty * price;

    row.find('.total').val(total.toFixed(2));
    updateTotal();
}

function updateTotal() {
    let subtotal = 0;

    $('.total').each(function() {
        subtotal += parseFloat($(this).val()) || 0;
    });

    let tax = subtotal * (18 / 118);
    let discount = parseFloat($('#discount').val()) || 0;
    let grandTotal = subtotal - discount;

    if (grandTotal < 0) {
        grandTotal = 0;
    }

    $('#subtotal').val(subtotal.toFixed(2));
    $('#tax').val(tax.toFixed(2));
    $('#grand_total').val(grandTotal.toFixed(2));
}
</script>

<?php include('include/footer.php'); ?>
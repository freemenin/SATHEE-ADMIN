<?php
require_once 'include/require_permission.php';
requirePermission('RAW_MATERIALS', 'view');
require_once 'include/csrf_helper.php';
include('include/header.php');

$result = $mysqli->query("
    SELECT 
        raw_material_id,
        material_name,
        unit,
        description,
        created_at,
        owner_type,
        current_stock,
        last_purchase_price
    FROM raw_materials 
    ORDER BY raw_material_id DESC
");
?>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Raw Material Management</h4>
            <small class="text-muted">Manage Sathee/CMD raw materials, stock and purchase price</small>
        </div>

        <div>
            <?php if (hasPermission('RAW_MATERIAL_PURCHASE', 'add')): ?>
                <a href="raw_material_purchase_add.php" class="btn btn-success btn-sm">+ Purchase Material</a>
            <?php endif; ?>
            <?php if (hasPermission('RAW_MATERIALS', 'add')): ?>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                    + Add Raw Material
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Raw Material List</strong>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Material Name</th>
                            <th>Unit</th>
                            <th>Owner</th>
                            <th>Current Stock</th>
                            <th>Last Purchase Price</th>
                            <th>Description</th>
                            <th>Created At</th>
                            <th style="width:140px;">Action</th>
                        </tr>
                    </thead>

                    <tbody id="rawMaterialTable">
                        <?php $i = 1; while ($row = $result->fetch_assoc()): ?>
                            <tr 
                                data-id="<?= $row['raw_material_id'] ?>"
                                data-name="<?= htmlspecialchars($row['material_name']) ?>"
                                data-unit="<?= htmlspecialchars($row['unit']) ?>"
                                data-owner="<?= htmlspecialchars($row['owner_type']) ?>"
                                data-description="<?= htmlspecialchars($row['description']) ?>"
                            >
                                <td><?= $i++ ?></td>

                                <td>
                                    <strong><?= htmlspecialchars($row['material_name']) ?></strong>
                                </td>

                                <td><?= htmlspecialchars($row['unit']) ?></td>

                                <td>
                                    <?php if ($row['owner_type'] == 'SATHEE'): ?>
                                        <span class="badge bg-primary">SATHEE</span>
                                    <?php else: ?>
                                        <span class="badge bg-info text-dark">CMD</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <strong><?= number_format((float)$row['current_stock'], 2) ?></strong>
                                    <?= htmlspecialchars($row['unit']) ?>
                                </td>

                                <td>
                                    ₹<?= number_format((float)$row['last_purchase_price'], 2) ?>
                                </td>

                                <td><?= htmlspecialchars($row['description']) ?></td>

                                <td><?= date('d-m-Y', strtotime($row['created_at'])) ?></td>

                                <td>
                                    <?php if (hasPermission('RAW_MATERIALS', 'edit')): ?>
                                        <button type="button" class="btn btn-sm btn-info editBtn">Edit</button>
                                    <?php endif; ?>
                                    <?php if (hasPermission('RAW_MATERIALS', 'delete')): ?>
                                        <button type="button" class="btn btn-sm btn-danger deleteBtn">Delete</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>

                        <?php if ($result->num_rows == 0): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">No raw material found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                </table>
            </div>
        </div>
    </div>
</div>


<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="addForm" method="POST">
            <?= csrfTokenField() ?>
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Add Raw Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">Material Name <span class="text-danger">*</span></label>
                        <input type="text" name="material_name" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                        <input type="text" name="unit" class="form-control" placeholder="kg, ltr, pcs" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Owner Type <span class="text-danger">*</span></label>
                        <select name="owner_type" class="form-select" required>
                            <option value="SATHEE">Sathee Enterprise</option>
                            <option value="CMD">CMD Enterprise</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control"></textarea>
                    </div>

                    <div class="alert alert-info py-2">
                        Stock and last purchase price will update automatically from purchase entry.
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Save</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>

            </div>
        </form>
    </div>
</div>


<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="editForm" method="POST">
            <?= csrfTokenField() ?>
            <input type="hidden" name="raw_material_id">

            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Edit Raw Material</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">Material Name <span class="text-danger">*</span></label>
                        <input type="text" name="material_name" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                        <input type="text" name="unit" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Owner Type <span class="text-danger">*</span></label>
                        <select name="owner_type" class="form-select" required>
                            <option value="SATHEE">Sathee Enterprise</option>
                            <option value="CMD">CMD Enterprise</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control"></textarea>
                    </div>

                    <div class="alert alert-warning py-2">
                        Current stock is not editable here. Stock should update from purchase/batch.
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Update</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>

            </div>
        </form>
    </div>
</div>


<script>
document.addEventListener("DOMContentLoaded", () => {

    document.getElementById("addForm").addEventListener("submit", function (e) {
        e.preventDefault();

        fetch('raw_material_ajax.php', {
            method: 'POST',
            body: new FormData(this)
        })
        .then(res => res.text())
        .then(res => {
            if (res.trim() === 'success') {
                location.reload();
            } else {
                alert(res);
            }
        });
    });

    document.querySelectorAll(".editBtn").forEach(btn => {
        btn.addEventListener("click", function () {
            const tr = this.closest("tr");

            const modal = document.getElementById("editModal");

            modal.querySelector("input[name=raw_material_id]").value = tr.dataset.id;
            modal.querySelector("input[name=material_name]").value = tr.dataset.name;
            modal.querySelector("input[name=unit]").value = tr.dataset.unit;
            modal.querySelector("select[name=owner_type]").value = tr.dataset.owner;
            modal.querySelector("textarea[name=description]").value = tr.dataset.description;

            new bootstrap.Modal(modal).show();
        });
    });

    document.getElementById("editForm").addEventListener("submit", function (e) {
        e.preventDefault();

        fetch('raw_material_ajax.php', {
            method: 'POST',
            body: new FormData(this)
        })
        .then(res => res.text())
        .then(res => {
            if (res.trim() === 'success') {
                location.reload();
            } else {
                alert(res);
            }
        });
    });

    document.querySelectorAll(".deleteBtn").forEach(btn => {
        btn.addEventListener("click", function () {
            if (!confirm("Are you sure you want to delete this raw material?")) {
                return;
            }

            const id = this.closest("tr").dataset.id;
            const formData = new FormData();
            formData.append("delete_id", id);
            formData.append("csrf_token", document.querySelector('#addForm input[name=csrf_token]').value);

            fetch('raw_material_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(res => {
                if (res.trim() === 'success') {
                    location.reload();
                } else {
                    alert(res);
                }
            });
        });
    });

});
</script>

<?php include('include/footer.php'); ?>
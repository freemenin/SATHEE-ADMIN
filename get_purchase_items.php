<?php
include('include/db.php');
$purchase_id = intval($_GET['purchase_id']);
$sql = "SELECT i.*, r.material_name FROM raw_material_purchase_items i
        JOIN raw_materials r ON i.raw_material_id = r.raw_material_id
        WHERE i.purchase_id = $purchase_id";

$result = $mysqli->query($sql);
$grand_total = 0;
?>
<table class="table table-bordered">
    <thead class="table-light">
        <tr>
            <th>#</th>
            <th>Material</th>
            <th>Quantity</th>
            <th>Unit Price</th>
            <th>Total</th>
        </tr>
    </thead>
    <tbody>
        <?php $sn = 1; while ($row = $result->fetch_assoc()):
            $total = $row['quantity'] * $row['unit_price'];
            $grand_total += $total;
        ?>
        <tr>
            <td><?= $sn++ ?></td>
            <td><?= htmlspecialchars($row['material_name']) ?></td>
            <td><?= $row['quantity'] ?></td>
            <td>₹<?= number_format($row['unit_price'], 2) ?></td>
            <td>₹<?= number_format($total, 2) ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
    <tfoot>
        <tr>
            <th colspan="4" class="text-end">Grand Total</th>
            <th>₹<?= number_format($grand_total, 2) ?></th>
        </tr>
    </tfoot>
</table>

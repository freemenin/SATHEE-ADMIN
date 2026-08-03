<?php
include('include/db.php');

$query = $_POST['query'] ?? '';
$query = $mysqli->real_escape_string($query);

$sql = "SELECT * FROM vendors 
        WHERE vendor_name LIKE '%$query%' 
        OR mobile_number LIKE '%$query%' 
        ORDER BY vendor_id DESC";

$result = $mysqli->query($sql);
$i = 1;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "<tr>
            <td>{$i}</td>
            <td>{$row['vendor_name']}</td>
            <td>{$row['contact_person']}</td>
            <td>{$row['mobile_number']}</td>
            <td>{$row['email']}</td>
            <td>{$row['city']}</td>
            <td>{$row['gst_number']}</td>
            <td>" . date('d M Y, h:i A', strtotime($row['created_at'])) . "</td>
            <td>
                <a href='vendor_edit.php?vendor_id={$row['vendor_id']}' class='btn btn-sm btn-info'>Edit</a>
                <a href='vendor_delete.php?vendor_id={$row['vendor_id']}' class='btn btn-sm btn-danger' onclick='return confirm(\"Are you sure?\")'>Delete</a>
            </td>
        </tr>";
        $i++;
    }
} else {
    echo "<tr><td colspan='9' class='text-center'>No vendors found.</td></tr>";
}

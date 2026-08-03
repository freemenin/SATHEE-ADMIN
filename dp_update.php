<?php
// FILE: set_sql_mode_root.php

// Root MySQL connection (no specific database selected)
$host     = "localhost";
$username = "root";       // replace with your root or privileged user
$password = "1e9093d23d3c398e";

// Connect to MySQL server (without database)
$mysqli = new mysqli($host, $username, $password);

// Check connection
if ($mysqli->connect_error) {
    die("<div style='
            margin:20px auto;
            max-width:600px;
            padding:15px;
            background:#ffecec;
            color:#d8000c;
            border:1px solid #d8000c;
            font-family:Arial, sans-serif;
            border-radius:6px;
        '>
        <h3>❌ Connection Failed</h3>
        <p>" . htmlspecialchars($mysqli->connect_error) . "</p>
    </div>");
}

// SQL Mode query (GLOBAL change)
$sql = "SET GLOBAL sql_mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'";

// Run query
if ($mysqli->query($sql) === TRUE) {
    echo "<div style='
            margin:20px auto;
            max-width:600px;
            padding:15px;
            background:#e9f9ec;
            color:#2d7a32;
            border:1px solid #2d7a32;
            font-family:Arial, sans-serif;
            border-radius:6px;
        '>
        <h3>✅ SQL Mode Updated (GLOBAL)</h3>
        <p><strong>Applied Mode:</strong><br>
        ERROR_FOR_DIVISION_BY_ZERO, NO_ZERO_DATE, NO_ZERO_IN_DATE, NO_ENGINE_SUBSTITUTION</p>
    </div>";
} else {
    echo "<div style='
            margin:20px auto;
            max-width:600px;
            padding:15px;
            background:#ffecec;
            color:#d8000c;
            border:1px solid #d8000c;
            font-family:Arial, sans-serif;
            border-radius:6px;
        '>
        <h3>❌ Error Updating SQL Mode</h3>
        <p>" . htmlspecialchars($mysqli->error) . "</p>
    </div>";
}

$mysqli->close();
?>

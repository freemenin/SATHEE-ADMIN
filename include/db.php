<?php
$host = "127.0.0.1";         // Change if your host is different
$username = "sathee";          // Your DB username
$password = "xPZeyCPNGLCBE8pe";              // Your DB password
$database = "sathee";  // Your database name

// Create connection
$mysqli = new mysqli($host, $username, $password, $database);

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Optional: Set charset to UTF-8
$mysqli->set_charset("utf8mb4");
date_default_timezone_set('Asia/Calcutta'); 
?>

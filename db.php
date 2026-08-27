<?php
$host     = "db";
$port     = "5432";
$dbname   = "elephant_db";
$user     = "postgres";
$password = "Namzom"; // หรือรหัสผ่านที่คุณตั้งไว้

// เติม sslmode=disable ไว้ท้ายสุด
$conn_string = "host={$host} port={$port} dbname={$dbname} user={$user} password={$password} sslmode=disable";

$db = pg_connect($conn_string);

if (!$db) {
    die("Database Connection Failed: " . pg_last_error());
}
?>

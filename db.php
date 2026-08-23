<?php
date_default_timezone_set('Asia/Bangkok');

// ข้อมูลเชื่อมต่อ DB...
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'elephant_db_2uc0';
$user = getenv('DB_USER') ?: 'postgres_elephant';
$password = getenv('DB_PASSWORD') ?: '2Ea4suoOfjTUjoyFfamIrUqRpSKOIEUt';

// เพิ่ม sslmode=require เพื่อให้รองรับ Render PostgreSQL
$conn_string = "host={$host} port={$port} dbname={$dbname} user={$user} password={$password} sslmode=require";

$db = @pg_connect($conn_string);

if (!$db) {
    die("Database Connection Failed: " . error_get_last()['message']);
}
?>

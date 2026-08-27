<?php
// การตั้งค่าการเชื่อมต่อฐานข้อมูล PostgreSQL บน Docker
$host     = "db";                // ชี้ไปที่ container 'db' ใน docker-compose
$port     = "5432";              // พอร์ตมาตรฐานของ PostgreSQL
$dbname   = "elephant_db";       // ชื่อฐานข้อมูล
$user     = "postgres";          // ชื่อผู้ใช้งาน
$password = "Namzom";            // รหัสผ่านฐานข้อมูล

// รวมคำสั่ง Connection String พร้อมปิด SSL (sslmode=disable)
$connection_string = "host={$host} port={$port} dbname={$dbname} user={$user} password={$password} sslmode=disable";

// ทำการเชื่อมต่อฐานข้อมูล
$db = pg_connect($connection_string);

// ตรวจสอบสถานะการเชื่อมต่อ
if (!$db) {
    die("❌ ไม่สามารถเชื่อมต่อฐานข้อมูล PostgreSQL ได้: " . pg_last_error());
}

// ตั้งค่าให้ระบบรองรับภาษาไทย (UTF-8) อย่างสมบูรณ์
pg_set_client_encoding($db, "UNICODE"); 
?>

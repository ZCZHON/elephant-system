<?php
// การตั้งค่าการเชื่อมต่อฐานข้อมูล PostgreSQL (XAMPP)
$host     = "localhost";
$port     = "5432";         // พอร์ตมาตรฐานของ PostgreSQL
$dbname   = "elephant_db";   // ชื่อฐานข้อมูลของคุณ
$user     = "postgres";      // ชื่อผู้ใช้งานหลักของ PostgreSQL
$password = "Namzom";        // รหัสผ่านฐานข้อมูลที่คุณตั้งไว้

// รวมคำสั่ง Connection String
$connection_string = "host={$host} port={$port} dbname={$dbname} user={$user} password={$password}";

// ทำการเชื่อมต่อฐานข้อมูล
$db = pg_connect($connection_string);

// ตรวจสอบสถานะการเชื่อมต่อ
if (!$db) {
    // หากเชื่อมต่อล้มเหลว จะแสดง Error ออกมาทางหน้าจอ (เหมาะสำหรับช่วงทดสอบระบบ)
    die("❌ ไม่สามารถเชื่อมต่อฐานข้อมูล PostgreSQL ได้: " . pg_last_error());
}

// ตั้งค่าให้ระบบรองรับภาษาไทย (UTF-8) อย่างสมบูรณ์
pg_set_client_encoding($db, "UNICODE"); 
?>
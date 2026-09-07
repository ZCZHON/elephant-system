<?php
// 1. กำหนด Timezone ให้ตรงกับประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// 2. ตั้งค่า Cookie Session ให้รองรับ HTTPS, Cloudflare Tunnel และ LIFF
// (ต้องตั้งค่าก่อนสั่ง session_start เสมอ)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,   // อายุ Session 1 วัน
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,    // บังคับใช้ HTTPS (Cloudflare Tunnel ใช้ HTTPS)
        'httponly' => true,    // ป้องกัน XSS เข้าถึง Cookie
        'samesite' => 'None'   // อนุญาตส่ง Cookie ข้ามโดเมน / Cloudflare / LIFF
    ]);
    session_start();
}

// 3. การตั้งค่าการเชื่อมต่อฐานข้อมูล PostgreSQL บน Docker
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

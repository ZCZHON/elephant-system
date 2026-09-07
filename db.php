<?php
// 1. กำหนด Timezone ให้ตรงกับประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// 2. ปรับปรุงการตั้งค่า Session Cookie (ป้องกันการทำงานซ้ำซ้อน)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // ปิด session ชั่วคราวหากมีการเปิดค้างไว้ก่อนหน้า
}

// ตั้งค่า Cookie Session ให้รองรับ HTTPS, Cloudflare Tunnel และ LIFF
session_set_cookie_params([
    'lifetime' => 86400,   // อายุ Session 1 วัน
    'path'     => '/',
    'domain'   => '',
    'secure'   => true,    // บังคับใช้ HTTPS
    'httponly' => true,    // ป้องกัน XSS
    'samesite' => 'None'   // อนุญาตส่ง Cookie ข้ามโดเมน / Cloudflare / LIFF
]);

// เปิด Session ใหม่อีกครั้งหลังจากตั้งค่าเรียบร้อย
session_start();

// 3. การตั้งค่าการเชื่อมต่อฐานข้อมูล PostgreSQL บน Docker
$host     = "db";                
$port     = "5432";              
$dbname   = "elephant_db";       
$user     = "postgres";          
$password = "Namzom";            

$connection_string = "host={$host} port={$port} dbname={$dbname} user={$user} password={$password} sslmode=disable";

$db = pg_connect($connection_string);

if (!$db) {
    die("❌ ไม่สามารถเชื่อมต่อฐานข้อมูล PostgreSQL ได้: " . pg_last_error());
}

pg_set_client_encoding($db, "UNICODE"); 
?>

<?php
// 1. กำหนด Timezone
date_default_timezone_set('Asia/Bangkok');

// 2. ตรวจสอบการเชื่อมต่อแบบ HTTPS
$is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// 3. จัดการ Session ให้ปลอดภัยและรองรับ LINE LIFF
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,              // เป็น true เมื่อใช้ HTTPS เท่านั้น
        'httponly' => true,                   // ป้องกัน JavaScript ดึง Cookie
        'samesite' => $is_https ? 'None' : 'Lax' // ใช้ None เฉพาะตอนเป็น HTTPS
    ]);
    session_start();
}

// 4. เชื่อมต่อฐานข้อมูล PostgreSQL
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

<?php
// 1. กำหนด Timezone
date_default_timezone_set('Asia/Bangkok');

// 2. จัดการ Session ป้องกัน Error
if (session_status() === PHP_SESSION_NONE) {
    // ถ้ายังไม่ได้เริ่ม Session ให้ตั้งค่า Cookie Parameters ก่อน
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
} else {
    // ถ้า Session เริ่มไปแล้ว ให้ส่ง Cookie ทับอีกครั้งเพื่อบังคับใช้ SameSite=None
    $session_id = session_id();
    if ($session_id) {
        setcookie(session_name(), $session_id, [
            'expires'  => time() + 86400,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'None'
        ]);
    }
}

// 3. เชื่อมต่อฐานข้อมูล PostgreSQL
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

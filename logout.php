<?php
// กำหนด Timezone ระดับ PHP
date_default_timezone_set('Asia/Bangkok');

// 🟢 1. ตั้งค่า Cookie ให้ตรงกับ login.php ก่อนเปิด Session
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => true,      // บังคับใช้ HTTPS
    'httponly' => true,    // ป้องกัน JavaScript เข้าถึง Cookie
    'samesite' => 'None'   // อนุญาตให้ส่ง Cookie ข้าม Domain/LIFF ได้
]);

session_start();

// 2. เคลียร์ค่าตัวแปรใน Session ทั้งหมด
$_SESSION = array();

// 3. ทำลาย Cookie ของ Session บนเบราว์เซอร์
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// 4. ทำลาย Session บน Server
session_destroy();

// 5. ส่งผู้ใช้งานกลับไปยังหน้าล็อกอิน
header("Location: login.php");
exit;
?>

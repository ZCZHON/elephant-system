<?php
// 1. เริ่มต้น Session
session_start();

// 2. เคลียร์ค่าตัวแปรใน Session ทั้งหมด
$_SESSION = array();

// 3. ทำลาย Cookie ของ Session (ถ้ามี) เพื่อความปลอดภัยสูงสุด
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

// 5. Redirect ส่งผู้ใช้งานกลับไปที่หน้า Login
header("Location: login.php");
exit;
?>
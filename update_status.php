<?php
// กำหนด Timezone ระดับ PHP
date_default_timezone_set('Asia/Bangkok');

// 🟢 1. ตั้งค่า Cookie Session ก่อนเริ่ม Session หรือ include ไฟล์อื่น
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']), // เปิดใช้งาน secure เฉพาะกรณีเชื่อมต่อผ่าน HTTPS
        'httponly' => true,                    // ป้องกัน JavaScript เข้าถึง Cookie
        'samesite' => 'Lax'                    // ปรับเป็น Lax เพื่อความปลอดภัยจาก CSRF
    ]);
    session_start();
}

// 🟢 2. เรียกใช้ไฟล์ฐานข้อมูลและสคริปต์แจ้งเตือน
include('db.php');

if (file_exists('send_geo_alert.php')) {
    include_once('send_geo_alert.php');
}

// กำหนด Response Header เป็น JSON
header('Content-Type: application/json; charset=utf-8');

// 🟢 3. ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode([
        'success' => false, 
        'message' => 'คุณไม่มีสิทธิ์ในการทำรายการนี้'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 🟢 4. รับค่า (รองรับทั้ง POST Form-Data และ JSON Payload)
$input = json_decode(file_get_contents('php://input'), true);
$report_id = intval($_POST['report_id'] ?? $input['report_id'] ?? 0);
$status    = trim($_POST['status'] ?? $input['status'] ?? '');

$allowed_statuses = ['pending', 'verified', 'rejected'];

if ($report_id <= 0 || !in_array($status, $allowed_statuses, true)) {
    echo json_encode([
        'success' => false, 
        'message' => 'ข้อมูลที่ส่งมาไม่ถูกต้อง'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 🟢 5. อัปเดตสถานะใน PostgreSQL
$query  = "UPDATE tbl_reports SET status = $1 WHERE report_id = $2";
$result = pg_query_params($db, $query, array($status, $report_id));

if ($result) {
    $message          = 'อัปเดตสถานะเรียบร้อยแล้ว';
    $redirect_url     = null;
    $alert_sent_count = 0;

    if ($status === 'verified') {
        // 🚨 ส่งแจ้งเตือนภัย LINE ให้คนที่อยู่ในรัศมี 5 กิโลเมตร
        if (function_exists('sendElephantAlert')) {
            $alert_sent_count = sendElephantAlert($report_id, $db);
        }

        $message      = 'ยืนยันข้อมูลเรียบร้อย! ส่งแจ้งเตือนภัยให้ผู้ใช้ในรัศมี 5 กม. แล้ว';
        $redirect_url = 'public_map.php?highlight_id=' . $report_id; 
    } elseif ($status === 'rejected') {
        $message = 'ปฏิเสธรายงานเรียบร้อยแล้ว';
    }

    echo json_encode([
        'success'      => true, 
        'message'      => $message,
        'status'       => $status,
        'redirect_url' => $redirect_url,
        'alert_sent'   => $alert_sent_count
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูลลงฐานข้อมูล'
    ], JSON_UNESCAPED_UNICODE);
}
?>

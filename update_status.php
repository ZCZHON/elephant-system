<?php
// กำหนด Timezone ระดับ PHP
date_default_timezone_set('Asia/Bangkok');

include('db.php');
include('send_geo_alert.php'); // 1. ดึงไฟล์ส่งแจ้งเตือนเข้ามาร่วมใช้งาน

// 🟢 ตั้งค่า Cookie ให้ตรงกับระบบ (รองรับ HTTPS และข้าม Frame/Domain)
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => true,      // บังคับใช้ HTTPS
    'httponly' => true,    // ป้องกัน JavaScript เข้าถึง Cookie
    'samesite' => 'None'   // อนุญาตให้ส่ง Cookie ข้าม Domain/LIFF ได้
]);

session_start();

header('Content-Type: application/json; charset=utf-8');

// 1. ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'คุณไม่มีสิทธิ์ในการทำรายการนี้']);
    exit();
}

// 2. รับค่า (รองรับทั้ง POST Form-Data และ JSON Payload)
$input = json_decode(file_get_contents('php://input'), true);
$report_id = intval($_POST['report_id'] ?? $input['report_id'] ?? 0);
$status    = trim($_POST['status'] ?? $input['status'] ?? '');

$allowed_statuses = ['pending', 'verified', 'rejected'];

if ($report_id <= 0 || !in_array($status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit();
}

// 3. อัปเดตสถานะใน PostgreSQL
$query = "UPDATE tbl_reports SET status = $1 WHERE report_id = $2";
$result = pg_query_params($db, $query, array($status, $report_id));

if ($result) {
    // กำหนดข้อความและลิงก์ที่จะไปต่อ
    $message = 'อัปเดตสถานะเรียบร้อยแล้ว';
    $redirect_url = null;
    $alert_sent_count = 0;

    if ($status === 'verified') {
        // 🚨 2. ส่งแจ้งเตือนภัย LINE ให้คนที่อยู่ในรัศมี 5 กิโลเมตร
        if (function_exists('sendElephantAlert')) {
            $alert_sent_count = sendElephantAlert($report_id, $db);
        }

        $message = 'ยืนยันข้อมูลเรียบร้อย! ส่งแจ้งเตือนภัยให้ผู้ใช้ในรัศมี 5 กม. แล้ว';
        $redirect_url = 'public_map.php?highlight_id=' . $report_id; 
    } elseif ($status === 'rejected') {
        $message = 'ปฏิเสธรายงานเรียบร้อยแล้ว';
    }

    echo json_encode([
        'success'      => true, 
        'message'      => $message,
        'status'       => $status,
        'redirect_url' => $redirect_url,
        'alert_sent'   => $alert_sent_count // ส่งจำนวนคนที่ได้รับแจ้งเตือนกลับไป
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล']);
}
?>

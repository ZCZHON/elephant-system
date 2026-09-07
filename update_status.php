<?php
// update_status.php
date_default_timezone_set('Asia/Bangkok');

// 🟢 1. เริ่ม Buffer ป้องกันไม่ให้ PHP พ่น Warning/Error หรือ HTML ออกมาก่อน JSON
ob_start();

// 🟢 2. ตั้งค่า Cookie Session ก่อนเริ่ม Session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 🟢 3. เรียกใช้ไฟล์ฐานข้อมูลและสคริปต์แจ้งเตือน
include('db.php');

if (file_exists('send_geo_alert.php')) {
    include_once('send_geo_alert.php');
}

// ล้าง Output หรือ Warning แปลกปลอมจาก db.php ก่อนส่ง Header
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// 🟢 4. ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode([
        'success' => false, 
        'message' => 'คุณไม่มีสิทธิ์ในการทำรายการนี้'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 🟢 5. รับค่า (รองรับทั้ง POST Form-Data และ JSON Payload)
$input     = json_decode(file_get_contents('php://input'), true);
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

// 🟢 6. อัปเดตสถานะใน PostgreSQL
$query  = "UPDATE tbl_reports SET status = $1 WHERE report_id = $2";
$result = pg_query_params($db, $query, array($status, $report_id));

if ($result) {
    $message          = 'อัปเดตสถานะเรียบร้อยแล้ว';
    $redirect_url     = null;
    $alert_sent_count = 0;

    if ($status === 'verified') {
        // 🚨 ดักจับ Error การส่ง LINE ป้องกันไม่ให้โค้ดพังหากส่ง LINE ไม่ผ่าน
        try {
            if (function_exists('sendElephantAlert')) {
                $alert_sent_count = sendElephantAlert($report_id, $db);
            }
        } catch (Throwable $e) {
            error_log("LINE Alert Error: " . $e->getMessage());
        }

        $message      = 'ยืนยันข้อมูลเรียบร้อย! ส่งแจ้งเตือนภัยให้ผู้ใช้ในรัศมี 5 กม. แล้ว (ส่งสำเร็จ ' . $alert_sent_count . ' ราย)';
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

// ส่งข้อมูลออกไปและปิด Buffer
ob_end_flush();
exit();
?>

<?php
include('db.php');
session_start();

header('Content-Type: application/json; charset=utf-8');

// 1. ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'คุณไม่มีสิทธิ์ในการทำรายการนี้']);
    exit();
}

// 2. รับค่า
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

    if ($status === 'verified') {
        $message = 'ยืนยันข้อมูลเรียบร้อย! กำลังนำคุณไปยังหน้าแผนที่สาธารณะ...';
        // 🗺️ ใส่ชื่อไฟล์หน้าแผนที่สาธารณะของคุณตรงนี้ (เช่น public_map.php หรือ dashboard.php)
        $redirect_url = 'public_map.php?highlight_id=' . $report_id; 
    } elseif ($status === 'rejected') {
        $message = 'ระบุเป็นข้อมูลเท็จเรียบร้อยแล้ว';
    }

    echo json_encode([
        'success' => true, 
        'message' => $message,
        'status'  => $status,
        'redirect_url' => $redirect_url
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล']);
}
?>
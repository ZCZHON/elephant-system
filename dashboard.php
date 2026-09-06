<?php
// กำหนด Timezone ระดับ PHP
date_default_timezone_set('Asia/Bangkok');

include('db.php');

// 🟢 ตั้งค่า Cookie Session
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

session_start();

// ตัวแปรเช็กสิทธิ์สำหรับแสดง UI
$is_logged_in = isset($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? 'user';
$user_name = $_SESSION['fullname'] ?? $_SESSION['user_name'] ?? 'ผู้ใช้งาน';

// 🟢 1. รับค่าการกรองช่วงเวลา (Days Range: 7, 15, 30 หรือ all)
$days = isset($_GET['range']) ? $_GET['range'] : '7';
$date_condition = "";

if ($days === '7') {
    $date_condition = " AND reported_at >= NOW() - INTERVAL '7 days' ";
} elseif ($days === '15') {
    $date_condition = " AND reported_at >= NOW() - INTERVAL '15 days' ";
} elseif ($days === '30') {
    $date_condition = " AND reported_at >= NOW() - INTERVAL '30 days' ";
} else {
    $days = 'all';
    $date_condition = "";
}

// 📊 2. ดึงสถิติตัวเลขภาพรวม (Stat Cards)
// 2.1 จำนวนช้างป่าที่พบทั้งหมด (รวมตามจำนวนตัว)
$q_elephants = pg_query($db, "SELECT COALESCE(SUM(elephant_count), 0) as total_elephants, COUNT(report_id) as total_reports FROM tbl_reports WHERE status IN ('verified', 'approved') {$date_condition}");
$r_elephants = pg_fetch_assoc($q_elephants);
$total_elephants = $r_elephants['total_elephants'];
$total_reports = $r_elephants['total_reports'];

// 2.2 จำนวนอาสาสมัคร/ผู้ลงทะเบียนทั้งหมดในระบบ
$q_volunteers = pg_query($db, "SELECT COUNT(user_id) as total_volunteers FROM tbl_users");
$r_volunteers = pg_fetch_assoc($q_volunteers);
$total_volunteers = $r_volunteers['total_volunteers'];

// 2.3 จำนวนรายงานรอตรวจสอบ (Pending)
$q_pending = pg_query($db, "SELECT COUNT(report_id) as total_pending FROM tbl_reports WHERE status = 'pending' {$date_condition}");
$r_pending = pg_fetch_assoc($q_pending);
$total_pending = $r_pending['total_pending'];

// 📜 3. ดึงประวัติรายการรายงานล่าสุด (Latest Reports List)
$q_history = pg_query($db, "SELECT r.report_id, r.elephant_count, r.behavior_type, r.details, r.photo_path, r.reported_at, r.status, u.first_name, u.last_name 
                            FROM tbl_reports r 
                            LEFT JOIN tbl_users u ON r.user_id = u.user_id 
                            WHERE 1=1 {$date_condition}
                            ORDER BY r.reported_at DESC LIMIT 10");
$history_list = [];
if ($q_history) {
    while ($row = pg_fetch_assoc($q_history)) {
        $history_list[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>แดชบอร์ดภาพรวม - ระบบติดตามช้างป่า</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background-color: #122112; 
            color: #fff; 
            min-height: 100vh; 
        }
        .nav-custom { 
            background-color: rgba(14, 34, 14, 0.95); 
            backdrop-filter: blur(8px); 
        }
        .stat-card {
            border-radius: 16px;
            padding: 20px;
            color: #fff;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .bg-card-1 { background: linear-gradient(135deg, #2e7d32, #1b5e20); }
        .bg-card-2 { background: linear-gradient(135deg, #0288d1, #01579b); }
        .bg-card-3 { background: linear-gradient(135deg, #ed6c02, #e65100); }
        .bg-card-4 { background: linear-gradient(135deg, #9c27b0, #6a1b9a); }
        
        .content-card {
            background: rgba(255, 255, 255, 0.96);
            border-radius: 20px;
            color: #333;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }
        .badge-status-verified { background-color: #198754; }
        .badge-status-pending { background-color: #ffc107; color: #000; }
        .img-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; }
    </style>
</head>
<body>

    <!-- 🟢 UNIFIED SYSTEM NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark nav-custom mb-4 shadow-sm border-bottom border-success">
        <div class="container-fluid container-md">
            <a class="navbar-brand fw-bold text-warning fs-6" href="index.php">
                🐘 <span class="d-none d-sm-inline">ระบบติดตามการกระจายตัวของช้างป่า</span>
                <span class="d-inline d-sm-none">ติดตามช้างป่า</span>
            </a>
            
            <div class="d-flex align-items-center gap-1 gap-sm-2">
                <?php if ($is_logged_in): ?>
                    <span class="text-white small me-1 d-none d-md-inline">
                        👤 <?= htmlspecialchars($user_name) ?>
                        <span class="badge bg-<?= $user_role === 'admin' ? 'danger' : 'success' ?> ms-1"><?= strtoupper($user_role) ?></span>
                    </span>
                <?php endif; ?>

                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>

                <a href="index.php" class="btn btn-<?= $current_page === 'index.php' ? 'success' : 'outline-light' ?> btn-sm fw-bold">
                    ➕ <span class="d-none d-sm-inline">ส่งรายงาน</span><span class="d-inline d-sm-none">รายงาน</span>
                </a>

                <a href="report.php" class="btn btn-<?= $current_page === 'report.php' ? 'warning' : 'outline-warning' ?> btn-sm fw-bold">
                    📜 <span class="d-none d-sm-inline">ประวัติรายงาน</span><span class="d-inline d-sm-none">ประวัติ</span>
                </a>

                <a href="dashboard.php" class="btn btn-<?= $current_page === 'dashboard.php' ? 'info text-white' : 'outline-info' ?> btn-sm fw-bold">
                    📊 <span class="d-none d-sm-inline">Dashboard</span><span class="d-inline d-sm-none">สถิติ</span>
                </a>

                <a href="public_map.php" class="btn btn-<?= $current_page === 'public_map.php' ? 'info text-white' : 'outline-info' ?> btn-sm fw-bold">
                    🗺️ <span class="d-none d-sm-inline">แผนที่</span><span class="d-inline d-sm-none">แผนที่</span>
                </a>

                <?php if ($user_role === 'admin'): ?>
                    <a href="admin_dashboard.php" class="btn btn-<?= $current_page === 'admin_dashboard.php' ? 'danger' : 'outline-danger' ?> btn-sm fw-bold shadow-sm">
                        ⚙️ <span class="d-none d-sm-inline">จัดการระบบ</span><span class="d-inline d-sm-none">Admin</span>
                    </a>
                <?php endif; ?>

                <?php if ($is_logged_in): ?>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm ms-1" title="ออกจากระบบ">🔴 <span class="d-none d-md-inline">ออก</span></a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-success btn-sm ms-1 fw-bold">🔑 เข้าสู่ระบบ</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid container-md mb-5">
        
        <!-- 🟢 ตัวเลือกกรองช่วงเวลา (Filter Range) -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="fw-bold text-warning m-0"><i class="fa-solid fa-chart-line me-2"></i>สรุปสถิติสถานการณ์ช้างป่า</h5>
            <div class="btn-group shadow-sm" role="group">
                <a href="dashboard.php?range=7" class="btn btn-sm <?= $days === '7' ? 'btn-success fw-bold' : 'btn-outline-light' ?>">7 วัน</a>
                <a href="dashboard.php?range=15" class="btn btn-sm <?= $days === '15' ? 'btn-success fw-bold' : 'btn-outline-light' ?>">15 วัน</a>
                <a href="dashboard.php?range=30" class="btn btn-sm <?= $days === '30' ? 'btn-success fw-bold' : 'btn-outline-light' ?>">30 วัน</a>
                <a href="dashboard.php?range=all" class="btn btn-sm <?= $days === 'all' ? 'btn-success fw-bold' : 'btn-outline-light' ?>">ทั้งหมด</a>
            </div>
        </div>

        <!-- 📊 STAT CARDS -->
        <div class="row g-3 mb-4">
            <!-- 1. จำนวนช้างที่พบทั้งหมด -->
            <div class="col-6 col-lg-3">
                <div class="stat-card bg-card-1">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small text-white-50 fw-bold">จำนวนช้างที่พบ</div>
                            <h2 class="fw-bold my-1"><?= number_format($total_elephants) ?> <span class="fs-6 fw-normal">ตัว</span></h2>
                            <div class="small text-white-50">จาก <?= number_format($total_reports) ?> ครั้งที่ยืนยัน</div>
                        </div>
                        <div class="fs-1 opacity-50"><i class="fa-solid fa-elephant"></i></div>
                    </div>
                </div>
            </div>

            <!-- 2. จำนวนอาสาสมัคร/ผู้ใช้งาน -->
            <div class="col-6 col-lg-3">
                <div class="stat-card bg-card-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small text-white-50 fw-bold">อาสาสมัครในระบบ</div>
                            <h2 class="fw-bold my-1"><?= number_format($total_volunteers) ?> <span class="fs-6 fw-normal">คน</span></h2>
                            <div class="small text-white-50">ผู้ลงทะเบียนทั้งหมด</div>
                        </div>
                        <div class="fs-1 opacity-50"><i class="fa-solid fa-users"></i></div>
                    </div>
                </div>
            </div>

            <!-- 3. จำนวนเคสรอดำเนินการ -->
            <div class="col-6 col-lg-3">
                <div class="stat-card bg-card-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small text-white-50 fw-bold">รอดำเนินการ</div>
                            <h2 class="fw-bold my-1"><?= number_format($total_pending) ?> <span class="fs-6 fw-normal">เคส</span></h2>
                            <div class="small text-white-50">รอเจ้าหน้าที่ตรวจสอบ</div>
                        </div>
                        <div class="fs-1 opacity-50"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    </div>
                </div>
            </div>

            <!-- 4. จำนวนรายงานรวมช่วงเวลา -->
            <div class="col-6 col-lg-3">
                <div class="stat-card bg-card-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small text-white-50 fw-bold">การแจ้งเหตุทั้งหมด</div>
                            <h2 class="fw-bold my-1"><?= number_format($total_reports + $total_pending) ?> <span class="fs-6 fw-normal">ครั้ง</span></h2>
                            <div class="small text-white-50">ช่วง <?= $days === 'all' ? 'ทั้งหมด' : $days . ' วันล่าสุด' ?></div>
                        </div>
                        <div class="fs-1 opacity-50"><i class="fa-solid fa-clipboard-list"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📜 TABLE: ประวัติการรายงานล่าสุด -->
        <div class="content-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-success m-0"><i class="fa-solid fa-list-check me-2"></i>ประวัติการรายงานล่าสุด</h5>
                <a href="report.php" class="btn btn-sm btn-outline-success">ดูทั้งหมด</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="width: 70px;">รูปภาพ</th>
                            <th scope="col">รายละเอียดเหตุการณ์</th>
                            <th scope="col" class="text-center" style="width: 90px;">จำนวน</th>
                            <th scope="col" class="d-none d-md-table-cell">ผู้แจ้ง</th>
                            <th scope="col">เวลาแจ้ง</th>
                            <th scope="col" class="text-center">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($history_list) > 0): ?>
                            <?php foreach ($history_list as $item): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($item['photo_path'])): ?>
                                            <img src="<?= htmlspecialchars($item['photo_path']) ?>" class="img-thumb" alt="ช้างป่า">
                                        <?php else: ?>
                                            <div class="img-thumb bg-secondary text-white d-flex align-items-center justify-content-center small">ไม่มีรูป</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($item['behavior_type'] ?: 'ไม่ระบุพฤติกรรม') ?></div>
                                        <div class="small text-muted text-truncate" style="max-width: 200px;"><?= htmlspecialchars($item['details'] ?: '-') ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger rounded-pill fs-6"><?= intval($item['elephant_count']) ?> ตัว</span>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <small class="fw-semibold text-secondary">
                                            <?= htmlspecialchars(trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? '')) ?: 'อาสาสมัคร') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= date('d/m/Y H:i', strtotime($item['reported_at'])) ?> น.
                                        </small>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($item['status'] === 'verified' || $item['status'] === 'approved'): ?>
                                            <span class="badge badge-status-verified">ยืนยันแล้ว</span>
                                        <?php else: ?>
                                            <span class="badge badge-status-pending">รอดำเนินการ</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">ไม่พบข้อมูลรายงานในช่วงเวลานี้</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

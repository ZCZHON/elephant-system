<?php
// กำหนด Timezone ระดับ PHP
date_default_timezone_set('Asia/Bangkok');

include('db.php');

// 🟢 ตั้งค่า Cookie ให้ตรงกับ login.php (รองรับ HTTPS และข้าม Frame/Domain)
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => true,      // บังคับใช้ HTTPS
    'httponly' => true,    // ป้องกัน JavaScript เข้าถึง Cookie
    'samesite' => 'None'   // อนุญาตให้ส่ง Cookie ข้าม Domain/LIFF ได้
]);

session_start();

// 🔒 ล็อกความปลอดภัย: ถ้าไม่ได้ล็อกอิน หรือไม่ได้เป็น admin ให้เด้งไปหน้า login.php
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

$alert_script = "";

// ➕ ระบบประมวลผลเพิ่ม Admin ใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin') {
    $username   = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $phone      = trim($_POST['phone_number'] ?? '');
    $raw_pass   = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($raw_pass)) {
        // เช็ก username ซ้ำในระบบ
        $check_q = "SELECT user_id FROM tbl_users WHERE username = $1";
        $check_res = pg_query_params($db, $check_q, array($username));

        if ($check_res && pg_num_rows($check_res) > 0) {
            $alert_script = "Swal.fire('เกิดข้อผิดพลาด', 'ชื่อผู้ใช้งาน (Username) นี้มีในระบบแล้ว', 'warning');";
        } else {
            // Hash รหัสผ่านเพื่อความปลอดภัย
            $password_hash = password_hash($raw_pass, PASSWORD_DEFAULT);

            $insert_q = "INSERT INTO tbl_users (username, password, first_name, last_name, phone_number, role, registered_at) 
                         VALUES ($1, $2, $3, $4, $5, 'admin', NOW())";
            $insert_res = pg_query_params($db, $insert_q, array($username, $password_hash, $first_name, $last_name, $phone));

            if ($insert_res) {
                $alert_script = "Swal.fire('สำเร็จ!', 'เพิ่มเจ้าหน้าที่ Admin คนใหม่เรียบร้อยแล้ว', 'success');";
            } else {
                $alert_script = "Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถบันทึกข้อมูลลงฐานข้อมูลได้', 'error');";
            }
        }
    } else {
        $alert_script = "Swal.fire('คำเตือน', 'กรุณากรอก Username และ Password ให้ครบถ้วน', 'warning');";
    }
}

// 1. ดึงข้อมูลรายงานแจ้งเหตุทั้งหมด
$query = "SELECT r.*, 
                 CONCAT(u.first_name, ' ', u.last_name) AS fullname,
                 u.phone_number 
          FROM tbl_reports r 
          LEFT JOIN tbl_users u ON r.user_id = u.user_id 
          ORDER BY r.reported_at DESC";

$result = pg_query($db, $query);
$reports = ($result) ? pg_fetch_all($result) ?: [] : [];

// คำนวณสถิติ
$total_reports = count($reports);
$pending_count = 0;
$verified_count = 0;
$rejected_count = 0;

foreach ($reports as $r) {
    $st = $r['status'] ?? 'pending';
    if ($st === 'pending') $pending_count++;
    elseif ($st === 'verified') $verified_count++;
    elseif ($st === 'rejected') $rejected_count++;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ระบบจัดการข้อมูลสำหรับเจ้าหน้าที่ (Admin Dashboard)</title>
    
    <!-- Google Fonts, Bootstrap 5, FontAwesome, SweetAlert2 -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f4f6f9;
            color: #333;
        }
        .navbar-admin {
            background-color: #1b4332;
        }
        .card-stat {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            background: #fff;
        }
        .card-stat:hover {
            transform: translateY(-2px);
        }
        .table-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            background: #fff;
        }
        .img-report {
            width: 55px;
            height: 55px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 1px solid #dee2e6;
            transition: transform 0.15s ease-in-out;
        }
        .img-report:hover {
            transform: scale(1.08);
            border-color: #198754;
        }
        
        /* 📱 Mobile Adjustments */
        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.85rem;
            }
            .btn-action {
                padding: 4px 8px;
                font-size: 0.75rem;
            }
            .card-stat h3 {
                font-size: 1.4rem !important;
            }
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-admin shadow-sm">
        <div class="container-fluid container-md">
            <a class="navbar-brand fw-bold" href="admin_dashboard.php">
                🐘 Admin Management
            </a>
            
            <div class="d-flex align-items-center gap-2">
                <span class="text-white me-2 d-none d-md-inline small">
                    <i class="fa-solid fa-user-shield me-1 text-warning"></i>
                    <?php echo htmlspecialchars($_SESSION['fullname'] ?? 'เจ้าหน้าที่'); ?>
                </span>
                
                <a href="public_map.php" class="btn btn-outline-light btn-sm fw-bold">
                    <i class="fa-solid fa-map-location-dot me-1"></i>
                    <span class="d-none d-sm-inline">แผนที่</span> (Public Map)
                </a>
                
                <a href="logout.php" class="btn btn-danger btn-sm fw-bold">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span class="d-none d-sm-inline ms-1">ออกจากระบบ</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid container-md my-4">
        
        <!-- Header + ปุ่มเพิ่ม Admin -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h4 class="fw-bold text-dark mb-0">
                    <i class="fa-solid fa-sliders me-2 text-success"></i>ระบบจัดการรายงานช้างป่า
                </h4>
                <p class="text-muted small mb-0 d-none d-sm-block">ตรวจสอบและยืนยันข้อมูลจากอาสาสมัครก่อนแสดงบนแผนที่สาธารณะ</p>
            </div>
            
            <!-- ปุ่มเปิด Modal เพิ่ม Admin -->
            <button type="button" class="btn btn-success btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                <i class="fa-solid fa-user-plus me-1"></i> เพิ่ม Admin ใหม่
            </button>
        </div>

        <!-- สรุปสถิติ 4 ช่อง -->
        <div class="row g-2 mb-4">
            <div class="col-6 col-md-3">
                <div class="card card-stat p-3 border-start border-primary border-4">
                    <div class="text-muted small fw-bold">รายงานทั้งหมด</div>
                    <h3 class="fw-bold text-primary mb-0 mt-1"><?php echo number_format($total_reports); ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-stat p-3 border-start border-warning border-4">
                    <div class="text-muted small fw-bold">รอตรวจสอบ (Pending)</div>
                    <h3 class="fw-bold text-warning mb-0 mt-1"><?php echo number_format($pending_count); ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-stat p-3 border-start border-success border-4">
                    <div class="text-muted small fw-bold">อนุมัติแล้ว (Verified)</div>
                    <h3 class="fw-bold text-success mb-0 mt-1"><?php echo number_format($verified_count); ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-stat p-3 border-start border-danger border-4">
                    <div class="text-muted small fw-bold">ปฏิเสธ (Rejected)</div>
                    <h3 class="fw-bold text-danger mb-0 mt-1"><?php echo number_format($rejected_count); ?></h3>
                </div>
            </div>
        </div>

        <!-- ตารางจัดการข้อมูล -->
        <div class="table-card p-3 p-md-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 70px;">รูปภาพ</th>
                            <th>เวลาแจ้งเหตุ</th>
                            <th>ผู้แจ้ง / เบอร์โทร</th>
                            <th>จำนวน</th>
                            <th>พฤติกรรม</th>
                            <th>รายละเอียด / พิกัด</th>
                            <th>สถานะ</th>
                            <th class="text-center" style="min-width: 130px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($reports)): ?>
                            <?php foreach ($reports as $row): ?>
                                <tr id="row-<?php echo $row['report_id']; ?>">
                                    <!-- รูปภาพเปิดดูด้วย Modal -->
                                    <td>
                                        <?php if (!empty($row['photo_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($row['photo_path']); ?>" 
                                                 class="img-report" 
                                                 alt="รูปช้าง"
                                                 title="กดเพื่อขยายรูปภาพ"
                                                 onclick="openImageModal('<?php echo htmlspecialchars($row['photo_path']); ?>')">
                                        <?php else: ?>
                                            <div class="bg-light text-muted text-center rounded py-2 small" style="width:55px; height:55px; line-height:38px;">
                                                <i class="fa-regular fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- เวลาที่รายงาน -->
                                    <td class="small">
                                        <div class="fw-bold text-dark">
                                            <?php echo date('d/m/Y', strtotime($row['reported_at'] ?? $row['created_at'])); ?>
                                        </div>
                                        <div class="text-muted">
                                            <?php echo date('H:i น.', strtotime($row['reported_at'] ?? $row['created_at'])); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="fw-bold text-dark small"><?php echo htmlspecialchars($row['fullname'] ?: 'ผู้ใช้งาน LINE'); ?></div>
                                        <a href="tel:<?php echo htmlspecialchars($row['phone_number'] ?? ''); ?>" class="text-decoration-none text-muted small">
                                            <i class="fa-solid fa-phone fa-xs me-1"></i><?php echo htmlspecialchars(($row['phone_number'] ?? '') ?: '-'); ?>
                                        </a>
                                    </td>

                                    <td>
                                        <span class="badge bg-danger rounded-pill px-2 fs-6">
                                             <?php echo $row['elephant_count']; ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?php echo htmlspecialchars(($row['behavior_type'] ?? $row['behavior'] ?? '') ?: 'ไม่ระบุ'); ?>
                                        </span>
                                    </td>

                                    <td class="small" style="max-width: 220px;">
                                        <div class="text-truncate" title="<?php echo htmlspecialchars($row['details']); ?>">
                                            <?php echo htmlspecialchars($row['details'] ?: '-'); ?>
                                        </div>
                                        <small class="text-primary d-block">
                                            <i class="fa-solid fa-location-dot me-1"></i><?php echo $row['latitude']; ?>, <?php echo $row['longitude']; ?>
                                        </small>
                                    </td>

                                    <td id="status-badge-<?php echo $row['report_id']; ?>">
                                        <?php if ($row['status'] === 'verified'): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Verified</span>
                                        <?php elseif ($row['status'] === 'rejected'): ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Rejected</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i>Pending</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button onclick="updateStatus(<?php echo $row['report_id']; ?>, 'verified')" 
                                                    class="btn btn-success btn-action" 
                                                    title="อนุมัติและแสดงบนแผนที่">
                                                <i class="fa-solid fa-check"></i> <span class="d-none d-md-inline">อนุมัติ</span>
                                            </button>
                                            <button onclick="updateStatus(<?php echo $row['report_id']; ?>, 'rejected')" 
                                                    class="btn btn-outline-danger btn-action" 
                                                    title="ปฏิเสธรายงานนี้">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-folder-open fa-2x mb-2 text-secondary opacity-50"></i>
                                    <div>ยังไม่มีรายการแจ้งเหตุในระบบ</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ➕ Modal ฟอร์มเพิ่ม Admin ใหม่ -->
    <div class="modal fade" id="addAdminModal" tabindex="-1" aria-labelledby="addAdminModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="admin_dashboard.php" method="POST">
                    <input type="hidden" name="action" value="add_admin">
                    <div class="modal-header bg-success text-white py-2">
                        <h6 class="modal-title fw-bold" id="addAdminModalLabel">
                            <i class="fa-solid fa-user-plus me-2"></i>เพิ่มบัญชีเจ้าหน้าที่ (Admin)
                        </h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">ชื่อผู้ใช้งาน (Username) <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" placeholder="เช่น officer01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">รหัสผ่าน (Password) <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" placeholder="กรอกรหัสผ่านอย่างน้อย 6 ตัวอักษร" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-secondary">ชื่อจริง</label>
                                <input type="text" name="first_name" class="form-control" placeholder="ชื่อ">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-secondary">นามสกุล</label>
                                <input type="text" name="last_name" class="form-control" placeholder="นามสกุล">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-secondary">เบอร์โทรศัพท์</label>
                            <input type="tel" name="phone_number" class="form-control" placeholder="08X-XXX-XXXX">
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-success btn-sm fw-bold px-3">
                            <i class="fa-solid fa-save me-1"></i>บันทึก Admin ใหม่
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 🖼️ Modal ป๊อปอัปขยายรูปภาพ -->
    <div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-labelledby="imagePreviewLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark text-white border-0 shadow-lg">
                <div class="modal-header border-secondary py-2">
                    <h6 class="modal-title fw-bold" id="imagePreviewLabel">
                        <i class="fa-solid fa-image me-2 text-warning"></i>รูปภาพจากรายงาน
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center p-2">
                    <img id="modalImageTarget" src="" class="img-fluid rounded" style="max-height: 75vh; object-fit: contain;" alt="รูปภาพขยาย">
                </div>
                <div class="modal-footer border-secondary py-2 justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm px-3 fw-bold" data-bs-dismiss="modal">
                        <i class="fa-solid fa-xmark me-1"></i>ปิดหน้าต่าง
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 🔔 เรียกใช้ SweetAlert2 จากฝั่ง PHP
        <?php if (!empty($alert_script)) echo $alert_script; ?>

        // 🖼️ เปิด Modal แสดงรูปใหญ่
        function openImageModal(imageSrc) {
            document.getElementById('modalImageTarget').src = imageSrc;
            var imageModal = new bootstrap.Modal(document.getElementById('imagePreviewModal'));
            imageModal.show();
        }

        // ⚡ อัปเดตสถานะ อนุมัติ / ปฏิเสธ (ใช้ SweetAlert2)
        function updateStatus(reportId, newStatus) {
            var actionText = newStatus === 'verified' ? 'อนุมัติ' : 'ปฏิเสธ';
            var confirmBtnColor = newStatus === 'verified' ? '#198754' : '#dc3545';

            Swal.fire({
                title: `ยืนยันการ${actionText}?`,
                text: `คุณต้องการ "${actionText}" รายการแจ้งเหตุนี้ใช่หรือไม่?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: confirmBtnColor,
                cancelButtonColor: '#6c757d',
                confirmButtonText: `ใช่, ${actionText}`,
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'กำลังอัปเดตข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

                    fetch('update_status.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `report_id=${reportId}&status=${newStatus}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        Swal.close();
                        if (data.success) {
                            var badgeCell = document.getElementById('status-badge-' + reportId);
                            
                            if (newStatus === 'verified') {
                                badgeCell.innerHTML = '<span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Verified</span>';
                                Swal.fire({
                                    title: 'อนุมัติเรียบร้อยแล้ว!',
                                    text: 'ต้องการเปิดไปดูตำแหน่งช้างบนแผนที่สาธารณะเลยหรือไม่?',
                                    icon: 'success',
                                    showCancelButton: true,
                                    confirmButtonColor: '#198754',
                                    confirmButtonText: 'เปิดแผนที่',
                                    cancelButtonText: 'อยู่ในหน้านี้ต่อ'
                                }).then((mapResult) => {
                                    if (mapResult.isConfirmed) {
                                        window.location.href = 'public_map.php?highlight_id=' + reportId;
                                    }
                                });
                            } else {
                                badgeCell.innerHTML = '<span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Rejected</span>';
                                Swal.fire('ปฏิเสธรายการแล้ว', 'อัปเดตสถานะเป็น Rejected เรียบร้อย', 'info');
                            }
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด', data.message || 'ไม่สามารถอัปเดตสถานะได้', 'error');
                        }
                    })
                    .catch(error => {
                        Swal.close();
                        console.error('Error:', error);
                        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
                    });
                }
            });
        }
    </script>
</body>
</html>

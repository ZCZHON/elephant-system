<?php
include('db.php');
session_start();

// 🔒 ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['fullname'] ?? 'ผู้ใช้งานระบบ';
$user_role = $_SESSION['role'] ?? 'user';

$error_msg = null;
$success_msg = false;

// 🐘 ประมวลผลเมื่อมีการส่งฟอร์มรายงาน
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $latitude       = trim($_POST['latitude'] ?? '');
    $longitude      = trim($_POST['longitude'] ?? '');
    $elephant_count = $_POST['elephant_count'] ?? 1;
    $behavior_type  = $_POST['behavior_type'] ?? '';
    $details        = $_POST['details'] ?? '';

    // 🔒 Validation: บังคับต้องระบุพิกัด และแนบรูปภาพเท่านั้น
    $has_photo = isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK && $_FILES['photo']['size'] > 0;

    if (empty($latitude) || empty($longitude)) {
        $error_msg = "กรุณาเลือกตำแหน่งพิกัดบนแผนที่ก่อนส่งรายงาน";
    } elseif (!$has_photo) {
        $error_msg = "กรุณาแนบรูปภาพประกอบการรายงาน";
    } else {
        // จัดการอัปโหลดไฟล์รูปภาพ
        $ext        = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $new_name   = 'report_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $target_dir = 'uploads/';
        
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $target_file = $target_dir . $new_name;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
            $photo_path = $target_file;

            // บันทึกลง PostgreSQL (แปลง Type ::double precision)
            $query = "INSERT INTO tbl_reports (user_id, latitude, longitude, elephant_count, behavior_type, details, photo_path, status, reported_at, geom) 
                      VALUES ($1, $2::double precision, $3::double precision, $4, $5, $6, $7, 'pending', NOW(), ST_SetSRID(ST_MakePoint($3::double precision, $2::double precision), 4326))";
            
            $result = pg_query_params($db, $query, array(
                $user_id, 
                $latitude, 
                $longitude, 
                $elephant_count, 
                $behavior_type, 
                $details, 
                $photo_path
            ));

            if ($result) {
                $success_msg = true;
            } else {
                $error_msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูลลงฐานข้อมูล";
            }
        } else {
            $error_msg = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์รูปภาพ";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบติดตามการกระจายตัวของช้างป่าในประเทศไทย</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Sarabun', sans-serif; background: linear-gradient(rgba(14, 34, 14, 0.75), rgba(14, 34, 14, 0.75)), url('https://images.unsplash.com/photo-1516026672322-bc52d61a55d5?q=80&w=1920') no-repeat center center fixed; background-size: cover; min-height: 100vh; }
        .main-card { background: rgba(255, 255, 255, 0.96); border-radius: 24px; padding: 30px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3); border: 2px solid #2e5a27; max-width: 800px; margin: auto; }
        #map { height: 320px; width: 100%; border-radius: 12px; border: 1px solid #ced4da; }
        .nav-custom { background-color: rgba(14, 34, 14, 0.88); backdrop-filter: blur(8px); }

        .leaflet-control-locate {
            font-size: 16px; font-weight: bold; line-height: 30px; text-align: center;
            cursor: pointer; display: block; width: 30px; height: 30px; color: #333;
            text-decoration: none; background-color: #fff; border-bottom: 1px solid #ccc;
        }
        .leaflet-control-locate:hover { background-color: #f4f4f4; color: #0d6efd; }
    </style>
</head>
<body>

    <!-- 🟢 NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark nav-custom mb-4 shadow-sm border-bottom border-success">
        <div class="container">
            <a class="navbar-brand fw-bold text-warning" href="index.php">🐘 ระบบติดตามการกระจายตัวของช้างป่าในประเทศไทย</a>
            
            <div class="d-flex align-items-center gap-2">
                <span class="text-white small d-none d-md-inline me-1">
                    👤 <?= htmlspecialchars($user_name) ?>
                    <span class="badge bg-<?= $user_role === 'admin' ? 'danger' : 'success' ?> ms-1"><?= strtoupper($user_role) ?></span>
                </span>
                
                <a href="index.php" class="btn btn-warning btn-sm fw-bold">➕ ส่งรายงาน</a>
                <a href="report.php" class="btn btn-outline-warning btn-sm fw-bold">📜 ประวัติรายงาน</a>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm fw-bold">📊 Dashboard</a>

                <?php if ($user_role === 'admin'): ?>
                    <a href="admin_dashboard.php" class="btn btn-danger btn-sm fw-bold shadow-sm">⚙️ จัดการระบบ</a>
                <?php endif; ?>

                <a href="logout.php" class="btn btn-outline-danger btn-sm ms-1">🔴 ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="container mb-5">
        <div class="main-card">
            <div class="text-center mb-4">
                <h3 class="fw-bold text-success">➕ ฟอร์มรายงานการพบเห็นช้างป่า</h3>
                <p class="text-muted small m-0">กรอกข้อมูลพิกัดและรายละเอียดเพื่อแจ้งเตือนเจ้าหน้าที่และชุมชน</p>
            </div>

            <form action="index.php" method="POST" enctype="multipart/form-data" onsubmit="return validateForm()">
                <div class="mb-3">
                    <label class="form-label fw-bold text-success">📍 เลือกตำแหน่งที่พบช้าง (คลิกบนแผนที่) <span class="text-danger">*</span>:</label>
                    <div id="map"></div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">ละติจูด (Latitude) <span class="text-danger">*</span></label>
                        <input type="text" name="latitude" id="latitude" class="form-control bg-light" readonly required placeholder="คลิกบนแผนที่เพื่อระบุพิกัด">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">ลองจิจูด (Longitude) <span class="text-danger">*</span></label>
                        <input type="text" name="longitude" id="longitude" class="form-control bg-light" readonly required placeholder="คลิกบนแผนที่เพื่อระบุพิกัด">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">🐘 จำนวนช้างที่พบ (ตัว)</label>
                        <input type="number" name="elephant_count" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">⚠️ พฤติกรรมที่พบเห็น</label>
                        <select name="behavior_type" class="form-select" required>
                            <option value="">-- เลือกพฤติกรรม --</option>
                            <option value="เดินผ่าน/สัญจร">เดินผ่าน/สัญจร</option>
                            <option value="หากินในพืชผลทางการเกษตร">หากินในพืชผลทางการเกษตร</option>
                            <option value="ดุร้าย/ตกมัน/วิ่งชาร์จ">ดุร้าย/ตกมัน/วิ่งชาร์จ</option>
                            <option value="บาดเจ็บ/ต้องการความช่วยเหลือ">บาดเจ็บ/ต้องการความช่วยเหลือ</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">📝 รายละเอียดเพิ่มเติม</label>
                    <textarea name="details" class="form-control" rows="3" placeholder="ระบุรายละเอียด เช่น จุดสังเกต ทิศทางการเดิน..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">📷 ถ่ายภาพ/แนบรูปภาพ <span class="text-danger">* (จำเป็นต้องมี)</span></label>
                    <input type="file" name="photo" id="photo" class="form-control" accept="image/*" required>
                </div>

                <button type="submit" class="btn btn-success w-100 fw-bold py-2 fs-5 shadow">ส่งรายงานข้อมูล</button>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const map = L.map('map').setView([13.543210, 102.123450], 9);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '© OpenStreetMap' }).addTo(map);

        let marker;

        function setMarker(lat, lng) {
            if (marker) map.removeLayer(marker);
            marker = L.marker([lat, lng]).addTo(map);
            document.getElementById('latitude').value = lat.toFixed(6);
            document.getElementById('longitude').value = lng.toFixed(6);
        }

        function locateUser() {
            if (navigator.geolocation) {
                Swal.fire({ title: 'กำลังค้นหาตำแหน่งของคุณ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        Swal.close();
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        map.setView([lat, lng], 15, { animate: true });
                        setMarker(lat, lng);
                    },
                    (error) => { Swal.fire('ไม่สามารถระบุตำแหน่งได้', 'กรุณาเปิด GPS หรือคลิกเลือกพิกัดบนแผนที่ด้วยตนเอง', 'error'); },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            }
        }

        const zoomControlContainer = map.zoomControl.getContainer();
        const locateBtn = L.DomUtil.create('a', 'leaflet-control-locate', zoomControlContainer);
        locateBtn.innerHTML = '➢'; locateBtn.href = '#'; locateBtn.title = 'ไปที่ตำแหน่งปัจจุบันของคุณ';
        L.DomEvent.on(locateBtn, 'click', function(e) { L.DomEvent.stopPropagation(); L.DomEvent.preventDefault(); locateUser(); });

        map.on('click', function(e) { setMarker(e.latlng.lat, e.latlng.lng); });

        function validateForm() {
            const lat = document.getElementById('latitude').value;
            const lng = document.getElementById('longitude').value;
            const photoInput = document.getElementById('photo');

            if (!lat || !lng) {
                Swal.fire('ข้อมูลไม่ครบถ้วน', 'กรุณาเลือกตำแหน่งพิกัดบนแผนที่ก่อนส่งรายงาน', 'warning');
                return false;
            }
            if (!photoInput.files || photoInput.files.length === 0) {
                Swal.fire('ข้อมูลไม่ครบถ้วน', 'กรุณาถ่ายภาพหรือเลือกไฟล์รูปภาพประกอบการรายงาน', 'warning');
                return false;
            }
            return true;
        }

        locateUser();
    </script>

    <?php if ($success_msg): ?>
    <script>
        Swal.fire({ title: 'ส่งรายงานสำเร็จ!', text: 'ข้อมูลถูกส่งเข้าสู่ระบบเรียบร้อยแล้ว', icon: 'success', confirmButtonColor: '#198754' }).then(() => { window.location.href = 'report.php'; });
    </script>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <script>
        Swal.fire({ title: 'ไม่สามารถบันทึกข้อมูลได้', text: '<?= htmlspecialchars($error_msg) ?>', icon: 'error', confirmButtonColor: '#d33' });
    </script>
    <?php endif; ?>
</body>
</html>
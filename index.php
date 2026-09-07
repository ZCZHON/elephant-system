<?php
// กำหนด Timezone ระดับ PHP
date_default_timezone_set('Asia/Bangkok');

// 🟢 1. ตั้งค่า Cookie Session ก่อนเริ่ม Session หรือ include ไฟล์อื่น
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']), // เปิดใช้ secure เฉพาะเมื่อเป็น HTTPS
        'httponly' => true,                    // ป้องกัน JavaScript เข้าถึง Cookie
        'samesite' => 'Lax'                    // ปรับเป็น Lax เพื่อความปลอดภัย
    ]);
    session_start();
}

// 🟢 2. เรียกใช้ไฟล์ฐานข้อมูล
include('db.php');

// 🔒 3. ตรวจสอบการเข้าสู่ระบบ (หากไม่มี Session และไม่ใช่ POST Request ให้เด้งไป login.php)
if (!isset($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit();
}

// ตัวแปรข้อมูลผู้ใช้สำหรับแสดงบน Navbar
$user_name = $_SESSION['fullname'] ?? $_SESSION['user_name'] ?? 'ผู้ใช้งาน LINE';
$user_role = $_SESSION['role'] ?? 'user';

// 🐘 4. ประมวลผลเมื่อมีการส่งฟอร์มรายงาน (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // กำหนดให้ Output คืนค่ากลับเป็น JSON เสมอ
    header('Content-Type: application/json; charset=utf-8');

    $line_user_id    = trim($_POST['line_userid'] ?? '');
    $user_name_input = trim($_POST['user_name'] ?? 'ผู้ใช้งาน LINE');
    $latitude        = trim($_POST['latitude'] ?? '');
    $longitude       = trim($_POST['longitude'] ?? '');
    $elephant_count  = (int)($_POST['elephant_count'] ?? 1);
    $behavior_type   = trim($_POST['behavior_type'] ?? '');
    $details         = trim($_POST['details'] ?? '');

    $error_msg = null;
    $success_msg = false;

    // Validation
    $has_photo = isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK && $_FILES['photo']['size'] > 0;

    if (empty($latitude) || empty($longitude)) {
        $error_msg = "กรุณาเลือกตำแหน่งพิกัดบนแผนที่ก่อนส่งรายงาน";
    } elseif (!$has_photo) {
        $error_msg = "กรุณาแนบรูปภาพประกอบการรายงาน";
    } else {
        // ใช้ user_id จาก Session ก่อน หากไม่มีจึงค่อยค้นหา/สร้างใหม่จาก LINE ID
        $user_id = $_SESSION['user_id'] ?? null;

        if (!$user_id && !empty($line_user_id)) {
            $check_user = pg_query_params($db, "SELECT user_id FROM tbl_users WHERE line_user_id = $1", array($line_user_id));
            
            if ($check_user && pg_num_rows($check_user) > 0) {
                $user_row = pg_fetch_assoc($check_user);
                $user_id = $user_row['user_id'];
            } else {
                // สมาชิกใหม่ -> บันทึกลง tbl_users
                $insert_user = pg_query_params($db, 
                    "INSERT INTO tbl_users (line_user_id, first_name, registered_at, role, last_latitude, last_longitude, last_location_updated) 
                     VALUES ($1, $2, NOW(), 'user', $3::double precision, $4::double precision, NOW()) RETURNING user_id", 
                    array($line_user_id, $user_name_input, $latitude, $longitude)
                );
                
                if ($insert_user) {
                    $user_row = pg_fetch_assoc($insert_user);
                    $user_id = $user_row['user_id'];
                } else {
                    $error_msg = "เกิดข้อผิดพลาดในการสร้างบัญชีผู้ใช้ใหม่";
                }
            }
        }

        if (empty($user_id) && empty($error_msg)) {
            $error_msg = "ไม่พบข้อมูลบัญชีผู้ใช้งาน กรุณาล็อกอินใหม่อีกครั้ง";
        }

        // หากระบุ user_id ได้สำเร็จ
        if (!empty($user_id) && empty($error_msg)) {
            
            // 📍 5. อัปเดตพิกัดล่าสุดของผู้ใช้ไว้ส่งแจ้งเตือนภัย LINE Geo-Alert
            pg_query_params($db, 
                "UPDATE tbl_users SET last_latitude = $1::double precision, last_longitude = $2::double precision, last_location_updated = NOW() WHERE user_id = $3", 
                array($latitude, $longitude, $user_id)
            );

            // 📸 6. จัดการอัปโหลดไฟล์รูปภาพอย่างปลอดภัย
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (empty($ext) || !in_array($ext, $allowed_exts, true)) { 
                $ext = 'jpg'; 
            }
            
            $new_name   = 'report_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target_dir = 'uploads/';
            
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
            }
            
            $target_file = $target_dir . $new_name;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;

                // บันทึกลง tbl_reports พร้อมคำนวณ PostGIS Geom
                $query = "INSERT INTO tbl_reports (user_id, latitude, longitude, elephant_count, behavior_type, details, photo_path, status, reported_at, geom) 
                          VALUES ($1, $2::double precision, $3::double precision, $4, $5, $6, $7, 'pending', NOW() AT TIME ZONE 'Asia/Bangkok', ST_SetSRID(ST_MakePoint($3::double precision, $2::double precision), 4326))";
                
                $result = pg_query_params($db, $query, array(
                    $user_id, $latitude, $longitude, $elephant_count, $behavior_type, $details, $photo_path
                ));

                if ($result) {
                    $success_msg = true;
                } else {
                    $error_msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูลรายงานลงฐานข้อมูล";
                }
            } else {
                $error_msg = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์รูปภาพบนเซิร์ฟเวอร์";
            }
        }
    }

    // Return JSON สำหรับ AJAX Submission
    echo json_encode([
        'status'  => $success_msg ? 'success' : 'error',
        'message' => $error_msg
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ระบบติดตามการกระจายตัวของช้างป่าในประเทศไทย</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- 🟢 LINE LIFF SDK -->
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>

    <style>
        body { font-family: 'Sarabun', sans-serif; background: linear-gradient(rgba(14, 34, 14, 0.75), rgba(14, 34, 14, 0.75)), url('https://images.unsplash.com/photo-1516026672322-bc52d61a55d5?q=80&w=1920') no-repeat center center fixed; background-size: cover; min-height: 100vh; }
        .main-card { background: rgba(255, 255, 255, 0.96); border-radius: 24px; padding: 25px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3); border: 2px solid #2e5a27; max-width: 800px; margin: auto; }
        #map { height: 300px; width: 100%; border-radius: 12px; border: 1px solid #ced4da; }
        .nav-custom { background-color: rgba(14, 34, 14, 0.95); backdrop-filter: blur(8px); }

        .leaflet-control-locate {
            font-size: 16px; font-weight: bold; line-height: 30px; text-align: center;
            cursor: pointer; display: block; width: 30px; height: 30px; color: #333;
            text-decoration: none; background-color: #fff; border-bottom: 1px solid #ccc;
        }
        .leaflet-control-locate:hover { background-color: #f4f4f4; color: #0d6efd; }
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
                <span class="text-white small d-none d-md-inline me-1" id="line_user_display">
                    👤 <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>
                    <span class="badge bg-<?= $user_role === 'admin' ? 'danger' : 'success' ?> ms-1"><?= strtoupper(htmlspecialchars($user_role, ENT_QUOTES, 'UTF-8')) ?></span>
                </span>

                <a href="index.php" class="btn btn-outline-light btn-sm fw-bold">
                    ➕ <span class="d-none d-sm-inline">ส่งรายงาน</span><span class="d-inline d-sm-none">รายงาน</span>
                </a>

                <a href="report.php" class="btn btn-warning btn-sm fw-bold">
                    📜 <span class="d-none d-sm-inline">ประวัติรายงาน</span><span class="d-inline d-sm-none">ประวัติ</span>
                </a>

                <a href="dashboard.php" class="btn btn-outline-light btn-sm fw-bold">
                    📊 <span class="d-none d-sm-inline">Dashboard</span><span class="d-inline d-sm-none">สถิติ</span>
                </a>

                <?php if ($user_role === 'admin'): ?>
                    <a href="admin_dashboard.php" class="btn btn-danger btn-sm fw-bold shadow-sm">
                        ⚙️ <span class="d-none d-sm-inline">จัดการระบบ</span><span class="d-inline d-sm-none">Admin</span>
                    </a>
                <?php endif; ?>

                <a href="logout.php" class="btn btn-outline-danger btn-sm ms-1" title="ออกจากระบบ">🔴 <span class="d-none d-md-inline">ออกจากระบบ</span></a>
            </div>
        </div>
    </nav>

    <div class="container mb-5">
        <div class="main-card">
            <div class="text-center mb-3">
                <h4 class="fw-bold text-success m-0">➕ ฟอร์มรายงานการพบเห็นช้างป่า</h4>
                <p class="text-muted small m-0">ระบุพิกัดและข้อมูลเหตุการณ์เพื่อแจ้งเตือนชุมชน</p>
            </div>

            <form id="reportForm" enctype="multipart/form-data">
                <input type="hidden" name="line_userid" id="line_userid">
                <input type="hidden" name="user_name" id="user_name">

                <div class="mb-3">
                    <label class="form-label fw-bold text-success">📍 เลือกตำแหน่งที่พบช้าง (คลิกบนแผนที่) <span class="text-danger">*</span>:</label>
                    <div id="map"></div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold small">ละติจูด (Latitude) <span class="text-danger">*</span></label>
                        <input type="text" name="latitude" id="latitude" class="form-control form-control-sm bg-light" readonly required placeholder="คลิกบนแผนที่">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">ลองจิจูด (Longitude) <span class="text-danger">*</span></label>
                        <input type="text" name="longitude" id="longitude" class="form-control form-control-sm bg-light" readonly required placeholder="คลิกบนแผนที่">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">🐘 จำนวนช้างที่พบ (ตัว)</label>
                        <input type="number" name="elephant_count" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small">⚠️ พฤติกรรมที่พบเห็น</label>
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
                    <label class="form-label fw-bold small">📝 รายละเอียดเพิ่มเติม</label>
                    <textarea name="details" class="form-control" rows="2" placeholder="ระบุจุดสังเกต หรือทิศทางการเดิน..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold small">📷 ถ่ายภาพ/แนบรูปภาพ <span class="text-danger">* (จำเป็นต้องมี)</span></label>
                    <input type="file" name="photo" id="photo" class="form-control" accept="image/*" capture="environment" required>
                </div>

                <button type="submit" id="btnSubmit" class="btn btn-success w-100 fw-bold py-2 fs-5 shadow">ส่งรายงานข้อมูล</button>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const MY_LIFF_ID = "2011293676-4qqKadRs";

        // 🟢 1. ตรวจสอบการใช้งาน LINE LIFF
        async function initLiff() {
            try {
                if (typeof liff !== 'undefined' && MY_LIFF_ID) {
                    await liff.init({ liffId: MY_LIFF_ID });
                    if (liff.isLoggedIn()) {
                        const profile = await liff.getProfile();
                        document.getElementById('line_userid').value = profile.userId;
                        document.getElementById('user_name').value = profile.displayName;
                        const displayElement = document.getElementById('line_user_display');
                        if (displayElement) {
                            displayElement.innerHTML = '👤 ' + profile.displayName + ' <span class="badge bg-<?= $user_role === "admin" ? "danger" : "success" ?> ms-1"><?= strtoupper(htmlspecialchars($user_role, ENT_QUOTES, "UTF-8")) ?></span>';
                        }
                    }
                }
            } catch (err) {
                console.warn("LIFF Initialization skipped or failed", err);
            }
        }

        initLiff();

        // 🟢 2. แผนที่ Leaflet
        const map = L.map('map').setView([13.736717, 100.523186], 6);
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
                    (error) => { 
                        Swal.close();
                        Swal.fire('แนะนำเพิ่มเติม', 'คลิกเลือกพิกัดบนแผนที่ด้วยตนเองได้ทันทีครับ', 'info'); 
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            }
        }

        const zoomControlContainer = map.zoomControl.getContainer();
        const locateBtn = L.DomUtil.create('a', 'leaflet-control-locate', zoomControlContainer);
        locateBtn.innerHTML = '➢'; locateBtn.href = '#'; locateBtn.title = 'ไปที่ตำแหน่งปัจจุบัน';
        L.DomEvent.on(locateBtn, 'click', function(e) { L.DomEvent.stopPropagation(); L.DomEvent.preventDefault(); locateUser(); });

        map.on('click', function(e) { setMarker(e.latlng.lat, e.latlng.lng); });
        locateUser();

        // 🟢 3. ฟังก์ชันสำหรับย่อขนาดรูปภาพก่อนอัปโหลด
        function compressImage(file, maxWidth = 1200, quality = 0.75) {
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onload = (event) => {
                    const img = new Image();
                    img.src = event.target.result;
                    img.onload = () => {
                        let width = img.width;
                        let height = img.height;

                        if (width > maxWidth) {
                            height = Math.round((height * maxWidth) / width);
                            width = maxWidth;
                        }

                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, width, height);

                        canvas.toBlob((blob) => {
                            if (blob) {
                                const compressedFile = new File([blob], file.name || 'photo.jpg', {
                                    type: 'image/jpeg',
                                    lastModified: Date.now()
                                });
                                resolve(compressedFile);
                            } else {
                                resolve(file);
                            }
                        }, 'image/jpeg', quality);
                    };
                    img.onerror = () => resolve(file);
                };
                reader.onerror = () => resolve(file);
            });
        }

        // 🟢 4. จัดการ Form Submit ด้วย AJAX
        document.getElementById('reportForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const lat = document.getElementById('latitude').value;
            const photoInput = document.getElementById('photo');

            if (!lat) {
                Swal.fire('ข้อมูลไม่ครบถ้วน', 'กรุณาเลือกตำแหน่งพิกัดบนแผนที่ก่อนส่งรายงาน', 'warning');
                return;
            }
            if (!photoInput.files || photoInput.files.length === 0) {
                Swal.fire('ข้อมูลไม่ครบถ้วน', 'กรุณาถ่ายภาพประกอบการรายงาน', 'warning');
                return;
            }

            Swal.fire({ title: 'กำลังประมวลผลรูปภาพ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

            try {
                const rawFile = photoInput.files[0];
                const compressedFile = await compressImage(rawFile);

                const formData = new FormData(this);
                formData.set('photo', compressedFile, compressedFile.name);

                Swal.fire({ title: 'กำลังบันทึกข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

                fetch('index.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    Swal.close();
                    if (data.status === 'success') {
                        Swal.fire({ 
                            title: 'ส่งรายงานสำเร็จ!', 
                            text: 'ขอบคุณสำหรับการแจ้งข้อมูล ระบบได้บันทึกเรียบร้อยแล้ว', 
                            icon: 'success', 
                            confirmButtonColor: '#198754' 
                        }).then(() => {
                            if (typeof liff !== 'undefined' && liff.isLoggedIn()) {
                                liff.closeWindow();
                            } else {
                                window.location.reload();
                            }
                        });
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message || 'ไม่สามารถบันทึกข้อมูลได้', 'error');
                    }
                })
                .catch(err => {
                    Swal.close();
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
                });

            } catch (err) {
                Swal.close();
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถประมวลผลไฟล์รูปภาพได้', 'error');
            }
        });
    </script>
</body>
</html>

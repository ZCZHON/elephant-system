<?php
// กำหนด Timezone
date_default_timezone_set('Asia/Bangkok');

include('db.php');

// 🟢 ตั้งค่า Session Cookie ให้รองรับ LINE LIFF & HTTPS
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

session_start();

// 🔴 ส่วนประมวลผลเมื่อกดบันทึกรายงาน (Form Submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $user_id        = $_SESSION['user_id'] ?? null;
    $line_userid    = trim($_POST['line_userid'] ?? '');
    $latitude       = floatval($_POST['latitude'] ?? 0);
    $longitude      = floatval($_POST['longitude'] ?? 0);
    $elephant_count = intval($_POST['elephant_count'] ?? 1);
    $behavior_type  = trim($_POST['behavior_type'] ?? '');
    $details        = trim($_POST['details'] ?? '');

    // ตรวจสอบข้อมูลจำเป็น
    if ($latitude == 0 || $longitude == 0) {
        echo json_encode(['success' => false, 'message' => 'กรุณาระบุพิกัดตำแหน่งบนแผนที่']);
        exit;
    }

    // 📸 จัดการการอัปโหลดไฟล์รูปภาพ
    $photo_path = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($file_ext, $allowed_exts)) {
            $new_file_name = 'report_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            $destination = $upload_dir . $new_file_name;

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                $photo_path = $destination;
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกไฟล์รูปภาพบนเซิร์ฟเวอร์ได้']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, WEBP) เท่านั้น']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'กรุณาแนบรูปภาพถ่ายสถานการณ์ช้างป่า']);
        exit;
    }

    // 🐘 บันทึกลงฐานข้อมูล PostgreSQL / PostGIS
    $query = "INSERT INTO tbl_reports (user_id, line_userid, latitude, longitude, elephant_count, behavior_type, details, photo_path, geom, status, reported_at) 
              VALUES ($1, $2, $3, $4, $5, $6, $7, $8, ST_SetSRID(ST_MakePoint($4, $3), 4326), 'pending', NOW()) 
              RETURNING report_id";

    $params = [$user_id, $line_userid, $latitude, $longitude, $elephant_count, $behavior_type, $details, $photo_path];
    $result = pg_query_params($db, $query, $params);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'ส่งรายงานข้อมูลช้างป่าสำเร็จแล้ว เจ้าหน้าที่จะทำการตรวจสอบโดยเร็วที่สุด']);
    } else {
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูลลงฐานข้อมูล']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>แจ้งพบช้างป่า - Elephant Tracker</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>

    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #122112; color: #fff; min-height: 100vh; }
        .main-card { background: rgba(255, 255, 255, 0.96); border-radius: 20px; color: #333; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        #map { height: 280px; width: 100%; border-radius: 12px; border: 2px solid #2e5a27; }
    </style>
</head>
<body>

    <div class="container py-3">
        <div class="main-card">
            <h4 class="fw-bold text-success text-center mb-3">🐘 แจ้งพบช้างป่าเชิงพื้นที่</h4>

            <!-- 🟢 ฟอร์มรายงาน (มี enctype สำหรับรูปภาพ) -->
            <form id="reportForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="line_userid" id="line_userid">

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-bold text-success m-0">📍 ตำแหน่งที่พบช้าง (ระบุบนแผนที่) <span class="text-danger">*</span></label>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="locateUser()">🎯 พิกัดปัจจุบัน</button>
                    </div>
                    <div id="map"></div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold small">ละติจูด (Lat)</label>
                        <input type="text" name="latitude" id="latitude" class="form-control form-control-sm bg-light" readonly required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">ลองจิจูด (Lng)</label>
                        <input type="text" name="longitude" id="longitude" class="form-control form-control-sm bg-light" readonly required>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-bold small">🐘 จำนวน (ตัว)</label>
                        <input type="number" name="elephant_count" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small">⚠️ พฤติกรรม</label>
                        <select name="behavior_type" class="form-select" required>
                            <option value="">-- เลือก --</option>
                            <option value="เดินผ่าน/สัญจร">เดินผ่าน/สัญจร</option>
                            <option value="หากินในพืชผล">หากินในพืชผล</option>
                            <option value="ดุร้าย/วิ่งชาร์จ">ดุร้าย/วิ่งชาร์จ</option>
                            <option value="บาดเจ็บ">บาดเจ็บ</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold small">📝 รายละเอียดเพิ่มเติม</label>
                    <textarea name="details" class="form-control" rows="2" placeholder="ระบุทิศทางการเดิน หรือจุดสังเกต..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold small">📷 ถ่ายภาพ / แนบรูปภาพ <span class="text-danger">*</span></label>
                    <input type="file" name="photo" id="photo" class="form-control" accept="image/*" capture="environment" required>
                </div>

                <button type="submit" id="btnSubmit" class="btn btn-success w-100 fw-bold py-2 fs-5 shadow">🚀 ส่งรายงานข้อมูล</button>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // 初始化 Leaflet แผนที่
        const map = L.map('map').setView([13.7563, 100.5018], 7);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18 }).addTo(map);

        let currentMarker = null;

        function setMarker(lat, lng) {
            document.getElementById('latitude').value = lat.toFixed(6);
            document.getElementById('longitude').value = lng.toFixed(6);

            if (currentMarker) {
                currentMarker.setLatLng([lat, lng]);
            } else {
                currentMarker = L.marker([lat, lng], { draggable: true }).addTo(map);
                currentMarker.on('dragend', function(e) {
                    const position = currentMarker.getLatLng();
                    setMarker(position.lat, position.lng);
                });
            }
        }

        map.on('click', function(e) {
            setMarker(e.latlng.lat, e.latlng.lng);
        });

        // 🎯 ดึงพิกัด GPS สด ป้องกันการแคชพิกัดเก่า
        function locateUser() {
            if (navigator.geolocation) {
                Swal.fire({ title: 'กำลังค้นหาพิกัด GPS...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        Swal.close();
                        const lat = pos.coords.latitude;
                        const lng = pos.coords.longitude;
                        map.setView([lat, lng], 15);
                        setMarker(lat, lng);
                    },
                    (err) => {
                        Swal.close();
                        Swal.fire('คำแนะนำ', 'กรุณาคลิกเลือกตำแหน่งบนแผนที่ด้วยตนเอง', 'info');
                    },
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                );
            }
        }

        // 🟢 ส่งข้อมูลแบบ AJAX (Form Submit)
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const btnSubmit = document.getElementById('btnSubmit');
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '⏳ กำลังส่งข้อมูล...';

            const formData = new FormData(this);

            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '🚀 ส่งรายงานข้อมูล';

                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ!',
                        text: data.message,
                        confirmButtonText: 'ตกลง'
                    }).then(() => {
                        window.location.href = 'report.php'; // นำไปยังหน้าประวัติรายงาน
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(err => {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '🚀 ส่งรายงานข้อมูล';
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            });
        });

        // 🔑 เริ่มต้นเปิดใช้ LINE LIFF Auto-login
        async function main() {
            try {
                await liff.init({ liffId: "2011293676-4qqKadRs" });
                if (liff.isLoggedIn()) {
                    const profile = await liff.getProfile();
                    document.getElementById('line_userid').value = profile.userId;
                } else {
                    liff.login();
                }
            } catch (err) {
                console.log("LIFF Init Error:", err);
            }
        }
        main();
    </script>
</body>
</html>

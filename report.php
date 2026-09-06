<?php
// กำหนด Timezone ระดับ PHP
date_default_timezone_set('Asia/Bangkok');

include('db.php');

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

// 🔒 ตรวจสอบการเข้าสู่ระบบ (ถ้าไม่มี Session ให้รีไดเรกต์ไปหน้า login.php)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['fullname'] ?? 'ผู้ใช้งานระบบ';
$user_role = $_SESSION['role'] ?? 'user';

// 🎯 ดึงข้อมูลรายงานเฉพาะของคนที่ล็อกอินอยู่ ($user_id)
$reports = [];
if ($user_id) {
    $query = "SELECT report_id, user_id, photo_path, latitude, longitude, elephant_count, behavior_type, details, reported_at, status
              FROM tbl_reports
              WHERE user_id = $1
              ORDER BY reported_at DESC";

    $result = pg_query_params($db, $query, array($user_id));

    if ($result) {
        while ($row = pg_fetch_assoc($result)) {
            if (!empty($row['reported_at'])) {
                $timestamp = strtotime($row['reported_at']);
                $thai_months = [1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'];
                $day = date('j', $timestamp);
                $month = $thai_months[(int)date('n', $timestamp)];
                $year = date('Y', $timestamp) + 543;
                $time = date('H:i', $timestamp);
                $row['formatted_date'] = "$day $month $year - $time น.";
            } else {
                $row['formatted_date'] = 'ไม่ระบุเวลา';
            }
            $reports[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการรายงานช้างป่าเชิงพื้นที่</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background: linear-gradient(rgba(14, 34, 14, 0.75), rgba(14, 34, 14, 0.75)), url('https://images.unsplash.com/photo-1516026672322-bc52d61a55d5?q=80&w=1920') no-repeat center center fixed; 
            background-size: cover; 
            min-height: 100vh; 
        }
        .main-card { 
            background: rgba(255, 255, 255, 0.96); 
            border-radius: 24px; 
            padding: 30px; 
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3); 
            border: 2px solid #2e5a27; 
        }
        #map { 
            height: 380px; 
            width: 100%; 
            border-radius: 16px; 
            border: 1px solid #ced4da; 
        }
        .img-thumb { 
            width: 50px; 
            height: 50px; 
            object-fit: cover; 
            border-radius: 8px; 
            cursor: pointer; 
            border: 1px solid #2e5a27; 
            transition: transform 0.2s; 
        }
        .img-thumb:hover { transform: scale(1.1); }
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
            <a class="navbar-brand fw-bold text-warning" href="index.php">🐘 ระบบติดตามการกระจายตัวของช้างป่า</a>
            
            <div class="d-flex align-items-center gap-2">
                <span class="text-white small d-none d-md-inline me-1">
                    👤 <?= htmlspecialchars($user_name) ?>
                    <span class="badge bg-<?= $user_role === 'admin' ? 'danger' : 'success' ?> ms-1"><?= strtoupper($user_role) ?></span>
                </span>

                <a href="index.php" class="btn btn-outline-light btn-sm fw-bold">➕ ส่งรายงาน</a>
                <a href="report.php" class="btn btn-warning btn-sm fw-bold">📜 ประวัติรายงาน</a>
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
            <div class="text-center mb-3">
                <h3 class="fw-bold text-success">📜 ประวัติการรายงานของฉัน</h3>
                <p class="text-muted small m-0">ตำแหน่งและรายละเอียดที่คุณเคยส่งรายงานเข้าสู่ระบบ</p>
            </div>

            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="fw-bold text-success m-0">🗺️ แผนที่จุดที่เคยรายงาน</h5>
                    <span class="badge bg-success fs-6">ประวัติรวม <?= count($reports) ?> รายการ</span>
                </div>
                <div id="map"></div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle border">
                    <thead class="table-success">
                        <tr>
                            <th class="text-center" style="width: 60px;">ภาพ</th>
                            <th style="width: 160px;">วัน/เวลา</th>
                            <th class="text-center" style="width: 80px;">จำนวน</th>
                            <th>พฤติกรรม</th>
                            <th>รายละเอียด</th>
                            <th>พิกัด (Lat, Lng)</th>
                            <th class="text-center" style="width: 90px;">สถานะ</th>
                            <th class="text-center" style="width: 60px;">ปักหมุด</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($reports) > 0): ?>
                            <?php foreach ($reports as $row): ?>
                                <?php $report_id = $row['report_id'] ?? $row['id'] ?? 0; ?>
                                <tr>
                                    <td class="text-center">
                                        <?php if (!empty($row['photo_path'])): ?>
                                            <img src="<?= htmlspecialchars($row['photo_path']) ?>" class="img-thumb" onclick="showImage('<?= htmlspecialchars($row['photo_path']) ?>')">
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap" style="font-size: 0.85rem;">🕒 <?= htmlspecialchars($row['formatted_date']) ?></td>
                                    <td class="text-center fw-bold">🐘 <?= htmlspecialchars($row['elephant_count']) ?></td>
                                    <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($row['behavior_type'] ?? '-') ?></span></td>
                                    <td class="small text-muted"><?= htmlspecialchars($row['details'] ?: '-') ?></td>
                                    <td class="font-monospace text-nowrap" style="font-size: 0.8rem;">📍 <?= htmlspecialchars($row['latitude']) ?>, <?= htmlspecialchars($row['longitude']) ?></td>
                                    
                                    <!-- แสดงสถานะการอนุมัติ -->
                                    <td class="text-center">
                                        <?php if ($row['status'] === 'verified' || $row['status'] === 'approved'): ?>
                                            <span class="badge bg-success">อนุมัติแล้ว</span>
                                        <?php elseif ($row['status'] === 'rejected'): ?>
                                            <span class="badge bg-danger">ไม่ผ่าน</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">รอตรวจสอบ</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-success" onclick="focusMap(<?= $row['latitude'] ?>, <?= $row['longitude'] ?>, <?= $report_id ?>)" title="ดูตำแหน่งบนแผนที่">📍</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">คุณยังไม่มีประวัติการแจ้งรายงานช้างป่า</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const reportsData = <?= json_encode($reports) ?>;
        const map = L.map('map').setView([13.736717, 100.523186], 7);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '© OpenStreetMap' }).addTo(map);

        const markers = {};
        let userLocationMarker;
        const bounds = [];

        reportsData.forEach(item => {
            const lat = parseFloat(item.latitude);
            const lng = parseFloat(item.longitude);
            const id = item.report_id || item.id;

            if (!isNaN(lat) && !isNaN(lng)) {
                bounds.push([lat, lng]);
                const marker = L.marker([lat, lng]).addTo(map).bindPopup(`
                    <div style="max-width: 200px;">
                        ${item.photo_path ? `<img src="${item.photo_path}" style="width:100%; border-radius:8px; margin-bottom:8px; height:100px; object-fit:cover;">` : ''}
                        <b>🐘 จำนวน: ${item.elephant_count} ตัว</b><br>
                        ${item.behavior_type ? `พฤติกรรม: ${item.behavior_type}<br>` : ''}
                        <small class="text-muted">🕒 ${item.formatted_date}</small>
                    </div>
                `);
                if (id) markers[id] = marker;
            }
        });

        // ปรับโฟกัสแผนที่ให้อยู่ตรงกลางตำแหน่งที่เคยปักหมุดไว้ทั้งหมด
        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [30, 30] });
        }

        function locateUser() {
            if (navigator.geolocation) {
                Swal.fire({ title: 'กำลังค้นหาตำแหน่งของคุณ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        Swal.close();
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        if (userLocationMarker) map.removeLayer(userLocationMarker);
                        userLocationMarker = L.circleMarker([lat, lng], { radius: 9, fillColor: "#0d6efd", color: "#fff", weight: 3, fillOpacity: 0.8 }).addTo(map).bindPopup("📍 ตำแหน่งปัจจุบันของคุณ").openPopup();
                        map.setView([lat, lng], 14, { animate: true });
                    },
                    (error) => { Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถค้นหาตำแหน่งได้ กรุณาเปิด GPS', 'error'); },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            }
        }

        const zoomControlContainer = map.zoomControl.getContainer();
        const locateBtn = L.DomUtil.create('a', 'leaflet-control-locate', zoomControlContainer);
        locateBtn.innerHTML = '➢'; locateBtn.href = '#'; locateBtn.title = 'ไปยังตำแหน่งปัจจุบันของคุณ';
        L.DomEvent.on(locateBtn, 'click', function(e) { L.DomEvent.stopPropagation(); L.DomEvent.preventDefault(); locateUser(); });

        function focusMap(lat, lng, id) {
            const latitude = parseFloat(lat);
            const longitude = parseFloat(lng);
            if (!isNaN(latitude) && !isNaN(longitude)) {
                map.setView([latitude, longitude], 15, { animate: true });
                document.getElementById('map').scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (id && markers[id]) {
                    setTimeout(() => { markers[id].openPopup(); }, 300);
                }
            }
        }

        function showImage(src) { Swal.fire({ imageUrl: src, showConfirmButton: false, showCloseButton: true }); }
    </script>
</body>
</html>

<?php
include('db.php');
session_start();

// ตั้งค่า Timezone ให้ตรงกับประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// ตรวจสอบความปลอดภัย ต้อง Login ก่อนเข้าหน้า Dashboard
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_name = $_SESSION['fullname'] ?? 'ผู้ใช้งานระบบ';

// ฟังก์ชันซ่อนชื่อบางส่วน (Name Masking)
function maskName($name) {
    $trimmed = trim($name);
    if (empty($trimmed)) return 'ผู้ไม่ประสงค์ออกนาม';
    $length = mb_strlen($trimmed, 'UTF-8');
    if ($length <= 3) {
        return mb_substr($trimmed, 0, 1, 'UTF-8') . '***';
    }
    $first = mb_substr($trimmed, 0, 2, 'UTF-8');
    $last = mb_substr($trimmed, -2, 2, 'UTF-8');
    return $first . '******' . $last;
}

// 1. ดึงข้อมูลภาพรวมสถิติรายงาน และจำนวนช้าง
$total_reports_res = pg_query($db, "SELECT COUNT(*) as total, COALESCE(SUM(elephant_count), 0) as total_elephants FROM tbl_reports");
$total_data = $total_reports_res ? pg_fetch_assoc($total_reports_res) : ['total' => 0, 'total_elephants' => 0];

// 2. ดึงจำนวนอาสาสมัครทั้งหมดจากตาราง tbl_users
$total_volunteers_res = pg_query($db, "SELECT COUNT(*) as total_volunteers FROM tbl_users");
$total_volunteers = $total_volunteers_res ? pg_fetch_result($total_volunteers_res, 0, 'total_volunteers') : 0;

// 3. ดึงพิกัดรายงานล่าสุดเพื่อนำไป Reverse Geocode เป็นชื่อจังหวัดด้วย JS
$top_province_query = "SELECT latitude, longitude FROM tbl_reports ORDER BY reported_at DESC LIMIT 1";
$top_province_res = pg_query($db, $top_province_query);
$latest_report_coord = ($top_province_res && pg_num_rows($top_province_res) > 0) ? pg_fetch_assoc($top_province_res) : null;

// 4. ดึงรายการรายงานย้อนหลังภายใน 4 ชั่วโมง (ถ้าไม่มี ให้ดึง 5 รายการล่าสุดแทน)
$recent_4h_query = "SELECT e.photo_path, e.latitude, e.longitude, e.elephant_count, e.behavior_type, e.details, e.reported_at, COALESCE(e.status, 'pending') as status,
                           u.first_name, u.last_name
                    FROM tbl_reports e
                    LEFT JOIN tbl_users u ON e.user_id = u.user_id 
                    WHERE e.reported_at >= NOW() - INTERVAL '4 hours'
                    ORDER BY e.reported_at DESC";

$recent_4h_res = pg_query($db, $recent_4h_query);

if (!$recent_4h_res || pg_num_rows($recent_4h_res) == 0) {
    $recent_fallback_query = "SELECT e.photo_path, e.latitude, e.longitude, e.elephant_count, e.behavior_type, e.details, e.reported_at, COALESCE(e.status, 'pending') as status,
                               u.first_name, u.last_name
                        FROM tbl_reports e
                        LEFT JOIN tbl_users u ON e.user_id = u.user_id 
                        ORDER BY e.reported_at DESC LIMIT 5";
    $recent_4h_res = pg_query($db, $recent_fallback_query);
}

$recent_reports = [];
if ($recent_4h_res) {
    while ($row = pg_fetch_assoc($recent_4h_res)) {
        $recent_reports[] = $row;
    }
}

// 5. ดึงรายการรายงานทั้งหมดแสดงบนแผนที่ (พร้อมคำนวณ Epoch Milliseconds)
$query = "SELECT photo_path, latitude, longitude, elephant_count, behavior_type, details, reported_at FROM tbl_reports ORDER BY reported_at DESC";
$result = pg_query($db, $query);
$reports = [];
if ($result) {
    while ($row = pg_fetch_assoc($result)) {
        if (!empty($row['reported_at'])) {
            $time_stamp = strtotime($row['reported_at']);
            $row['timestamp_ms'] = $time_stamp ? ($time_stamp * 1000) : null;
        } else {
            $row['timestamp_ms'] = null;
        }
        $reports[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard - สรุปรายงานช้างป่า</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background: linear-gradient(rgba(14, 34, 14, 0.8), rgba(14, 34, 14, 0.8)), url('https://images.unsplash.com/photo-1516026672322-bc52d61a55d5?q=80&w=1920') no-repeat center center fixed; 
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
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 20px;
            border-left: 6px solid #2e5a27;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            height: 100%;
        }
        .stat-card.warning-border { border-left-color: #ffc107; }
        .stat-card.info-border { border-left-color: #0dcaf0; }

        #map { height: 420px; width: 100%; border-radius: 16px; border: 1px solid #ced4da; }
        .nav-custom { background-color: rgba(14, 34, 14, 0.9); backdrop-filter: blur(8px); }

        .img-thumb {
            width: 55px;
            height: 55px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        .table-custom {
            vertical-align: middle;
        }

        .legend-color {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            display: inline-block;
        }
        .bg-red-alert { background-color: #dc3545; box-shadow: 0 0 6px rgba(220, 53, 69, 0.6); }
        .bg-orange-warning { background-color: #fd7e14; }
        .bg-gray-history { background-color: #6c757d; }

        /* Animation เอฟเฟกต์หมุดสีแดง (พบไม่เกิน 1 ชม.) */
        @keyframes pulse-red {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { transform: scale(1.1); box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        .pulse-marker-red {
            border-radius: 50%;
            animation: pulse-red 1.5s infinite;
        }

        @media (max-width: 767.98px) {
            body { padding-bottom: 20px; }
            .container { padding-left: 10px; padding-right: 10px; }
            .main-card { padding: 16px !important; border-radius: 16px !important; }
            #map { height: 340px !important; }
            .stat-card { padding: 14px !important; }
            .stat-card .fs-2 { font-size: 1.6rem !important; }
            .navbar-brand { font-size: 1.05rem !important; }
            .table-custom th, .table-custom td { font-size: 0.85rem !important; padding: 8px 6px !important; }
            .img-thumb { width: 42px; height: 42px; }
            .mobile-hide { display: none !important; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark nav-custom mb-3 mb-md-4 shadow-sm border-bottom border-success">
        <div class="container">
            <a class="navbar-brand fw-bold text-warning" href="index.php">🐘 ระบบติดตามการกระจายตัวของช้างป่าในประเทศไทย</a>
            
            <div class="d-flex align-items-center gap-1 gap-md-2">
                <span class="text-white small d-none d-md-inline me-2">👤 <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></span>
                <a href="index.php" class="btn btn-warning btn-sm fw-bold px-2 py-1">➕ เพิ่มรายงาน</a>
                <a href="report.php" class="btn btn-outline-warning btn-sm fw-bold">📜 ประวัติรายงาน</a>
                <a href="logout.php" class="btn btn-outline-danger btn-sm px-2 py-1">🔴 ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <div class="container mb-4 mb-md-5">
        <div class="main-card">
            
            <div class="text-center mb-3 mb-md-4">
                <h4 class="fw-bold text-success mt-1 mb-1">📊 Dashboard สรุปภาพรวม</h4>
                <p class="text-muted small m-0">สรุปสถิติและพิกัดการพบเห็นช้างป่าในพื้นที่</p>
                <hr class="my-2" style="border-top: 2px solid #2e5a27; opacity: 0.2;">
            </div>

            <div class="row g-2 g-md-3 mb-3 mb-md-4">
                <div class="col-4 col-md-4">
                    <div class="stat-card">
                        <div class="text-muted fw-bold small text-truncate">📋 รายงานรวม</div>
                        <div class="fs-2 fw-bold text-success mt-1"><?= number_format($total_data['total'] ?? 0) ?> <span class="fs-6 text-muted d-none d-md-inline font-normal">ครั้ง</span></div>
                    </div>
                </div>
                <div class="col-4 col-md-4">
                    <div class="stat-card warning-border">
                        <div class="text-muted fw-bold small text-truncate">🐘 ช้างป่ารวม</div>
                        <div class="fs-2 fw-bold text-warning mt-1"><?= number_format($total_data['total_elephants'] ?? 0) ?> <span class="fs-6 text-muted d-none d-md-inline font-normal">ตัว</span></div>
                    </div>
                </div>
                <div class="col-4 col-md-4">
                    <div class="stat-card info-border">
                        <div class="text-muted fw-bold small text-truncate">👥 อาสาสมัคร</div>
                        <div class="fs-2 fw-bold text-info mt-1"><?= number_format($total_volunteers) ?> <span class="fs-6 text-muted d-none d-md-inline font-normal">คน</span></div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 mb-md-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
                        <h6 class="fw-bold text-success m-0">🗺️ แผนที่พิกัดการกระจายตัวแบบเรียลไทม์</h6>
                        <div class="badge bg-danger p-1 p-md-2 fs-6">
                            🔥 พื้นที่ล่าสุด: <strong id="province-name-text">กำลังโหลด...</strong>
                        </div>
                    </div>
                    
                    <div id="map"></div>

                    <div class="p-2 mt-2 bg-light rounded-3 border d-flex flex-wrap justify-content-around align-items-center gap-2 small">
                        <div class="d-flex align-items-center gap-1">
                            <span class="legend-color bg-red-alert"></span>
                            <span><strong>สีแดง:</strong> วิกฤต (0 - 1 ชม.)</span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span class="legend-color bg-orange-warning"></span>
                            <span><strong>สีส้ม:</strong> เฝ้าระวัง (1 - 4 ชม.)</span>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span class="legend-color bg-gray-history"></span>
                            <span><strong>สีเทา:</strong> ประวัติ (> 4 ชม.)</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="border rounded-4 p-2 p-md-3 bg-light shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-2 mb-md-3 flex-wrap gap-2">
                    <h6 class="fw-bold text-danger m-0 d-flex align-items-center gap-1 gap-md-2">
                        <span>🚨 รายการล่าสุด / ย้อนหลัง (4 ชม.)</span>
                    </h6>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover table-custom bg-white rounded-3 overflow-hidden shadow-sm mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" style="width: 60px;">รูป</th>
                                <th>ผู้รายงาน</th>
                                <th class="text-center">จำนวน</th>
                                <th>พฤติกรรม / รายละเอียด</th>
                                <th class="mobile-hide">พิกัด (Lat, Lng)</th>
                                <th class="text-center">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_reports)): ?>
                                <?php foreach ($recent_reports as $item): ?>
                                    <?php 
                                        $full_name = trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
                                        $reporter = !empty($full_name) ? maskName($full_name) : 'ผู้ใช้งานทั่วไป';
                                        
                                        $status_raw = strtolower($item['status'] ?? 'pending');
                                        $status_text = 'รอตรวจสอบ';
                                        $status_badge = 'bg-warning text-dark';

                                        if ($status_raw === 'approved' || $status_raw === 'ตรวจสอบแล้ว' || $status_raw === 'ยืนยันแล้ว') {
                                            $status_text = 'ตรวจสอบแล้ว';
                                            $status_badge = 'bg-success';
                                        } elseif ($status_raw === 'rejected' || $status_raw === 'เท็จ' || $status_raw === 'ไม่อนุมัติ') {
                                            $status_text = 'ไม่อนุมัติ';
                                            $status_badge = 'bg-danger';
                                        }
                                    ?>
                                    <tr>
                                        <td class="text-center align-middle">
                                            <?php if (!empty($item['photo_path'])): ?>
                                                <a href="<?= htmlspecialchars($item['photo_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                                                    <img src="<?= htmlspecialchars($item['photo_path'], ENT_QUOTES, 'UTF-8') ?>" class="img-thumb" alt="ภาพช้าง">
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">ไม่มี</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td class="fw-bold text-primary align-middle">
                                            👤 <?= htmlspecialchars($reporter, ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        
                                        <td class="text-center align-middle">
                                            <span class="badge bg-warning text-dark fs-6"> <?= htmlspecialchars($item['elephant_count'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        
                                        <td class="align-middle">
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($item['behavior_type'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="small text-muted text-truncate" style="max-width: 180px;"><?= htmlspecialchars($item['details'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        
                                        <td class="align-middle mobile-hide">
                                            <small class="font-monospace text-secondary">
                                                📍 <?= htmlspecialchars($item['latitude'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($item['longitude'], ENT_QUOTES, 'UTF-8') ?>
                                            </small>
                                        </td>
                                        
                                        <td class="text-center align-middle">
                                            <span class="badge <?= $status_badge ?> px-2 py-1 small">
                                                <?= htmlspecialchars($status_text, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3 small">
                                        🍃 ไม่มีรายการรายงานช้างป่าในขณะนี้
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        function escapeHtml(text) {
            if (!text) return '';
            return text.toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        const reportsData = <?= json_encode($reports, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        const defaultLat = reportsData.length > 0 && !isNaN(parseFloat(reportsData[0].latitude)) ? parseFloat(reportsData[0].latitude) : 13.7563;
        const defaultLng = reportsData.length > 0 && !isNaN(parseFloat(reportsData[0].longitude)) ? parseFloat(reportsData[0].longitude) : 100.5018;

        const map = L.map('map').setView([defaultLat, defaultLng], 8);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        function createElephantMarkerIcon(colorClass, isRedAlert = false) {
            const pulseStyle = isRedAlert ? 'pulse-marker-red' : '';
            return L.divIcon({
                className: 'custom-pin-container',
                html: `
                    <div class="${pulseStyle}" style="
                        background-color: ${colorClass};
                        width: 34px;
                        height: 34px;
                        border-radius: 50%;
                        border: 2px solid white;
                        box-shadow: 0 3px 8px rgba(0,0,0,0.4);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 16px;
                        color: white;
                    ">
                        🐘
                    </div>
                `,
                iconSize: [34, 34],
                iconAnchor: [17, 17],
                popupAnchor: [0, -17]
            });
        }

        // เวลาปัจจุบันของฝั่ง Client/Browser
        const nowMs = Date.now();

        reportsData.forEach(item => {
            const lat = parseFloat(item.latitude);
            const lng = parseFloat(item.longitude);

            if (!isNaN(lat) && !isNaN(lng)) {
                let statusColor = '#6c757d'; // 🔘 สีเทา Default (> 4 ชม.)
                let statusTitle = '🔘 ประวัติการพบเห็น (> 4 ชั่วโมง)';
                let isRedAlert = false;

                if (item.timestamp_ms) {
                    const diffInHours = (nowMs - parseFloat(item.timestamp_ms)) / (1000 * 60 * 60);

                    if (diffInHours >= -0.1 && diffInHours <= 1) {
                        statusColor = '#dc3545'; // 🔴 สีแดง (0 - 1 ชม.)
                        statusTitle = '🚨 วิกฤต: พบใน 0-1 ชม. (เฝ้าระวังสูงสุด)';
                        isRedAlert = true;
                    } else if (diffInHours > 1 && diffInHours <= 4) {
                        statusColor = '#fd7e14'; // 🟠 สีส้ม (1 - 4 ชม.)
                        statusTitle = '⚠️ เฝ้าระวัง: พบใน 1-4 ชม. (อาจเคลื่อนตัว)';
                    }
                }

                const customIcon = createElephantMarkerIcon(statusColor, isRedAlert);

                const photoImg = item.photo_path 
                    ? `<img src="${escapeHtml(item.photo_path)}" style="width:100%; border-radius:8px; margin-bottom:6px; max-height:100px; object-fit:cover;">` 
                    : '';

                const popupContent = `
                    <div style="max-width: 200px;">
                        <div style="font-size: 0.8rem; font-weight: bold; margin-bottom: 5px; color: ${statusColor};">
                            ${escapeHtml(statusTitle)}
                        </div>
                        ${photoImg}
                        <b>🐘 จำนวน:</b> ${escapeHtml(item.elephant_count)} ตัว<br>
                        <b>พฤติกรรม:</b> ${escapeHtml(item.behavior_type || '-')}<br>
                        <small style="color: #666;">${escapeHtml(item.details || '')}</small>
                    </div>
                `;

                L.marker([lat, lng], { icon: customIcon }).addTo(map).bindPopup(popupContent);
            }
        });

        // --- ส่วนแปลงพิกัดรายงานล่าสุดเป็นชื่อจังหวัดจริง (Reverse Geocoding) ---
        const latestLat = <?= json_encode($latest_report_coord['latitude'] ?? null) ?>;
        const latestLng = <?= json_encode($latest_report_coord['longitude'] ?? null) ?>;

        if (latestLat && latestLng) {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${latestLat}&lon=${latestLng}&accept-language=th`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.address) {
                        const province = data.address.province || data.address.state || data.address.city || 'ไม่ระบุพื้นที่';
                        document.getElementById('province-name-text').innerText = province;
                    } else {
                        document.getElementById('province-name-text').innerText = 'ไม่ระบุพื้นที่';
                    }
                })
                .catch(err => {
                    console.error(err);
                    document.getElementById('province-name-text').innerText = 'ไม่สามารถดึงข้อมูลได้';
                });
        } else {
            document.getElementById('province-name-text').innerText = 'ยังไม่มีข้อมูลรายงาน';
        }
    </script>
</body>
</html>
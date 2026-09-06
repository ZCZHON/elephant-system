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

$highlight_id = isset($_GET['highlight_id']) ? (int)$_GET['highlight_id'] : 0;

// 🟢 ดึงเฉพาะรายงานที่ได้รับการยืนยัน (status = 'verified' หรือ 'approved')
$query = "SELECT report_id, photo_path, latitude, longitude, elephant_count, behavior_type, details, reported_at 
          FROM tbl_reports 
          WHERE status IN ('verified', 'approved') 
          ORDER BY reported_at DESC";

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
    <title>แผนที่สาธารณะ - ติดตามการกระจายตัวของช้างป่า</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background-color: #1a2e1a;
            color: #333;
            min-height: 100vh;
        }
        .navbar-custom {
            background-color: rgba(14, 34, 14, 0.95);
            backdrop-filter: blur(8px);
        }
        #map { 
            height: calc(100vh - 130px); 
            width: 100%; 
            border-radius: 16px; 
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }
        .legend-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 10px 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
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
            #map { height: calc(100vh - 160px); }
            .navbar-brand { font-size: 1rem !important; }
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom shadow-sm border-bottom border-success">
        <div class="container-fluid container-md">
            <a class="navbar-brand fw-bold text-warning" href="#">
                🐘 แผนที่เฝ้าระวังช้างป่า (Public Map)
            </a>
            
            <div class="d-flex align-items-center gap-2">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                        <a href="admin_dashboard.php" class="btn btn-warning btn-sm fw-bold">⚙️ หน้า Admin</a>
                    <?php else: ?>
                        <a href="index.php" class="btn btn-success btn-sm fw-bold">➕ แจ้งพบช้าง</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm fw-bold">ออกจากระบบ</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-success btn-sm fw-bold">💬 เข้าสู่ระบบ LINE</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid container-md my-2 my-md-3">
        <div id="map"></div>

        <!-- คำอธิบายสัญลักษณ์สี -->
        <div class="legend-card mt-2 d-flex flex-wrap justify-content-around align-items-center gap-2 small">
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
        const highlightId = <?= $highlight_id ?>;

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
                        width: 36px;
                        height: 36px;
                        border-radius: 50%;
                        border: 2px solid white;
                        box-shadow: 0 3px 8px rgba(0,0,0,0.4);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 18px;
                        color: white;
                    ">
                        🐘
                    </div>
                `,
                iconSize: [36, 36],
                iconAnchor: [18, 18],
                popupAnchor: [0, -18]
            });
        }

        const nowMs = Date.now();
        let targetMarker = null;

        reportsData.forEach(item => {
            const lat = parseFloat(item.latitude);
            const lng = parseFloat(item.longitude);

            if (!isNaN(lat) && !isNaN(lng)) {
                let statusColor = '#6c757d'; // สีเทา
                let statusTitle = '🔘 ประวัติการพบเห็น (> 4 ชั่วโมง)';
                let isRedAlert = false;

                if (item.timestamp_ms) {
                    const diffInHours = (nowMs - parseFloat(item.timestamp_ms)) / (1000 * 60 * 60);

                    if (diffInHours >= -0.1 && diffInHours <= 1) {
                        statusColor = '#dc3545'; // สีแดง
                        statusTitle = '🚨 วิกฤต: พบใน 0-1 ชม.';
                        isRedAlert = true;
                    } else if (diffInHours > 1 && diffInHours <= 4) {
                        statusColor = '#fd7e14'; // สีส้ม
                        statusTitle = '⚠️ เฝ้าระวัง: พบใน 1-4 ชม.';
                    }
                }

                const customIcon = createElephantMarkerIcon(statusColor, isRedAlert);

                const photoImg = item.photo_path 
                    ? `<img src="${escapeHtml(item.photo_path)}" style="width:100%; border-radius:8px; margin-bottom:6px; max-height:120px; object-fit:cover;">` 
                    : '';

                const popupContent = `
                    <div style="max-width: 200px;">
                        <div style="font-size: 0.85rem; font-weight: bold; margin-bottom: 5px; color: ${statusColor};">
                            ${escapeHtml(statusTitle)}
                        </div>
                        ${photoImg}
                        <b>🐘 จำนวน:</b> ${escapeHtml(item.elephant_count)} ตัว<br>
                        <b>พฤติกรรม:</b> ${escapeHtml(item.behavior_type || '-')}<br>
                        <small style="color: #666;">${escapeHtml(item.details || '')}</small>
                    </div>
                `;

                const marker = L.marker([lat, lng], { icon: customIcon }).addTo(map).bindPopup(popupContent);

                if (highlightId > 0 && parseInt(item.report_id) === highlightId) {
                    targetMarker = marker;
                    map.setView([lat, lng], 14, { animate: true });
                }
            }
        });

        if (targetMarker) {
            setTimeout(() => {
                targetMarker.openPopup();
            }, 500);
        }
    </script>
</body>
</html>

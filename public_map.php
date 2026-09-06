<?php
// กำหนด Timezone ระดับ PHP
date_default_timezone_set('Asia/Bangkok');

include('db.php');

// 🟢 ตั้งค่า Cookie ให้รองรับ HTTPS และข้าม Frame/LIFF
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

session_start();

// รับค่า Filter ช่วงเวลา
$filter = $_GET['filter'] ?? 'all';

$where_clause = "WHERE r.status IN ('verified', 'approved')";

if ($filter === 'today') {
    $where_clause .= " AND DATE(r.reported_at) = CURRENT_DATE";
} elseif ($filter === '7days') {
    $where_clause .= " AND r.reported_at >= NOW() - INTERVAL '7 days'";
} elseif ($filter === '30days') {
    $where_clause .= " AND r.reported_at >= NOW() - INTERVAL '30 days'";
}

// 1. ดึงข้อมูลรายงานที่ verified หรือ approved แล้ว
$query = "SELECT r.*, 
                 CONCAT(u.first_name, ' ', u.last_name) AS fullname 
          FROM tbl_reports r 
          LEFT JOIN tbl_users u ON r.user_id = u.user_id 
          $where_clause
          ORDER BY r.reported_at DESC";

$result = pg_query($db, $query);
$verified_reports = ($result) ? pg_fetch_all($result) ?: [] : [];

// คำนวณสถิติ
$total_spots = count($verified_reports);
$total_elephants = 0;
foreach ($verified_reports as $item) {
    $total_elephants += intval($item['elephant_count'] ?? 1);
}

// 2. ดึงจำนวนอาสาสมัคร
$query_users = "SELECT COUNT(*) AS total_volunteers FROM tbl_users"; 
$res_users = pg_query($db, $query_users);
$total_volunteers = 0;
if ($res_users) {
    $row_user = pg_fetch_assoc($res_users);
    $total_volunteers = intval($row_user['total_volunteers'] ?? 0);
}

$highlight_id = intval($_GET['highlight_id'] ?? 0);

// ตัวแปรเช็กสิทธิ์สำหรับแสดง UI
$is_logged_in = isset($_SESSION['user_id']);
$is_admin = $is_logged_in && (($_SESSION['role'] ?? '') === 'admin');
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ระบบติดตามการกระจายตัวของช้างป่าในประเทศไทย</title>
    
    <!-- Google Fonts, Bootstrap 5, FontAwesome -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Leaflet CSS & JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- LINE LIFF SDK -->
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>

    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #122111;
            color: #fff;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        /* Navbar หลัก */
        .top-navbar {
            background-color: #0b150a;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 10px 0;
        }
        
        .card-stat {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 12px;
            backdrop-filter: blur(5px);
        }
        
        .map-card {
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            color: #333;
            position: relative;
        }
        
        #publicMap {
            height: 550px;
            width: 100%;
        }
        
        .list-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 15px;
            max-height: 550px;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .report-item {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            border-left: 5px solid #6c757d;
        }
        .report-item.border-red { border-left-color: #dc3545 !important; }
        .report-item.border-orange { border-left-color: #fd7e14 !important; }
        .report-item.border-gray { border-left-color: #6c757d !important; }

        .report-item:hover {
            background: rgba(255, 255, 255, 0.18);
        }
        
        .popup-img {
            width: 100%;
            height: 110px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .map-legend {
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 12px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            font-size: 0.8rem;
            color: #333;
            line-height: 1.4;
        }
        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
        }
        .legend-item:last-child { margin-bottom: 0; }
        .color-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
            flex-shrink: 0;
        }

        /* ปุ่มค้นหาพิกัดผู้ใช้ */
        .leaflet-control-locate {
            font-size: 16px; font-weight: bold; line-height: 30px; text-align: center;
            cursor: pointer; display: block; width: 30px; height: 30px; color: #333;
            text-decoration: none; background-color: #fff; border-bottom: 1px solid #ccc;
        }
        .leaflet-control-locate:hover { background-color: #f4f4f4; color: #0d6efd; }

        #liffCloseBtn {
            display: none;
        }

        /* 📱 Mobile Responsive Styles */
        @media (max-width: 767.98px) {
            .top-navbar {
                padding: 8px 12px;
            }
            #publicMap {
                height: 42vh !important;
            }
            .list-card {
                max-height: 35vh !important;
                padding: 10px;
            }
            .card-stat {
                padding: 8px 6px;
            }
            .card-stat h2 {
                font-size: 1.2rem !important;
            }
            .map-legend {
                padding: 6px 8px;
                font-size: 0.7rem;
            }
            .btn-group-sm > .btn {
                padding: 0.25rem 0.4rem;
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <nav class="top-navbar">
        <div class="container-fluid container-md d-flex justify-content-between align-items-center">
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="text-white text-decoration-none fw-bold fs-6">
                🐘 <span class="ms-1 d-none d-sm-inline">ระบบติดตามการกระจายตัวของช้างป่า</span>
                <span class="ms-1 d-inline d-sm-none">เฝ้าระวังช้างป่า</span>
            </a>
            
            <div class="d-flex align-items-center gap-1 gap-sm-2">
                <?php if ($is_admin): ?>
                    <a href="admin_dashboard.php" class="btn btn-outline-success btn-sm fw-bold">
                        <i class="fa-solid fa-sliders me-1"></i> <span class="d-none d-sm-inline">จัดการระบบ</span> Admin
                    </a>
                <?php endif; ?>

                <?php if ($is_logged_in): ?>
                    <a href="index.php" class="btn btn-success btn-sm fw-bold">
                        <i class="fa-solid fa-plus me-1"></i> แจ้งพบช้าง
                    </a>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm fw-bold">
                        <i class="fa-solid fa-right-from-bracket me-1"></i> <span class="d-none d-sm-inline">ออกจากระบบ</span>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-success btn-sm fw-bold">
                        <i class="fa-solid fa-right-to-bracket me-1"></i> เข้าสู่ระบบ
                    </a>
                <?php endif; ?>

                <!-- ปุ่มปิดหน้าต่าง LIFF -->
                <button id="liffCloseBtn" onclick="liff.closeWindow()" class="btn btn-outline-light btn-sm fw-bold">
                    <i class="fa-solid fa-xmark me-1"></i> ปิด
                </button>
            </div>
        </div>
    </nav>

    <div class="container-fluid container-md my-2 my-md-3">
        
        <!-- Header Section + Filter -->
        <div class="row align-items-center mb-2 g-2">
            <div class="col-5 col-md-6">
                <h5 class="fw-bold text-success mb-0 fs-6 fs-md-5">
                    <i class="fa-solid fa-map-location-dot me-1"></i> แผนที่สาธารณะ
                </h5>
            </div>
            
            <!-- ตัวกรองช่วงเวลา -->
            <div class="col-7 col-md-6 text-end">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?filter=all" class="btn btn-outline-light <?php echo $filter === 'all' ? 'active fw-bold' : ''; ?>">ทั้งหมด</a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?filter=today" class="btn btn-outline-light <?php echo $filter === 'today' ? 'active fw-bold' : ''; ?>">วันนี้</a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?filter=7days" class="btn btn-outline-light <?php echo $filter === '7days' ? 'active fw-bold' : ''; ?>">7 วัน</a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?filter=30days" class="btn btn-outline-light <?php echo $filter === '30days' ? 'active fw-bold' : ''; ?>">30 วัน</a>
                </div>
            </div>
        </div>

        <!-- การ์ดสรุปสถิติ 3 ช่อง -->
        <div class="row g-2 mb-2 mb-md-3">
            <div class="col-4">
                <div class="card-stat text-center text-md-start">
                    <span class="text-white-50 small fw-bold">
                        <i class="fa-solid fa-location-dot me-1 text-success"></i><span class="d-none d-sm-inline">จุดพบล่าสุด</span><span class="d-inline d-sm-none">จุดพบ</span>
                    </span>
                    <h2 class="fw-bold text-success mb-0 mt-1"><?php echo number_format($total_spots); ?> <small class="fs-6 text-white-50">จุด</small></h2>
                </div>
            </div>
            <div class="col-4">
                <div class="card-stat text-center text-md-start">
                    <span class="text-white-50 small fw-bold">
                        <i class="fa-solid fa-elephant me-1 text-warning"></i><span class="d-none d-sm-inline">จำนวนช้างรวม</span><span class="d-inline d-sm-none">ช้างรวม</span>
                    </span>
                    <h2 class="fw-bold text-warning mb-0 mt-1"><?php echo number_format($total_elephants); ?> <small class="fs-6 text-white-50">ตัว</small></h2>
                </div>
            </div>
            <div class="col-4">
                <div class="card-stat text-center text-md-start" style="border-left: 4px solid #0dcaf0;">
                    <span class="text-white-50 small fw-bold">
                        <i class="fa-solid fa-users me-1 text-info"></i><span class="d-none d-sm-inline">เครือข่ายอาสาสมัคร</span><span class="d-inline d-sm-none">อาสาสมัคร</span>
                    </span>
                    <h2 class="fw-bold text-info mb-0 mt-1"><?php echo number_format($total_volunteers); ?> <small class="fs-6 text-white-50">คน</small></h2>
                </div>
            </div>
        </div>

        <!-- แผนที่ และ รายการด้านข้าง -->
        <div class="row g-2 g-md-3">
            <!-- แผนที่ -->
            <div class="col-lg-8">
                <div class="map-card p-1 p-md-2">
                    <div id="publicMap"></div>
                </div>
            </div>

            <!-- รายการพบเห็นย้อนหลัง -->
            <div class="col-lg-4">
                <div class="list-card">
                    <h6 class="fw-bold text-success mb-2 small">
                        <i class="fa-solid fa-list-ul me-2"></i>รายการพบเห็นล่าสุด
                    </h6>

                    <?php if (!empty($verified_reports)): ?>
                        <?php foreach ($verified_reports as $item): 
                            $reported_time = strtotime($item['reported_at'] ?? $item['created_at'] ?? 'now');
                            $diff_hours = (time() - $reported_time) / 3600;

                            $border_class = 'border-gray';
                            $badge_bg = 'bg-secondary';
                            $status_text = 'ประวัติ (>4 ชม.)';

                            if ($diff_hours <= 1) {
                                $border_class = 'border-red';
                                $badge_bg = 'bg-danger';
                                $status_text = '🔴 วิกฤต (0-1 ชม.)';
                            } elseif ($diff_hours <= 4) {
                                $border_class = 'border-orange';
                                $badge_bg = 'bg-warning text-dark';
                                $status_text = '🟠 เฝ้าระวัง (1-4 ชม.)';
                            }
                        ?>
                            <div class="report-item <?php echo $border_class; ?>" onclick="focusOnMap(<?php echo $item['latitude']; ?>, <?php echo $item['longitude']; ?>, <?php echo $item['report_id']; ?>)">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <span class="badge <?php echo $badge_bg; ?>" style="font-size: 0.7rem;"><?php echo $status_text; ?></span>
                                    <small class="text-white-50" style="font-size: 0.75rem;">
                                        <?php echo date('d/m/Y H:i', strtotime($item['reported_at'])); ?>
                                    </small>
                                </div>
                                <div class="fw-bold text-white small mt-1">
                                    🐘 พบช้าง <?php echo $item['elephant_count']; ?> ตัว | พฤติกรรม: <?php echo htmlspecialchars(($item['behavior_type'] ?? $item['behavior'] ?? '') ?: 'ไม่ระบุ'); ?>
                                </div>
                                <div class="text-white-50 small text-truncate mt-1" style="font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($item['details'] ?: 'ไม่มีรายละเอียดเพิ่มเติม'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-white-50 py-4">
                            <i class="fa-solid fa-map-location-dot fa-2x mb-2 text-warning opacity-75"></i>
                            <h6 class="fw-bold text-white mb-1 small">ไม่พบข้อมูลการแจ้งพบเห็น</h6>
                            <p class="small mb-0 text-white-50">ไม่มีรายงานการพบเห็นช้างป่าในช่วงเวลานี้</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Script Leaflet & LIFF -->
    <script>
        const MY_LIFF_ID = "2011293676-4qqKadRs";

        // 🟢 ตรวจสอบ LINE LIFF
        document.addEventListener("DOMContentLoaded", function() {
            liff.init({ liffId: MY_LIFF_ID })
                .then(() => {
                    if (liff.isInClient()) {
                        document.getElementById('liffCloseBtn').style.display = 'inline-block';
                    }
                })
                .catch((err) => {
                    console.log("LIFF Init Mode: Web Browser", err);
                });
        });

        // 🗺️ Leaflet Map Setup
        var map = L.map('publicMap').setView([13.736717, 100.523186], 7);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        function createElephantIcon(colorHex) {
            return L.divIcon({
                className: 'custom-div-icon',
                html: `<div style="
                    background-color: ${colorHex};
                    width: 28px;
                    height: 28px;
                    border-radius: 50%;
                    border: 2px solid #ffffff;
                    box-shadow: 0 3px 8px rgba(0,0,0,0.4);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 13px;
                ">🐘</div>`,
                iconSize: [28, 28],
                iconAnchor: [14, 14],
                popupAnchor: [0, -14]
            });
        }

        var iconRed = createElephantIcon('#dc3545');    
        var iconOrange = createElephantIcon('#fd7e14'); 
        var iconGray = createElephantIcon('#6c757d');   

        var reports = <?php echo json_encode($verified_reports); ?>;
        var highlightId = <?php echo $highlight_id; ?>;
        var markers = {};
        var bounds = [];
        var targetMarker = null;

        reports.forEach(function(item) {
            if (item.latitude && item.longitude) {
                var lat = parseFloat(item.latitude);
                var lng = parseFloat(item.longitude);
                
                bounds.push([lat, lng]);

                var reportedAt = new Date(item.reported_at || item.created_at).getTime();
                var now = new Date().getTime();
                var diffHours = (now - reportedAt) / (1000 * 60 * 60);

                var selectedIcon = iconGray;
                var statusBadge = '<span class="badge bg-secondary">⚪ ประวัติการพบเห็น (>4 ชม.)</span>';

                if (diffHours <= 1) {
                    selectedIcon = iconRed;
                    statusBadge = '<span class="badge bg-danger">🔴 วิกฤต (พบภายใน 0-1 ชม.)</span>';
                } else if (diffHours <= 4) {
                    selectedIcon = iconOrange;
                    statusBadge = '<span class="badge bg-warning text-dark">🟠 เฝ้าระวัง (พบภายใน 1-4 ชม.)</span>';
                }

                var behaviorTxt = item.behavior_type || item.behavior || 'ไม่ระบุ';
                var photoSrc = item.photo_path || item.image_path || '';

                var popupContent = `
                    <div style="width: 200px; color: #333;">
                        ${photoSrc ? `<img src="${photoSrc}" class="popup-img" alt="รูปช้าง">` : ''}
                        <div class="mb-2">${statusBadge}</div>
                        <h6 class="fw-bold text-dark mb-1">🐘 พบช้าง ${item.elephant_count} ตัว</h6>
                        <p class="small text-muted mb-1"><b>พฤติกรรม:</b> ${behaviorTxt}</p>
                        <p class="small text-muted mb-1"><b>รายละเอียด:</b> ${item.details || '-'}</p>
                        <hr class="my-1">
                        <small class="text-secondary d-block">
                            <i class="fa-regular fa-clock me-1"></i>${new Date(item.reported_at).toLocaleString('th-TH')}
                        </small>
                    </div>
                `;

                var marker = L.marker([lat, lng], {icon: selectedIcon})
                    .addTo(map)
                    .bindPopup(popupContent);

                markers[item.report_id] = marker;

                if (parseInt(item.report_id) === highlightId) {
                    targetMarker = marker;
                }
            }
        });

        // 🟢 เพิ่มปุ่มค้นหาพิกัดของผู้ใช้งาน (Locate Me) บนแผนที่
        const zoomControlContainer = map.zoomControl.getContainer();
        const locateBtn = L.DomUtil.create('a', 'leaflet-control-locate', zoomControlContainer);
        locateBtn.innerHTML = '➢';
        locateBtn.href = '#';
        locateBtn.title = 'ตำแหน่งของฉัน';

        let userMarker = null;
        L.DomEvent.on(locateBtn, 'click', function(e) {
            L.DomEvent.stopPropagation();
            L.DomEvent.preventDefault();

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        const userLat = position.coords.latitude;
                        const userLng = position.coords.longitude;

                        if (userMarker) map.removeLayer(userMarker);

                        userMarker = L.circleMarker([userLat, userLng], {
                            radius: 8,
                            fillColor: '#0d6efd',
                            color: '#ffffff',
                            weight: 2,
                            opacity: 1,
                            fillOpacity: 0.9
                        }).addTo(map).bindPopup("📍 ตำแหน่งของคุณ").openPopup();

                        map.setView([userLat, userLng], 12, { animate: true });
                    },
                    () => {
                        alert('ไม่สามารถระบุตำแหน่งของคุณได้ กรุณาเปิดบริการตำแหน่ง (GPS)');
                    },
                    { enableHighAccuracy: true, timeout: 10000 }
                );
            }
        });

        // 🔔 ควบคุมการซูม
        if (targetMarker) {
            map.setView(targetMarker.getLatLng(), 14);
            targetMarker.openPopup();
        } else if (bounds.length > 0) {
            map.fitBounds(bounds, {padding: [30, 30]});
        }

        function focusOnMap(lat, lng, reportId) {
            map.setView([lat, lng], 14, { animate: true });
            if (markers[reportId]) {
                markers[reportId].openPopup();
            }
        }

        // สัญลักษณ์เตือนภัย Legend
        var legend = L.control({position: 'topright'});
        legend.onAdd = function (map) {
            var div = L.DomUtil.create('div', 'map-legend');
            div.innerHTML = `
                <div class="fw-bold mb-1 border-bottom pb-1"><i class="fa-solid fa-layer-group me-1"></i>สัญลักษณ์</div>
                <div class="legend-item">
                    <span class="color-dot" style="background-color: #dc3545;"></span>
                    <span><b>สีแดง:</b> วิกฤต (0-1 ชม.)</span>
                </div>
                <div class="legend-item">
                    <span class="color-dot" style="background-color: #fd7e14;"></span>
                    <span><b>สีส้ม:</b> เฝ้าระวัง (1-4 ชม.)</span>
                </div>
                <div class="legend-item">
                    <span class="color-dot" style="background-color: #6c757d;"></span>
                    <span><b>สีเทา:</b> ประวัติ (>4 ชม.)</span>
                </div>
            `;
            return div;
        };
        legend.addTo(map);
    </script>
</body>
</html>

<?php
include('db.php');
session_start();

// รับค่า Filter ช่วงเวลา
$filter = $_GET['filter'] ?? 'all';

$where_clause = "WHERE r.status = 'verified'";

if ($filter === 'today') {
    $where_clause .= " AND DATE(r.reported_at) = CURRENT_DATE";
} elseif ($filter === '7days') {
    $where_clause .= " AND r.reported_at >= NOW() - INTERVAL '7 days'";
} elseif ($filter === '30days') {
    $where_clause .= " AND r.reported_at >= NOW() - INTERVAL '30 days'";
}

// 1. ดึงข้อมูลรายงานที่ verified แล้ว
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

// ตัวแปรเช็กสิทธิ์สำหรับแสดง UI เจ้าหน้าที่/ผู้ใช้งาน
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
        
        /* Navbar หลักบน PC */
        .top-navbar {
            background-color: #0b150a;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 12px 0;
        }
        
        .card-stat {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 15px;
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
            height: 580px;
            width: 100%;
        }
        
        .list-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 15px;
            max-height: 580px;
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
            padding: 12px 15px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            font-size: 0.82rem;
            color: #333;
            line-height: 1.5;
        }
        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        .legend-item:last-child { margin-bottom: 0; }
        .color-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            flex-shrink: 0;
        }

        /* ปุ่มปิดหน้าต่างเฉพาะใน LIFF */
        #liffCloseBtn {
            display: none;
        }

        /* 📱 Mobile & LIFF Responsive Styles */
        @media (max-width: 768px) {
            .top-navbar {
                padding: 10px 15px;
            }
            #publicMap {
                height: 48vh !important;
            }
            .list-card {
                max-height: 38vh !important;
                padding: 10px;
            }
            .card-stat {
                padding: 8px 10px;
            }
            .card-stat h2 {
                font-size: 1.3rem !important;
            }
            .map-legend {
                padding: 8px 10px;
                font-size: 0.72rem;
            }
        }
    </style>
</head>
<body>

    <!-- Header Navbar (รองรับทั้ง PC และ LIFF) -->
    <nav class="top-navbar">
        <div class="container-fluid container-md d-flex justify-content-between align-items-center">
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="text-white text-decoration-none fw-bold fs-5">
                🐘 <span class="ms-1 d-none d-sm-inline">ระบบติดตามการกระจายตัวของช้างป่าในประเทศไทย</span>
                <span class="ms-1 d-inline d-sm-none fs-6">เฝ้าระวังช้างป่า</span>
            </a>
            
            <div class="d-flex align-items-center gap-2">
                <?php if ($is_admin): ?>
                    <a href="admin_dashboard.php" class="btn btn-outline-success btn-sm fw-bold">
                        <i class="fa-solid fa-sliders me-1"></i> <span class="d-none d-sm-inline">จัดการระบบ</span> Admin
                    </a>
                <?php endif; ?>

                <?php if ($is_logged_in): ?>
                    <a href="logout.php" class="btn btn-danger btn-sm fw-bold">
                        <i class="fa-solid fa-right-from-bracket me-1"></i> <span class="d-none d-sm-inline">ออกจากระบบ</span>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-success btn-sm fw-bold">
                        <i class="fa-solid fa-right-to-bracket me-1"></i> เข้าสู่ระบบเจ้าหน้าที่
                    </a>
                <?php endif; ?>

                <!-- ปุ่มปิดหน้าต่าง LIFF (จะถูกเปิดใช้งานอัตโนมัติด้วย JS เมื่อเปิดใน LINE) -->
                <button id="liffCloseBtn" onclick="liff.closeWindow()" class="btn btn-outline-light btn-sm fw-bold">
                    <i class="fa-solid fa-xmark me-1"></i> ปิด
                </button>
            </div>
        </div>
    </nav>

    <div class="container-fluid container-md my-3">
        
        <!-- Header Section + Filter -->
        <div class="row align-items-center mb-3 g-2">
            <div class="col-6 col-md-6">
                <h4 class="fw-bold text-success mb-0 fs-5">
                    <i class="fa-solid fa-map-location-dot me-1"></i> Dashboard & Map
                </h4>
                <p class="text-white-50 small mb-0 d-none d-md-block">แผนที่และสถิติรายงานการพบเห็นช้างป่าแบบเรียลไทม์</p>
            </div>
            
            <!-- ปุ่มตัวกรองช่วงเวลา (ใช้ PHP_SELF ป้องกัน 404) -->
            <div class="col-6 col-md-6 text-end">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?filter=all" class="btn btn-outline-light <?php echo $filter === 'all' ? 'active' : ''; ?>">ทั้งหมด</a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?filter=today" class="btn btn-outline-light <?php echo $filter === 'today' ? 'active' : ''; ?>">วันนี้</a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?filter=7days" class="btn btn-outline-light <?php echo $filter === '7days' ? 'active' : ''; ?>">7 วัน</a>
                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?filter=30days" class="btn btn-outline-light <?php echo $filter === '30days' ? 'active' : ''; ?>">30 วัน</a>
                </div>
            </div>
        </div>

        <!-- การ์ดสรุปสถิติ 3 ช่อง -->
        <div class="row g-2 mb-3">
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
        <div class="row g-3">
            <!-- ฝั่งแผนที่ -->
            <div class="col-lg-8">
                <div class="map-card p-2">
                    <div id="publicMap"></div>
                </div>
            </div>

            <!-- ฝั่งรายการด้านข้าง -->
            <div class="col-lg-4">
                <div class="list-card">
                    <h6 class="fw-bold text-success mb-3 small">
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
                        <!-- 🔔 Empty State แสดงกรณีไม่มีข้อมูลช่วงเวลานั้น -->
                        <div class="text-center text-white-50 py-5">
                            <i class="fa-solid fa-map-location-dot fa-3x mb-3 text-warning opacity-75"></i>
                            <h6 class="fw-bold text-white mb-1">ไม่พบข้อมูลการแจ้งพบเห็น</h6>
                            <p class="small mb-0 text-white-50">ไม่มีรายงานการพบเห็นช้างป่าในช่วงเวลานี้</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Script Leaflet & LIFF -->
    <script>
        // 🟢 ตรวจสอบการเปิดผ่าน LINE LIFF
        document.addEventListener("DOMContentLoaded", function() {
            // ใส่ LIFF ID ที่รับมาจาก LINE Developers Console
            liff.init({ liffId: "YOUR_LIFF_ID" })
                .then(() => {
                    if (liff.isInClient()) {
                        // ถ้าเปิดใน LINE App จริง ให้แสดงปุ่มปิดหน้าต่าง
                        document.getElementById('liffCloseBtn').style.display = 'inline-block';
                    }
                })
                .catch((err) => {
                    console.log("LIFF Init (Web Browser Mode)", err);
                });
        });

        // 🗺️ Leaflet Map Setup
        var map = L.map('publicMap').setView([13.736717, 100.523186], 7);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
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

                var popupContent = `
                    <div style="width: 200px; color: #333;">
                        ${item.image_path ? `<img src="${item.image_path}" class="popup-img" alt="รูปช้าง">` : ''}
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

        // 🔔 ควบคุมการซูมและการแสดง Empty State กลางแผนที่
        if (targetMarker) {
            map.setView(targetMarker.getLatLng(), 14);
            targetMarker.openPopup();
        } else if (bounds.length > 0) {
            map.fitBounds(bounds, {padding: [40, 40]});
        } else {
            // กล่องแจ้งเตือนเมื่อไม่มีข้อมูลในแผนที่
            var emptyControl = L.control({position: 'topleft'});
            emptyControl.onAdd = function (map) {
                var div = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                div.style.backgroundColor = 'rgba(255, 255, 255, 0.95)';
                div.style.padding = '10px 15px';
                div.style.borderRadius = '10px';
                div.style.boxShadow = '0 4px 12px rgba(0,0,0,0.2)';
                div.style.marginTop = '10px';
                div.style.marginLeft = '10px';
                div.style.color = '#495057';
                div.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-circle-info text-warning fs-4"></i>
                        <div>
                            <div style="font-size: 0.9rem; font-weight: bold;" class="text-dark">ไม่พบข้อมูลการแจ้งพบเห็นช้างป่า</div>
                            <div style="font-size: 0.75rem;" class="text-muted">ไม่มีรายงานตามช่วงเวลาที่คุณเลือก</div>
                        </div>
                    </div>
                `;
                return div;
            };
            emptyControl.addTo(map);
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
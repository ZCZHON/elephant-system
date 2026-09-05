<?php
include('db.php');
session_start();

// 🟢 1. จัดการ Endpoint AJAX เมื่อ LINE LIFF ส่งข้อมูลยืนยันตัวตนมา
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'liff_login') {
    header('Content-Type: application/json; charset=utf-8');

    $line_userid = trim($_POST['line_userid'] ?? '');
    $display_name = trim($_POST['display_name'] ?? 'ผู้ใช้งาน LINE');

    if (empty($line_userid)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสผู้ใช้ LINE']);
        exit;
    }

    // ค้นหาผู้ใช้จาก line_userid ใน tbl_users
    $query = "SELECT user_id, first_name, last_name, role FROM tbl_users WHERE line_userid = $1";
    $result = pg_query_params($db, $query, array($line_userid));

    if ($result && $row = pg_fetch_assoc($result)) {
        // มีผู้ใช้อยู่แล้ว -> บันทึก Session
        $_SESSION['user_id']  = $row['user_id'];
        $_SESSION['fullname'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $_SESSION['role']     = !empty($row['role']) ? $row['role'] : 'user';

        $redirect = ($_SESSION['role'] === 'admin') ? 'admin_dashboard.php' : 'index.php';
        echo json_encode(['status' => 'success', 'redirect' => $redirect]);
        exit;
    } else {
        // สมาชิกใหม่ -> บันทึกลง tbl_users อัตโนมัติ
        $insert_query = "INSERT INTO tbl_users (line_userid, first_name, role) VALUES ($1, $2, 'user') RETURNING user_id";
        $insert_result = pg_query_params($db, $insert_query, array($line_userid, $display_name));

        if ($insert_result && $new_user = pg_fetch_assoc($insert_result)) {
            $_SESSION['user_id']  = $new_user['user_id'];
            $_SESSION['fullname'] = $display_name;
            $_SESSION['role']     = 'user';

            echo json_encode(['status' => 'success', 'redirect' => 'index.php']);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถสร้างบัญชีผู้ใช้ใหม่ได้']);
            exit;
        }
    }
}

// 🟢 2. เช็ก Session เดิม หากเข้าสู่ระบบแล้ว ให้ไปหน้า Dashboard ตามสิทธิ์
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - Elephant Tracker</title>
    <!-- Google Fonts & Bootstrap 5 & SweetAlert2 -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- 🟢 LINE LIFF SDK -->
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>

    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background: linear-gradient(rgba(14,34,14,0.7), rgba(14,34,14,0.7)), url('https://images.unsplash.com/photo-1516026672322-bc52d61a55d5?q=80&w=1920') no-repeat center center fixed; 
            background-size: cover; 
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
        }
        .auth-card { 
            background: rgba(255, 255, 255, 0.96); 
            border-radius: 20px; 
            padding: 35px 30px; 
            max-width: 420px; 
            margin: auto; 
            box-shadow: 0 15px 30px rgba(0,0,0,0.3); 
            border: 2px solid #2e5a27; 
        }
        .line-green-btn {
            background-color: #06C755;
            color: #ffffff;
            font-weight: 700;
            border: none;
            transition: all 0.2s ease-in-out;
        }
        .line-green-btn:hover {
            background-color: #05b34c;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="container my-4">
        <div class="auth-card text-center">
            <h3 class="fw-bold text-success mb-1">🐘 รายงานการพบช้างป่า</h3>
            <p class="text-muted small mb-4">ระบบติดตามและเตือนภัยช้างป่าเชิงพื้นที่</p>
            
            <div id="loading-spinner" class="py-4">
                <div class="spinner-border text-success mb-3" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="text-secondary fw-semibold mb-0" id="status-text">กำลังเชื่อมต่อ LINE...</p>
            </div>

            <div id="login-container" style="display: none;">
                <p class="text-muted small mb-3">เข้าสู่ระบบเพื่อเริ่มส่งรายงานและรับแจ้งเตือนภัย</p>
                <button type="button" onclick="liffLogin()" class="btn line-green-btn w-100 py-3 rounded-3 fs-6 shadow-sm mb-3">
                    💬 เข้าสู่ระบบด้วย LINE
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const MY_LIFF_ID = "2011293676-4qqKadRs";

        async function initLiff() {
            try {
                await liff.init({ liffId: MY_LIFF_ID });

                if (liff.isLoggedIn()) {
                    document.getElementById('status-text').innerText = "กำลังยืนยันตัวตน...";
                    const profile = await liff.getProfile();
                    
                    // ส่งข้อมูลไปยืนยัน Session ที่ PHP
                    processBackendLogin(profile.userId, profile.displayName);
                } else {
                    // แสดงปุ่มให้กด Login หากไม่ได้เปิดผ่าน LINE App โดยตรง
                    document.getElementById('loading-spinner').style.display = 'none';
                    document.getElementById('login-container').style.display = 'block';
                }
            } catch (err) {
                console.error("LIFF Init Error:", err);
                document.getElementById('loading-spinner').style.display = 'none';
                document.getElementById('login-container').style.display = 'block';
            }
        }

        function liffLogin() {
            if (!liff.isLoggedIn()) {
                liff.login();
            }
        }

        function processBackendLogin(lineUserId, displayName) {
            const formData = new FormData();
            formData.append('action', 'liff_login');
            formData.append('line_userid', lineUserId);
            formData.append('display_name', displayName);

            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    window.location.href = data.redirect;
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message || 'ไม่สามารถเข้าสู่ระบบได้', 'error');
                }
            })
            .catch(err => {
                console.error("Login Fetch Error:", err);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            });
        }

        // เริ่มทำงานเมื่อโหลดหน้าเว็บ
        window.onload = function() {
            initLiff();
        };
    </script>
</body>
</html>

<?php
// 1. กำหนด Timezone
date_default_timezone_set('Asia/Bangkok');

// 🟢 2. เช็คว่าเป็นการเชื่อมต่อผ่าน HTTPS หรือไม่ก่อนตั้งค่า Cookie
$is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
             (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,              // เป็น true เมื่อใช้ HTTPS เท่านั้น (ถ้า run localhost จะเป็น false)
        'httponly' => true,
        'samesite' => $is_https ? 'None' : 'Lax' // ถ้าไม่ใช่ HTTPS ให้ใช้ Lax เพื่อป้องกัน Cookie โดนบล็อก
    ]);
    session_start();
}

// 3. เชื่อมต่อฐานข้อมูล
include('db.php');

// 🐘 4. ประมวลผลเมื่อมีการส่งค่า LINE Profile จาก LIFF (POST/AJAX Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ล้าง Output Buffer ก่อนส่ง JSON เพื่อป้องกัน Error จาก Warning/Notice ของ PHP
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $line_user_id = trim($_POST['line_userid'] ?? '');
    $user_name    = trim($_POST['user_name'] ?? 'ผู้ใช้งาน LINE');

    if (empty($line_user_id)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลบัญชี LINE']);
        exit;
    }

    // 1. ตรวจสอบว่ามี User นี้ในฐานข้อมูล tbl_users แล้วหรือยัง
    $check_user = pg_query_params($db, "SELECT user_id, first_name, role FROM tbl_users WHERE line_user_id = $1", array($line_user_id));

    $role = 'user';

    if ($check_user && pg_num_rows($check_user) > 0) {
        // มีผู้ใช้งานเดิมในระบบแล้ว -> ดึงข้อมูลเข้า Session
        $user_row = pg_fetch_assoc($check_user);
        $_SESSION['user_id']  = $user_row['user_id'];
        $_SESSION['fullname'] = $user_row['first_name'];
        $_SESSION['role']     = $user_row['role'];
        $role                 = $user_row['role'];
    } else {
        // สมาชิกใหม่ -> บันทึกลง tbl_users อัตโนมัติ
        $insert_user = pg_query_params($db, 
            "INSERT INTO tbl_users (line_user_id, first_name, last_name, registered_at, role) VALUES ($1, $2, '', NOW(), 'user') RETURNING user_id, role", 
            array($line_user_id, $user_name)
        );

        if ($insert_user) {
            $user_row = pg_fetch_assoc($insert_user);
            $_SESSION['user_id']  = $user_row['user_id'];
            $_SESSION['fullname'] = $user_name;
            $_SESSION['role']     = $user_row['role'];
            $role                 = $user_row['role'];
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถสร้างบัญชีผู้ใช้ใหม่ได้']);
            exit;
        }
    }

    // บังคับบันทึก Session ลงดิสก์ทันทีก่อนส่ง Response กลับ
    session_write_close();

    // กำหนด URL ปลายทาง
    $redirect_url = ($role === 'admin') ? 'admin_dashboard.php' : 'index.php';

    // ส่งผลลัพธ์กลับไปยัง JavaScript LIFF
    echo json_encode([
        'status'   => 'success',
        'redirect' => $redirect_url
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบติดตามช้างป่า</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- 🟢 LINE LIFF SDK -->
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>

    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background: linear-gradient(rgba(14, 34, 14, 0.75), rgba(14, 34, 14, 0.75)), url('https://images.unsplash.com/photo-1516026672322-bc52d61a55d5?q=80&w=1920') no-repeat center center fixed; 
            background-size: cover; 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card { 
            background: rgba(255, 255, 255, 0.96); 
            border-radius: 24px; 
            padding: 35px 25px; 
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3); 
            border: 2px solid #2e5a27; 
            width: 100%;
            max-width: 420px; 
            text-align: center;
        }
        .btn-line {
            background-color: #06C755;
            color: white;
            font-weight: 600;
            border: none;
            padding: 12px;
            border-radius: 12px;
            transition: all 0.2s;
        }
        .btn-line:hover {
            background-color: #05b34c;
            color: white;
        }
    </style>
</head>
<body>

    <div class="container d-flex justify-content-center">
        <div class="login-card">
            <div class="mb-4">
                <h3 class="fw-bold text-success mb-2">🐘 รายงานการพบช้างป่า</h3>
                <p class="text-muted small m-0">ระบบติดตามและเตือนภัยช้างป่าเชิงพื้นที่</p>
            </div>

            <div id="statusBox" class="my-4">
                <div class="spinner-border text-success" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted small" id="statusText">กำลังยืนยันตัวตนผ่าน LINE...</p>
            </div>

            <button id="btnLogin" class="btn btn-line w-100 fs-6 shadow-sm d-none" onclick="liff.login()">
                💬 เข้าสู่ระบบด้วย LINE
            </button>
        </div>
    </div>

    <script>
        // 🟢 LIFF ID ของคุณ
        const MY_LIFF_ID = "2011293676-4qqKadRs";

        async function initLiff() {
            try {
                await liff.init({ liffId: MY_LIFF_ID });

                if (!liff.isLoggedIn()) {
                    // หากยังไม่ได้ล็อกอิน ให้ซ่อน Spinner แล้วแสดงปุ่มเข้าสู่ระบบด้วย LINE
                    document.getElementById('statusBox').classList.add('d-none');
                    document.getElementById('btnLogin').classList.remove('d-none');
                } else {
                    // หากล็อกอิน LINE แล้ว ให้ดึง Profile และส่งไปล็อกอินฝั่ง PHP
                    document.getElementById('statusText').innerText = "กำลังเข้าสู่ระบบ...";
                    const profile = await liff.getProfile();
                    processLogin(profile.userId, profile.displayName);
                }
            } catch (err) {
                console.error("LIFF Init Failed", err);
                document.getElementById('statusText').innerText = "ไม่สามารถเชื่อมต่อกับระบบ LINE ได้";
            }
        }

        function processLogin(userId, displayName) {
            const formData = new FormData();
            formData.append('line_userid', userId);
            formData.append('user_name', displayName);

            fetch('login.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // เปลี่ยนหน้าไปยัง index.php หรือ admin_dashboard.php
                    window.location.href = data.redirect;
                } else {
                    Swal.fire('ข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire('ข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            });
        }

        // เริ่มการทำงานเมื่อโหลดหน้าเว็บ
        initLiff();
    </script>

</body>
</html>

<?php
include('db.php');
session_start();

// ถ้าเข้าสู่ระบบอยู่แล้ว ให้ส่งไปหน้าที่ถูกต้องทันที
if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit;
}

$alert_script = '';
$active_tab = 'login'; // แท็บเริ่มต้น

// -------------------------------------------------------------
// 1. ประมวลผลการเข้าสู่ระบบ / ลงทะเบียนอัตโนมัติด้วย LINE User ID
// -------------------------------------------------------------
if (isset($_POST['line_login'])) {
    $line_user_id = trim($_POST['line_user_id'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');

    if (!empty($line_user_id)) {
        // ค้นหาผู้ใช้จาก line_user_id ใน tbl_users
        $query = "SELECT user_id, first_name, last_name, role FROM tbl_users WHERE line_user_id = $1";
        $result = pg_query_params($db, $query, array($line_user_id));

        if ($result && $row = pg_fetch_assoc($result)) {
            // กรณีเคยลงทะเบียนไว้แล้ว: บันทึกเข้า Session และ Auto-Login ทันที
            $_SESSION['user_id']  = $row['user_id'];
            $_SESSION['fullname'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $_SESSION['role']     = !empty($row['role']) ? $row['role'] : 'user';

            if ($_SESSION['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: index.php");
            }
            exit;
        } else {
            // กรณีเป็นสมาชิกใหม่: สลับไปแท็บลงทะเบียนพร้อมเติมชื่อจาก LINE ให้อัตโนมัติ
            $active_tab = 'reg';
            $alert_script = "Swal.fire('ยินดีต้อนรับ!', 'กรุณายินยอมรับนโยบายและกรอกข้อมูลเพื่อเริ่มใช้งานระบบครับ', 'info');";
        }
    }
}

// -------------------------------------------------------------
// 2. ประมวลผลการลงทะเบียนสมาชิกใหม่ (Register ด้วย LINE ID + วันเกิด)
// -------------------------------------------------------------
if (isset($_POST['register'])) {
    $active_tab = 'reg';
    $first_name   = trim($_POST['first_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $birth_date   = trim($_POST['birth_date'] ?? '');
    $line_user_id = trim($_POST['line_user_id'] ?? '');
    $agree_term   = $_POST['agree_term'] ?? '';

    if (empty($agree_term)) {
        $alert_script = "Swal.fire('ข้อผิดพลาด', 'กรุณายินยอมรับนโยบายความเป็นส่วนตัวก่อนลงทะเบียนครับ', 'error');";
    } elseif (empty($line_user_id)) {
        $alert_script = "Swal.fire('เกิดข้อผิดพลาด', 'ไม่พบ LINE User ID กรุณาลองใหม่อีกครั้งผ่านแอป LINE', 'error');";
    } elseif (empty($birth_date)) {
        $alert_script = "Swal.fire('ข้อผิดพลาด', 'กรุณาระบุวัน/เดือน/ปีเกิดของคุณครับ', 'error');";
    } else {
        // เช็ก LINE User ID ซ้ำในระบบ
        $check = pg_query_params($db, "SELECT line_user_id FROM tbl_users WHERE line_user_id = $1", array($line_user_id));
        
        if ($check && pg_num_rows($check) > 0) {
            $alert_script = "Swal.fire('บัญชีนี้ซ้ำ', 'บัญชี LINE นี้เคยลงทะเบียนไว้แล้วครับ', 'info');";
            $active_tab = 'login';
        } else {
            // บันทึกสมาชิกใหม่โดยใช้ line_user_id และ birth_date
            $query = "INSERT INTO tbl_users (first_name, last_name, birth_date, line_user_id, role) VALUES ($1, $2, $3, $4, 'user') RETURNING user_id";
            $result = pg_query_params($db, $query, array($first_name, $last_name, $birth_date, $line_user_id));
            
            if ($result && $row = pg_fetch_assoc($result)) {
                // บันทึก Session แล้วพาเข้าสู่ระบบทันที
                $_SESSION['user_id']  = $row['user_id'];
                $_SESSION['fullname'] = trim($first_name . ' ' . $last_name);
                $_SESSION['role']     = 'user';

                header("Location: index.php");
                exit;
            } else {
                $alert_script = "Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง', 'error');";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ / ลงทะเบียน - Elephant Tracker</title>
    <!-- LIFF SDK -->
    <script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <!-- Google Fonts & Bootstrap 5 & SweetAlert2 -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            padding: 30px; 
            max-width: 450px; 
            margin: auto; 
            box-shadow: 0 15px 30px rgba(0,0,0,0.3); 
            border: 2px solid #2e5a27; 
        }
        .nav-pills .nav-link.active { background-color: #2e5a27; }
        .nav-pills .nav-link { color: #2e5a27; font-weight: 600; }
        .privacy-box { 
            background: rgba(46, 90, 39, 0.05); 
            border-radius: 10px; 
            padding: 10px 12px; 
            border: 1px dashed rgba(46, 90, 39, 0.3); 
        }
        .user-avatar { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; }
    </style>
</head>
<body>
    <div class="container my-4">
        <div class="auth-card">
            <h3 class="text-center fw-bold text-success mb-1">🐘 รายงานการพบช้างป่า</h3>
            <p class="text-center text-muted small mb-3">ระบบติดตามและเตือนภัยช้างป่าเชิงพื้นที่</p>
            
            <!-- Loading Status แสดงระหว่างรอ LIFF ดึงข้อมูล -->
            <div id="liff-loading" class="text-center py-4">
                <div class="spinner-border text-success mb-2" role="status"></div>
                <p class="text-muted small">กำลังยืนยันตัวตนผ่าน LINE...</p>
            </div>

            <div id="auth-container" style="display: none;">
                <!-- แสดงโปรไฟล์ LINE ที่ดึงมาได้ -->
                <div class="text-center mb-3">
                    <img id="line-img" class="user-avatar shadow-sm mb-2" src="https://via.placeholder.com/70" alt="LINE Profile">
                    <h6 id="line-name-display" class="fw-bold mb-0 text-dark">...</h6>
                    <span class="badge bg-light text-success border border-success mt-1">ยืนยันตัวตนผ่าน LINE แล้ว</span>
                </div>

                <!-- แท็บสลับ เข้าสู่ระบบ / ลงทะเบียน -->
                <ul class="nav nav-pills nav-fill mb-4" id="pills-tab">
                    <li class="nav-item">
                        <button class="nav-link <?php echo ($active_tab === 'login') ? 'active' : ''; ?>" id="pills-login-tab" data-bs-toggle="pill" data-bs-target="#pills-login" type="button">เข้าสู่ระบบ</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo ($active_tab === 'reg') ? 'active' : ''; ?>" id="pills-reg-tab" data-bs-toggle="pill" data-bs-target="#pills-reg" type="button">ลงทะเบียนใหม่</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- 1. แท็บเข้าสู่ระบบด้วย LINE User ID -->
                    <div class="tab-pane fade <?php echo ($active_tab === 'login') ? 'show active' : ''; ?>" id="pills-login">
                        <form action="login.php" method="POST">
                            <input type="hidden" name="line_user_id" class="line-user-id-input">
                            <input type="hidden" name="display_name" class="line-display-name-input">
                            
                            <button type="submit" name="line_login" class="btn btn-success w-100 py-2 fw-bold fs-5 shadow-sm" style="background-color: #2e5a27;">
                                เข้าสู่ระบบด้วย LINE
                            </button>
                        </form>
                    </div>

                    <!-- 2. แท็บลงทะเบียนใหม่ -->
                    <div class="tab-pane fade <?php echo ($active_tab === 'reg') ? 'show active' : ''; ?>" id="pills-reg">
                        <form action="login.php" method="POST">
                            <input type="hidden" name="line_user_id" class="line-user-id-input">

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small fw-bold">ชื่อจริง</label>
                                    <input type="text" id="first_name_input" name="first_name" class="form-control" placeholder="ชื่อ" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold">นามสกุล</label>
                                    <input type="text" name="last_name" class="form-control" placeholder="นามสกุล" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                                </div>
                            </div>

                            <!-- ช่องกรอกวัน/เดือน/ปีเกิด -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold">📅 วัน/เดือน/ปีเกิด</label>
                                <input type="date" name="birth_date" class="form-control" value="<?php echo htmlspecialchars($_POST['birth_date'] ?? ''); ?>" required>
                            </div>

                            <!-- ส่วน PDPA Consent Box -->
                            <div class="privacy-box mb-3">
                                <div class="form-check text-start m-0">
                                    <input class="form-check-input" type="checkbox" id="agree_term" name="agree_term" value="1" required>
                                    <label class="form-check-label small text-secondary" for="agree_term" style="font-size: 0.82rem; line-height: 1.4;">
                                        ข้าพเจ้ายินยอมให้เก็บและใช้ข้อมูลเพื่อการวิจัยและเตือนภัย ตาม 
                                        <a href="#" class="text-success fw-bold text-decoration-underline" data-bs-toggle="modal" data-bs-target="#privacyModal">
                                            นโยบายความเป็นส่วนตัว (Privacy Policy)
                                        </a>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" name="register" class="btn btn-outline-success w-100 py-2 fw-bold">บันทึกข้อมูลและลงทะเบียน</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal แสดงรายละเอียด Privacy Policy -->
    <div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content rounded-4">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold text-success" id="privacyModalLabel">🐘 นโยบายความเป็นส่วนตัว (Privacy Policy)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small text-secondary">
                    <h6 class="fw-bold text-dark">1. วัตถุประสงค์การจัดเก็บข้อมูล</h6>
                    <p>ข้อมูลส่วนบุคคล (ชื่อ-นามสกุล, พิกัดตำแหน่ง) จะถูกรวบรวมเพื่อใช้ในโครงการวิจัย <strong>"การพัฒนาแพลตฟอร์มวิทยาศาสตร์พลเมืองในการติดตามการกระจายตัวของช้างป่า"</strong> เพื่อยืนยันตัวตนของผู้รายงานข้อมูลและแจ้งเตือนภัยแก่ชุมชน</p>

                    <h6 class="fw-bold text-dark">2. การคุ้มครองข้อมูลส่วนบุคคล (Data Protection)</h6>
                    <p>ชื่อ-นามสกุลของผู้รายงานจะถูกซ่อนบางส่วน (Name Masking) บนหน้า Dashboard สาธารณะ และ LINE Account จะถูกเก็บเป็นความลับ</p>

                    <h6 class="fw-bold text-dark">3. การนำข้อมูลไปใช้ประโยชน์</h6>
                    <p>ข้อมูลพิกัดและพฤติกรรมช้างป่าจะนำไปประมวลผลทางสถิติและแผนที่ เพื่อช่วยลดความขัดแย้งระหว่างคนกับช้างป่าในพื้นที่</p>
                </div>
                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-success btn-sm px-4 rounded-3 fw-bold" style="background-color: #2e5a27;" data-bs-dismiss="modal">รับทราบและเข้าใจ</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Script ทำงานร่วมกับ LIFF SDK -->
    <script>
        const MY_LIFF_ID = "2011293676-4qqKadRs";

        async function initLiff() {
            try {
                await liff.init({ liffId: MY_LIFF_ID });
                
                if (liff.isLoggedIn()) {
                    const profile = await liff.getProfile();
                    
                    // นำข้อมูล LINE มาใส่ใน Form hidden inputs
                    document.querySelectorAll('.line-user-id-input').forEach(el => el.value = profile.userId);
                    document.querySelectorAll('.line-display-name-input').forEach(el => el.value = profile.displayName);
                    
                    // แสดงผลโปรไฟล์บน UI
                    document.getElementById('line-img').src = profile.pictureUrl || 'https://via.placeholder.com/70';
                    document.getElementById('line-name-display').innerText = profile.displayName;
                    
                    // เติมชื่อจริงให้อัตโนมัติในช่องลงทะเบียน (ถ้าช่องนั้นว่างอยู่)
                    const firstNameInput = document.getElementById('first_name_input');
                    if (firstNameInput && !firstNameInput.value) {
                        firstNameInput.value = profile.displayName;
                    }

                    // แสดงแบบฟอร์มหลัก
                    document.getElementById('liff-loading').style.display = 'none';
                    document.getElementById('auth-container').style.display = 'block';
                } else {
                    liff.login();
                }
            } catch (err) {
                console.error("LIFF Initialization failed", err);
                document.getElementById('liff-loading').innerHTML = '<p class="text-danger">ไม่สามารถเชื่อมต่อ LINE ได้ กรุณาเปิดผ่านแอป LINE</p>';
            }
        }

        initLiff();
    </script>

    <!-- Script แสดง SweetAlert กรณีมีข้อมูลแจ้งเตือน -->
    <?php if (!empty($alert_script)): ?>
    <script>
        <?php echo $alert_script; ?>
    </script>
    <?php endif; ?>
</body>
</html>

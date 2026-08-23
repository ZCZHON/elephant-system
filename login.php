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
// 1. ประมวลผลการเข้าสู่ระบบ (Login)
// -------------------------------------------------------------
if (isset($_POST['login'])) {
    $phone = trim($_POST['phone_number'] ?? '');

    if (!empty($phone)) {
        // ค้นหาผู้ใช้จากเบอร์โทรศัพท์ใน tbl_users
        $query = "SELECT user_id, first_name, last_name, role FROM tbl_users WHERE phone_number = $1";
        $result = pg_query_params($db, $query, array($phone));

        if ($result && $row = pg_fetch_assoc($result)) {
            // บันทึกข้อมูลลงใน Session
            $_SESSION['user_id']  = $row['user_id'];
            $_SESSION['fullname'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            
            // กำหนดค่า role (หากใน DB เป็น NULL ให้ตั้งเป็น 'user')
            $_SESSION['role']     = !empty($row['role']) ? $row['role'] : 'user';

            // แยกหน้า Redirect ตามสิทธิ์ Admin / User
            if ($_SESSION['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: index.php");
            }
            exit;
        } else {
            $alert_script = "Swal.fire('ไม่พบข้อมูล', 'ไม่พบเบอร์โทรศัพท์นี้ในระบบ กรุณาลงทะเบียนก่อนใช้งานครับ', 'warning');";
        }
    } else {
        $alert_script = "Swal.fire('กรุณากรอกข้อมูล', 'กรุณากรอกเบอร์โทรศัพท์', 'info');";
    }
}

// -------------------------------------------------------------
// 2. ประมวลผลการลงทะเบียนสมาชิกใหม่ (Register)
// -------------------------------------------------------------
if (isset($_POST['register'])) {
    $active_tab = 'reg'; // สลับหน้ามาที่แท็บลงทะเบียนเมื่อมีการกดปุ่มสมัครสมาชิก
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $phone      = trim($_POST['phone_number'] ?? '');
    $agree_term = $_POST['agree_term'] ?? '';

    // ตรวจสอบว่าติ๊กยินยอมนโยบายความเป็นส่วนตัวหรือยัง
    if (empty($agree_term)) {
        $alert_script = "Swal.fire('ข้อผิดพลาด', 'กรุณายินยอมรับนโยบายความเป็นส่วนตัวก่อนลงทะเบียนครับ', 'error');";
    } else {
        // เช็กเบอร์โทรซ้ำในระบบ
        $check = pg_query_params($db, "SELECT phone_number FROM tbl_users WHERE phone_number = $1", array($phone));
        if ($check && pg_num_rows($check) > 0) {
            $alert_script = "Swal.fire('เบอร์โทรซ้ำ', 'เบอร์โทรศัพท์นี้เคยลงทะเบียนไว้แล้วครับ สามารถเข้าสู่ระบบได้เลย', 'info');";
            $active_tab = 'login'; // เบอร์ซ้ำ ให้สลับกลับไปแท็บเข้าสู่ระบบ
        } else {
            // บันทึกสมาชิกใหม่ (กำหนดค่าเริ่มต้น role เป็น 'user')
            $query = "INSERT INTO tbl_users (first_name, last_name, phone_number, role) VALUES ($1, $2, $3, 'user')";
            $result = pg_query_params($db, $query, array($first_name, $last_name, $phone));
            
            if ($result) {
                $alert_script = "Swal.fire('ลงทะเบียนสำเร็จ!', 'สามารถกรอกเบอร์โทรศัพท์เพื่อเข้าสู่ระบบได้เลยครับ', 'success');";
                $active_tab = 'login'; // สมัครสำเร็จ ให้สลับกลับไปแท็บเข้าสู่ระบบ
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
        .nav-pills .nav-link.active { 
            background-color: #2e5a27; 
        }
        .nav-pills .nav-link { 
            color: #2e5a27; 
            font-weight: 600; 
        }
        .privacy-box { 
            background: rgba(46, 90, 39, 0.05); 
            border-radius: 10px; 
            padding: 10px 12px; 
            border: 1px dashed rgba(46, 90, 39, 0.3); 
        }
    </style>
</head>
<body>
    <div class="container my-4">
        <div class="auth-card">
            <h3 class="text-center fw-bold text-success mb-1">🐘 รายงานการพบช้างป่า</h3>
            <p class="text-center text-muted small mb-4">ระบบติดตามและเตือนภัยช้างป่าเชิงพื้นที่</p>
            
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
                <!-- 1. แท็บเข้าสู่ระบบ -->
                <div class="tab-pane fade <?php echo ($active_tab === 'login') ? 'show active' : ''; ?>" id="pills-login">
                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">📞 กรอกเบอร์โทรศัพท์เพื่อเข้าสู่ระบบ</label>
                            <input type="tel" name="phone_number" class="form-control text-center fs-5" placeholder="เช่น 0812345678" maxlength="10" pattern="[0-9]{9,10}" required autofocus>
                        </div>
                        <button type="submit" name="login" class="btn btn-success w-100 py-2 fw-bold fs-5 shadow-sm" style="background-color: #2e5a27;">เข้าสู่ระบบเพื่อรายงาน</button>
                    </form>
                </div>

                <!-- 2. แท็บลงทะเบียนใหม่ -->
                <div class="tab-pane fade <?php echo ($active_tab === 'reg') ? 'show active' : ''; ?>" id="pills-reg">
                    <form action="login.php" method="POST">
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">ชื่อจริง</label>
                                <input type="text" name="first_name" class="form-control" placeholder="ชื่อ" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">นามสกุล</label>
                                <input type="text" name="last_name" class="form-control" placeholder="นามสกุล" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">📞 เบอร์โทรศัพท์มือถือ</label>
                            <input type="tel" name="phone_number" class="form-control" placeholder="กรอกเบอร์โทรศัพท์ 10 หลัก" maxlength="10" pattern="[0-9]{9,10}" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>" required>
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
                    <p>ข้อมูลส่วนบุคคล (ชื่อ-นามสกุล, เบอร์โทรศัพท์, พิกัดตำแหน่ง) จะถูกรวบรวมเพื่อใช้ในโครงการวิจัย <strong>"การพัฒนาแพลตฟอร์มวิทยาศาสตร์พลเมืองในการติดตามการกระจายตัวของช้างป่า"</strong> เพื่อยืนยันตัวตนของผู้รายงานข้อมูลและแจ้งเตือนภัยแก่ชุมชน</p>

                    <h6 class="fw-bold text-dark">2. การคุ้มครองข้อมูลส่วนบุคคล (Data Protection)</h6>
                    <p>ชื่อ-นามสกุลของผู้รายงานจะถูกซ่อนบางส่วน (Name Masking) บนหน้า Dashboard สาธารณะ และเบอร์โทรศัพท์จะถูกเก็บเป็นความลับเพื่อใช้ติดต่อเฉพาะกรณีฉุกเฉินโดยทีมงานหรือเจ้าหน้าที่ผลักดันช้างป่าเท่านั้น</p>

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
    
    <!-- Script แสดง SweetAlert กรณีมีข้อมูลแจ้งเตือน -->
    <?php if (!empty($alert_script)): ?>
    <script>
        <?php echo $alert_script; ?>
    </script>
    <?php endif; ?>
</body>
</html>
<?php
/**
 * =====================================================================
 *  ช่องทางเติมเงิน : PromptPay QR Code
 *  ออกแบบด้วย Bootstrap 5 (CDN) + FontAwesome
 * =====================================================================
 *  - จำนวนเงินเลือกจากปุ่มลัด ($mony_list) หรือพิมพ์เอง
 *  - กดชำระเงิน -> เรียก API create_pay สร้าง QR -> แสดง QR ให้สแกน
 *  - หน้าเว็บจะ poll ไปที่ paid.php ทุก 3 วินาที เพื่อเช็คว่าชำระเงินสำเร็จ
 * =====================================================================
 */

session_start();
require_once __DIR__ . '/function.php';

$mony_list        = config_get('mony_list', array());
$tmweasy_user     = config_get('tmweasy_user', '');
$tmweasy_password = config_get('tmweasy_password', '');
$con_id           = config_get('con_id', '');
$prommpay_no      = config_get('prommpay_no', '');
$prommpay_type    = config_get('prommpay_type', '01');
$prommpay_name    = config_get('prommpay_name', '');

/* ---------- helper เล็ก ๆ สำหรับแสดงข้อความ ---------- */
function flash_set($type, $text)
{
    $_SESSION['flash'] = array('type' => $type, 'text' => $text);
}
function flash_get()
{
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}
function flash_alert_type($type)
{
    if ($type === 'error') {
        return 'danger';
    }
    if ($type === 'success') {
        return 'success';
    }
    return 'info';
}

/* ---------- จัดการ action ต่าง ๆ ---------- */
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'cancel') {
    // ยกเลิกรายการชำระเงินที่ค้างอยู่ฝั่ง API ด้วย
    if (!empty($_SESSION['id_pay'])) {
        try {
            api_qr_call('cancel', array('id_pay' => $_SESSION['id_pay']));
        } catch (Exception $e) {
            // ข้าม error ไปได้ (รายการอาจหมดอายุไปแล้ว)
        }
    }
    $_SESSION['id_pay'] = '';
    flash_set('info', 'ยกเลิกรายการแล้ว คุณสามารถเริ่มโอนเงินใหม่ได้');
    header('Location: qr.php');
    exit;
}

if ($action === 'exit') {
    // ออกจากหน้าชำระเงิน โดยไม่ต้องไปยกเลิกฝั่ง API
    $_SESSION['id_pay'] = '';
    header('Location: qr.php');
    exit;
}

$success_view = ($action === 'success');
if ($success_view) {
    // แสดงหน้าสำเร็จแล้ว -> เคลียร์ id_pay เพื่อให้กลับมาเริ่มใหม่ได้
    $_SESSION['id_pay'] = '';
}

/* ---------- รับค่าจากฟอร์ม : สร้างรายการชำระเงิน ---------- */
$error    = '';
$ref1     = '';
$post_ref = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success_view) {
    $ref1     = isset($_POST['ref1']) ? trim((string) $_POST['ref1']) : '';
    $amount   = isset($_POST['amount']) ? trim((string) $_POST['amount']) : '';
    $post_ref = $ref1;

    if ($ref1 === '') {
        $error = 'กรุณากรอก Ref1 (username ของลูกค้า)';
    } elseif (!is_numeric($amount) || $amount <= 0) {
        $error = 'กรุณากรอกจำนวนเงินให้ถูกต้อง (มากกว่า 0)';
    } else {
        // ตรวจสอบว่าใส่ค่า API ใน config.php แล้วหรือยัง
        if ($tmweasy_user === '' || $tmweasy_password === '' || $con_id === '') {
            $error = 'ยังไม่ได้กำหนดค่า API ในไฟล์ config.php (tmweasy_user / tmweasy_password / con_id)';
        } else {
            try {
                $res = api_qr_call('create_pay', array(
                    'amount' => $amount,
                    'ref1'   => $ref1,
                ));

                if (!is_array($res) || !isset($res['status']) || $res['status'] != '1') {
                    $error = 'สร้างรายการไม่สำเร็จ : ' . (isset($res['msg']) ? $res['msg'] : 'ไม่ทราบสาเหตุ');
                } else {
                    // เก็บ id_pay ไว้ใน session แล้ว redirect ไปหน้าแสดง QR
                    $_SESSION['id_pay'] = $res['id_pay'];
                    header('Location: qr.php');
                    exit;
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

/* ---------- ถ้ามี id_pay ค้างอยู่ -> ดึงข้อมูล QR มาแสดง ---------- */
$pay_view   = false;
$qr_src     = '';
$pay_amount = '0.00';
$time_out   = 0;

if (!$success_view && !empty($_SESSION['id_pay'])) {
    try {
        $res = api_qr_call('detail_pay', array(
            'id_pay'       => $_SESSION['id_pay'],
            'type'         => $prommpay_type,
            'promptpay_id' => $prommpay_no,
        ));

        if (!is_array($res) || !isset($res['status']) || $res['status'] != '1') {
            // รายการหมดอายุ / ใช้ไม่ได้แล้ว -> เคลียร์แล้วกลับไปกรอกใหม่
            $_SESSION['id_pay'] = '';
            flash_set('error', 'รายการชำระเงินหมดอายุหรือไม่ถูกต้อง : ' . (isset($res['msg']) ? $res['msg'] : 'ไม่ทราบสาเหตุ') . ' กรุณาเริ่มโอนใหม่');
            header('Location: qr.php');
            exit;
        }

        $pay_view = true;

        // ยอดเงิน: API คืน amount_check เป็นหน่วย "สตางค์" จึงหาร 100 ให้เป็นบาท
        if (isset($res['amount_check'])) {
            $pay_amount = number_format((float) $res['amount_check'] / 100, 2, '.', '');
        } elseif (isset($res['amount'])) {
            $pay_amount = number_format((float) $res['amount'], 2, '.', '');
        }
        $time_out = isset($res['time_out']) ? (int) $res['time_out'] : 0;
        if (isset($res['qr_image_base64'])) {
            $qr_src = 'data:image/png;base64,' . $res['qr_image_base64'];
        }
        $ref1 = isset($res['ref1']) ? $res['ref1'] : $ref1;
    } catch (Exception $e) {
        $_SESSION['id_pay'] = '';
        flash_set('error', $e->getMessage());
        header('Location: qr.php');
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>เติมเงินด้วย PromptPay QR Code</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="css/custom.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php"><i class="fas fa-wallet"></i> ระบบเติมเงิน</a>
        <div class="navbar-nav">
            <a class="nav-link" href="index.php"><i class="fas fa-home"></i> หน้าแรก</a>
            <a class="nav-link active" href="qr.php"><i class="fas fa-qrcode"></i> PromptPay QR</a>
            <a class="nav-link" href="gift.php"><i class="fas fa-gift"></i> TrueMoney Gift</a>
        </div>
    </div>
</nav>

<main class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

            <?php if ($success_view): ?>

                <!-- ================= หน้าสำเร็จ ================= -->
                <div class="alert alert-success" role="alert">
                    <h4 class="alert-heading"><i class="fas fa-check-circle"></i> ชำระเงินสำเร็จแล้ว</h4>
                    <p class="mb-0">ระบบได้รับยอดเงินและบันทึกเครดิตให้เรียบร้อยแล้ว (ตรวจสอบได้จากตาราง topup_history)</p>
                    <hr>
                    <p class="mb-0 text-muted">หากพบปัญหากรุณาติดต่อ Admin ครับ / ปิดหน้านี้ได้เลย</p>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-primary" href="qr.php"><i class="fas fa-redo-alt"></i> เติมเงินอีกครั้ง</a>
                    <a class="btn btn-secondary" href="index.php"><i class="fas fa-home"></i> กลับหน้าเลือกช่องทาง</a>
                </div>

            <?php elseif ($pay_view): ?>

                <!-- ================= หน้าแสดง QR ให้สแกน ================= -->
                <?php $flash = flash_get(); if ($flash): ?>
                    <div class="alert alert-<?= flash_alert_type($flash['type']) ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-info-circle"></i> <?= htmlspecialchars($flash['text']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- ข้อมูลที่ js/custom.js ต้องใช้: id_pay และเวลาที่เหลือ (วินาที) -->
                <div class="card shadow-sm" id="pay_box" data-id-pay="<?= htmlspecialchars((string) $_SESSION['id_pay'], ENT_QUOTES) ?>" data-time-out="<?= (int) $time_out ?>">
                    <div class="card-header bg-dark text-white text-center">
                        <i class="fas fa-qrcode"></i> สแกน QR เพื่อชำระเงิน
                    </div>
                    <div class="card-body text-center">
                        <p class="text-muted mb-1">Ref1 : <strong><?= htmlspecialchars((string) $ref1) ?></strong></p>

                        <div class="qr-area mb-3" id="qr_area">
                            <?php if ($qr_src !== ''): ?>
                                <img src="<?= $qr_src ?>" alt="QR PromptPay">
                            <?php else: ?>
                                <p class="text-muted mb-0">ไม่พบรูป QR (อาจต้องเปิดใช้งาน API ก่อน)</p>
                            <?php endif; ?>
                        </div>

                        <p class="mb-1 text-muted">
                            เลขพร้อมเพย์ : <strong><?= htmlspecialchars((string) $prommpay_no) ?></strong><br>
                            ชื่อบัญชี : <?= htmlspecialchars((string) $prommpay_name) ?>
                        </p>

                        <p class="mb-1 text-muted">ยอดเงินที่ต้องโอน (บาท)</p>
                        <p class="amount-big"><?= number_format((float) $pay_amount, 2) ?></p>
                        <div class="alert alert-danger py-2 mb-2">
                            <i class="fas fa-exclamation-triangle"></i> โอนจำนวนเงินให้ตรงกับยอดด้านบนเท่านั้น ระบบจะเติมเครดิตอัตโนมัติ
                        </div>

                        <p class="mb-1 text-muted">เวลาที่เหลือ : <strong id="time_left" class="text-danger">--:--</strong></p>

                        <!-- แสดงเมื่อหมดเวลา (js/custom.js จะสลับ class d-none) -->
                        <div id="expired_box" class="d-none mt-3">
                            <div class="alert alert-danger py-2 mb-3">
                                <i class="fas fa-exclamation-circle"></i> หมดเวลาโอนเงินแล้ว หากโอนไปแล้วกรุณากด "ตรวจสอบอีกครั้ง" หากยังไม่โอนกรุณากด "เริ่มโอนใหม่"
                            </div>
                            <div class="d-grid gap-2">
                                <a class="btn btn-danger" href="?action=cancel"><i class="fas fa-undo-alt"></i> เริ่มโอนใหม่ (ยกเลิกรายการเดิม)</a>
                                <a class="btn btn-secondary" href="qr.php"><i class="fas fa-search"></i> ตรวจสอบอีกครั้ง</a>
                            </div>
                        </div>

                        <a class="btn btn-link btn-sm mt-2" href="?action=exit"><i class="fas fa-sign-out-alt"></i> ออกจากหน้านี้</a>
                    </div>
                </div>

            <?php else: ?>

                <!-- ================= ฟอร์มกรอกยอดเงิน ================= -->
                <?php $flash = flash_get(); if ($flash): ?>
                    <div class="alert alert-<?= flash_alert_type($flash['type']) ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-info-circle"></i> <?= htmlspecialchars($flash['text']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-times-circle"></i> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white">
                        <i class="fas fa-qrcode"></i> เติมเงินด้วย PromptPay QR Code
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            เลือกยอดเงินจากปุ่มลัด <strong>หรือ</strong> พิมพ์ยอดเงินเองในช่องกรอก<br>
                            จากนั้นกรอก Ref1 (username) แล้วกด "ชำระเงิน" เพื่อรับ QR Code
                        </p>

                        <form method="post" action="qr.php" id="topup_form">
                            <div class="mb-3">
                                <label for="ref1" class="form-label">Ref1 (Username ของลูกค้า)</label>
                                <?php
                                $ref1_value = ($post_ref !== '') ? $post_ref : (isset($_GET['ref1']) ? trim((string) $_GET['ref1']) : '');
                                ?>
                                <input type="text" class="form-control" id="ref1" name="ref1" value="<?= htmlspecialchars($ref1_value) ?>" placeholder="เช่น demo">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">จำนวนเงิน (บาท) — คลิกเลือก หรือพิมพ์เอง</label>
                                <div class="d-flex flex-wrap gap-2 mb-2" id="money_btns">
                                    <?php foreach ($mony_list as $m): ?>
                                        <button type="button" class="btn btn-outline-primary btn-money" data-val="<?= (float) $m ?>"><?= number_format((float) $m) ?> บาท</button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="number" class="form-control" id="amount" name="amount" min="1" step="0.01" placeholder="หรือพิมพ์ยอดเงินเอง เช่น 150.50">
                            </div>

                            <div class="form-text mb-4">
                                <i class="fas fa-exclamation-circle"></i> เตรียมแอปธนาคารให้พร้อมก่อนกดชำระเงิน
                            </div>

                            <button type="submit" id="btn_submit" class="btn btn-primary w-100"><i class="fas fa-money-check-alt"></i> กดเพื่อชำระเงิน</button>
                        </form>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/custom.js"></script>
</body>
</html>

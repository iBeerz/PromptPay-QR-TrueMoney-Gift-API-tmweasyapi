<?php
/**
 * =====================================================================
 *  ช่องทางเติมเงิน : TrueMoney Gift (ซองของขวัญ / อั่งเปา)
 *  ออกแบบด้วย Bootstrap 5 (CDN) + FontAwesome
 * =====================================================================
 *  - กรอก Ref1/username และ URL ซองของขวัญ
 *  - ระบบส่ง URL ไปตรวจสอบกับ API (apiwallet.php)
 *  - ถ้า check_success -> บวกเครดิต 1:1 กับจำนวนเงินบาท + บันทึกประวัติ
 *  - ฐานข้อมูล / config ใช้ร่วมกับระบบ QR (qr.php) ชุดเดียวกัน
 * =====================================================================
 */

session_start();
require_once __DIR__ . '/function.php';

$truewallet           = config_get('truewallet', array());
$db_users_table        = config_get('db_users_table', 'users');
$db_users_ref_field    = config_get('db_users_ref_field', 'username');
$db_users_credit_field = config_get('db_users_credit_field', 'credit');
$tmweasy_user          = config_get('tmweasy_user', '');
$tmweasy_password      = config_get('tmweasy_password', '');

// เก็บข้อความแจ้งเตือน (session flash)
function set_alert($content, $type)
{
    $_SESSION["alert_content"] = $content;
    $_SESSION["alert_type"]    = $type; // alert-danger / alert-success
}

/* ---------------- รับค่าจากฟอร์มเติมเงิน ---------------- */

$show_credits = null; // ใช้แสดงยอดเครดิตปัจจุบันของลูกค้า

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["transactionid"]) && $_POST["transactionid"] === "truegift") {

    $gifturl = trim($_POST["gifturl"] ?? "");
    $ref1    = trim($_POST["ref1"] ?? "");

    // ตรวจสอบข้อมูลที่กรอก
    if ($ref1 === "") {
        set_alert("Error : กรุณากรอก Ref1 / User / ID", "alert-danger");
    } elseif (strpos($gifturl, "ttp") === false) {
        set_alert("Error : การกรอก URL ซองของขวัญไม่ถูกต้อง", "alert-danger");
    } else {
        // ตรวจสอบว่ากรอกค่า API ใน config.php หรือยัง
        if ($tmweasy_user === '' || $tmweasy_password === '' || empty($truewallet["mobile"])) {
            set_alert("Error : ยังไม่ได้กำหนดค่า API ในไฟล์ config.php (tmweasy_user / tmweasy_password / truewallet.mobile)", "alert-danger");
        } else {
            // เรียก API ตรวจสอบซองของขวัญ (endpoint: apiwallet.php)
            $api_respone = api_gift_check($gifturl, $ref1);

            /*
                // สำหรับทดสอบ จำลองว่าเติมสำเร็จ (comment บรรทัดนี้ออกเพื่อทดสอบ)
                $api_respone['Status'] = "check_success";
                $api_respone['Amount'] = 50;
                $api_respone['Type']   = "truegift";
                */

            if (!is_array($api_respone) || !isset($api_respone["Status"])) {
                set_alert("Error : ไม่สามารถติดต่อ API ได้ โปรดลองใหม่", "alert-danger");
            } elseif ($api_respone["Status"] === "check_success") {
                // เติมสำเร็จ -> บวกเครดิต 1:1 กับจำนวนเงิน (ไม่มีตัวคูณเครดิต)
                $money_total = (float) $api_respone["Amount"];
                $point       = number_format($money_total, 2, '.', '');

                try {
                    $pdo = db();
                    $pdo->beginTransaction();

                    // 1. ตรวจสอบว่าผู้ใช้ (ref1) มีอยู่ในระบบหรือไม่
                    $stmt = $pdo->prepare(
                        "SELECT id, `{$db_users_ref_field}`, `{$db_users_credit_field}`
                                   FROM `{$db_users_table}`
                                   WHERE `{$db_users_ref_field}` = :ref1
                                   FOR UPDATE"
                    );
                    $stmt->execute(array(":ref1" => $ref1));
                    $user = $stmt->fetch();

                    if (!$user) {
                        throw new Exception("ไม่พบผู้ใช้งาน " . $ref1 . " ในระบบ");
                    }

                    // 2. กันการเติมซ้ำ : ซองของขวัญนี้ต้องไม่เคยเติมสำเร็จมาก่อน
                    if (history_transactionid_success($gifturl)) {
                        throw new Exception("ซองของขวัญนี้ถูกใช้ไปแล้ว");
                    }

                    // 3. บวกเครดิตให้ผู้ใช้
                    $stmt = $pdo->prepare(
                        "UPDATE `{$db_users_table}`
                                 SET `{$db_users_credit_field}` = `{$db_users_credit_field}` + :point
                                 WHERE `{$db_users_ref_field}` = :ref1"
                    );
                    $stmt->execute(array(
                        ":point" => $point,
                        ":ref1"  => $ref1,
                    ));

                    // 4. บันทึกประวัติการเติมเงิน (ตารางเดียวกับระบบ QR)
                    history_insert(array(
                        'method'        => 'truegift',
                        'transactionid' => $gifturl,
                        'ref1'          => $ref1,
                        'amount'        => $money_total,
                        'credit_added'  => $point,
                        'status'        => 'check_success',
                        'api_msg'       => json_encode($api_respone, JSON_UNESCAPED_UNICODE),
                        'client_ip'     => my_ip(),
                    ));

                    $pdo->commit();

                    set_alert(
                        "ยอดเงินที่ได้รับ " . number_format($money_total, 2) . " บาท ได้เครดิต "
                            . number_format($point, 2) . " เครดิต ขอบคุณที่ใช้บริการครับ [ ปิดหน้านี้ได้เลย! ]",
                        "alert-success"
                    );
                } catch (Exception $e) {
                    $pdo->rollBack();
                    set_alert("Error : " . $e->getMessage(), "alert-danger");
                }

                header("location:gift.php?ref1=" . urlencode($ref1));
                die();
            } else {
                // เติมไม่สำเร็จ -> บันทึก log ไว้ตรวจสอบ
                $msg = $api_respone["Msg"] ?? "check_failed";

                try {
                    history_insert(array(
                        'method'        => 'truegift',
                        'transactionid' => $gifturl,
                        'ref1'          => $ref1,
                        'amount'        => '0.00',
                        'credit_added'  => '0.00',
                        'status'        => 'check_failed',
                        'api_msg'       => $msg,
                        'client_ip'     => my_ip(),
                    ));
                } catch (PDOException $e) {
                    // ข้าม error นี้ได้ ไม่กระทบการแสดงผล
                }

                set_alert("Error : " . $msg, "alert-danger");
            }
        }
    }
}

// ดึงยอดเครดิตของผู้ใช้มาแสดง (ถ้ามีค่า ref1 ใน URL)
if (!empty($_GET["ref1"])) {
    $show_credits = user_get_credit(trim($_GET["ref1"]));
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="th">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>เติมเงินด้วย TrueMoney Gift (ซองของขวัญ)</title>
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
        <a class="nav-link" href="qr.php"><i class="fas fa-qrcode"></i> PromptPay QR</a>
        <a class="nav-link active" href="gift.php"><i class="fas fa-gift"></i> TrueMoney Gift</a>
      </div>
    </div>
  </nav>

  <main class="container py-4">
    <div class="row justify-content-center">
      <div class="col-md-6 col-lg-5">

        <?php if (!empty($_SESSION["alert_content"])) { ?>
          <?php
          $alert_content = $_SESSION["alert_content"];
          $alert_type    = $_SESSION["alert_type"] === "alert-danger" ? "danger" : "success";
          unset($_SESSION["alert_content"], $_SESSION["alert_type"]);
          ?>
          <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($alert_content) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php } ?>

        <div class="card shadow-sm">
          <div class="card-header bg-danger text-white text-center">
            <h5 class="mb-0"><i class="fas fa-gift"></i> เติมเงินด้วย TrueMoney Gift</h5>
            <small>ซองของขวัญ / อั่งเปา</small>
          </div>
          <div class="card-body">

            <p class="text-muted mb-1">โอนซองของขวัญมาที่เบอร์</p>
            <h3 class="text-danger"><strong><i class="fas fa-mobile-alt"></i> <?= htmlspecialchars($truewallet["mobile"] ?? "") ?></strong></h3>
            <p class="text-muted mb-3">ชื่อ : <?= htmlspecialchars($truewallet["name"] ?? "") ?></p>

            <div class="alert alert-info py-2 mb-3">
              <i class="fas fa-info-circle"></i> รองรับการรับยอดจากซองของขวัญ TrueMoney Wallet
            </div>

            <?php if ($show_credits !== null) { ?>
              <div class="alert alert-primary py-2">
                ยอดเครดิตปัจจุบันของ <strong><?= htmlspecialchars(trim($_GET["ref1"])) ?></strong> :
                <strong><?= number_format((float) $show_credits, 2) ?></strong> เครดิต
              </div>
            <?php } ?>

            <form method="post">
              <div class="mb-3">
                <label for="ref1" class="form-label">Ref1/username</label>
                <input type="text" class="form-control" id="ref1" name="ref1"
                  value="<?= htmlspecialchars($_GET["ref1"] ?? "") ?>" required />
              </div>
              <div class="mb-3">
                <label for="gifturl" class="form-label">URL ซองของขวัญ</label>
                <input type="text" class="form-control" id="gifturl" name="gifturl" required
                  placeholder="https://gift.truemoney.com/..." />
              </div>
              <input type="hidden" name="transactionid" value="truegift">
              <button type="submit" class="btn btn-danger w-100"><i class="fas fa-paper-plane"></i> ตรวจสอบเติมเงิน</button>
            </form>

          </div>
        </div>

      </div>
    </div>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/custom.js"></script>
</body>

</html>

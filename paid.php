<?php
/**
 * =====================================================================
 *  ตรวจสอบสถานะการชำระเงิน PromptPay QR (ถูกเรียกจาก qr.php ทุก 3 วินาที)
 * =====================================================================
 *  อ่านจากตาราง topup_history ในฐานข้อมูลโดยตรง
 *  (hook_action.php จะ INSERT แถว status = 'success' เมื่อชำระเงินสำเร็จ)
 *
 *  คืนค่า : "ok" เมื่อชำระเงินสำเร็จ , ว่างเปล่าเมื่อยังไม่ชำระ
 * =====================================================================
 */

session_start();
require_once __DIR__ . '/function.php';

header('Content-Type: text/plain; charset=utf-8');

$db_history_table = config_get('db_history_table', 'topup_history');

$id_pay = isset($_GET['id_pay']) ? trim((string) $_GET['id_pay']) : '';

// ตรวจสอบว่า id_pay ที่ขอมาตรงกับ id_pay ใน session ของเราจริง ๆ
// (กันคนอื่นมารู้ว่า id_pay ไหนชำระเงินแล้ว)
if ($id_pay === '' || empty($_SESSION['id_pay']) || $_SESSION['id_pay'] !== $id_pay) {
    exit;
}

try {
    $stmt = db()->prepare("SELECT status FROM `{$db_history_table}` WHERE method = 'qr' AND id_pay = :id_pay LIMIT 1");
    $stmt->execute(array(':id_pay' => $id_pay));

    $row = $stmt->fetch();
    if ($row && $row['status'] === 'success') {
        echo 'ok';
    }
} catch (PDOException $e) {
    // ฐานข้อมูล error -> ไม่ตอบอะไร ปล่อยให้ qr.php poll ใหม่รอบถัดไป
}
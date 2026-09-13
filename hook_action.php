<?php
/**
 * =====================================================================
 *  รับ Webhook (ระบบส่งกลับ) เมื่อลูกค้าชำระเงินสำเร็จด้วย PromptPay QR
 * =====================================================================
 *  เว็บ tmweasy จะ POST ข้อมูลมาที่ไฟล์นี้ เมื่อตรวจพบว่ามีการโอนเงินเข้า
 *  ตรงกับ QR ที่เราสร้างไว้ (ต้องไปตั้ง "Callback URL" ที่หน้าเว็บ tmweasy
 *  ให้ชี้มาที่ URL ของ hook_action.php เช่น https://เว็บคุณ/hook_action.php)
 *
 *  ข้อมูลที่ส่งมา (POST) :
 *    data      = json string เช่น {"id_pay":"...","ref1":"...","amount":100,"timestamp":1234567890}
 *    signature = md5( data . ':' . $tmweasy_api_key )
 *
 *  ขั้นตอนการทำงาน :
 *    1. ตรวจสอบเวลาของ data (กัน request ย้อนหลัง / replay)
 *    2. ตรวจสอบ signature (กันคนนอกปลอมแปลง)
 *    3. เช็คว่า id_pay เคยประมวลผลไปแล้วหรือยัง (กันเครดิตซ้ำ)
 *    4. ใช้ PDO transaction: บวกเครดิตให้ลูกค้า + บันทึกประวัติการเติมเงิน
 *    5. ตอบกลับ {"status":1,"msg":"ok"} เสมอเมื่อรับข้อมูลถูกต้อง
 * =====================================================================
 */

require_once __DIR__ . '/function.php';

header('Content-Type: application/json; charset=utf-8');

$webhook_time_allow = config_get('webhook_time_allow', 30);

/** จบการทำงานพร้อมตอบกลับเป็น json */
function json_response($status, $msg)
{
    echo json_encode(array('status' => (int) $status, 'msg' => $msg), JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------------------------
//  รับค่าที่ API ส่งมา
// ------------------------------------------------------------------
$raw_data  = isset($_POST['data']) ? $_POST['data'] : '';
$signature = isset($_POST['signature']) ? $_POST['signature'] : '';

if ($raw_data === '' || $signature === '') {
    json_response(0, 'Data is empty.');
}

$data = json_decode($raw_data, true);
if (!is_array($data)) {
    json_response(0, 'Data format incorrect.');
}

// ------------------------------------------------------------------
//  1) ตรวจสอบเวลา  (timestamp ต้องใกล้เคียงกับเวลาปัจจุบัน)
// ------------------------------------------------------------------
$timestamp = isset($data['timestamp']) ? (int) $data['timestamp'] : 0;
if (abs(time() - $timestamp) > $webhook_time_allow) {
    json_response(0, 'Data is not current.');
}

// ------------------------------------------------------------------
//  2) ตรวจสอบ signature
// ------------------------------------------------------------------
if (!check_webhook_signature($raw_data, $signature)) {
    json_response(0, 'Signature incorrect. Check API Key.');
}

// ------------------------------------------------------------------
//  3) ตรวจสอบข้อมูลพื้นฐาน
// ------------------------------------------------------------------
$id_pay = isset($data['id_pay']) ? trim((string) $data['id_pay']) : '';
$ref1   = isset($data['ref1'])   ? trim((string) $data['ref1'])   : '';
$amount = isset($data['amount']) ? $data['amount']                : 0;

if ($id_pay === '' || $ref1 === '' || !is_numeric($amount) || $amount <= 0) {
    json_response(0, 'Data incorrect.');
}

// แปลงยอดเงินให้เป็นทศนิยม 2 ตำแหน่ง (บาท) เก็บเป็น string เพื่อกันเลขเพี้ยน
$amount = number_format((float) $amount, 2, '.', '');

try {
    // ------------------------------------------------------------------
    //  4) เช็ค id_pay เคยประมวลผลแล้วหรือยัง -> ตอบ ok โดยไม่เครดิตซ้ำ
    // ------------------------------------------------------------------
    if (history_id_pay_exists($id_pay)) {
        json_response(1, 'ok'); // เคยประมวลผลไปแล้ว (API ส่งซ้ำ)
    }

    $pdo = db();
    $pdo->beginTransaction();

    // ตัวอย่าง: เงินที่โอนมา 1 บาท = เครดิต 1 (ถ้าต้องการอัตราอื่น ๆ ให้แก้ไขตรงนี้)
    $credit_added = $amount;

    // บวกเครดิตให้ลูกค้า (rowCount = 0 แปลว่าไม่พบ username นี้ในตาราง users)
    $updated = user_add_credit($ref1, $amount);

    if ($updated > 0) {
        $status = 'success';        // เติมเครดิตสำเร็จ
        $credit = $credit_added;
    } else {
        $status = 'user_not_found'; // ยังไม่มีลูกค้าคนนี้ -> บันทึกไว้ให้ admin ตรวจสอบ
        $credit = '0.00';
    }

    // บันทึกประวัติการเติมเงินลงตาราง topup_history (method = qr)
    history_insert(array(
        'method'       => 'qr',
        'id_pay'       => $id_pay,
        'ref1'         => $ref1,
        'amount'       => $amount,
        'credit_added' => $credit,
        'status'       => $status,
        'client_ip'    => my_ip(),
    ));

    $pdo->commit();

    // ตอบ ok เสมอ (แม้หาลูกค้าไม่เจอ) เพื่อให้ API ไม่ส่ง webhook ซ้ำ
    json_response(1, 'ok');
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // ถ้า error เพราะ id_pay ซ้ำ (มี webhook อีกตัววิ่งมาพร้อมกัน)
    // แปลว่าเพิ่งประมวลผลเสร็จ -> ให้ถือว่า ok เหมือนกัน กันเครดิตซ้ำ
    if ($e->getCode() === '23000') {
        json_response(1, 'ok');
    }

    // error อื่น ๆ ให้ตอบ error กลับไป เพื่อให้ API ลองส่งใหม่
    json_response(0, 'Database error: ' . $e->getMessage());
}
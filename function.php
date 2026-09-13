<?php
/**
 * =====================================================================
 *  ฟังก์ชันกลางของระบบเติมเงินรวม  (PDO + Prepared Statements เท่านั้น)
 * =====================================================================
 *  ทุกไฟล์ในระบบ require_once ไฟล์นี้เพียงไฟล์เดียว
 *  เพราะข้างในจะ require_once config.php ให้อัตโนมัติ
 * =====================================================================
 */

require_once __DIR__ . '/config.php';

// ตั้ง encoding เริ่มต้นเป็น UTF-8 สำหรับทุกหน้า (กันภาษาไทยเพี้ยน)
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

/**
 * เชื่อมต่อฐานข้อมูล MySQL ด้วย PDO (เรียกใช้ซ้ำได้ จะได้ connection เดียวกัน)
 *
 * @return PDO
 */
function db()
{
    global $db_host, $db_port, $db_user, $db_password, $db_name, $db_charset;

    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset={$db_charset}";
        try {
            $pdo = new PDO($dsn, $db_user, $db_password, array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        } catch (PDOException $e) {
            die('เชื่อมต่อฐานข้อมูลไม่ได้: ' . $e->getMessage());
        }
    }
    return $pdo;
}

/**
 * อ่านค่าจาก config.php ผ่านตัวแปร global อย่างปลอดภัย
 *
 * @param string $key     ชื่อตัวแปรใน config.php เช่น 'mony_list'
 * @param mixed  $default ค่าเริ่มต้น ถ้าไม่พบตัวแปรนั้น
 * @return mixed
 */
function config_get($key, $default = null)
{
    return isset($GLOBALS[$key]) ? $GLOBALS[$key] : $default;
}

/**
 * ส่ง HTTP GET ไปยัง URL (ใช้ curl) แล้วคืนค่าเนื้อหาที่ได้กลับมา
 *
 * @param string $url
 * @return string
 * @throws Exception เมื่อเชื่อมต่อไม่ได้
 */
function connect_api($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ));

    $result = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        throw new Exception('ติดต่อ API ไม่ได้: ' . $error);
    }
    return $result;
}

/**
 * หา IP จริงของลูกค้า (กันกรณีอยู่หลัง proxy)
 *
 * @return string
 */
function my_ip()
{
    foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR') as $key) {
        if (!empty($_SERVER[$key])) {
            $first = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/**
 * เรียกใช้งาน API ช่องทาง PromptPay QR (endpoint: api_pph.php)
 * คืนค่าเป็น array (json -> array)
 *
 * @param string $method ชื่อ method เช่น create_pay , detail_pay , cancel
 * @param array  $params พารามิเตอร์เฉพาะของ method นั้น ๆ
 * @return array|null
 */
function api_qr_call($method, $params = array())
{
    global $api_url_pph, $tmweasy_user, $tmweasy_password, $con_id;

    $params = array_merge(array(
        'username' => $tmweasy_user,
        'password' => $tmweasy_password,
        'con_id'   => $con_id,
    ), $params);

    $params['method'] = $method;

    // สร้างรายการชำระเงิน ต้องส่ง IP ของลูกค้าไปด้วย
    if ($method === 'create_pay') {
        $params['ip'] = my_ip();
    }

    $url    = $api_url_pph . '?' . http_build_query($params);
    $result = connect_api($url);

    return json_decode($result, true);
}

/**
 * เรียกใช้งาน API ช่องทาง TrueMoney Gift (endpoint: apiwallet.php)
 * นำ URL ซองของขวัญไปตรวจสอบยอด คืนค่าเป็น array (json -> array)
 *
 * @param string $gifturl URL ซองของขวัญ (transactionid)
 * @param string $ref1    username ของลูกค้า
 * @return array|null
 */
function api_gift_check($gifturl, $ref1)
{
    global $api_url_wallet, $tmweasy_user, $tmweasy_password, $truewallet;

    $url = $api_url_wallet
        . '?username='      . rawurlencode($tmweasy_user)
        . '&password='      . rawurlencode($tmweasy_password)
        . '&tmemail='       . $truewallet['mobile']
        . '&transactionid=' . rawurlencode($gifturl)
        . '&clientip='      . urlencode(my_ip())
        . '&ref1='          . rawurlencode($ref1)
        . '&action=yes&json=1';

    return json_decode(connect_api($url), true);
}

/**
 * ตรวจสอบ signature ที่ webhook (hook_action.php) ส่งกลับมา
 * วิธีคิด: md5( data . ':' . api_key ) เทียบกับค่า signature
 *
 * @param string $data      ข้อความ data (json string) ที่ API ส่งมา
 * @param string $signature ค่า signature ที่ API ส่งมา
 * @return bool
 */
function check_webhook_signature($data, $signature)
{
    global $tmweasy_api_key;

    $calc = md5($data . ':' . $tmweasy_api_key);

    // hash_equals ช่วยกัน timing attack
    return hash_equals($calc, (string) $signature);
}

/**
 * ตรวจสอบว่า webhook มีการประมวลผล id_pay นี้ไปแล้วหรือยัง (ช่องทาง QR)
 *
 * @param string $id_pay
 * @return bool true ถ้ามี id_pay อยู่ในตารางประวัติแล้ว
 */
function history_id_pay_exists($id_pay)
{
    global $db_history_table;

    $stmt = db()->prepare("SELECT id FROM `{$db_history_table}` WHERE id_pay = :id_pay LIMIT 1");
    $stmt->execute(array(':id_pay' => $id_pay));

    return (bool) $stmt->fetch();
}

/**
 * ตรวจสอบว่าซองของขวัญนี้ถูกใช้เติมเงินสำเร็จไปแล้วหรือยัง (ช่องทาง Gift)
 *
 * @param string $transactionid URL ซองของขวัญ
 * @return bool true ถ้ามีประวัติ check_success ของซองนี้แล้ว
 */
function history_transactionid_success($transactionid)
{
    global $db_history_table;

    $stmt = db()->prepare(
        "SELECT id FROM `{$db_history_table}`
          WHERE transactionid = :tid AND status = 'check_success'
          LIMIT 1"
    );
    $stmt->execute(array(':tid' => $transactionid));

    return (bool) $stmt->fetch();
}

/**
 * บันทึกประวัติการเติมเงิน (ใช้ร่วมกันทั้ง 2 ช่องทาง)
 *
 * @param array $data ฟิลด์ที่ต้องการบันทึก เช่น
 *   array(
 *     'method'        => 'qr' | 'truegift',
 *     'id_pay'        => '...'  (qr),
 *     'transactionid' => '...'  (gift),
 *     'ref1'          => '...',
 *     'amount'        => '...',
 *     'credit_added'  => '...',
 *     'status'        => 'success | user_not_found | check_success | check_failed',
 *     'api_msg'       => '...'  (gift),
 *     'client_ip'     => '...'  (gift),
 *   )
 * @return void
 */
function history_insert($data)
{
    global $db_history_table;

    $defaults = array(
        'method'        => 'qr',
        'id_pay'        => null,
        'transactionid' => null,
        'ref1'          => '',
        'amount'        => '0.00',
        'credit_added'  => '0.00',
        'status'        => 'success',
        'api_msg'       => null,
        'client_ip'     => null,
    );
    $d = array_merge($defaults, $data);

    $stmt = db()->prepare(
        "INSERT INTO `{$db_history_table}`
            (method, id_pay, transactionid, ref1, amount, credit_added, status, api_msg, client_ip)
         VALUES
            (:method, :id_pay, :transactionid, :ref1, :amount, :credit_added, :status, :api_msg, :client_ip)"
    );
    $stmt->execute(array(
        ':method'        => $d['method'],
        ':id_pay'        => $d['id_pay'],
        ':transactionid' => $d['transactionid'],
        ':ref1'          => $d['ref1'],
        ':amount'        => $d['amount'],
        ':credit_added'  => $d['credit_added'],
        ':status'        => $d['status'],
        ':api_msg'       => $d['api_msg'],
        ':client_ip'     => $d['client_ip'],
    ));
}

/**
 * เพิ่มเครดิตให้ลูกค้า (ใช้ prepared statement)
 *
 * @param string $ref1   ค่าอ้างอิงลูกค้า เช่น username
 * @param string $amount ยอดเงิน/เครดิตที่จะบวกเข้า (บาท)
 * @return int จำนวนแถวที่ถูกอัพเดท (0 = ไม่พบลูกค้าคนนี้ในตาราง)
 */
function user_add_credit($ref1, $amount)
{
    global $db_users_table, $db_users_ref_field, $db_users_credit_field;

    $stmt = db()->prepare(
        "UPDATE `{$db_users_table}`
            SET `{$db_users_credit_field}` = `{$db_users_credit_field}` + :amount
          WHERE `{$db_users_ref_field}` = :ref1"
    );
    $stmt->execute(array(
        ':amount' => $amount,
        ':ref1'   => $ref1,
    ));

    return $stmt->rowCount();
}

/**
 * อ่านยอดเครดิตปัจจุบันของลูกค้า
 *
 * @param string $ref1 ค่าอ้างอิงลูกค้า เช่น username
 * @return string|null คืนค่าเป็น string ถ้าเจอลูกค้า, null ถ้าไม่เจอ
 */
function user_get_credit($ref1)
{
    global $db_users_table, $db_users_ref_field, $db_users_credit_field;

    $stmt = db()->prepare(
        "SELECT `{$db_users_credit_field}` AS credit
           FROM `{$db_users_table}`
          WHERE `{$db_users_ref_field}` = :ref1
          LIMIT 1"
    );
    $stmt->execute(array(':ref1' => $ref1));

    $row = $stmt->fetch();
    return $row ? $row['credit'] : null;
}
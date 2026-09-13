-- =====================================================================
--  ฐานข้อมูลระบบเติมเงินรวม : PromptPay QR + TrueMoney Gift
--  ใช้ฐานข้อมูลเดียวกัน เพียง 1 ชุด (topup_system)
--  วิธีใช้งาน:
--    1. นำไฟล์นี้ไป run ใน phpMyAdmin / MySQL หรือคำสั่ง:
--         mysql -u root -p < database.sql
--    2. ถ้าต้องการเปลี่ยนชื่อตาราง/ฟิว ให้แก้ที่ไฟล์ config.php ด้วย
-- =====================================================================

CREATE DATABASE IF NOT EXISTS topup_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE topup_system;

-- ---------------------------------------------------------------
-- ตารางลูกค้า/สมาชิก เก็บเครดิต (ใช้ร่วมกันทั้ง 2 ช่องทาง)
--  - ฟิว username ใช้เป็นค่าอ้างอิง (ref1) ที่ลูกค้ากรอกตอนเติมเงิน
--  - ฟิว credit = เครดิต/ยอดเงินปัจจุบัน (ผ่าน config.php: $db_users_credit_field)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    username   VARCHAR(50)   NOT NULL,
    credit     DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'เครดิต/ยอดเงินปัจจุบัน',
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- ตารางประวัติการเติมเงิน (รองรับทั้ง 2 ช่องทางในตารางเดียว)
--  - method:
--      qr       = ชำระผ่าน PromptPay QR (บันทึกจาก hook_action.php)
--      truegift = เติมผ่าน TrueMoney Gift (บันทึกจาก gift.php)
--  - status:
--      qr       = success / user_not_found
--      truegift = check_success / check_failed
--  - id_pay เป็น UNIQUE กัน webhook QR ส่งซ้ำแล้วเติมเครดิตซ้ำ
--  - transactionid เป็น UNIQUE กันใช้ซองของขวัญซ้ำ
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS topup_history (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    method        VARCHAR(20)   NOT NULL DEFAULT 'qr' COMMENT 'qr / truegift',
    id_pay        VARCHAR(100)  NULL     COMMENT 'QR: id_pay จาก api_pph.php',
    transactionid VARCHAR(191)  NULL     COMMENT 'Gift: URL ซองของขวัญ',
    ref1          VARCHAR(100)  NOT NULL COMMENT 'username/ref ของลูกค้าที่เติมเงิน',
    amount        DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'ยอดเงินที่ลูกค้าโอน (บาท)',
    credit_added  DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'เครดิตที่เพิ่มให้ลูกค้า (บาท)',
    status        VARCHAR(30)   NOT NULL DEFAULT 'success' COMMENT 'success / user_not_found / check_success / check_failed',
    api_msg       TEXT          NULL     COMMENT 'ข้อความตอบกลับจาก API (ช่องทาง Gift)',
    client_ip     VARCHAR(45)   NULL     COMMENT 'IP ของลูกค้า (ช่องทาง Gift)',
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_id_pay (id_pay),
    UNIQUE KEY uk_transactionid (transactionid),
    KEY idx_ref1 (ref1),
    KEY idx_status (status),
    KEY idx_method (method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
--  ตัวอย่างข้อมูลลูกค้า (ทดสอบเติมเงินโดยใช้ ref1 = demo / testuser)
-- ---------------------------------------------------------------
INSERT INTO users (username, credit) VALUES
  ('demo',     0.00),
  ('testuser', 100.00)
ON DUPLICATE KEY UPDATE username = username;

-- ---------------------------------------------------------------
--  ตัวอย่างคำสั่งที่ใช้อ่านข้อมูล
-- ---------------------------------------------------------------
--  SELECT * FROM topup_history ORDER BY id DESC;                  -- ประวัติทั้งหมด
--  SELECT * FROM topup_history WHERE method = 'qr';               -- เฉพาะช่องทาง QR
--  SELECT * FROM topup_history WHERE method = 'truegift';         -- เฉพาะช่องทาง Gift
--  SELECT * FROM users;                                           -- เช็คเครดิตลูกค้า
--  SELECT * FROM topup_history WHERE status = 'user_not_found';   -- รายการที่หาลูกค้าไม่เจอ
-- ---------------------------------------------------------------
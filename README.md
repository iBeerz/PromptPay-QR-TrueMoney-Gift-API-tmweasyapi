# ระบบเติมเงินรวม 2 ช่องทาง (PromptPay QR + TrueMoney Gift)

ระบบเติมเงิน PHP (PDO/MySQL) ที่รวม **2 วิธีชำระเงิน** ไว้ในระบบเดียว ใช้ **config และฐานข้อมูลร่วมกัน** ผ่าน API ของ [https://www.tmweasyapi.com/](https://www.tmweasyapi.com/)

- **PromptPay QR Code** — ลูกค้าสแกน QR เพื่อโอนเงิน ระบบเติมเครดิตอัตโนมัติผ่าน Webhook
- **TrueMoney Gift (ซองของขวัญ / อั่งเปา)** — ลูกค้ากรอก URL ซองของขวัญ ระบบตรวจสอบกับ API แล้วบวกเครดิตอัตโนมัติ

---

## โครงสร้างไฟล์

| ไฟล์ | หน้าที่ |
|------|--------|
| `config.php` | ค่ากำหนดทั้งระบบ (API tmweasy, ข้อมูลพร้อมเพย์, ข้อมูลวอเลท, ฐานข้อมูล) — แก้ที่เดียว |
| `function.php` | ฟังก์ชันกลาง: เชื่อมต่อ PDO, เรียก API ทั้ง 2 endpoint, ตรวจ signature, อัปเดตเครดิต, บันทึกประวัติ |
| `index.php` | หน้าแรก เลือกช่องทางเติมเงิน |
| `qr.php` | ช่องทาง PromptPay QR: ฟอร์มยอดเงิน → สร้าง QR → หน้าแสดง QR + นับเวลาถอยหลัง + เช็คสถานะทุก 3 วิ |
| `gift.php` | ช่องทาง TrueMoney Gift: ฟอร์มกรอก ref1 + URL ซองของขวัญ → ตรวจสอบกับ API → บวกเครดิต |
| `paid.php` | ตรวจสอบว่าชำระ QR สำเร็จหรือยัง (อ่านจากตาราง `topup_history`) |
| `hook_action.php` | **Webhook** ที่ tmweasy ส่งกลับมาเมื่อชำระ QR สำเร็จ → เติมเครดิต + บันทึกประวัติ |
| `database.sql` | สร้างฐานข้อมูล `topup_system` พร้อมตาราง `users`, `topup_history` + ข้อมูลทดสอบ |
| `css/custom.css` | สไตล์เสริมเล็กน้อย (หน้าตาหลักเป็น Bootstrap 5 ค่าเริ่มต้น) |
| `js/custom.js` | สคริปต์หน้า QR (ปุ่มลัดยอดเงิน, นับเวลาถอยหลัง, poll สถานะ, auto-close alert) |

## ออกแบบ UI

ใช้ **Bootstrap 5 (CDN) + FontAwesome + Google Fonts (Sarabun)** ให้หน้าต่าเป็น Bootstrap ค่าเริ่มต้น ไม่เขียน style/script แบบ inline (แยกไว้ที่ `css/custom.css` และ `js/custom.js`):

```html
<meta charset="UTF-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="css/custom.css" rel="stylesheet">
...
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/custom.js"></script>
```

> หมายเหตุ: `font-family: "Sarabun"` กำหนดไว้ใน `css/custom.css` โดยโหลดฟอนต์จาก Google Fonts ในทุกหน้าเว็บ (`index.php`, `qr.php`, `gift.php`)

## UTF-8 สำหรับภาษาไทย

- บันทึกไฟล์ทุกไฟล์เป็น **UTF-8 ไร้ BOM** (ตรวจสอบแล้วทั้งระบบ)
- ทุกหน้าส่ง `header('Content-Type: text/html; charset=utf-8')` และมี `<meta charset="UTF-8">`
- ฐานข้อมูล + ตาราง + คอลัมน์ ใช้ **utf8mb4** (ดู `database.sql`) และเชื่อมต่อ PDO ด้วย `charset=utf8mb4` (ดู `function.php`)
- API รับ-ส่ง JSON/Text ใช้ `charset=utf-8` (ดู `hook_action.php`, `paid.php`)

---

## URL API (แยก endpoint ตามวิธีชำระเงิน)

ใช้ base URL เดียวกันแต่เรียกไฟล์ endpoint ต่างกันในเวลาที่ต่างกัน:

| ช่องทาง | ไฟล์เรียกใช้ | Endpoint |
|---------|-------------|----------|
| PromptPay QR | `qr.php` / `hook_action.php` | `https://tmwallet.thaighost.net/api_pph.php` |
| TrueMoney Gift | `gift.php` | `https://tmwallet.thaighost.net/apiwallet.php` |

ตั้งค่าได้ที่ `config.php`:
```php
$api_base_url = "https://tmwallet.thaighost.net";
$api_url_pph    = $api_base_url . "/api_pph.php";    // PromptPay QR
$api_url_wallet = $api_base_url . "/apiwallet.php";  // TrueMoney Gift
```

---

## วิธีติดตั้ง

### 1. สร้างฐานข้อมูล

นำเข้า `database.sql` ผ่าน phpMyAdmin หรือรันคำสั่ง:

```bash
mysql -u root -p < database.sql
```

จะสร้างฐานข้อมูล `topup_system` พร้อมตาราง:

| ตาราง | รายละเอียด |
|-------|-----------|
| `users` | ผู้ใช้งาน + ยอดเครดิต (มีข้อมูลทดสอบ `demo`, `testuser`) |
| `topup_history` | ประวัติการเติมเงินทั้ง 2 ช่องทาง (แยกด้วยคอลัมน์ `method` = `qr` / `truegift`) |

### 2. ตั้งค่า `config.php`

```php
// ---------- API tmweasy (ใช้ร่วมกันทั้ง 2 ระบบ) ----------
$tmweasy_user     = "your_tmweasy_user";
$tmweasy_password = "your_tmweasy_password";

// เฉพาะช่องทาง PromptPay QR (เปิดใช้บริการ "Qr Promptpay Api")
$tmweasy_api_key  = "your_api_key";
$con_id           = "your_con_id";

// ---------- ข้อมูลพร้อมเพย์ (ช่องทาง QR) ----------
$prommpay_no   = "0812345678";    //เลขพร้อมเพย์ เช่น เบอร์มือถือ หรือเลขบัตรประชาชน
$prommpay_type = "01";           // 01 = เบอร์โทร, 02 = เลขบัตรประชาชน, 03 = E-Wallet
$prommpay_name = "ชื่อเจ้าของบัญชี";

// ---------- ข้อมูล TrueMoney Wallet (ช่องทาง Gift) ----------
$truewallet["mobile"] = "0812345678";  // เบอร์ที่รับซองของขวัญ (10 หลัก)
$truewallet["name"]   = "ชื่อบัญชีวอเลท";

// ---------- ฐานข้อมูล (ใช้ร่วมกันชุดเดียว) ----------
$db_host     = "localhost";
$db_user     = "root";
$db_password = "";
$db_name     = "topup_system";
```

หมายเหตุ: ค่า login tmweasyapi กำหนดที่ `$tmweasy_user` / `$tmweasy_password` แล้วระบบจะแจกให้แบบ array `$tmweasy["user"]` / `$tmweasy["password"]` อัตโนมัติ สำหรับโค้ด/ระบบเดิมที่ใช้รูปแบบ array

### 3. ตั้ง Callback URL (เฉพาะช่องทาง QR)

ที่หน้าเว็บ tmweasyapi ให้ตั้ง "Callback URL" ของ Qr Promptpay Api ชี้มาที่:

```
https://ชื่อเว็บคุณ/topup/hook_action.php
```

### 4. เปิดใช้งาน

วางโฟลเดอร์ `topup` ใน web server (Apache/Nginx + PHP 7.0 ขึ้นไป พร้อม extension `pdo_mysql` และ `curl`)

```text
http://localhost/topup/
```

ทดสอบช่องทาง QR: กรอก ref1 = `demo`
ทดสอบช่องทาง Gift: กรอก ref1 = `testuser`

---

## วิธีใช้งาน (สำหรับลูกค้า)

### ช่องทาง PromptPay QR Code

1. เลือกยอดเงินจากปุ่มลัด หรือพิมพ์ยอดเงินเอง
2. กรอก **Ref1 (username)** แล้วกด "กดเพื่อชำระเงิน"
3. สแกน QR ด้วยแอปธนาคาร
4. ระบบตรวจพบยอดโอน (ผ่าน Webhook) → เติมเครดิตอัตโนมัติ → หน้าแจ้งสำเร็จ

### ช่องทาง TrueMoney Gift (ซองของขวัญ)

1. ลูกค้าโอนซองของขวัญมาที่เบอร์วอเลทตามที่แสดง
2. กรอก **Ref1 (username)** และ **URL ซองของขวัญ** แล้วกด "ตรวจสอบเติมเงิน"
3. ระบบตรวจสอบกับ API → ถ้าผ่าน (`check_success`) → บวกเครดิต 1:1 กับจำนวนเงินบาท

---

## ลำดับการทำงานของระบบ

### ช่องทาง QR (Webhook)

```
ผู้ใช้กรอกยอดเงิน + ref1 (username)
        │
        ▼
qr.php ── create_pay ──► API api_pph.php (ได้ id_pay)
        │                                │
        ▼                                ▼  ลูกค้าสแกน QR แล้วโอนเงิน
หน้าแสดง QR ◄────── detail_pay   API ตรวจพบยอดโอน
        │                                │
        │ poll ทุก 3 วินาที               ▼
        ▼                       hook_action.php (Webhook)
paid.php ── เช็คตาราง           1. ตรวจเวลาของ data
topup_history  ◄─────────────   2. ตรวจ signature (md5)
        │                       3. เช็ค id_pay ซ้ำ (กันเครดิตซ้ำ)
        ▼                       4. transaction: เติมเครดิต users
"ชำระเงินสำเร็จ"                  + INSERT ประวัติ topup_history
                                5. ตอบ {"status":1,"msg":"ok"}
```

### ช่องทาง Gift (ตรวจสอบแบบ Real-time)

```
ผู้ใช้กรอก ref1 + URL ซองของขวัญ
        │
        ▼
gift.php ── เรียก API apiwallet.php (username, password, tmemail, transactionid, clientip, ref1)
        │
        ▼
Status = check_success
        │                     Status = check_failed
        ▼                          │
transaction (กันซ้ำ):              บันทึก log check_failed + แจ้ง error
 1. ตรวจผู้ใช้มีอยู่
 2. เช็คซองยังไม่เคยใช้
 3. บวกเครดิต users
 4. INSERT ประวัติ check_success
```

---

## ตารางฐานข้อมูล

### users — ผู้ใช้งาน + เครดิต (ใช้ร่วมกันทั้ง 2 ช่องทาง)

| ฟิว | ความหมาย |
|-----|----------|
| `username` | ค่าอ้างอิงลูกค้า (ref1) — ตั้งชื่อฟิวได้ที่ `$db_users_ref_field` |
| `credit` | เครดิต/ยอดเงินปัจจุบัน — ตั้งชื่อฟิวได้ที่ `$db_users_credit_field` |

### topup_history — ประวัติการเติมเงินทั้ง 2 ช่องทาง

| ฟิว | ความหมาย |
|-----|----------|
| `method` | ช่องทาง: `qr` หรือ `truegift` |
| `id_pay` | (QR) เลข id_pay จาก API — UNIQUE กัน webhook ซ้ำ |
| `transactionid` | (Gift) URL ซองของขวัญ — UNIQUE กันใช้ซองซ้ำ |
| `ref1` | username ของลูกค้า |
| `amount` | ยอดเงินที่ได้รับ (บาท) |
| `credit_added` | เครดิตที่เพิ่มให้ลูกค้า (1:1 กับบาท) |
| `status` | QR: `success` / `user_not_found` — Gift: `check_success` / `check_failed` |
| `api_msg` | ข้อความตอบกลับจาก API (ช่องทาง Gift) |
| `client_ip` | IP ของลูกค้า |
| `created_at` | เวลาที่บันทึก |

คำสั่งตรวจสอบข้อมูล:
```sql
SELECT * FROM topup_history ORDER BY id DESC;                 -- ประวัติทั้งหมด
SELECT * FROM topup_history WHERE method = 'qr';              -- เฉพาะช่องทาง QR
SELECT * FROM topup_history WHERE method = 'truegift';        -- เฉพาะช่องทาง Gift
SELECT * FROM users;                                          -- เช็คเครดิตลูกค้า
SELECT * FROM topup_history WHERE status = 'user_not_found';  -- รายการที่หาลูกค้าไม่เจอ
```

---

## ระบบป้องกัน

- **กันเติมซ้ำ (QR)** : `id_pay` เป็น UNIQUE ใน `topup_history` + ตรวจสอบก่อนบวกเครดิต — webhook ส่งซ้ำไม่เติมซ้ำ
- **กันใช้ซองซ้ำ (Gift)** : `transactionid` ยูนิค + ตรวจสอบก่อนบวกเครดิต — ซองของขวัญเดียวใช้ได้ครั้งเดียว
- **Prepared Statements** : ทุกคำสั่ง SQL ผ่าน PDO binding ป้องกัน SQL injection
- **Transaction** : การเติมเครดิต (ทั้ง 2 ช่องทาง) อยู่ใน transaction เดียวกัน → rollback อัตโนมัติถ้า error
- **ตรวจสอบ Webhook** : ตรวจ timestamp กัน request ย้อนหลัง + ตรวจ signature (md5) กันปลอมแปลง
- **ตรวจผู้ใช้ก่อนบวก** : ถ้า ref1 ไม่มีในตาราง `users` จะไม่บวกเครดิต (บันทึก status ไว้ให้ admin ตรวจสอบ)

---

## การทดสอบ

### ทดสอบช่องทาง QR (จำลองยอดเติมสำเร็จ)

เปิด `C:\xampp\htdocs\topup\hook_action.php` แล้วคอมเมนต์/แก้ส่วนตรวจสอบเพื่อ POST ข้อมูลจำลอง หรือแทรกข้อมูลลงตาราง `topup_history` ตรง ๆ:
```sql
INSERT INTO topup_history (method, id_pay, transactionid, ref1, amount, credit_added, status) VALUES
('qr', 'TEST0001', NULL, 'demo', 100.00, 100.00, 'success');
```

### ทดสอบช่องทาง Gift (ไม่ต้องเรียก API จริง)

เปิด `gift.php` เอาคอมเมนต์ออกจากบล็อกนี้ (ช่วงเรียก API):
```php
// สำหรับทดสอบ จำลองว่าเติมสำเร็จ (comment บรรทัดนี้ออกเพื่อทดสอบ)
$api_respone['Status'] = "check_success";
$api_respone['Amount'] = 50;
$api_respone['Type']   = "truegift";
```

จากนั้นเปิด `http://localhost/topup/gift.php` กรอก `ref1 = testuser` + URL อะไรก็ได้ที่มีคำว่า `ttp` → ระบบจะบวกเครดิต 50 ให้ `testuser`

---

## หมายเหตุ

- ต้องมีบัญชีและเครดิตใช้งานที่ [https://www.tmweasyapi.com/](https://www.tmweasyapi.com/) ก่อนจึงจะเรียก API ได้
- เบอร์วอเลท (`$truewallet["mobile"]`) ต้องเป็นเบอร์ที่ผูกกับซองของขวัญที่จะรับยอด
- ระบบนี้เป็นเพียงส่วน "เติมเงิน" — ส่วน login/หน้าแสดงเครดิตของเว็บหลักสามารถดึงจากตาราง `users` ต่อได้เอง
- `hook_action.php` ควรเข้าถึงได้ผ่าน HTTPS และอาจจำกัดเฉพาะ IP ของ API
- ในตัวอย่างเปิด `CURLOPT_SSL_VERIFYPEER = false` ไว้ (function.php) ถ้า server มี SSL จริงควรเปิดเป็น `true`
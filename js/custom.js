/* =====================================================================
   ระบบเติมเงินรวม : PromptPay QR + TrueMoney Gift
   (Bootstrap 5 bundle + FontAwesome โหลดจาก CDN ที่หน้าเว็บ)
   ===================================================================== */

/* ---------------------------------------------------------------------
 * 1) ฟอร์มเติมเงิน QR: กดปุ่มลัดเลือกยอดเงิน + ตรวจสอบก่อน submit
 *    (ทำงานเฉพาะเมื่อมีฟอร์ม id="topup_form" อยู่บนหน้า)
 * --------------------------------------------------------------------- */
(function () {
    'use strict';

    var form = document.getElementById('topup_form');
    if (!form) {
        return; // ไม่ใช่หน้าฟอร์ม -> ข้ามไป
    }

    var amountInput = document.getElementById('amount');
    var btnSubmit   = document.getElementById('btn_submit');
    var moneyBtns   = document.querySelectorAll('.btn-money');

    function setAmount(btn) {
        for (var i = 0; i < moneyBtns.length; i++) {
            moneyBtns[i].classList.remove('btn-primary');
            moneyBtns[i].classList.add('btn-outline-primary');
        }
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-primary');
        amountInput.value = btn.getAttribute('data-val');
    }

    for (var i = 0; i < moneyBtns.length; i++) {
        (function (btn) {
            btn.addEventListener('click', function () {
                setAmount(btn);
            });
        })(moneyBtns[i]);
    }

    form.addEventListener('submit', function () {
        if (amountInput.value.trim() === '') {
            alert('กรุณาเลือกยอดเงินจากปุ่ม หรือพิมพ์ยอดเงินเองก่อน');
            amountInput.focus();
            return false; // ไม่ให้ submit
        }
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังสร้าง QR Code...';
        return true;
    });
})();

/* ---------------------------------------------------------------------
 * 2) หน้าแสดง QR: นับเวลาถอยหลัง + เช็คสถานะชำระเงินทุก 3 วินาที
 *    (ทำงานเฉพาะเมื่อมี element id="pay_box" อยู่บนหน้า)
 *    ข้อมูล id_pay / เวลาที่เหลือ อ่านจาก data-* ที่ PHP echo ไว้ในหน้า
 * --------------------------------------------------------------------- */
(function () {
    'use strict';

    var box = document.getElementById('pay_box');
    if (!box) {
        return; // ไม่ใช่หน้า QR -> ข้ามไป
    }

    var idPay      = box.getAttribute('data-id-pay') || '';
    var timeLeft   = parseInt(box.getAttribute('data-time-out') || '0', 10);
    var qrArea     = document.getElementById('qr_area');
    var expiredBox = document.getElementById('expired_box');
    var timeLabel  = document.getElementById('time_left');

    var pollTimer  = null;
    var countTimer = null;

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function updateTimeLabel() {
        if (timeLabel) {
            timeLabel.textContent = pad(Math.floor(timeLeft / 60)) + ':' + pad(timeLeft % 60);
        }
    }

    function stopTimers() {
        if (countTimer) {
            clearInterval(countTimer);
            countTimer = null;
        }
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function showExpired() {
        stopTimers();
        qrArea.classList.add('d-none');
        expiredBox.classList.remove('d-none');
    }

    function checkPaid() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'paid.php?id_pay=' + encodeURIComponent(idPay) + '&t=' + new Date().getTime(), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4 && xhr.responseText.trim() === 'ok') {
                stopTimers();
                window.location = 'qr.php?action=success';
            }
        };
        xhr.send();
    }

    updateTimeLabel();

    // นับเวลาถอยหลัง เมื่อหมดเวลา -> ตรวจสอบครั้งสุดท้ายก่อนแสดง "หมดเวลา"
    countTimer = setInterval(function () {
        timeLeft--;
        if (timeLeft <= 0) {
            clearInterval(countTimer);
            countTimer = null;
            checkPaid();      // เช็คเผื่อกรณีโอนเงินพอดีตอนหมดเวลา
            setTimeout(showExpired, 1500);
            return;
        }
        updateTimeLabel();
    }, 1000);

    // Poll หาสถานะชำระเงินทุก 3 วินาที
    pollTimer = setInterval(checkPaid, 3000);
})();

/* ---------------------------------------------------------------------
 * 3) ให้ alert ปิดตัวเองหลังจากแสดงสักครู่ (ใช้ปุ่ม x ปิดเองก็ได้)
 * --------------------------------------------------------------------- */
(function () {
    'use strict';

    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        setTimeout(function () {
            var alert = bootstrap.Alert.getOrCreateInstance(el);
            alert.close();
        }, 8000);
    });
})();

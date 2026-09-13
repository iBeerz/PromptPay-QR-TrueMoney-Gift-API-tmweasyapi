<?php
/**
 * =====================================================================
 *  หน้าแรกของระบบเติมเงินรวม : เลือกช่องทางการเติมเงิน
 *  ออกแบบด้วย Bootstrap 5 (CDN) + FontAwesome
 * =====================================================================
 *  - PromptPay QR Code        -> qr.php   (เรียก API: api_pph.php)
 *  - TrueMoney Gift ภายของขวัญ -> gift.php  (เรียก API: apiwallet.php)
 * =====================================================================
 */

session_start();
require_once __DIR__ . '/function.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ระบบเติมเงิน</title>
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
            <a class="nav-link active" href="index.php"><i class="fas fa-home"></i> หน้าแรก</a>
            <a class="nav-link" href="qr.php"><i class="fas fa-qrcode"></i> PromptPay QR</a>
            <a class="nav-link" href="gift.php"><i class="fas fa-gift"></i> TrueMoney Gift</a>
        </div>
    </div>
</nav>

<main class="container py-4">

    <div class="p-4 mb-4 bg-body-tertiary rounded-3">
        <h1 class="display-6"><i class="fas fa-wallet"></i> ระบบเติมเงิน</h1>
        <p class="lead mb-0 text-muted">เลือกช่องทางการเติมเงินที่ต้องการ</p>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card text-center h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <i class="fas fa-qrcode fa-3x text-primary mb-3"></i>
                    <h5 class="card-title">PromptPay QR Code</h5>
                    <p class="card-text text-muted">สแกน QR เพื่อโอนเงิน ระบบเติมเครดิตอัตโนมัติผ่าน Webhook</p>
                    <a href="qr.php" class="btn btn-primary mt-auto"><i class="fas fa-money-check-alt"></i> เติมเงิน</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card text-center h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <i class="fas fa-gift fa-3x text-danger mb-3"></i>
                    <h5 class="card-title">TrueMoney Gift (ซองของขวัญ)</h5>
                    <p class="card-text text-muted">เติมด้วยซองของขวัญ / อั่งเปา TrueMoney Wallet</p>
                    <a href="gift.php" class="btn btn-danger mt-auto"><i class="fas fa-paper-plane"></i> เติมเงิน</a>
                </div>
            </div>
        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/custom.js"></script>
</body>
</html>
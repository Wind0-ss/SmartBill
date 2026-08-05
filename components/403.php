<?php
/**
 * 403 Forbidden Page
 */
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — ไม่มีสิทธิ์เข้าถึง</title>
    <link rel="stylesheet" href="<?= APP_URL ?? '' ?>/assets/css/app.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap">
</head>
<body class="bg-surface min-h-screen flex items-center justify-center text-center p-8">
    <div>
        <span class="material-symbols-outlined text-[64px] text-error mb-4 block">block</span>
        <h1 class="text-4xl font-bold text-on-surface mb-2">403</h1>
        <p class="text-lg text-on-surface-variant mb-6">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</p>
        <a href="javascript:history.back()" class="btn btn-secondary">← กลับ</a>
    </div>
</body>
</html>

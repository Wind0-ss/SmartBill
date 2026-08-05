<?php
/**
 * Gateway Portal — Tenant Bill Lookup
 * Public page where tenants enter room number to view their bill
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Bill — ระบบตรวจสอบค่าน้ำและค่าไฟ</title>
    <meta name="description" content="ระบบตรวจสอบค่าน้ำและค่าไฟของห้องพัก สำหรับผู้เช่า">
    <?php include __DIR__ . '/components/head.php'; ?>
    <style>
        .gateway-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(180deg, #f0f3ff 0%, #f9f9ff 100%);
        }
        .gateway-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(12px);
            border: 1px solid #e2e8f0;
            box-shadow: 0px 8px 32px rgba(30, 41, 59, 0.06);
        }
        .gateway-input:focus {
            outline: none;
            border-color: #0058be;
            box-shadow: 0 0 0 3px rgba(0, 88, 190, 0.12);
        }
        .search-btn {
            background: linear-gradient(135deg, #bfdbfe 0%, #93c5fd 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .search-btn:hover {
            background: linear-gradient(135deg, #93c5fd 0%, #60a5fa 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
        }
        .hero-image {
            background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%);
        }
    </style>
</head>
<body>
<main class="gateway-container">
    <div class="gateway-card rounded-2xl w-full max-w-[440px] px-6 py-10 flex flex-col items-center">
        
        <!-- Logo -->
        <div class="flex flex-col items-center mb-6">
            <div class="w-16 h-16 bg-[#f0f3ff] rounded-full flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[#0058be] text-[32px]">receipt_long</span>
            </div>
            <h1 class="text-2xl font-extrabold text-[#0058be] tracking-tight">Smart Bill</h1>
            <p class="text-sm text-[#424754] mt-1">ระบบตรวจสอบค่าน้ำและค่าไฟของห้องพัก</p>
        </div>

        <!-- Hero Image -->
        <div class="w-full mb-6 overflow-hidden rounded-xl aspect-[16/9] hero-image flex items-center justify-center">
            <div class="text-center">
                <span class="material-symbols-outlined text-[#0058be] text-[64px] opacity-30">apartment</span>
                <p class="text-sm text-[#424754] opacity-50 mt-2">Property Management</p>
            </div>
        </div>

        <!-- Search Form -->
        <form id="searchForm" class="w-full space-y-4">
            <div>
                <label class="block text-sm font-medium text-[#424754] mb-1.5 ml-1" for="room_number">หมายเลขห้องพัก</label>
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-[#c2c6d6]">meeting_room</span>
                    <input type="text" id="room_number" name="room_number"
                           placeholder="เช่น 101, A205"
                           class="gateway-input w-full pl-12 pr-4 py-3.5 bg-[#f9f9ff] border border-[#c2c6d6] rounded-xl text-base text-[#111c2d] placeholder:text-[#c2c6d6] transition-all"
                           required autofocus>
                </div>
            </div>

            <button type="submit" id="searchBtn"
                    class="search-btn w-full text-[#0058be] font-bold py-3.5 px-6 rounded-xl flex items-center justify-center gap-2 active:scale-[0.98]">
                <span>ค้นหาบิลของฉัน</span>
                <span class="material-symbols-outlined">search</span>
            </button>
        </form>

        <!-- Error Message -->
        <div id="errorMsg" class="hidden w-full mt-4 p-3 rounded-xl bg-[#ffdad6] text-[#93000a] text-sm font-medium flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span>
            <span id="errorText"></span>
        </div>

        <!-- Divider -->
        <div class="w-full flex items-center my-6">
            <div class="flex-grow border-t border-[#c2c6d6] opacity-50"></div>
            <span class="px-4 text-sm text-[#727785] font-medium">หรือ</span>
            <div class="flex-grow border-t border-[#c2c6d6] opacity-50"></div>
        </div>

        <!-- Admin Access -->
        <a href="<?= APP_URL ?>/login.php" 
           class="w-full flex items-center justify-center gap-2 border border-[#c2c6d6] text-[#424754] hover:bg-[#f0f3ff] font-medium py-3.5 px-6 rounded-xl transition-all">
            <span class="material-symbols-outlined">admin_panel_settings</span>
            <span>หน้าจัดการระบบแอดมิน</span>
        </a>

        <!-- Footer -->
        <p class="mt-8 text-[11px] text-[#727785] text-center">
            © <?= date('Y') ?> Smart Bill. Minimalist Property Management.
        </p>
    </div>
</main>

<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
document.getElementById('searchForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = document.getElementById('room_number');
    const btn = document.getElementById('searchBtn');
    const errorDiv = document.getElementById('errorMsg');
    const errorText = document.getElementById('errorText');
    const roomNumber = input.value.trim();

    errorDiv.classList.add('hidden');

    if (!roomNumber) {
        input.classList.add('border-[#ba1a1a]');
        setTimeout(() => input.classList.remove('border-[#ba1a1a]'), 1500);
        return;
    }

    // Show loading
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span> <span>กำลังค้นหา...</span>';
    btn.disabled = true;

    try {
        const response = await fetch(`<?= APP_URL ?>/api/bills.php?action=lookup&room=${encodeURIComponent(roomNumber)}`);
        const data = await response.json();

        if (data.success && data.redirect) {
            window.location.href = data.redirect;
        } else {
            errorText.textContent = data.message || 'ไม่พบข้อมูลห้อง ' + roomNumber + ' ในระบบ';
            errorDiv.classList.remove('hidden');
        }
    } catch (err) {
        errorText.textContent = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';
        errorDiv.classList.remove('hidden');
    } finally {
        btn.innerHTML = '<span>ค้นหาบิลของฉัน</span><span class="material-symbols-outlined">search</span>';
        btn.disabled = false;
    }
});
</script>
</body>
</html>

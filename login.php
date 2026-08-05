<?php
/**
 * Login Page — Admin / Staff Authentication
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Already logged in? Redirect to dashboard
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/admin/');
    exit;
}

$flash = getFlash();
$error = '';
$success = '';

if ($flash) {
    if ($flash['type'] === 'error') {
        $error = $flash['message'];
    } else {
        $success = $flash['message'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfInput = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfInput)) {
        $error = 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
    
        if (empty($username) || empty($password)) {
            $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
        } else {
            $result = login($username, $password);
            if ($result['success']) {
                header('Location: ' . APP_URL . '/admin/');
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — Smart Bill</title>
    <?php include __DIR__ . '/components/head.php'; ?>
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(135deg, #f0f3ff 0%, #f9f9ff 50%, #e7eeff 100%);
        }
        .login-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid #e2e8f0;
            box-shadow: 0px 8px 32px rgba(30, 41, 59, 0.08);
        }
        .login-input:focus {
            border-color: #0058be;
            box-shadow: 0 0 0 3px rgba(0, 88, 190, 0.12);
        }
    </style>
</head>
<body>
<main class="login-container">
    <div class="login-card rounded-2xl w-full max-w-[420px] p-8 md:p-10">
        <!-- Logo -->
        <div class="flex flex-col items-center mb-8">
            <div class="w-16 h-16 bg-[#d8e2ff] rounded-full flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[#0058be] text-[32px]">admin_panel_settings</span>
            </div>
            <h1 class="text-2xl font-extrabold text-[#0058be] tracking-tight">Smart Bill</h1>
            <p class="text-sm text-[#424754] mt-1">เข้าสู่ระบบสำหรับผู้ดูแล</p>
        </div>

        <!-- Error Message -->
        <?php if ($error): ?>
        <div class="mb-6 p-3 rounded-xl bg-[#ffdad6] text-[#93000a] text-sm font-medium flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span>
            <?= sanitize($error) ?>
        </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if ($success): ?>
        <div class="mb-6 p-3 rounded-xl bg-green-50 text-green-700 border border-green-200 text-sm font-medium flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">check_circle</span>
            <?= sanitize($success) ?>
        </div>
        <?php endif; ?>

        <!-- LINE Login Button (Primary) -->
        <div class="mb-6">
            <a href="<?= APP_URL ?>/api/line-login-redirect.php" 
               class="w-full bg-[#06C755] hover:bg-[#05b34c] text-white font-bold py-3.5 rounded-xl transition-all shadow-md shadow-green-500/20 active:scale-[0.98] flex items-center justify-center gap-3 no-underline">
                <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24">
                    <path d="M24 10.304c0-5.369-5.383-9.738-12-9.738-6.616 0-12 4.369-12 9.738 0 4.814 4.269 8.846 10.036 9.564.39.084.922.258 1.058.592.12.296.079.759.038 1.057l-.171 1.026c-.052.312-.252 1.22.108 1.666.216.268.57.292.797.234.227-.058 2.433-1.603 3.427-2.529.742-.693 1.037-1.077 1.451-1.644 2.871-1.745 4.656-4.524 4.656-7.765zm-14.773 3.66c0 .356-.289.645-.644.645h-2.129v-5.614c0-.356.289-.645.644-.645.356 0 .644.289.644.645v4.324h1.485c.355 0 .644.289.644.645zm2.576 0c0 .356-.288.645-.644.645-.355 0-.644-.289-.644-.645v-5.614c0-.356.289-.645.644-.645s.644.289.644.645v5.614zm4.184-1.921c0 .324-.131.62-.344.837l-1.398 1.397v-1.61c0-.356-.289-.645-.644-.645-.356 0-.644.289-.644.645v3.298c0 .356.288.645.644.645h2.129c.355 0 .644-.289.644-.645v-2.079l.957-.957c.214-.217.345-.512.345-.837s-.131-.62-.345-.837c-.439-.44-.44-.439-.877-.879-.217-.215-.512-.346-.838-.346s-.621.131-.837.346l-1.579 1.578v-1.733c0-.356-.289-.645-.644-.645-.356 0-.644.289-.644.645v3.298c0 .356.289.645.644.645h2.129c.356 0 .644-.289.644-.645v-2.079zm2.842 0c0 .356-.289.645-.644.645h-1.485v.851h1.485c.355 0 .644.289.644.645s-.289.645-.644.645h-2.129c-.356 0-.644-.289-.644-.645v-5.614c0-.356.289-.645.644-.645h2.129c.355 0 .644.289.644.645s-.289.645-.644.645h-1.485v.85h1.485c.355 0 .644.289.644.645s-.289.647-.644.647z"/>
                </svg>
                เข้าสู่ระบบด้วย LINE
            </a>
        </div>

        <div class="relative flex py-2 items-center">
            <div class="flex-grow border-t border-[#e2e8f0]"></div>
            <span class="flex-shrink mx-4 text-xs text-[#727785] font-medium">หรือ</span>
            <div class="flex-grow border-t border-[#e2e8f0]"></div>
        </div>

        <div class="text-center mt-2">
            <button type="button" onclick="toggleEmergencyForm()" class="text-xs text-[#727785] hover:text-[#0058be] font-semibold hover:underline">
                เข้าสู่ระบบด้วยชื่อผู้ใช้ (กรณีฉุกเฉิน)
            </button>
        </div>

        <!-- Emergency Login Form (Hidden by default) -->
        <div id="emergencyForm" class="hidden mt-6 pt-6 border-t border-[#e2e8f0]">
            <form method="POST" action="" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                
                <div>
                    <label class="block text-sm font-medium text-[#424754] mb-1.5" for="username">ชื่อผู้ใช้</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[#c2c6d6]">person</span>
                        <input type="text" id="username" name="username" 
                               value="<?= sanitize($_POST['username'] ?? '') ?>"
                               placeholder="กรอกชื่อผู้ใช้"
                               class="login-input w-full pl-11 pr-4 py-3.5 bg-[#f8fafc] border border-[#c2c6d6] rounded-xl text-base text-[#111c2d] placeholder:text-[#c2c6d6] outline-none transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-[#424754] mb-1.5" for="password">รหัสผ่าน</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[#c2c6d6]">lock</span>
                        <input type="password" id="password" name="password" 
                               placeholder="กรอกรหัสผ่าน"
                               class="login-input w-full pl-11 pr-12 py-3.5 bg-[#f8fafc] border border-[#c2c6d6] rounded-xl text-base text-[#111c2d] placeholder:text-[#c2c6d6] outline-none transition-all">
                        <button type="button" onclick="togglePassword()" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-[#c2c6d6] hover:text-[#424754]">
                            <span class="material-symbols-outlined text-[20px]" id="eyeIcon">visibility_off</span>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="remember" class="w-4 h-4 rounded border-[#c2c6d6] text-[#0058be] focus:ring-[#0058be]">
                        <span class="text-sm text-[#424754]">จดจำฉัน</span>
                    </label>
                </div>

                <button type="submit" 
                        class="w-full bg-[#0058be] hover:bg-[#2170e4] text-white font-bold py-3.5 rounded-xl transition-all shadow-md shadow-blue-500/20 active:scale-[0.98]">
                    เข้าสู่ระบบด้วยรหัสผ่าน
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="mt-8 text-center">
            <a href="<?= APP_URL ?>/" class="text-sm text-[#0058be] hover:underline font-medium">← กลับหน้าหลัก</a>
        </div>
        <p class="mt-4 text-[11px] text-[#727785] text-center">
            © <?= date('Y') ?> Smart Bill. Minimalist Property Management.
        </p>
    </div>
</main>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.textContent = 'visibility';
    } else {
        input.type = 'password';
        icon.textContent = 'visibility_off';
    }
}

function toggleEmergencyForm() {
    const form = document.getElementById('emergencyForm');
    form.classList.toggle('hidden');
}
</script>
</body>
</html>

<?php
/**
 * Public Invitation Landing Page
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

$token = trim($_GET['token'] ?? '');

$db = getDB();
$invitation = null;
$error = '';

if (empty($token)) {
    $error = 'ไม่พบรหัสคำเชิญ กรุณาใช้ลิงก์เชิญที่ได้รับจากแอดมิน';
} else {
    // Fetch invitation details
    $stmt = $db->prepare("
        SELECT ai.*, r.display_name as role_name, u.full_name as inviter_name 
        FROM admin_invitations ai
        JOIN roles r ON ai.role_id = r.id
        JOIN users u ON ai.created_by = u.id
        WHERE ai.token = ?
    ");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch();
    
    if (!$invitation) {
        $error = 'ลิงก์เชิญนี้ไม่ถูกต้องหรือไม่พบในระบบ';
    } elseif ($invitation['is_used']) {
        $error = 'ลิงก์เชิญนี้ถูกใช้งานไปแล้ว';
    } elseif (strtotime($invitation['expires_at']) < time()) {
        $error = 'ลิงก์เชิญนี้หมดอายุแล้วเมื่อวันที่ ' . formatDate($invitation['expires_at'], 'd/m/Y H:i');
    }
}

// Handle accept
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $invitation) {
    // Store token in session to carry over LINE login
    $_SESSION['invite_token'] = $token;
    // Redirect to LINE Login redirector
    header('Location: ' . APP_URL . '/api/line-login-redirect.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รับคำเชิญผู้ดูแลระบบ — Smart Bill</title>
    <?php include __DIR__ . '/components/head.php'; ?>
    <style>
        .invite-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(135deg, #f0f3ff 0%, #f9f9ff 50%, #e7eeff 100%);
        }
        .invite-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid #e2e8f0;
            box-shadow: 0px 8px 32px rgba(30, 41, 59, 0.08);
        }
    </style>
</head>
<body>
<main class="invite-container">
    <div class="invite-card rounded-2xl w-full max-w-[480px] p-8 md:p-10 text-center">
        <!-- Logo -->
        <div class="flex flex-col items-center mb-6">
            <div class="w-16 h-16 bg-[#d8e2ff] rounded-full flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[#0058be] text-[32px]">mail</span>
            </div>
            <h1 class="text-2xl font-extrabold text-[#0058be] tracking-tight">Smart Bill</h1>
            <p class="text-sm text-[#424754] mt-1">ระบบบริหารจัดการผู้ดูแลระบบ</p>
        </div>

        <?php if ($error): ?>
            <!-- Error State -->
            <div class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
                <span class="material-symbols-outlined text-red-600 text-[48px] mb-2">cancel</span>
                <h2 class="text-lg font-bold text-red-800 mb-1">ไม่สามารถใช้คำเชิญได้</h2>
                <p class="text-sm text-red-600"><?= htmlspecialchars($error) ?></p>
            </div>
            <a href="<?= APP_URL ?>/login.php" class="inline-flex items-center gap-2 text-sm text-[#0058be] hover:underline font-semibold">
                <span class="material-symbols-outlined text-[16px]">arrow_back</span>
                ไปที่หน้าเข้าสู่ระบบ
            </a>
        <?php else: ?>
            <!-- Invitation Details -->
            <div class="mb-8">
                <h2 class="text-xl font-bold text-on-surface mb-2">คุณได้รับเชิญให้เป็นผู้ดูแลระบบ</h2>
                <p class="text-sm text-on-surface-variant mb-6">กรุณายอมรับคำเชิญเพื่อเปิดสิทธิ์และผูกบัญชี LINE Login ของคุณในการเข้าทำงาน</p>
                
                <div class="bg-surface-container-low border border-outline-variant rounded-xl p-5 text-left space-y-3.5 mb-6">
                    <div class="flex justify-between items-center text-sm border-b border-outline-variant pb-2.5">
                        <span class="text-on-surface-variant font-medium">สิทธิ์ผู้ใช้งาน (Role):</span>
                        <span class="font-bold text-primary px-3 py-1 bg-primary-fixed/20 rounded-full text-xs">
                            <?= sanitize($invitation['role_name']) ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-sm border-b border-outline-variant pb-2.5">
                        <span class="text-on-surface-variant font-medium">ผู้เชิญ (Invited By):</span>
                        <span class="font-semibold text-on-surface"><?= sanitize($invitation['inviter_name']) ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-on-surface-variant font-medium">วันหมดอายุลิงก์:</span>
                        <span class="text-on-surface text-xs"><?= formatDate($invitation['expires_at'], 'd M Y เวลา H:i') ?></span>
                    </div>
                </div>

                <!-- Accept Form -->
                <form method="POST" action="">
                    <button type="submit" 
                            class="w-full bg-[#06C755] hover:bg-[#05b34c] text-white font-bold py-3.5 rounded-xl transition-all shadow-md shadow-green-500/20 active:scale-[0.98] flex items-center justify-center gap-3">
                        <svg class="w-6 h-6 fill-current" viewBox="0 0 24 24">
                            <path d="M24 10.304c0-5.369-5.383-9.738-12-9.738-6.616 0-12 4.369-12 9.738 0 4.814 4.269 8.846 10.036 9.564.39.084.922.258 1.058.592.12.296.079.759.038 1.057l-.171 1.026c-.052.312-.252 1.22.108 1.666.216.268.57.292.797.234.227-.058 2.433-1.603 3.427-2.529.742-.693 1.037-1.077 1.451-1.644 2.871-1.745 4.656-4.524 4.656-7.765zm-14.773 3.66c0 .356-.289.645-.644.645h-2.129v-5.614c0-.356.289-.645.644-.645.356 0 .644.289.644.645v4.324h1.485c.355 0 .644.289.644.645zm2.576 0c0 .356-.288.645-.644.645-.355 0-.644-.289-.644-.645v-5.614c0-.356.289-.645.644-.645s.644.289.644.645v5.614zm4.184-1.921c0 .324-.131.62-.344.837l-1.398 1.397v-1.61c0-.356-.289-.645-.644-.645-.356 0-.644.289-.644.645v3.298c0 .356.288.645.644.645h2.129c.355 0 .644-.289.644-.645v-2.079l.957-.957c.214-.217.345-.512.345-.837s-.131-.62-.345-.837c-.439-.44-.44-.439-.877-.879-.217-.215-.512-.346-.838-.346s-.621.131-.837.346l-1.579 1.578v-1.733c0-.356-.289-.645-.644-.645-.356 0-.644.289-.644.645v3.298c0 .356.289.645.644.645h2.129c.356 0 .644-.289.644-.645v-2.079zm2.842 0c0 .356-.289.645-.644.645h-1.485v.851h1.485c.355 0 .644.289.644.645s-.289.645-.644.645h-2.129c-.356 0-.644-.289-.644-.645v-5.614c0-.356.289-.645.644-.645h2.129c.355 0 .644.289.644.645s-.289.645-.644.645h-1.485v.85h1.485c.355 0 .644.289.644.645s-.289.647-.644.647z"/>
                        </svg>
                        ยอมรับคำเชิญและเข้าสู่ระบบด้วย LINE
                    </button>
                </form>
            </div>
            
            <p class="text-xs text-[#727785]">
                การยอมรับคำเชิญนี้จะเชื่อมโยงโปรไฟล์ LINE ของคุณเข้ากับสิทธิ์ในการบริหารระบบ Smart Bill เพื่อความปลอดภัยสูงสุด
            </p>
        <?php endif; ?>

        <p class="mt-8 text-[11px] text-[#727785]">
            © <?= date('Y') ?> Smart Bill. Minimalist Property Management.
        </p>
    </div>
</main>
</body>
</html>

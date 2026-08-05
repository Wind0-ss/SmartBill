<?php
/**
 * System Settings — Utility rates, PromptPay, LINE API
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();
requireRole('super_admin', 'admin');

$pageTitle = 'ตั้งค่าระบบ';
$db = getDB();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $userId = $_SESSION['user_id'];
    setSetting('water_rate', $_POST['water_rate'], 'number', $userId);
    setSetting('electric_rate', $_POST['electric_rate'], 'number', $userId);
    setSetting('promptpay_number', $_POST['promptpay_number'], 'string', $userId);
    setSetting('line_channel_token', $_POST['line_channel_token'], 'string', $userId);
    setSetting('line_login_channel_id', $_POST['line_login_channel_id'], 'string', $userId);
    setSetting('line_login_channel_secret', $_POST['line_login_channel_secret'], 'string', $userId);
    setSetting('company_name', $_POST['company_name'], 'string', $userId);
    setSetting('company_phone', $_POST['company_phone'], 'string', $userId);
    setSetting('bill_due_day', $_POST['bill_due_day'], 'number', $userId);
    setSetting('liff_id', $_POST['liff_id'] ?? '', 'string', $userId);
    
    logActivity($userId, 'อัปเดตการตั้งค่าระบบ', 'settings', null);
    redirect(APP_URL . '/admin/settings.php', 'บันทึกการตั้งค่าเรียบร้อย');
}

// Current settings
$waterRate = getSetting('water_rate', 18.00);
$electricRate = getSetting('electric_rate', 7.50);
$promptpay = getSetting('promptpay_number', '');
$lineToken = getSetting('line_channel_token', '');
$lineLoginChannelId = getSetting('line_login_channel_id', '');
$lineLoginChannelSecret = getSetting('line_login_channel_secret', '');
$companyName = getSetting('company_name', 'Smart Bill');
$companyPhone = getSetting('company_phone', '');
$billDueDay = getSetting('bill_due_day', 5);
$liffId = getSetting('liff_id', '');

// Recent activity logs
$recentLogs = $db->query("
    SELECT al.action, al.created_at, u.full_name 
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.entity_type = 'settings' OR al.action LIKE '%ตั้งค่า%' OR al.action LIKE '%rate%' OR al.action LIKE '%LINE%'
    ORDER BY al.created_at DESC LIMIT 5
")->fetchAll();

include dirname(__DIR__) . '/components/navbar.php';
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-on-surface">ตั้งค่าระบบ</h1>
    <p class="text-on-surface-variant text-sm mt-1">กำหนดอัตราค่าน้ำค่าไฟ และเชื่อมต่อ API ภายนอก</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Left: Settings Form -->
    <section class="lg:col-span-7">
        <form method="POST" class="bg-white border border-outline-variant rounded-xl p-6 shadow-sm">
            <input type="hidden" name="save_settings" value="1">
            
            <div class="flex items-center gap-3 mb-6">
                <span class="material-symbols-outlined text-primary p-2 bg-primary-fixed/30 rounded-lg">tune</span>
                <h2 class="text-xl font-semibold text-on-surface">การตั้งค่าทั่วไป</h2>
            </div>

            <div class="space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">อัตราค่าน้ำ (ต่อหน่วย)</label>
                        <div class="relative">
                            <input type="number" name="water_rate" value="<?= $waterRate ?>" step="0.01" class="form-input pr-14">
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-semibold text-outline">THB</span>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">อัตราค่าไฟ (ต่อหน่วย)</label>
                        <div class="relative">
                            <input type="number" name="electric_rate" value="<?= $electricRate ?>" step="0.01" class="form-input pr-14">
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-semibold text-outline">THB</span>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="form-label">หมายเลข PromptPay</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline">payments</span>
                        <input type="text" name="promptpay_number" value="<?= sanitize($promptpay) ?>" placeholder="เบอร์โทร หรือ เลขบัตรประชาชน" class="form-input pl-12">
                    </div>
                </div>

                <div>
                    <label class="form-label">LINE Messaging API Key</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline">api</span>
                        <input type="password" name="line_channel_token" value="<?= sanitize($lineToken) ?>" placeholder="Channel Access Token" class="form-input pl-12">
                    </div>
                    <p class="text-xs text-outline mt-1">ใช้สำหรับส่งใบแจ้งหนี้อัตโนมัติผ่าน LINE</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">LINE Login Channel ID</label>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline">fingerprint</span>
                            <input type="text" name="line_login_channel_id" value="<?= sanitize($lineLoginChannelId) ?>" placeholder="Channel ID" class="form-input pl-12">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">LINE Login Channel Secret</label>
                        <div class="relative">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline">key</span>
                            <input type="password" name="line_login_channel_secret" value="<?= sanitize($lineLoginChannelSecret) ?>" placeholder="Channel Secret" class="form-input pl-12">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="form-label">LIFF ID</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-outline">web</span>
                        <input type="text" name="liff_id" value="<?= sanitize($liffId) ?>" placeholder="1234567890-AbCdEfGh" class="form-input pl-12">
                    </div>
                    <p class="text-xs text-outline mt-1">สร้างได้ที่ LINE Developers Console → LIFF → Add</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">ชื่อหอพัก / บริษัท</label>
                        <input type="text" name="company_name" value="<?= sanitize($companyName) ?>" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">เบอร์โทรติดต่อ</label>
                        <input type="text" name="company_phone" value="<?= sanitize($companyPhone) ?>" class="form-input" placeholder="02-XXX-XXXX">
                    </div>
                </div>

                <div class="w-1/2">
                    <label class="form-label">วันครบกำหนดชำระ</label>
                    <div class="relative">
                        <input type="number" name="bill_due_day" value="<?= $billDueDay ?>" min="1" max="28" class="form-input">
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-outline">ของเดือน</span>
                    </div>
                </div>

                <div class="pt-4 border-t border-outline-variant">
                    <button type="submit" class="btn btn-dark">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        บันทึกการตั้งค่า
                    </button>
                </div>
            </div>
        </form>
    </section>

    <!-- Right: LINE Guide -->
    <section class="lg:col-span-5">
        <div class="bg-white border border-outline-variant rounded-xl p-6 shadow-sm">
            <div class="flex items-center gap-3 mb-6">
                <span class="material-symbols-outlined text-secondary p-2 bg-secondary-container/10 rounded-lg">integration_instructions</span>
                <h2 class="text-xl font-semibold text-on-surface">วิธีเชื่อมต่อ LINE</h2>
            </div>
            
            <div class="space-y-4 mb-6">
                <?php
                $steps = [
                    'เข้า <a href="https://developers.line.biz" class="text-primary hover:underline" target="_blank">LINE Developers Console</a>',
                    'เลือก Provider และ Channel (Messaging API)',
                    'คัดลอก <strong>Webhook URL</strong> ด้านล่างวางใน Webhook settings',
                    'เปิดใช้ "Use Webhooks" ใน LINE Console'
                ];
                foreach ($steps as $i => $step): ?>
                <div class="flex gap-3">
                    <div class="w-6 h-6 rounded-full bg-outline-variant flex items-center justify-center flex-shrink-0 text-xs font-semibold"><?= $i + 1 ?></div>
                    <p class="text-sm text-on-surface-variant"><?= $step ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="space-y-2">
                <label class="form-label">Webhook Endpoint</label>
                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="flex-grow bg-surface-container-lowest border border-outline-variant rounded-lg px-4 py-3 font-mono text-xs text-outline truncate select-all">
                        <?= APP_URL ?>/api/line.php
                    </div>
                    <button onclick="copyToClipboard('<?= APP_URL ?>/api/line.php', this)" class="btn btn-secondary text-sm whitespace-nowrap">
                        <span class="material-symbols-outlined text-[18px]">content_copy</span>
                        Copy
                    </button>
                </div>
            </div>

            <div class="space-y-2 mt-4">
                <label class="form-label">LINE Login Callback URL</label>
                <div class="flex flex-col sm:flex-row gap-2">
                    <div class="flex-grow bg-surface-container-lowest border border-outline-variant rounded-lg px-4 py-3 font-mono text-xs text-outline truncate select-all">
                        <?= APP_URL ?>/api/line-login-callback.php
                    </div>
                    <button onclick="copyToClipboard('<?= APP_URL ?>/api/line-login-callback.php', this)" class="btn btn-secondary text-sm whitespace-nowrap">
                        <span class="material-symbols-outlined text-[18px]">content_copy</span>
                        Copy
                    </button>
                </div>
                <p class="text-[11px] text-outline">ใช้กรอกใน LINE Console > LINE Login settings > Callback URL</p>
            </div>

            <!-- Connection Status -->
            <div class="mt-6 p-4 rounded-xl border <?= $lineToken ? 'bg-green-50/50 border-green-200' : 'bg-orange-50/50 border-orange-200' ?>">
                <div class="flex gap-3">
                    <span class="material-symbols-outlined <?= $lineToken ? 'text-secondary' : 'text-tertiary' ?>">
                        <?= $lineToken ? 'verified' : 'warning' ?>
                    </span>
                    <div>
                        <p class="text-sm font-medium <?= $lineToken ? 'text-secondary' : 'text-tertiary' ?>">
                            <?= $lineToken ? 'เชื่อมต่อแล้ว' : 'ยังไม่ได้เชื่อมต่อ' ?>
                        </p>
                        <p class="text-xs text-on-surface-variant">
                            <?= $lineToken ? 'ระบบพร้อมส่งแจ้งเตือนผ่าน LINE' : 'กรุณากรอก LINE API Key เพื่อเปิดใช้งาน' ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white border border-outline-variant rounded-xl overflow-hidden shadow-sm mt-6">
            <div class="px-6 py-3 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
                <h3 class="text-xs font-bold text-outline uppercase tracking-wider">กิจกรรมล่าสุด</h3>
            </div>
            <div class="divide-y divide-outline-variant/50">
                <?php if (empty($recentLogs)): ?>
                <div class="px-6 py-6 text-center text-sm text-on-surface-variant">ยังไม่มีกิจกรรม</div>
                <?php else: ?>
                <?php foreach ($recentLogs as $log): ?>
                <div class="px-6 py-3 flex justify-between items-center">
                    <div class="flex items-center gap-3">
                        <span class="material-symbols-outlined text-outline text-[18px]">update</span>
                        <p class="text-sm"><?= sanitize($log['action']) ?></p>
                    </div>
                    <span class="text-xs text-outline"><?= formatDate($log['created_at'], 'd M H:i') ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php include dirname(__DIR__) . '/components/footer.php'; ?>

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

// ประเภทค่าใช้จ่ายเพิ่มเติม (ค่าขยะ, ค่าบำรุงมิเตอร์ ฯลฯ)
try {
    $expenseTypes = $db->query("SELECT * FROM expense_types ORDER BY sort_order ASC, id ASC")->fetchAll();
    $expenseTableReady = true;
} catch (PDOException $e) {
    // ยังไม่ได้รัน database/migrate_additional_charges.php
    $expenseTypes = [];
    $expenseTableReady = false;
}

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

<!-- ============================================================
     ประเภทค่าใช้จ่ายเพิ่มเติม
     ============================================================ -->
<section id="expense-types" class="mt-6 scroll-mt-24">
    <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
        <div class="p-6 pb-4 flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-tertiary p-2 bg-tertiary-fixed-dim/30 rounded-lg">receipt_long</span>
                <div>
                    <h2 class="text-xl font-semibold text-on-surface">ประเภทค่าใช้จ่ายเพิ่มเติม</h2>
                    <p class="text-xs text-on-surface-variant mt-0.5">
                        รายการที่จะให้เลือกติ๊กในหน้า <a href="<?= APP_URL ?>/admin/meters.php" class="text-primary hover:underline">บันทึกมิเตอร์</a> เช่น ค่าขยะ ค่าบำรุงมิเตอร์
                    </p>
                </div>
            </div>
            <?php if ($expenseTableReady): ?>
            <button type="button" onclick="openExpenseForm()" class="btn btn-dark">
                <span class="material-symbols-outlined text-[18px]">add</span>
                เพิ่มรายการ
            </button>
            <?php endif; ?>
        </div>

        <?php if (!$expenseTableReady): ?>
        <div class="mx-6 mb-6 p-4 rounded-xl bg-orange-50/60 border border-orange-200">
            <p class="text-sm font-medium text-tertiary">ยังไม่ได้ติดตั้งตารางสำหรับค่าใช้จ่ายเพิ่มเติม</p>
            <p class="text-xs text-on-surface-variant mt-1">
                รันคำสั่ง <code class="font-mono bg-white px-1.5 py-0.5 rounded border border-outline-variant">php database/migrate_additional_charges.php</code>
                หรือเปิด <code class="font-mono bg-white px-1.5 py-0.5 rounded border border-outline-variant"><?= APP_URL ?>/database/migrate_additional_charges.php</code> หนึ่งครั้ง
            </p>
        </div>
        <?php else: ?>

        <!-- ฟอร์มเพิ่ม/แก้ไข -->
        <div id="expenseForm" class="hidden mx-6 mb-4 p-4 rounded-xl border border-primary/30 bg-primary-fixed/20">
            <input type="hidden" id="etId" value="0">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                <div class="md:col-span-4">
                    <label class="form-label">ชื่อรายการ</label>
                    <input type="text" id="etName" class="form-input" placeholder="เช่น ค่าขยะ" maxlength="150">
                </div>
                <div class="md:col-span-3">
                    <label class="form-label">ออกรวมกับบิล</label>
                    <select id="etGroup" class="form-input">
                        <option value="rent">บิลค่าเช่า</option>
                        <option value="utility">บิลค่าน้ำ/ไฟ</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="form-label">ยอดเริ่มต้น</label>
                    <input type="number" id="etAmount" class="form-input" step="0.01" min="0" placeholder="0.00">
                </div>
                <div class="md:col-span-3">
                    <label class="form-label">ไอคอน</label>
                    <input type="text" id="etIcon" class="form-input" placeholder="delete" maxlength="50">
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
                <label class="flex items-center gap-2 text-sm text-on-surface-variant cursor-pointer">
                    <input type="checkbox" id="etActive" class="w-4 h-4 rounded border-outline-variant text-primary focus:ring-primary" checked>
                    เปิดใช้งาน (แสดงในหน้าบันทึกมิเตอร์)
                </label>
                <div class="flex gap-2">
                    <button type="button" onclick="closeExpenseForm()" class="btn btn-ghost text-sm">ยกเลิก</button>
                    <button type="button" onclick="saveExpenseType()" id="etSaveBtn" class="btn btn-primary text-sm">
                        <span class="material-symbols-outlined text-[18px]">save</span>
                        บันทึก
                    </button>
                </div>
            </div>
            <p class="text-[11px] text-outline mt-2">
                ยอดเริ่มต้นคือตัวเลขที่ระบบเติมให้อัตโนมัติตอนติ๊กรายการ — แก้ไขรายห้องได้เสมอในหน้าบันทึก
            </p>
        </div>

        <div class="overflow-x-auto border-t border-outline-variant">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-surface-container-low">
                        <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase">รายการ</th>
                        <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase">ออกรวมกับบิล</th>
                        <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase text-right">ยอดเริ่มต้น</th>
                        <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase">สถานะ</th>
                        <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase text-right">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/50">
                    <?php if (empty($expenseTypes)): ?>
                    <tr><td colspan="5" class="px-6 py-10 text-center text-on-surface-variant">
                        <span class="material-symbols-outlined text-[40px] text-outline-variant block mb-2">playlist_add</span>
                        ยังไม่มีรายการ — กด "เพิ่มรายการ" เพื่อเริ่มต้น
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($expenseTypes as $et): ?>
                    <tr class="hover:bg-surface-container-low/50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-outline text-[20px]"><?= sanitize($et['icon'] ?: 'receipt_long') ?></span>
                                <span class="text-sm font-semibold"><?= sanitize($et['name']) ?></span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-block text-[11px] font-bold px-2 py-0.5 rounded-full <?= $et['bill_group'] === 'utility' ? 'text-blue-700 bg-blue-50 border border-blue-200' : 'text-teal-700 bg-teal-50 border border-teal-200' ?>">
                                <?= $et['bill_group'] === 'utility' ? 'บิลค่าน้ำ/ไฟ' : 'บิลค่าเช่า' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-right font-medium">
                            <?= (float)$et['default_amount'] > 0 ? formatCurrency($et['default_amount']) : '<span class="text-outline">—</span>' ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($et['is_active']): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-green-50 text-green-700 border-green-200">เปิดใช้งาน</span>
                            <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border bg-gray-50 text-gray-600 border-gray-200">ปิดอยู่</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right whitespace-nowrap">
                            <?php
                            // JS_ATTR_FLAGS หนี ' " & < > ทั้งหมด จึงวางใน onclick='' ได้อย่างปลอดภัย
                            $etJson = json_encode([
                                'id'             => (int)$et['id'],
                                'name'           => $et['name'],
                                'icon'           => $et['icon'],
                                'default_amount' => (float)$et['default_amount'],
                                'bill_group'     => $et['bill_group'],
                                'is_active'      => (int)$et['is_active'],
                                'sort_order'     => (int)$et['sort_order'],
                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
                            $nameJson = json_encode($et['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
                            ?>
                            <button type="button" onclick='editExpenseType(<?= $etJson ?>)'
                                    class="text-primary hover:underline text-xs font-medium">แก้ไข</button>
                            <span class="text-outline-variant mx-1">|</span>
                            <button type="button" onclick='deleteExpenseType(<?= (int)$et['id'] ?>, <?= $nameJson ?>)'
                                    class="text-error hover:underline text-xs font-medium">ลบ</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php
$extraScripts = <<<JS
<script>
function openExpenseForm() {
    document.getElementById('etId').value = 0;
    document.getElementById('etName').value = '';
    document.getElementById('etIcon').value = '';
    document.getElementById('etAmount').value = '';
    document.getElementById('etGroup').value = 'rent';
    document.getElementById('etActive').checked = true;
    document.getElementById('expenseForm').classList.remove('hidden');
    document.getElementById('etName').focus();
}

function closeExpenseForm() {
    document.getElementById('expenseForm').classList.add('hidden');
}

function editExpenseType(et) {
    document.getElementById('etId').value = et.id;
    document.getElementById('etName').value = et.name;
    document.getElementById('etIcon').value = et.icon || '';
    document.getElementById('etAmount').value = et.default_amount > 0 ? et.default_amount : '';
    document.getElementById('etGroup').value = et.bill_group;
    document.getElementById('etActive').checked = et.is_active == 1;
    document.getElementById('expenseForm').dataset.sortOrder = et.sort_order;
    document.getElementById('expenseForm').classList.remove('hidden');
    document.getElementById('expenseForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.getElementById('etName').focus();
}

async function saveExpenseType() {
    const name = document.getElementById('etName').value.trim();
    if (!name) { Toast.warning('กรุณากรอกชื่อรายการ'); return; }

    const btn = document.getElementById('etSaveBtn');
    setButtonLoading(btn, true);

    try {
        const data = await API.post('/api/expenses.php?action=save_type', {
            id: parseInt(document.getElementById('etId').value, 10) || 0,
            name: name,
            icon: document.getElementById('etIcon').value.trim(),
            default_amount: parseFloat(document.getElementById('etAmount').value) || 0,
            bill_group: document.getElementById('etGroup').value,
            is_active: document.getElementById('etActive').checked ? 1 : 0,
            sort_order: parseInt(document.getElementById('expenseForm').dataset.sortOrder || '0', 10)
        });
        if (data.success) {
            Toast.success(data.message);
            setTimeout(() => location.reload(), 800);
            return;
        }
    } catch (err) {}
    setButtonLoading(btn, false);
}

async function deleteExpenseType(id, name) {
    if (!confirm('ลบ "' + name + '" ออกจากรายการ?\\n\\nบิลที่ออกไปแล้วและประวัติที่บันทึกไว้จะไม่ถูกลบ')) return;

    try {
        const data = await API.post('/api/expenses.php?action=delete_type', { id: id });
        if (data.success) {
            Toast.success(data.message);
            setTimeout(() => location.reload(), 800);
        }
    } catch (err) {}
}
</script>
JS;

include dirname(__DIR__) . '/components/footer.php';
?>

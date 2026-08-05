<?php
/**
 * Admin Bills Management — View, manage, and generate invoices
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();

$pageTitle = 'จัดการบิล';
$db = getDB();

$selectedMonth = $_GET['month'] ?? date('Y-m-01');
$selectedStatus = $_GET['status'] ?? '';

$where = "WHERE i.billing_month = ?";
$params = [$selectedMonth];
if ($selectedStatus) {
    $where .= " AND i.status = ?";
    $params[] = $selectedStatus;
}

$stmt = $db->prepare("
    SELECT i.*, r.room_number, b.name as building_name,
           CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name,
           t.phone as tenant_phone, t.line_user_id
    FROM invoices i
    JOIN rooms r ON i.room_id = r.id
    LEFT JOIN buildings b ON r.building_id = b.id
    JOIN tenants t ON i.tenant_id = t.id
    $where
    ORDER BY r.room_number
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Stats
$stats = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(status = 'paid') as paid,
        SUM(status IN ('draft','sent')) as pending,
        SUM(status = 'overdue') as overdue,
        COALESCE(SUM(total_amount), 0) as total_billed,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as collected
    FROM invoices WHERE billing_month = ?
");
$stats->execute([$selectedMonth]);
$billStats = $stats->fetch();

include dirname(__DIR__) . '/components/navbar.php';
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
    <div>
        <h1 class="text-3xl font-bold text-on-surface">จัดการบิล</h1>
        <p class="text-on-surface-variant text-sm mt-1">ใบแจ้งหนี้ประจำเดือน <?= formatThaiMonth($selectedMonth) ?></p>
    </div>
    <div class="flex gap-3">
        <input type="month" value="<?= date('Y-m', strtotime($selectedMonth)) ?>" 
               onchange="location.href='?month=' + this.value + '-01'" class="form-input w-44 text-sm">
        <button onclick="notifyAllLine()" class="btn btn-secondary" id="notifyAllBtn" title="ส่งแจ้งเตือนทุกบิลที่ยังไม่ได้ส่ง">
            <span class="material-symbols-outlined text-[18px]">send</span>
            ส่ง LINE ทั้งหมด
        </button>
        <button onclick="generateBills()" class="btn btn-primary" id="generateBtn">
            <span class="material-symbols-outlined text-[18px]">receipt_long</span>
            สร้างบิลทั้งหมด
        </button>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white border border-outline-variant rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-on-surface"><?= $billStats['total'] ?></p>
        <p class="text-xs text-on-surface-variant mt-1">บิลทั้งหมด</p>
    </div>
    <div class="bg-white border border-outline-variant rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-secondary"><?= $billStats['paid'] ?></p>
        <p class="text-xs text-on-surface-variant mt-1">ชำระแล้ว</p>
    </div>
    <div class="bg-white border border-outline-variant rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-tertiary"><?= $billStats['pending'] ?></p>
        <p class="text-xs text-on-surface-variant mt-1">รอชำระ</p>
    </div>
    <div class="bg-white border border-outline-variant rounded-xl p-4 text-center">
        <p class="text-2xl font-bold text-error"><?= $billStats['overdue'] ?></p>
        <p class="text-xs text-on-surface-variant mt-1">เลยกำหนด</p>
    </div>
</div>

<!-- Status Filter -->
<div class="flex gap-2 mb-6 flex-wrap">
    <a href="?month=<?= $selectedMonth ?>" class="px-4 py-1.5 rounded-full text-xs font-medium <?= !$selectedStatus ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>">ทั้งหมด</a>
    <a href="?month=<?= $selectedMonth ?>&status=draft" class="px-4 py-1.5 rounded-full text-xs font-medium <?= $selectedStatus === 'draft' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>">แบบร่าง</a>
    <a href="?month=<?= $selectedMonth ?>&status=sent" class="px-4 py-1.5 rounded-full text-xs font-medium <?= $selectedStatus === 'sent' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>">ส่งแล้ว</a>
    <a href="?month=<?= $selectedMonth ?>&status=paid" class="px-4 py-1.5 rounded-full text-xs font-medium <?= $selectedStatus === 'paid' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>">ชำระแล้ว</a>
    <a href="?month=<?= $selectedMonth ?>&status=overdue" class="px-4 py-1.5 rounded-full text-xs font-medium <?= $selectedStatus === 'overdue' ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>">เลยกำหนด</a>
</div>

<!-- Bills Table -->
<div class="bg-white border border-outline-variant rounded-xl overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-surface-container-low">
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">เลขที่บิล</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ประเภท</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ห้อง</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ผู้เช่า</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant text-right">ยอดรวม</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ครบกำหนด</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">สถานะ</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant text-right">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/50">
                <?php if (empty($invoices)): ?>
                <tr><td colspan="8" class="px-6 py-12 text-center text-on-surface-variant">
                    <span class="material-symbols-outlined text-[48px] text-outline-variant block mb-2">receipt_long</span>
                    ไม่พบบิลในเดือนนี้
                </td></tr>
                <?php else: ?>
                <?php foreach ($invoices as $inv): ?>
                <tr class="hover:bg-surface-container-low/50 transition-colors">
                    <td class="px-6 py-4 text-sm font-medium text-primary"><?= sanitize($inv['invoice_number']) ?></td>
                    <td class="px-6 py-4 text-sm">
                        <?php if ($inv['invoice_type'] === 'utility'): ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-blue-700 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-full">💧⚡ ค่าน้ำ/ไฟ</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-teal-700 bg-teal-50 border border-teal-200 px-2 py-0.5 rounded-full">🏠 ค่าเช่า</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm font-bold"><?= sanitize($inv['room_number']) ?></td>
                    <td class="px-6 py-4 text-sm"><?= sanitize($inv['tenant_name']) ?></td>
                    <td class="px-6 py-4 text-sm font-medium text-right"><?= formatCurrency($inv['total_amount']) ?></td>
                    <td class="px-6 py-4 text-sm text-on-surface-variant"><?= formatDate($inv['due_date'], 'd M Y') ?></td>
                    <td class="px-6 py-4"><?= statusBadge($inv['status']) ?></td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <?php if (!empty($inv['line_user_id']) && $inv['status'] !== 'paid' && $inv['status'] !== 'cancelled'): ?>
                                <?php if ($inv['sent_via_line']): ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] text-secondary font-medium" title="ส่งแล้วเมื่อ <?= formatDate($inv['sent_at'], 'd/m/Y H:i') ?>">
                                        <span class="material-symbols-outlined text-[14px]">check_circle</span>
                                        ส่งแล้ว
                                    </span>
                                <?php endif; ?>
                                <button onclick="notifyLine(<?= $inv['id'] ?>, this)" 
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-medium text-green-700 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 transition-colors"
                                        title="<?= $inv['sent_via_line'] ? 'ส่งซ้ำ' : 'ส่งแจ้งเตือนผ่าน LINE' ?>">
                                    <span class="text-[14px]">📱</span>
                                    <?= $inv['sent_via_line'] ? 'ส่งซ้ำ' : 'ส่ง LINE' ?>
                                </button>
                            <?php elseif (empty($inv['line_user_id'])): ?>
                                <span class="text-[11px] text-outline" title="ผู้เช่ายังไม่ได้ลงทะเบียน LINE">ไม่มี LINE</span>
                            <?php endif; ?>
                            <a href="<?= APP_URL ?>/tenant/bill.php?room=<?= urlencode($inv['room_number']) ?>" 
                               class="text-primary hover:underline text-xs font-medium" target="_blank">ดูบิล</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$extraScripts = <<<JS
<script>
async function generateBills() {
    if (!confirm('สร้างบิลสำหรับทุกห้องที่มีผู้เช่า?')) return;
    
    const btn = document.getElementById('generateBtn');
    setButtonLoading(btn, true);
    
    try {
        const formData = new FormData();
        formData.append('billing_month', '{$selectedMonth}');
        const data = await API.upload('/api/bills.php?action=generate', formData);
        if (data.success) {
            Toast.success(data.message);
            setTimeout(() => location.reload(), 1000);
        }
    } catch (err) {}
    finally { setButtonLoading(btn, false); }
}

async function notifyLine(invoiceId, btnEl) {
    const origText = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = '<span class="spinner"></span>';
    
    try {
        const data = await API.post('/api/bills.php?action=notify_line', { invoice_id: invoiceId });
        if (data.success) {
            Toast.success(data.message);
            btnEl.innerHTML = '<span class="text-[14px]">✅</span> ส่งแล้ว';
            btnEl.classList.remove('text-green-700', 'bg-green-50', 'border-green-200');
            btnEl.classList.add('text-secondary', 'bg-secondary-container/20', 'border-secondary');
        }
    } catch (err) {
        btnEl.innerHTML = origText;
        btnEl.disabled = false;
    }
}

async function notifyAllLine() {
    if (!confirm('ส่งแจ้งเตือนบิลผ่าน LINE ไปยังผู้เช่าทั้งหมดที่ลงทะเบียนแล้ว?')) return;
    
    const btn = document.getElementById('notifyAllBtn');
    setButtonLoading(btn, true);
    
    try {
        const data = await API.post('/api/bills.php?action=notify_all', { billing_month: '{$selectedMonth}' });
        if (data.success) {
            Toast.success(data.message);
            setTimeout(() => location.reload(), 1500);
        }
    } catch (err) {}
    finally { setButtonLoading(btn, false); }
}
</script>
JS;

include dirname(__DIR__) . '/components/footer.php';
?>

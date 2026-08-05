<?php
/**
 * Payment Verification — Review and approve/reject payment slips
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();

$pageTitle = 'ตรวจสอบการชำระเงิน';
$db = getDB();

// Get pending payments
$payments = $db->query("
    SELECT p.*, 
           i.invoice_number, i.total_amount as invoice_total, i.billing_month, i.due_date,
           r.room_number,
           b.name as building_name,
           CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name,
           t.phone as tenant_phone,
           ps.image_path as slip_image, ps.id as slip_id
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    JOIN rooms r ON i.room_id = r.id
    LEFT JOIN buildings b ON r.building_id = b.id
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN payment_slips ps ON ps.payment_id = p.id
    ORDER BY FIELD(p.status, 'pending', 'approved', 'rejected'), p.created_at DESC
    LIMIT 50
")->fetchAll();

// Count by status
$pendingCount = 0;
foreach ($payments as $p) { if ($p['status'] === 'pending') $pendingCount++; }

include dirname(__DIR__) . '/components/navbar.php';
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
    <div>
        <h1 class="text-3xl font-bold text-on-surface">ตรวจสอบการชำระเงิน</h1>
        <p class="text-on-surface-variant text-sm mt-1">ตรวจสอบสลิปและอนุมัติการชำระเงินจากผู้เช่า</p>
    </div>
    <?php if ($pendingCount > 0): ?>
    <div class="badge badge-warning">
        <?= $pendingCount ?> รายการรอตรวจสอบ
    </div>
    <?php endif; ?>
</div>

<?php if (empty($payments)): ?>
<div class="bg-white border border-outline-variant rounded-xl p-12 text-center shadow-sm">
    <span class="material-symbols-outlined text-[64px] text-outline-variant mb-4 block">fact_check</span>
    <h3 class="text-lg font-semibold text-on-surface mb-1">ไม่มีรายการรอตรวจสอบ</h3>
    <p class="text-sm text-on-surface-variant">เมื่อผู้เช่าส่งสลิปชำระเงิน จะปรากฏที่นี่</p>
</div>
<?php else: ?>
<div class="space-y-6">
    <?php foreach ($payments as $p): ?>
    <div class="bg-white border border-outline-variant rounded-xl overflow-hidden shadow-sm" id="payment-<?= $p['id'] ?>">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-0">
            <!-- Left: Bill Info -->
            <div class="p-6 border-r border-outline-variant">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <span class="badge badge-info mb-2 inline-block">ห้อง <?= sanitize($p['room_number']) ?></span>
                        <h2 class="text-xl font-semibold text-on-surface"><?= sanitize($p['tenant_name']) ?></h2>
                        <p class="text-xs text-on-surface-variant"><?= sanitize($p['tenant_phone']) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-on-surface-variant">ครบกำหนด</p>
                        <p class="text-sm font-bold"><?= formatDate($p['due_date'], 'd M Y') ?></p>
                    </div>
                </div>

                <div class="border-t border-outline-variant pt-4 mt-4">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-on-surface-variant">เลขที่บิล</span>
                        <span class="text-sm font-medium"><?= sanitize($p['invoice_number']) ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-on-surface-variant">ยอดชำระ</span>
                        <span class="text-sm font-medium"><?= formatCurrency($p['amount']) ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm text-on-surface-variant">ยอดในบิล</span>
                        <span class="text-sm font-medium"><?= formatCurrency($p['invoice_total']) ?></span>
                    </div>
                    <?php if ($p['amount'] != $p['invoice_total']): ?>
                    <div class="p-2 rounded-lg bg-orange-50 text-orange-700 text-xs mt-2 flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">warning</span>
                        ยอดชำระไม่ตรงกับยอดในบิล
                    </div>
                    <?php endif; ?>
                </div>

                <div class="mt-6 pt-4 border-t-2 border-dashed border-outline-variant flex justify-between items-end">
                    <div>
                        <p class="text-xs text-on-surface-variant">ยอดรวม</p>
                        <p class="text-3xl font-bold text-primary"><?= formatCurrency($p['invoice_total']) ?></p>
                    </div>
                    <?= statusBadge($p['status']) ?>
                </div>

                <?php if ($p['status'] === 'pending'): ?>
                <div class="mt-6 flex flex-col sm:flex-row gap-3">
                    <button onclick="approvePayment(<?= $p['id'] ?>)" class="btn btn-success flex-1">
                        <span class="material-symbols-outlined text-[18px]">check_circle</span>
                        อนุมัติ
                    </button>
                    <button onclick="toggleRejectForm(<?= $p['id'] ?>)" class="btn btn-danger flex-1">
                        <span class="material-symbols-outlined text-[18px]">cancel</span>
                        ปฏิเสธ
                    </button>
                </div>
                <!-- Reject Form -->
                <div id="rejectForm-<?= $p['id'] ?>" class="hidden mt-4 bg-white border border-error rounded-xl p-4">
                    <label class="form-label">เหตุผลที่ปฏิเสธ</label>
                    <textarea id="rejectReason-<?= $p['id'] ?>" class="form-input" placeholder="เช่น ภาพไม่ชัด, ยอดเงินไม่ตรง..."></textarea>
                    <div class="mt-3 flex justify-end gap-3">
                        <button onclick="toggleRejectForm(<?= $p['id'] ?>)" class="btn btn-ghost btn-sm">ยกเลิก</button>
                        <button onclick="rejectPayment(<?= $p['id'] ?>)" class="btn btn-danger btn-sm">ยืนยันปฏิเสธ</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: Slip Image -->
            <div class="p-4 bg-surface-container-low flex items-center justify-center min-h-[300px]">
                <?php if ($p['slip_image']): ?>
                <div class="relative group cursor-zoom-in">
                    <img src="<?= APP_URL . '/' . sanitize($p['slip_image']) ?>" 
                         alt="Payment slip" 
                         class="max-h-[400px] w-auto rounded-lg border border-outline-variant transition-transform group-hover:scale-105"
                         onclick="this.classList.toggle('scale-150')">
                    <div class="absolute top-2 left-2 flex gap-1">
                        <button class="bg-white/80 backdrop-blur p-1.5 rounded-full shadow-sm hover:bg-white">
                            <span class="material-symbols-outlined text-[18px]">zoom_in</span>
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-center text-on-surface-variant">
                    <span class="material-symbols-outlined text-[48px] text-outline-variant block mb-2">image_not_supported</span>
                    <p class="text-sm">ยังไม่มีรูปสลิป</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$extraScripts = <<<'JS'
<script>
function toggleRejectForm(id) {
    const form = document.getElementById('rejectForm-' + id);
    form.classList.toggle('hidden');
}

async function approvePayment(id) {
    if (!confirm('ยืนยันอนุมัติการชำระเงินนี้?')) return;
    try {
        const data = await API.post('/api/payments.php?action=approve', { payment_id: id });
        if (data.success) {
            Toast.success('อนุมัติการชำระเงินเรียบร้อย');
            setTimeout(() => location.reload(), 800);
        }
    } catch (err) {}
}

async function rejectPayment(id) {
    const reason = document.getElementById('rejectReason-' + id).value.trim();
    if (!reason) { Toast.warning('กรุณาระบุเหตุผล'); return; }
    
    try {
        const data = await API.post('/api/payments.php?action=reject', { payment_id: id, reason: reason });
        if (data.success) {
            Toast.success('ปฏิเสธการชำระเงินเรียบร้อย');
            setTimeout(() => location.reload(), 800);
        }
    } catch (err) {}
}
</script>
JS;

include dirname(__DIR__) . '/components/footer.php';
?>

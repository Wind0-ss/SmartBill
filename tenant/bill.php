<?php
/**
 * Tenant Bill View — Public page for tenants to view bill and upload slip
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$roomNumber = $_GET['room'] ?? '';
$db = getDB();

if (empty($roomNumber)) {
    header('Location: ' . APP_URL . '/');
    exit;
}

// Find room
$roomStmt = $db->prepare("
    SELECT r.*, b.name as building_name,
           CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name,
           t.phone as tenant_phone, t.id as tenant_id
    FROM rooms r
    LEFT JOIN buildings b ON r.building_id = b.id
    LEFT JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
    WHERE r.room_number = ?
");
$roomStmt->execute([$roomNumber]);
$room = $roomStmt->fetch();

if (!$room) {
    header('Location: ' . APP_URL . '/?error=notfound');
    exit;
}

// Find latest billing month for this room
$latestMonthStmt = $db->prepare("
    SELECT billing_month FROM invoices 
    WHERE room_id = ? AND status != 'cancelled' 
    ORDER BY billing_month DESC LIMIT 1
");
$latestMonthStmt->execute([$room['id']]);
$latestMonth = $latestMonthStmt->fetchColumn();

// Fetch both invoices for this month
$invoices = [];
if ($latestMonth) {
    $invStmt = $db->prepare("
        SELECT * FROM invoices 
        WHERE room_id = ? AND billing_month = ? AND status != 'cancelled'
        ORDER BY FIELD(invoice_type, 'rent', 'utility')
    ");
    $invStmt->execute([$room['id'], $latestMonth]);
    $invoices = $invStmt->fetchAll();
}

$currentInvoice = null;
$activeTab = $_GET['tab'] ?? 'rent';

if (!empty($invoices)) {
    foreach ($invoices as $inv) {
        if ($inv['invoice_type'] === $activeTab) {
            $currentInvoice = $inv;
            break;
        }
    }
    if (!$currentInvoice) {
        $currentInvoice = $invoices[0];
        $activeTab = $currentInvoice['invoice_type'];
    }
}

// Prepare data for current invoice
$invoiceId = $currentInvoice ? $currentInvoice['id'] : null;
$invoiceNumber = $currentInvoice ? $currentInvoice['invoice_number'] : '';
$billingMonth = $currentInvoice ? $currentInvoice['billing_month'] : $latestMonth;
$totalAmount = $currentInvoice ? $currentInvoice['total_amount'] : 0;
$dueDate = $currentInvoice ? $currentInvoice['due_date'] : null;
$billStatus = $currentInvoice ? $currentInvoice['status'] : 'draft';

// Get invoice items for current invoice
$items = [];
if ($invoiceId) {
    $itemStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order");
    $itemStmt->execute([$invoiceId]);
    $items = $itemStmt->fetchAll();
}

// Get meter reading images for this month
$meterImages = [];
if ($billingMonth) {
    $meterStmt = $db->prepare("
        SELECT mr.image_path, mr.consumption, m.meter_type
        FROM meter_readings mr
        JOIN meters m ON mr.meter_id = m.id
        WHERE mr.room_id = ? AND mr.billing_month = ?
    ");
    $meterStmt->execute([$room['id'], $billingMonth]);
    $meterImages = $meterStmt->fetchAll();
}

// Get history for chart (last 5 months)
$history = $db->prepare("
    SELECT mr.billing_month, m.meter_type, mr.consumption
    FROM meter_readings mr
    JOIN meters m ON mr.meter_id = m.id
    WHERE mr.room_id = ?
    ORDER BY mr.billing_month DESC
    LIMIT 10
");
$history->execute([$room['id']]);
$historyData = $history->fetchAll();

$electricHistory = [];
$waterHistory = [];
$chartLabels = [];
foreach (array_reverse($historyData) as $h) {
    $monthLabel = date('M', strtotime($h['billing_month']));
    if (!in_array($monthLabel, $chartLabels)) $chartLabels[] = $monthLabel;
    if ($h['meter_type'] === 'electric') $electricHistory[$monthLabel] = $h['consumption'];
    if ($h['meter_type'] === 'water') $waterHistory[$monthLabel] = $h['consumption'];
}

$promptpay = getSetting('promptpay_number', '');
$companyPhone = getSetting('company_phone', '02-xxx-xxxx');
$billDueDay = getSetting('bill_due_day', 5);

$statusLabels = [
    'draft' => ['ยังไม่ส่ง', 'bg-gray-50 text-gray-600 border-gray-200'],
    'sent' => ['รอชำระ', 'bg-orange-50 text-orange-700 border-orange-200'],
    'paid' => ['ชำระแล้ว', 'bg-green-50 text-green-700 border-green-200'],
    'overdue' => ['เลยกำหนด', 'bg-red-50 text-red-700 border-red-200'],
];
$statusInfo = $statusLabels[$billStatus] ?? $statusLabels['draft'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Bill — ห้อง <?= sanitize($roomNumber) ?></title>
    <?php include dirname(__DIR__) . '/components/head.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .qr-gradient { background: linear-gradient(135deg, #0058be 0%, #2170e4 100%); }
    </style>
</head>
<body class="bg-surface text-on-surface">

<!-- Header -->
<header class="bg-surface border-b border-outline-variant sticky top-0 z-50">
    <div class="flex justify-between items-center w-full px-4 md:px-12 max-w-[1280px] mx-auto h-16">
        <a href="<?= APP_URL ?>/" class="text-2xl font-extrabold text-primary no-underline">Smart Bill</a>
        <div class="flex items-center gap-3">
            <span class="text-sm font-medium text-on-surface-variant">Tenant Portal</span>
            <span class="material-symbols-outlined text-primary cursor-pointer">help_outline</span>
        </div>
    </div>
</header>

<main class="max-w-[1280px] mx-auto px-4 md:px-12 py-10">
    <!-- Header & Status -->
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-on-surface mb-1">บิลค่าบริการประจำเดือน</h1>
            <div class="flex items-center gap-2">
                <span class="text-xl font-semibold text-primary">ห้อง <?= sanitize($roomNumber) ?></span>
                <span class="text-sm text-on-surface-variant">| รอบเดือน <?= $billingMonth ? formatThaiMonth($billingMonth) : '-' ?></span>
                <span class="text-sm text-on-surface-variant">| เลขที่บิล: <strong class="text-primary"><?= sanitize($invoiceNumber) ?></strong></span>
            </div>
        </div>
        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full border <?= $statusInfo[1] ?>">
            <span class="material-symbols-outlined text-[18px]">pending_actions</span>
            <span class="text-sm font-medium"><?= $statusInfo[0] ?></span>
        </div>
    </div>

    <!-- Bill Type Tabs -->
    <?php if (count($invoices) > 1): ?>
    <div class="flex gap-2 border-b border-outline-variant mb-6 overflow-x-auto pb-px">
        <?php foreach ($invoices as $inv): ?>
            <a href="?room=<?= urlencode($roomNumber) ?>&tab=<?= $inv['invoice_type'] ?>" 
               class="px-5 py-3 font-bold text-sm transition-all border-b-2 -mb-[2px] flex items-center gap-1.5 whitespace-nowrap <?= $activeTab === $inv['invoice_type'] ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-primary' ?>">
               <span class="material-symbols-outlined text-[18px]"><?= $inv['invoice_type'] === 'utility' ? 'bolt' : 'home' ?></span>
               <?= $inv['invoice_type'] === 'utility' ? 'บิลค่าน้ำ/ค่าไฟ' : 'บิลค่าเช่าห้องพัก' ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Left Column -->
        <div class="lg:col-span-8 space-y-6">
            <!-- Bill Table -->
            <div class="bg-white rounded-xl border border-outline-variant p-6 shadow-sm">
                <h3 class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider mb-6">รายการค้างชำระ</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="border-b border-outline-variant">
                            <tr>
                                <th class="py-3 text-sm font-medium text-on-surface-variant">รายการ</th>
                                <th class="py-3 text-sm font-medium text-on-surface-variant text-right">รายละเอียด</th>
                                <th class="py-3 text-sm font-medium text-on-surface-variant text-right">จำนวนเงิน</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/50">
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td class="py-4 text-sm"><?= sanitize($item['description']) ?></td>
                                <td class="py-4 text-sm text-right text-on-surface-variant">
                                    <?php if ($item['quantity'] > 1): ?>
                                    <?= number_format($item['quantity'], 2) ?> × <?= formatCurrency($item['unit_price']) ?>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 text-sm text-right font-medium"><?= number_format($item['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="bg-surface-container-low">
                                <td colspan="2" class="py-4 px-4 text-xl font-semibold text-primary text-right">ยอดชำระสุทธิ</td>
                                <td class="py-4 px-4 text-xl font-semibold text-primary text-right"><?= formatCurrency($totalAmount ?? 0) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Usage Chart -->
            <?php if (!empty($chartLabels)): ?>
            <div class="bg-white rounded-xl border border-outline-variant p-6 shadow-sm">
                <h3 class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider mb-6">สถิติการใช้งานย้อนหลัง</h3>
                <div class="h-64 w-full">
                    <canvas id="usageChart"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <!-- Meter Evidence -->
            <?php if (!empty($meterImages)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($meterImages as $m): ?>
                <div class="bg-white rounded-xl border border-outline-variant p-4 flex flex-col gap-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-bold">
                            มิเตอร์<?= $m['meter_type'] === 'electric' ? 'ไฟ' : 'น้ำ' ?> 
                            (<?= number_format($m['consumption'], 0) ?> หน่วย)
                        </span>
                    </div>
                    <?php if ($m['image_path']): ?>
                    <div class="rounded-lg overflow-hidden h-40 bg-surface-container">
                        <img src="<?= APP_URL . '/' . sanitize($m['image_path']) ?>" class="w-full h-full object-cover" alt="Meter">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Payment -->
        <div class="lg:col-span-4 space-y-6">
            <!-- QR Payment -->
            <div class="bg-white rounded-xl border border-outline-variant shadow-lg overflow-hidden">
                <div class="qr-gradient p-6 text-white text-center">
                    <p class="text-xs font-semibold opacity-90">Scan to pay via any Banking App</p>
                </div>
                <div class="p-8 flex flex-col items-center">
                    <div class="w-48 h-48 bg-white border-2 border-surface-container rounded-xl p-4 mb-4 flex items-center justify-center">
                        <div class="w-full h-full bg-on-surface opacity-10 flex items-center justify-center rounded-lg">
                            <span class="material-symbols-outlined text-[64px]">qr_code_2</span>
                        </div>
                    </div>
                    <p class="text-2xl font-bold text-on-surface"><?= formatCurrency($totalAmount ?? 0) ?></p>
                    <p class="text-xs text-on-surface-variant mt-1">
                        Ref: ห้อง <?= sanitize($roomNumber) ?> — <?= $billingMonth ? date('M Y', strtotime($billingMonth)) : '-' ?>
                    </p>
                </div>
            </div>

            <!-- Upload Slip -->
            <?php if ($invoiceId && $billStatus !== 'paid'): ?>
            <form id="slipForm" enctype="multipart/form-data" class="bg-surface-container-low rounded-xl border-2 border-dashed border-outline-variant p-6 text-center">
                <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
                <input type="hidden" name="room_number" value="<?= sanitize($roomNumber) ?>">
                <span class="material-symbols-outlined text-[48px] text-outline mb-3 block">cloud_upload</span>
                <h4 class="text-sm font-semibold text-on-surface mb-1">แจ้งชำระเงิน</h4>
                <p class="text-xs text-on-surface-variant mb-4">อัปโหลดภาพสลิปเพื่อยืนยันการโอน</p>
                <input type="file" name="slip" accept="image/*" required
                       class="block w-full text-sm text-on-surface-variant file:mr-3 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-on-primary hover:file:bg-primary-container cursor-pointer">
                <button type="submit" class="mt-4 w-full btn btn-primary">
                    ส่งสลิปแจ้งโอน
                </button>
            </form>
            <?php endif; ?>

            <!-- Notice -->
            <div class="p-5 bg-surface border border-outline-variant rounded-xl">
                <h5 class="text-sm font-bold text-on-surface flex items-center gap-1 mb-2">
                    <span class="material-symbols-outlined text-[18px]">info</span> ข้อควรทราบ
                </h5>
                <ul class="text-xs text-on-surface-variant space-y-1 list-disc list-inside">
                    <li>กรุณาชำระเงินภายในวันที่ <?= $billDueDay ?> ของเดือน</li>
                    <li>หลังโอนเงิน ระบบจะอัปเดตสถานะภายใน 24 ชม.</li>
                    <li>ติดต่อผู้ดูแล: <?= sanitize($companyPhone) ?></li>
                </ul>
            </div>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="bg-surface-container-low border-t border-outline-variant mt-10">
    <div class="flex flex-col md:flex-row justify-between items-center w-full py-6 px-4 md:px-12 max-w-[1280px] mx-auto gap-4">
        <span class="text-sm font-bold text-on-surface">Smart Bill</span>
        <p class="text-xs text-on-surface-variant">© <?= date('Y') ?> Smart Bill. Minimalist Property Management.</p>
        <div class="flex gap-6">
            <a href="#" class="text-xs text-on-surface-variant hover:text-primary">Support</a>
            <a href="#" class="text-xs text-on-surface-variant hover:text-primary">Privacy Policy</a>
        </div>
    </div>
</footer>

<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script>
<?php if (!empty($chartLabels)): ?>
// Usage Chart
const ctx = document.getElementById('usageChart')?.getContext('2d');
if (ctx) {
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'ค่าไฟฟ้า (หน่วย)',
                data: <?= json_encode(array_map(fn($l) => $electricHistory[$l] ?? 0, $chartLabels)) ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true, tension: 0.4
            }, {
                label: 'ค่าน้ำ (หน่วย)',
                data: <?= json_encode(array_map(fn($l) => $waterHistory[$l] ?? 0, $chartLabels)) ?>,
                borderColor: '#94a3b8',
                backgroundColor: 'rgba(148, 163, 184, 0.1)',
                fill: true, tension: 0.4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { font: { family: 'Sarabun', size: 12 } } } },
            scales: {
                y: { beginAtZero: true, grid: { borderDash: [5,5], color: '#e2e8f0' } },
                x: { grid: { display: false } }
            }
        }
    });
}
<?php endif; ?>

// Slip upload
document.getElementById('slipForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('button[type="submit"]');
    setButtonLoading(btn, true);

    try {
        const formData = new FormData(form);
        const response = await API.upload('/api/payments.php?action=upload_slip', formData);
        if (response.success) {
            Toast.success(response.message);
            btn.innerHTML = '<span class="material-symbols-outlined text-[18px]">check_circle</span> ส่งสลิปแล้ว!';
            btn.disabled = true;
        }
    } catch (err) {} finally {
        setButtonLoading(btn, false);
    }
});

// Drag-drop for slip upload
const uploadBox = document.querySelector('.border-dashed');
if (uploadBox) {
    uploadBox.addEventListener('dragover', (e) => { e.preventDefault(); uploadBox.classList.add('border-primary', 'bg-primary-fixed/10'); });
    uploadBox.addEventListener('dragleave', () => { uploadBox.classList.remove('border-primary', 'bg-primary-fixed/10'); });
}
</script>
</body>
</html>

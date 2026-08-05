<?php
/**
 * Admin Dashboard — Overview & Statistics
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();

$pageTitle = 'ภาพรวม';
$db = getDB();

// ---- Statistics ----
// Total rooms & occupancy
$roomStats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(status = 'occupied') as occupied,
        SUM(status = 'vacant') as vacant,
        SUM(status = 'maintenance') as maintenance
    FROM rooms
")->fetch();

// Total tenants
$tenantCount = $db->query("SELECT COUNT(*) FROM tenants WHERE is_active = 1")->fetchColumn();

// Current month invoices
$currentMonth = date('Y-m-01');
$invoiceStats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(status = 'paid') as paid,
        SUM(status = 'sent' OR status = 'draft') as pending,
        SUM(status = 'overdue') as overdue,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as collected,
        COALESCE(SUM(total_amount), 0) as total_billed
    FROM invoices 
    WHERE billing_month = '{$currentMonth}'
")->fetch();

$collectedPercent = $invoiceStats['total_billed'] > 0 
    ? round(($invoiceStats['collected'] / $invoiceStats['total_billed']) * 100) 
    : 0;

// Recent room activity (rooms + tenants + aggregated invoice status)
$recentRooms = $db->query("
    SELECT r.id, r.room_number, r.base_rent, r.other_fees, r.status as room_status,
           b.name as building_name,
           CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name,
           t.phone as tenant_phone,
           t.line_user_id,
           SUM(i.total_amount) as bill_total,
           CASE 
               WHEN SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) = COUNT(i.id) AND COUNT(i.id) > 0 THEN 'paid'
               WHEN SUM(CASE WHEN i.status = 'overdue' THEN 1 ELSE 0 END) > 0 THEN 'overdue'
               WHEN COUNT(i.id) > 0 THEN 'sent'
               ELSE NULL 
           END as bill_status
    FROM rooms r
    LEFT JOIN buildings b ON r.building_id = b.id
    LEFT JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
    LEFT JOIN invoices i ON i.room_id = r.id AND i.billing_month = '{$currentMonth}' AND i.status != 'cancelled'
    GROUP BY r.id, r.room_number, r.base_rent, r.other_fees, r.status, b.name, t.first_name, t.last_name, t.phone, t.line_user_id
    ORDER BY r.room_number ASC
    LIMIT 20
")->fetchAll();

// Total consumption this month
$consumption = $db->query("
    SELECT 
        COALESCE(SUM(CASE WHEN m.meter_type = 'electric' THEN mr.consumption ELSE 0 END), 0) as total_electric,
        COALESCE(SUM(CASE WHEN m.meter_type = 'water' THEN mr.consumption ELSE 0 END), 0) as total_water
    FROM meter_readings mr
    JOIN meters m ON mr.meter_id = m.id
    WHERE mr.billing_month = '{$currentMonth}'
")->fetch();

// Recent activity logs
$recentActivity = $db->query("
    SELECT al.*, u.full_name as user_name 
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT 5
")->fetchAll();

include dirname(__DIR__) . '/components/navbar.php';
?>

<!-- Page Header -->
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
    <div>
        <h1 class="text-3xl font-bold text-on-surface">ภาพรวม</h1>
        <p class="text-on-surface-variant text-sm mt-1">สรุปข้อมูลอาคาร ประจำเดือน <?= formatThaiMonth($currentMonth) ?></p>
    </div>
    <div class="flex gap-3">
        <a href="<?= APP_URL ?>/admin/meters.php" class="btn btn-secondary">
            <span class="material-symbols-outlined text-[18px]">speed</span>
            บันทึกมิเตอร์
        </a>
        <a href="<?= APP_URL ?>/admin/bills.php?action=generate" class="btn btn-primary">
            <span class="material-symbols-outlined text-[18px]">receipt_long</span>
            สร้างบิลประจำเดือน
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <!-- Collection Progress -->
    <div class="bg-white border border-outline-variant rounded-xl p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">ยอดเก็บเงินแล้ว</span>
            <span class="material-symbols-outlined text-primary text-[20px]">account_balance_wallet</span>
        </div>
        <p class="text-2xl font-bold text-primary"><?= formatCurrency($invoiceStats['collected']) ?></p>
        <div class="mt-3 h-2 bg-surface-container-high rounded-full overflow-hidden">
            <div class="h-full bg-primary rounded-full transition-all" style="width: <?= $collectedPercent ?>%"></div>
        </div>
        <p class="text-xs text-on-surface-variant mt-1"><?= $collectedPercent ?>% ของยอดทั้งหมด <?= formatCurrency($invoiceStats['total_billed']) ?></p>
    </div>

    <!-- Room Occupancy -->
    <div class="bg-white border border-outline-variant rounded-xl p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">ห้องพักทั้งหมด</span>
            <span class="material-symbols-outlined text-secondary text-[20px]">meeting_room</span>
        </div>
        <p class="text-2xl font-bold text-on-surface"><?= $roomStats['total'] ?> <span class="text-sm font-medium text-on-surface-variant">ห้อง</span></p>
        <div class="flex gap-3 mt-2">
            <span class="inline-flex items-center gap-1 text-xs text-secondary font-medium">
                <span class="w-2 h-2 bg-secondary rounded-full"></span>
                <?= $roomStats['occupied'] ?> มีผู้เช่า
            </span>
            <span class="inline-flex items-center gap-1 text-xs text-on-surface-variant font-medium">
                <span class="w-2 h-2 bg-outline-variant rounded-full"></span>
                <?= $roomStats['vacant'] ?> ว่าง
            </span>
        </div>
    </div>

    <!-- Electricity -->
    <div class="bg-white border border-outline-variant rounded-xl p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">ค่าไฟเดือนนี้</span>
            <span class="material-symbols-outlined text-tertiary text-[20px]">bolt</span>
        </div>
        <p class="text-2xl font-bold text-on-surface"><?= number_format($consumption['total_electric'], 0) ?> <span class="text-sm font-medium text-on-surface-variant">kWh</span></p>
        <p class="text-xs text-on-surface-variant mt-1">≈ <?= formatCurrency($consumption['total_electric'] * getSetting('electric_rate', 7.50)) ?></p>
    </div>

    <!-- Water -->
    <div class="bg-white border border-outline-variant rounded-xl p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">ค่าน้ำเดือนนี้</span>
            <span class="material-symbols-outlined text-primary text-[20px]">water_drop</span>
        </div>
        <p class="text-2xl font-bold text-on-surface"><?= number_format($consumption['total_water'], 0) ?> <span class="text-sm font-medium text-on-surface-variant">m³</span></p>
        <p class="text-xs text-on-surface-variant mt-1">≈ <?= formatCurrency($consumption['total_water'] * getSetting('water_rate', 18.00)) ?></p>
    </div>
</div>

<!-- Room Activity Table -->
<div class="bg-white border border-outline-variant rounded-xl overflow-hidden shadow-sm mb-8">
    <div class="px-6 py-4 border-b border-outline-variant flex justify-between items-center bg-surface-container-low">
        <h3 class="text-lg font-semibold text-on-surface">สถานะห้องพัก</h3>
        <div class="flex gap-2">
            <span class="px-3 py-1 bg-secondary-container/20 text-secondary text-xs font-semibold rounded-full"><?= $roomStats['occupied'] ?> มีผู้เช่า</span>
            <span class="px-3 py-1 bg-outline-variant/30 text-on-surface-variant text-xs font-semibold rounded-full"><?= $roomStats['vacant'] ?> ว่าง</span>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-surface-container-low">
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase tracking-wider border-b border-outline-variant">ห้อง</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase tracking-wider border-b border-outline-variant">ผู้เช่า</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase tracking-wider border-b border-outline-variant">ค่าเช่ารวม</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase tracking-wider border-b border-outline-variant">สถานะบิล</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase tracking-wider border-b border-outline-variant text-center">LINE</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase tracking-wider border-b border-outline-variant text-right">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/50">
                <?php foreach ($recentRooms as $room): ?>
                <tr class="hover:bg-surface-container-low/50 transition-colors">
                    <td class="px-6 py-4 font-bold text-primary"><?= sanitize($room['room_number']) ?></td>
                    <td class="px-6 py-4">
                        <?php if ($room['tenant_name'] && trim($room['tenant_name'])): ?>
                            <div class="text-sm font-medium text-on-surface"><?= sanitize($room['tenant_name']) ?></div>
                            <div class="text-xs text-on-surface-variant"><?= sanitize($room['tenant_phone'] ?? '-') ?></div>
                        <?php else: ?>
                            <span class="text-sm text-on-surface-variant">ว่าง</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm font-medium">
                        <?= $room['bill_total'] ? formatCurrency($room['bill_total']) : formatCurrency($room['base_rent'] + $room['other_fees']) ?>
                    </td>
                    <td class="px-6 py-4">
                        <?php if ($room['bill_status']): ?>
                            <?= statusBadge($room['bill_status']) ?>
                        <?php elseif ($room['room_status'] === 'occupied'): ?>
                            <span class="text-xs text-on-surface-variant">ยังไม่ออกบิล</span>
                        <?php else: ?>
                            <span class="text-xs text-on-surface-variant">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <?php if ($room['line_user_id']): ?>
                            <span class="material-symbols-outlined text-[#06C755]" style="font-variation-settings: 'FILL' 1;">chat_bubble</span>
                        <?php else: ?>
                            <span class="material-symbols-outlined text-outline-variant">chat_bubble</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <a href="<?= APP_URL ?>/admin/rooms.php?edit=<?= $room['id'] ?>" class="p-1.5 text-on-surface-variant hover:text-primary transition-colors inline-block">
                            <span class="material-symbols-outlined text-[18px]">edit</span>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Activity -->
<div class="bg-white border border-outline-variant rounded-xl overflow-hidden shadow-sm">
    <div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
        <h3 class="text-xs font-bold text-outline uppercase tracking-wider">กิจกรรมล่าสุด</h3>
    </div>
    <div class="divide-y divide-outline-variant/50">
        <?php if (empty($recentActivity)): ?>
        <div class="px-6 py-8 text-center text-sm text-on-surface-variant">
            <span class="material-symbols-outlined text-[32px] text-outline-variant block mb-2">history</span>
            ยังไม่มีกิจกรรม
        </div>
        <?php else: ?>
        <?php foreach ($recentActivity as $log): ?>
        <div class="px-6 py-3 flex justify-between items-center hover:bg-surface-container-lowest transition-colors">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-outline text-[20px]">update</span>
                <div>
                    <p class="text-sm text-on-surface"><?= sanitize($log['action']) ?></p>
                    <p class="text-xs text-outline"><?= sanitize($log['user_name'] ?? 'System') ?></p>
                </div>
            </div>
            <span class="text-xs text-outline"><?= formatDate($log['created_at'], 'd M H:i') ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/components/footer.php'; ?>

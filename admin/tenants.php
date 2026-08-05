<?php
/**
 * Tenant Management
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();

$pageTitle = 'จัดการผู้เช่า';
$db = getDB();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$search = trim($_GET['search'] ?? '');

$where = "WHERE t.is_active = 1";
$params = [];
if ($search) {
    $where .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.phone LIKE ? OR r.room_number LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

$total = $db->prepare("SELECT COUNT(*) FROM tenants t LEFT JOIN rooms r ON r.id = t.room_id $where");
$total->execute($params);
$pager = paginate($total->fetchColumn(), $perPage, $page);

$stmt = $db->prepare("
    SELECT t.*, r.room_number, b.name as building_name,
           l.start_date, l.end_date, l.deposit_amount, l.status as lease_status
    FROM tenants t
    LEFT JOIN rooms r ON t.room_id = r.id
    LEFT JOIN buildings b ON r.building_id = b.id
    LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'active'
    $where
    ORDER BY t.created_at DESC
    LIMIT {$pager['per_page']} OFFSET {$pager['offset']}
");
$stmt->execute($params);
$tenants = $stmt->fetchAll();

include dirname(__DIR__) . '/components/navbar.php';
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
    <div>
        <h1 class="text-3xl font-bold text-on-surface">จัดการผู้เช่า</h1>
        <p class="text-on-surface-variant text-sm mt-1">รายชื่อผู้เช่าทั้งหมดในระบบ</p>
    </div>
    <form class="flex gap-2" method="GET">
        <input type="text" name="search" value="<?= sanitize($search) ?>" class="form-input w-64 text-sm" placeholder="ค้นหาชื่อ, เบอร์โทร, เลขห้อง...">
        <button type="submit" class="btn btn-secondary">
            <span class="material-symbols-outlined text-[18px]">search</span>
        </button>
    </form>
</div>

<div class="bg-white border border-outline-variant rounded-xl overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-surface-container-low">
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ชื่อ-นามสกุล</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ห้อง</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">เบอร์โทร</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">สัญญาเช่า</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">เงินประกัน</th>
                    <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">สถานะ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/50">
                <?php if (empty($tenants)): ?>
                <tr><td colspan="6" class="px-6 py-12 text-center text-on-surface-variant">
                    <span class="material-symbols-outlined text-[48px] text-outline-variant block mb-2">group</span>
                    <?= $search ? 'ไม่พบผู้เช่าที่ค้นหา' : 'ยังไม่มีข้อมูลผู้เช่า' ?>
                </td></tr>
                <?php else: ?>
                <?php foreach ($tenants as $t): ?>
                <tr class="hover:bg-surface-container-low/50 transition-colors">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-full bg-primary-fixed flex items-center justify-center text-primary font-bold text-sm">
                                <?= mb_substr($t['first_name'], 0, 1) ?>
                            </div>
                            <div>
                                <div class="text-sm font-medium"><?= sanitize($t['first_name'] . ' ' . ($t['last_name'] ?? '')) ?></div>
                                <?php if ($t['id_card']): ?>
                                <div class="text-xs text-on-surface-variant"><?= sanitize($t['id_card']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <?php if ($t['room_number']): ?>
                        <span class="font-bold text-primary"><?= sanitize($t['room_number']) ?></span>
                        <span class="text-xs text-on-surface-variant block"><?= sanitize($t['building_name'] ?? '') ?></span>
                        <?php else: ?>
                        <span class="text-on-surface-variant">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm"><?= sanitize($t['phone'] ?? '-') ?></td>
                    <td class="px-6 py-4 text-xs text-on-surface-variant">
                        <?php if ($t['start_date']): ?>
                        <?= formatDate($t['start_date']) ?> — <?= formatDate($t['end_date']) ?>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm"><?= $t['deposit_amount'] ? formatCurrency($t['deposit_amount']) : '-' ?></td>
                    <td class="px-6 py-4">
                        <?= statusBadge($t['lease_status'] ?? 'active') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pager['total_pages'] > 1): ?>
    <div class="px-6 py-3 border-t border-outline-variant flex justify-center bg-surface-container-low">
        <nav class="flex gap-1">
            <?php for ($p = 1; $p <= $pager['total_pages']; $p++): ?>
            <a href="?page=<?= $p ?><?= $search ? '&search=' . urlencode($search) : '' ?>" 
               class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-medium <?= $p == $pager['current_page'] ? 'bg-primary text-on-primary' : 'hover:bg-surface-variant' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/components/footer.php'; ?>

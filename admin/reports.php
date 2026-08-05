<?php
/**
 * Reports Page — Summary reports
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();

$pageTitle = 'รายงาน';
$db = getDB();

// Monthly summary for last 6 months
$monthlyData = $db->query("
    SELECT billing_month,
           COUNT(*) as total_invoices,
           SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
           COALESCE(SUM(total_amount), 0) as total_billed,
           COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as collected
    FROM invoices
    WHERE billing_month >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 6 MONTH), '%Y-%m-01')
    GROUP BY billing_month
    ORDER BY billing_month
")->fetchAll();

$chartLabels = [];
$chartBilled = [];
$chartCollected = [];
foreach ($monthlyData as $m) {
    $chartLabels[] = date('M Y', strtotime($m['billing_month']));
    $chartBilled[] = $m['total_billed'];
    $chartCollected[] = $m['collected'];
}

// Room utilization
$utilization = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(status = 'occupied') as occupied,
        SUM(status = 'vacant') as vacant,
        SUM(status = 'maintenance') as maintenance,
        SUM(status = 'reserved') as reserved
    FROM rooms
")->fetch();

$occupancyRate = $utilization['total'] > 0 ? round(($utilization['occupied'] / $utilization['total']) * 100) : 0;

include dirname(__DIR__) . '/components/navbar.php';
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-on-surface">รายงาน</h1>
    <p class="text-on-surface-variant text-sm mt-1">สรุปภาพรวมรายได้และสถิติการใช้งาน</p>
</div>

<!-- Revenue Chart -->
<div class="bg-white border border-outline-variant rounded-xl p-6 shadow-sm mb-8">
    <h3 class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider mb-6">รายได้ย้อนหลัง 6 เดือน</h3>
    <div class="h-72">
        <canvas id="revenueChart"></canvas>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
    <!-- Occupancy -->
    <div class="bg-white border border-outline-variant rounded-xl p-6 shadow-sm">
        <h3 class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider mb-6">อัตราการเข้าพัก</h3>
        <div class="flex items-center gap-8">
            <div class="relative w-32 h-32">
                <canvas id="occupancyChart"></canvas>
                <div class="absolute inset-0 flex items-center justify-center">
                    <span class="text-2xl font-bold text-on-surface"><?= $occupancyRate ?>%</span>
                </div>
            </div>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 bg-primary rounded-full"></span>
                    <span class="text-sm">มีผู้เช่า: <?= $utilization['occupied'] ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 bg-outline-variant rounded-full"></span>
                    <span class="text-sm">ว่าง: <?= $utilization['vacant'] ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 bg-tertiary rounded-full"></span>
                    <span class="text-sm">ซ่อมบำรุง: <?= $utilization['maintenance'] ?? 0 ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Summary Table -->
    <div class="bg-white border border-outline-variant rounded-xl p-6 shadow-sm">
        <h3 class="text-xs font-semibold text-on-surface-variant uppercase tracking-wider mb-4">สรุปรายเดือน</h3>
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b border-outline-variant">
                <th class="py-2 text-xs font-semibold text-on-surface-variant">เดือน</th>
                <th class="py-2 text-xs font-semibold text-on-surface-variant text-right">ยอดรวม</th>
                <th class="py-2 text-xs font-semibold text-on-surface-variant text-right">เก็บได้</th>
                <th class="py-2 text-xs font-semibold text-on-surface-variant text-right">อัตรา</th>
            </tr></thead>
            <tbody class="divide-y divide-outline-variant/50">
                <?php foreach (array_reverse($monthlyData) as $m): 
                    $rate = $m['total_billed'] > 0 ? round(($m['collected'] / $m['total_billed']) * 100) : 0;
                ?>
                <tr>
                    <td class="py-2"><?= date('M Y', strtotime($m['billing_month'])) ?></td>
                    <td class="py-2 text-right"><?= formatCurrency($m['total_billed']) ?></td>
                    <td class="py-2 text-right text-secondary font-medium"><?= formatCurrency($m['collected']) ?></td>
                    <td class="py-2 text-right <?= $rate >= 80 ? 'text-secondary' : ($rate >= 50 ? 'text-tertiary' : 'text-error') ?> font-medium"><?= $rate ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php
$labelsJson = json_encode($chartLabels);
$billedJson = json_encode($chartBilled);
$collectedJson = json_encode($chartCollected);
$extraScripts = <<<JS
<script>
// Revenue Chart
new Chart(document.getElementById('revenueChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: {$labelsJson},
        datasets: [{
            label: 'ยอดรวม', data: {$billedJson},
            backgroundColor: 'rgba(0, 88, 190, 0.15)', borderColor: '#0058be', borderWidth: 1
        }, {
            label: 'เก็บได้', data: {$collectedJson},
            backgroundColor: 'rgba(0, 108, 73, 0.2)', borderColor: '#006c49', borderWidth: 1
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, grid: { borderDash: [5,5] } }, x: { grid: { display: false } } }
    }
});

// Occupancy Doughnut
new Chart(document.getElementById('occupancyChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['มีผู้เช่า', 'ว่าง', 'ซ่อมบำรุง'],
        datasets: [{ data: [{$utilization['occupied']}, {$utilization['vacant']}, {$utilization['maintenance']}], backgroundColor: ['#0058be', '#c2c6d6', '#924700'], borderWidth: 0 }]
    },
    options: { cutout: '70%', responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } } }
});
</script>
JS;

include dirname(__DIR__) . '/components/footer.php';
?>

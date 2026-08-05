<?php
/**
 * Building Management
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();

$pageTitle = 'จัดการอาคาร';
$db = getDB();

$buildings = $db->query("
    SELECT b.*, 
           COUNT(DISTINCT r.id) as total_rooms,
           SUM(r.status = 'occupied') as occupied_rooms,
           SUM(r.status = 'vacant') as vacant_rooms
    FROM buildings b
    LEFT JOIN rooms r ON r.building_id = b.id
    WHERE b.is_active = 1
    GROUP BY b.id
    ORDER BY b.name
")->fetchAll();

include dirname(__DIR__) . '/components/navbar.php';
?>

<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
    <div>
        <h1 class="text-3xl font-bold text-on-surface">จัดการอาคาร</h1>
        <p class="text-on-surface-variant text-sm mt-1">เพิ่ม แก้ไข และจัดการอาคารในระบบ</p>
    </div>
    <button onclick="Modal.open('addBuildingModal')" class="btn btn-primary">
        <span class="material-symbols-outlined text-[18px]">add</span>
        เพิ่มอาคาร
    </button>
</div>

<?php if (empty($buildings)): ?>
<div class="bg-white border border-outline-variant rounded-xl p-12 text-center shadow-sm">
    <span class="material-symbols-outlined text-[64px] text-outline-variant mb-4 block">apartment</span>
    <h3 class="text-lg font-semibold mb-1">ยังไม่มีอาคารในระบบ</h3>
    <p class="text-sm text-on-surface-variant mb-4">เริ่มต้นโดยเพิ่มอาคารหลังแรก</p>
    <button onclick="Modal.open('addBuildingModal')" class="btn btn-primary">เพิ่มอาคาร</button>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($buildings as $b): ?>
    <div class="bg-white border border-outline-variant rounded-xl p-6 shadow-sm hover:shadow-md transition-shadow">
        <div class="flex justify-between items-start mb-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-primary-fixed rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined text-primary text-[24px]">apartment</span>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-on-surface"><?= sanitize($b['name']) ?></h3>
                    <p class="text-xs text-on-surface-variant"><?= $b['total_floors'] ?> ชั้น</p>
                </div>
            </div>
            <div class="flex gap-1">
                <button onclick="editBuilding(<?= htmlspecialchars(json_encode($b)) ?>)" class="p-1.5 text-on-surface-variant hover:text-primary rounded-lg hover:bg-surface-container-low transition-colors">
                    <span class="material-symbols-outlined text-[18px]">edit</span>
                </button>
            </div>
        </div>
        
        <?php if ($b['address']): ?>
        <p class="text-xs text-on-surface-variant mb-4 flex items-start gap-1">
            <span class="material-symbols-outlined text-[14px] mt-0.5">location_on</span>
            <?= sanitize($b['address']) ?>
        </p>
        <?php endif; ?>
        
        <div class="flex gap-3 pt-4 border-t border-outline-variant">
            <div class="flex-1 text-center">
                <p class="text-xl font-bold text-on-surface"><?= $b['total_rooms'] ?></p>
                <p class="text-xs text-on-surface-variant">ห้องทั้งหมด</p>
            </div>
            <div class="flex-1 text-center">
                <p class="text-xl font-bold text-secondary"><?= $b['occupied_rooms'] ?? 0 ?></p>
                <p class="text-xs text-on-surface-variant">มีผู้เช่า</p>
            </div>
            <div class="flex-1 text-center">
                <p class="text-xl font-bold text-outline"><?= $b['vacant_rooms'] ?? 0 ?></p>
                <p class="text-xs text-on-surface-variant">ว่าง</p>
            </div>
        </div>

        <a href="<?= APP_URL ?>/admin/rooms.php?building_id=<?= $b['id'] ?>" class="btn btn-secondary btn-block mt-4 text-sm">
            ดูห้องพักทั้งหมด
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add Building Modal -->
<div class="modal-overlay" id="addBuildingModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="text-xl font-semibold">เพิ่มอาคารใหม่</h3>
            <button onclick="Modal.close('addBuildingModal')" class="text-on-surface-variant hover:text-on-surface">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form id="addBuildingForm" class="modal-body space-y-4">
            <div>
                <label class="form-label">ชื่ออาคาร *</label>
                <input type="text" name="name" class="form-input" placeholder="เช่น Building A" required>
            </div>
            <div>
                <label class="form-label">ที่อยู่</label>
                <textarea name="address" class="form-input" rows="2" placeholder="ที่อยู่อาคาร"></textarea>
            </div>
            <div>
                <label class="form-label">จำนวนชั้น</label>
                <input type="number" name="total_floors" class="form-input" value="1" min="1" max="50">
            </div>
        </form>
        <div class="modal-footer">
            <button onclick="Modal.close('addBuildingModal')" class="btn btn-ghost">ยกเลิก</button>
            <button onclick="saveBuilding()" class="btn btn-primary">บันทึก</button>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
async function saveBuilding() {
    const form = document.getElementById('addBuildingForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    if (!data.name.trim()) { Toast.warning('กรุณากรอกชื่ออาคาร'); return; }
    
    try {
        const response = await API.post('/api/buildings.php?action=create', data);
        if (response.success) {
            Toast.success('เพิ่มอาคารสำเร็จ');
            setTimeout(() => location.reload(), 800);
        }
    } catch (err) {}
}

function editBuilding(b) {
    // Simple prompt-based edit for now
    const newName = prompt('แก้ไขชื่ออาคาร:', b.name);
    if (newName && newName !== b.name) {
        API.post('/api/buildings.php?action=update', { id: b.id, name: newName })
           .then(r => { if(r.success) { Toast.success('อัปเดตสำเร็จ'); location.reload(); } });
    }
}
</script>
JS;

include dirname(__DIR__) . '/components/footer.php';
?>

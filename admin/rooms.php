<?php
/**
 * Room Management — CRUD + Tenant Assignment
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();

$pageTitle = 'จัดการห้องพัก';
$db = getDB();

// Get all buildings for dropdown
$buildings = $db->query("SELECT id, name FROM buildings WHERE is_active = 1 ORDER BY name")->fetchAll();
$selectedBuilding = $_GET['building_id'] ?? ($buildings[0]['id'] ?? 1);

// Get rooms with tenant info
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$countStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE building_id = ?");
$countStmt->execute([$selectedBuilding]);
$totalRooms = $countStmt->fetchColumn();
$pager = paginate($totalRooms, $perPage, $page);

$stmt = $db->prepare("
    SELECT r.*, b.name as building_name,
           CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name,
           t.phone as tenant_phone, t.line_user_id, t.id as tenant_id
    FROM rooms r
    LEFT JOIN buildings b ON r.building_id = b.id
    LEFT JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
    WHERE r.building_id = ?
    ORDER BY r.room_number ASC
    LIMIT ? OFFSET ?
");
$stmt->execute([$selectedBuilding, $pager['per_page'], $pager['offset']]);
$rooms = $stmt->fetchAll();

// Room stats
$statsStmt = $db->prepare("
    SELECT 
        SUM(status = 'occupied') as occupied,
        SUM(status = 'vacant') as vacant
    FROM rooms WHERE building_id = ?
");
$statsStmt->execute([$selectedBuilding]);
$roomStats = $statsStmt->fetch();

// Get floors for the selected building
$floors = $db->prepare("SELECT * FROM floors WHERE building_id = ? ORDER BY floor_number");
$floors->execute([$selectedBuilding]);
$floorList = $floors->fetchAll();

include dirname(__DIR__) . '/components/navbar.php';
?>

<div class="flex flex-col lg:flex-row items-start gap-6">
    <!-- Left Column: Add Room Form -->
    <aside class="w-full lg:w-1/3">
        <div class="bg-white border border-outline-variant rounded-xl p-6 shadow-sm">
            <h2 class="text-xl font-semibold text-on-surface mb-1">เพิ่มห้องพักใหม่</h2>
            <p class="text-sm text-on-surface-variant mb-6">ลงทะเบียนผู้เช่าและกำหนดค่าเช่า</p>
            
            <?php if (empty($buildings)): ?>
            <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 text-center">
                <span class="material-symbols-outlined text-orange-600 text-[32px] mb-1">apartment</span>
                <p class="text-sm font-semibold text-orange-800">ยังไม่มีข้อมูลอาคารในระบบ</p>
                <p class="text-xs text-orange-600 mt-1 mb-3">กรุณาเพิ่มอาคารก่อนลงทะเบียนห้องพัก</p>
                <a href="<?= APP_URL ?>/admin/buildings.php" class="btn btn-secondary text-xs inline-flex items-center gap-1.5 py-1.5 px-3 bg-orange-600 hover:bg-orange-700 text-white border-none rounded-lg">
                    <span class="material-symbols-outlined text-[16px]">add</span>
                    เพิ่มอาคารใหม่
                </a>
            </div>
            <?php else: ?>
            <form id="addRoomForm" class="space-y-4">
                <input type="hidden" name="building_id" value="<?= $selectedBuilding ?>">
                
                <div>
                    <label class="form-label">เลขห้อง *</label>
                    <input type="text" name="room_number" class="form-input" placeholder="เช่น 101" required>
                </div>

                <div>
                    <label class="form-label">ชื่อผู้เช่า</label>
                    <input type="text" name="tenant_name" class="form-input" placeholder="เช่น สมชาย รักดี">
                </div>
                
                <div>
                    <label class="form-label">เบอร์โทร</label>
                    <input type="tel" name="tenant_phone" class="form-input" placeholder="08X-XXX-XXXX">
                </div>

                <div>
                    <label class="form-label">ค่าเช่า (฿) *</label>
                    <input type="number" name="base_rent" class="form-input" placeholder="0.00" step="0.01" required>
                </div>

                <button type="submit" class="btn btn-dark btn-block mt-2">
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    บันทึกห้อง
                </button>
            </form>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Right Column: Room Table -->
    <section class="w-full lg:w-2/3">
        <div class="bg-white border border-outline-variant rounded-xl overflow-hidden shadow-sm">
            <div class="px-6 py-4 border-b border-outline-variant flex justify-between items-center bg-surface-container-low">
                <h3 class="text-lg font-semibold text-on-surface">จัดการห้องพัก</h3>
                <div class="flex gap-2">
                    <span class="px-3 py-1 bg-secondary-container/20 text-secondary text-xs font-semibold rounded-full">
                        <?= $roomStats['occupied'] ?? 0 ?> มีผู้เช่า
                    </span>
                    <span class="px-3 py-1 bg-outline-variant/30 text-on-surface-variant text-xs font-semibold rounded-full">
                        <?= $roomStats['vacant'] ?? 0 ?> ว่าง
                    </span>
                </div>
            </div>

            <!-- Building Filter -->
            <?php if (count($buildings) > 1): ?>
            <div class="px-6 py-3 bg-surface-container-lowest border-b border-outline-variant">
                <div class="flex gap-2 flex-wrap">
                    <?php foreach ($buildings as $bldg): ?>
                    <a href="?building_id=<?= $bldg['id'] ?>" 
                       class="px-4 py-1.5 rounded-full text-xs font-medium transition-colors <?= $selectedBuilding == $bldg['id'] ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>">
                        <?= sanitize($bldg['name']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-surface-container-low">
                            <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ห้อง</th>
                            <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ผู้เช่า & เบอร์โทร</th>
                            <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant">ค่าเช่า</th>
                            <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant text-center">LINE</th>
                            <th class="px-6 py-3 text-xs font-semibold text-on-surface-variant uppercase border-b border-outline-variant text-right">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/50">
                        <?php if (empty($rooms)): ?>
                        <tr><td colspan="5" class="px-6 py-12 text-center text-on-surface-variant">
                            <span class="material-symbols-outlined text-[48px] text-outline-variant block mb-2">meeting_room</span>
                            ยังไม่มีข้อมูลห้องพัก
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($rooms as $room): ?>
                        <tr class="hover:bg-surface-container-low/50 transition-colors" id="room-row-<?= $room['id'] ?>">
                            <td class="px-6 py-4 font-bold text-primary"><?= sanitize($room['room_number']) ?></td>
                            <td class="px-6 py-4">
                                <?php if ($room['tenant_name'] && trim($room['tenant_name'])): ?>
                                <div class="text-sm font-medium text-on-surface"><?= sanitize($room['tenant_name']) ?></div>
                                <div class="text-xs text-on-surface-variant"><?= sanitize($room['tenant_phone'] ?? '-') ?></div>
                                <?php else: ?>
                                <span class="text-sm text-on-surface-variant">ว่าง</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium"><?= formatCurrency($room['base_rent']) ?></td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($room['line_user_id']): ?>
                                <span class="material-symbols-outlined text-[#06C755]" style="font-variation-settings: 'FILL' 1;">chat_bubble</span>
                                <?php else: ?>
                                <span class="material-symbols-outlined text-outline-variant">chat_bubble</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button class="p-1.5 text-on-surface-variant hover:text-primary transition-colors"
                                        onclick="openEditModal(<?= htmlspecialchars(json_encode($room)) ?>)">
                                    <span class="material-symbols-outlined text-[18px]">edit</span>
                                </button>
                                <button class="p-1.5 text-error/60 hover:text-error transition-colors ml-1"
                                        onclick="deleteRoom(<?= $room['id'] ?>, '<?= sanitize($room['room_number']) ?>')">
                                    <span class="material-symbols-outlined text-[18px]">delete</span>
                                </button>
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
                    <a href="?building_id=<?= $selectedBuilding ?>&page=<?= $p ?>" 
                       class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-medium transition-colors <?= $p == $pager['current_page'] ? 'bg-primary text-on-primary' : 'hover:bg-surface-variant' ?>">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editRoomModal">
    <div class="modal-content max-w-md">
        <div class="modal-header">
            <h3 class="text-xl font-semibold">แก้ไขห้อง <span class="text-primary" id="editRoomNumber"></span></h3>
            <button onclick="Modal.close('editRoomModal')" class="text-on-surface-variant hover:text-on-surface">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form id="editRoomForm" class="modal-body space-y-4">
            <input type="hidden" name="id" id="editRoomId">
            
            <div>
                <label class="form-label">ชื่อผู้เช่า</label>
                <input type="text" name="tenant_name" id="editTenantName" class="form-input">
            </div>
            <div>
                <label class="form-label">เบอร์โทร</label>
                <input type="tel" name="tenant_phone" id="editTenantPhone" class="form-input">
            </div>
            <div>
                <label class="form-label">ค่าเช่า (฿)</label>
                <input type="number" name="base_rent" id="editBaseRent" class="form-input" step="0.01">
            </div>
        </form>
        <div class="modal-footer">
            <button onclick="Modal.close('editRoomModal')" class="btn btn-ghost">ยกเลิก</button>
            <button onclick="updateRoom()" class="btn btn-primary">อัปเดตห้อง</button>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
// Add Room
const addRoomForm = document.getElementById('addRoomForm');
if (addRoomForm) addRoomForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    if (!form) return;
    const btn = form.querySelector('button[type="submit"]');
    setButtonLoading(btn, true);

    try {
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        const response = await API.post('/api/rooms.php?action=create', data);
        
        if (response.success) {
            Toast.success('เพิ่มห้อง ' + data.room_number + ' สำเร็จ');
            setTimeout(() => location.reload(), 800);
        }
    } catch (err) {
        // Error handled by API helper
    } finally {
        setButtonLoading(btn, false);
    }
});

// Open edit modal
function openEditModal(room) {
    document.getElementById('editRoomId').value = room.id;
    document.getElementById('editRoomNumber').textContent = room.room_number;
    document.getElementById('editTenantName').value = (room.tenant_name || '').trim();
    document.getElementById('editTenantPhone').value = room.tenant_phone || '';
    document.getElementById('editBaseRent').value = room.base_rent;
    Modal.open('editRoomModal');
}

// Update room
async function updateRoom() {
    const form = document.getElementById('editRoomForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    try {
        const response = await API.post('/api/rooms.php?action=update', data);
        if (response.success) {
            Toast.success('อัปเดตห้องสำเร็จ');
            setTimeout(() => location.reload(), 800);
        }
    } catch (err) {}
}

// Delete room
function deleteRoom(id, number) {
    confirmAction('ต้องการลบห้อง ' + number + ' ใช่หรือไม่?', async () => {
        try {
            const response = await API.post('/api/rooms.php?action=delete', { id });
            if (response.success) {
                Toast.success('ลบห้อง ' + number + ' แล้ว');
                document.getElementById('room-row-' + id)?.remove();
            }
        } catch (err) {}
    });
}
</script>
JS;

include dirname(__DIR__) . '/components/footer.php';
?>

<?php
/**
 * Meter Scan & Record — ถ่ายมิเตอร์ + OCR อัตโนมัติ
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();

$pageTitle = 'บันทึกมิเตอร์';
$db = getDB();

// Get rooms
$rooms = $db->query("
    SELECT r.id, r.room_number, r.base_rent, r.other_fees, r.building_id,
           b.name as building_name,
           CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name
    FROM rooms r
    LEFT JOIN buildings b ON r.building_id = b.id
    LEFT JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
    WHERE r.status = 'occupied'
    ORDER BY r.room_number ASC
")->fetchAll();

$waterRate = getSetting('water_rate', 18.00);
$electricRate = getSetting('electric_rate', 7.50);

include dirname(__DIR__) . '/components/navbar.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <h1 class="text-3xl font-bold text-on-surface">📝 บันทึกมิเตอร์น้ำ / ไฟ</h1>
    <p class="text-on-surface-variant text-sm mt-1">เลือกห้อง → กรอกค่ามิเตอร์ล่าสุด → บันทึก</p>
</div>

<!-- Step 1: Room Selection -->
<div class="bg-white border border-outline-variant rounded-xl p-5 mb-6">
    <div class="flex items-center gap-2 mb-3">
        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary text-on-primary text-xs font-bold">1</span>
        <h2 class="text-lg font-bold text-on-surface">เลือกห้องพัก</h2>
    </div>
    <div class="flex flex-col md:flex-row gap-4">
        <div class="flex-1">
            <select id="roomSelect" class="form-input text-base" onchange="onRoomSelected()">
                <option value="">-- เลือกห้อง --</option>
                <?php foreach ($rooms as $room): ?>
                <option value="<?= $room['id'] ?>"
                        data-rent="<?= $room['base_rent'] ?>"
                        data-fees="<?= $room['other_fees'] ?>"
                        data-room="<?= sanitize($room['room_number']) ?>">
                    ห้อง <?= sanitize($room['room_number']) ?> — <?= sanitize($room['building_name']) ?>
                    <?= $room['tenant_name'] ? '(' . sanitize($room['tenant_name']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-full md:w-48">
            <input type="month" id="billingMonth" class="form-input text-base" value="<?= date('Y-m') ?>" onchange="onRoomSelected()">
        </div>
    </div>
</div>

<!-- Step 2: Meter Cards (Hidden until room selected) -->
<div id="meterSection" class="hidden">
    <div class="flex items-center gap-2 mb-4">
        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary text-on-primary text-xs font-bold">2</span>
        <h2 class="text-lg font-bold text-on-surface">กรอกค่ามิเตอร์</h2>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">

        <!-- ===== WATER METER ===== -->
        <div class="bg-white border-2 border-blue-100 rounded-2xl overflow-hidden">
            <!-- Header -->
            <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-5 py-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">water_drop</span>
                    <h3 class="text-lg font-bold">มิเตอร์น้ำ</h3>
                </div>
                <span class="text-blue-100 text-sm">฿<?= number_format($waterRate, 2) ?>/หน่วย</span>
            </div>

            <div class="p-5 space-y-4">
                <!-- Previous Reading -->
                <div class="flex gap-3">
                    <div class="flex-1 p-3 bg-gray-50 rounded-lg text-center">
                        <p class="text-xs text-gray-500 mb-1">ค่าเดือนก่อน</p>
                        <p class="text-lg font-bold text-gray-700" id="waterPrev">0.00</p>
                    </div>
                    <div class="flex-1 p-3 bg-blue-50 rounded-lg text-center">
                        <p class="text-xs text-blue-500 mb-1">ใช้ไป</p>
                        <p class="text-lg font-bold text-blue-600" id="waterUsage">0.00 <small class="text-xs font-normal">m³</small></p>
                    </div>
                </div>

                <!-- Current Reading Input -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">เลขมิเตอร์ปัจจุบัน (m³)</label>
                    <input type="number" id="waterCurrent" step="0.01" placeholder="กรอกตัวเลขที่อ่านได้"
                           class="w-full border-2 border-blue-200 rounded-xl px-4 py-4 text-3xl font-bold text-blue-600 text-center focus:border-blue-500 focus:ring-4 focus:ring-blue-100 outline-none transition-all placeholder:text-gray-300 placeholder:text-lg"
                           oninput="calcMeter('water')">
                </div>

                <!-- Cost -->
                <div class="text-right">
                    <span class="text-sm text-gray-500">ค่าน้ำ: </span>
                    <span class="text-xl font-bold text-blue-600" id="waterCost">฿ 0.00</span>
                </div>
            </div>
        </div>

        <!-- ===== ELECTRIC METER ===== -->
        <div class="bg-white border-2 border-orange-100 rounded-2xl overflow-hidden">
            <!-- Header -->
            <div class="bg-gradient-to-r from-orange-500 to-amber-500 text-white px-5 py-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">bolt</span>
                    <h3 class="text-lg font-bold">มิเตอร์ไฟ</h3>
                </div>
                <span class="text-orange-100 text-sm">฿<?= number_format($electricRate, 2) ?>/หน่วย</span>
            </div>

            <div class="p-5 space-y-4">
                <!-- Previous Reading -->
                <div class="flex gap-3">
                    <div class="flex-1 p-3 bg-gray-50 rounded-lg text-center">
                        <p class="text-xs text-gray-500 mb-1">ค่าเดือนก่อน</p>
                        <p class="text-lg font-bold text-gray-700" id="electricPrev">0.00</p>
                    </div>
                    <div class="flex-1 p-3 bg-orange-50 rounded-lg text-center">
                        <p class="text-xs text-orange-500 mb-1">ใช้ไป</p>
                        <p class="text-lg font-bold text-orange-600" id="electricUsage">0.00 <small class="text-xs font-normal">kWh</small></p>
                    </div>
                </div>

                <!-- Current Reading Input -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">เลขมิเตอร์ปัจจุบัน (kWh)</label>
                    <input type="number" id="electricCurrent" step="0.01" placeholder="กรอกตัวเลขที่อ่านได้"
                           class="w-full border-2 border-orange-200 rounded-xl px-4 py-4 text-3xl font-bold text-orange-600 text-center focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all placeholder:text-gray-300 placeholder:text-lg"
                           oninput="calcMeter('electric')">
                </div>

                <!-- Cost -->
                <div class="text-right">
                    <span class="text-sm text-gray-500">ค่าไฟ: </span>
                    <span class="text-xl font-bold text-orange-600" id="electricCost">฿ 0.00</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 3: Summary & Save -->
    <div class="bg-white border border-outline-variant rounded-2xl p-6 mb-6 shadow-sm">
        <div class="flex items-center gap-2 mb-4">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary text-on-primary text-xs font-bold">3</span>
            <h2 class="text-lg font-bold text-on-surface">สรุปบิลประจำเดือน</h2>
        </div>
        <div class="space-y-3">
            <div class="flex justify-between text-sm"><span class="text-gray-500">ค่าเช่ารายเดือน</span><span class="font-semibold" id="sRent">฿ 0.00</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-500">ค่าบริการอื่นๆ</span><span class="font-semibold" id="sFees">฿ 0.00</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-500">ค่าน้ำประปา</span><span class="font-semibold text-blue-600" id="sWater">฿ 0.00</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-500">ค่าไฟฟ้า</span><span class="font-semibold text-orange-600" id="sElectric">฿ 0.00</span></div>
            <div class="pt-3 mt-3 border-t-2 border-primary/10 flex justify-between items-center">
                <span class="text-lg font-bold">ยอดรวมทั้งหมด</span>
                <span class="text-3xl font-extrabold text-primary" id="sTotal">฿ 0.00</span>
            </div>
        </div>
    </div>

    <!-- Save Button -->
    <div class="flex justify-end gap-3">
        <button onclick="window.location.reload()" class="btn btn-ghost text-gray-500">ยกเลิก</button>
        <button onclick="saveAll()" id="saveBtn" class="px-8 py-3 bg-primary hover:bg-primary-container text-white font-bold rounded-xl shadow-lg shadow-primary/20 transition-all flex items-center gap-2 text-base">
            <span class="material-symbols-outlined text-[20px]">save</span>
            บันทึกมิเตอร์ & สร้างบิล
        </button>
    </div>
</div>

<!-- Empty state (when no room selected) -->
<div id="emptyState" class="text-center py-20">
    <span class="material-symbols-outlined text-6xl text-outline-variant mb-4 block">edit_note</span>
    <h3 class="text-xl font-semibold text-on-surface-variant mb-2">เลือกห้องเพื่อเริ่มบันทึกมิเตอร์</h3>
    <p class="text-sm text-outline">เลือกห้องพักจาก dropdown ด้านบน แล้วกรอกค่ามิเตอร์</p>
</div>

<?php
$appUrl = APP_URL;
$extraScripts = <<<JS
<script>
const APP_URL = '{$appUrl}';
const WATER_RATE = {$waterRate};
const ELECTRIC_RATE = {$electricRate};
let meterData = { waterPrev: 0, electricPrev: 0, rent: 0, fees: 0 };

function onRoomSelected() {
    const sel = document.getElementById('roomSelect');
    const roomId = sel.value;

    if (!roomId) {
        document.getElementById('meterSection').classList.add('hidden');
        document.getElementById('emptyState').classList.remove('hidden');
        return;
    }

    // Show meter section immediately
    document.getElementById('meterSection').classList.remove('hidden');
    document.getElementById('emptyState').classList.add('hidden');

    // Set rent/fees from data attributes
    const opt = sel.options[sel.selectedIndex];
    meterData.rent = parseFloat(opt.dataset.rent) || 0;
    meterData.fees = parseFloat(opt.dataset.fees) || 0;
    document.getElementById('sRent').textContent = formatCurrency(meterData.rent);
    document.getElementById('sFees').textContent = formatCurrency(meterData.fees);
    updateTotal();

    // Load previous readings from API
    const month = document.getElementById('billingMonth').value + '-01';
    fetch(APP_URL + '/api/meters.php?action=get_readings&room_id=' + roomId + '&billing_month=' + month, {credentials: 'same-origin'})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                meterData.waterPrev = data.water_previous || 0;
                meterData.electricPrev = data.electric_previous || 0;
                document.getElementById('waterPrev').textContent = meterData.waterPrev.toFixed(2);
                document.getElementById('electricPrev').textContent = meterData.electricPrev.toFixed(2);
            }
        })
        .catch(() => {
            // Even if API fails, the form still works with manual input
            meterData.waterPrev = 0;
            meterData.electricPrev = 0;
        });
}

function calcMeter(type) {
    const current = parseFloat(document.getElementById(type + 'Current').value) || 0;
    const prev = type === 'water' ? meterData.waterPrev : meterData.electricPrev;
    const rate = type === 'water' ? WATER_RATE : ELECTRIC_RATE;
    const usage = Math.max(0, current - prev);
    const cost = usage * rate;
    const unit = type === 'water' ? 'm³' : 'kWh';

    document.getElementById(type + 'Usage').innerHTML = usage.toFixed(2) + ' <small class="text-xs font-normal">' + unit + '</small>';
    document.getElementById(type + 'Cost').textContent = formatCurrency(cost);
    document.getElementById(type === 'water' ? 'sWater' : 'sElectric').textContent = formatCurrency(cost);

    meterData[type + 'Cost'] = cost;
    updateTotal();
}

function updateTotal() {
    const total = (meterData.rent || 0) + (meterData.fees || 0) + (meterData.waterCost || 0) + (meterData.electricCost || 0);
    document.getElementById('sTotal').textContent = formatCurrency(total);
}

async function saveAll() {
    const roomId = document.getElementById('roomSelect').value;
    const month = document.getElementById('billingMonth').value + '-01';
    const waterVal = parseFloat(document.getElementById('waterCurrent').value) || 0;
    const electricVal = parseFloat(document.getElementById('electricCurrent').value) || 0;

    if (!roomId) { Toast.warning('กรุณาเลือกห้อง'); return; }
    if (waterVal <= 0 && electricVal <= 0) { Toast.warning('กรุณากรอกค่ามิเตอร์อย่างน้อย 1 รายการ'); return; }

    const btn = document.getElementById('saveBtn');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<div class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div> กำลังบันทึก...';

    const formData = new FormData();
    formData.append('room_id', roomId);
    formData.append('billing_month', month);
    formData.append('water_reading', waterVal);
    formData.append('electric_reading', electricVal);

    try {
        const resp = await fetch(APP_URL + '/api/meters.php?action=save', { method: 'POST', body: formData, credentials: 'same-origin' });
        const data = await resp.json();
        if (data.success) {
            Toast.success('บันทึกมิเตอร์และสร้างบิลสำเร็จ! 🎉');
            setTimeout(() => location.reload(), 1500);
        } else {
            Toast.error(data.message || 'เกิดข้อผิดพลาด');
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    } catch {
        Toast.error('เกิดข้อผิดพลาดในการเชื่อมต่อ');
        btn.disabled = false;
        btn.innerHTML = origText;
    }
}
</script>
JS;

include dirname(__DIR__) . '/components/footer.php';
?>

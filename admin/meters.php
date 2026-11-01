<?php
/**
 * Meter Scan & Record — ถ่ายมิเตอร์ + OCR อัตโนมัติ + ค่าใช้จ่ายเพิ่มเติม
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

// ประเภทค่าใช้จ่ายเพิ่มเติมที่เปิดใช้งาน (จัดการได้ที่หน้าตั้งค่าระบบ)
try {
    $expenseTypes = $db->query("
        SELECT id, name, icon, default_amount, bill_group
        FROM expense_types
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll();
} catch (PDOException $e) {
    // ยังไม่ได้รัน database/migrate_additional_charges.php
    $expenseTypes = [];
}

$waterRate = getSetting('water_rate', 18.00);
$electricRate = getSetting('electric_rate', 7.50);

include dirname(__DIR__) . '/components/navbar.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <h1 class="text-3xl font-bold text-on-surface">📝 บันทึกมิเตอร์ &amp; ค่าใช้จ่าย</h1>
    <p class="text-on-surface-variant text-sm mt-1">เลือกห้อง → กรอกค่ามิเตอร์ → เพิ่มค่าใช้จ่ายอื่นๆ → บันทึก</p>
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

    <!-- Step 3: Additional Charges -->
    <div class="bg-white border border-outline-variant rounded-2xl p-6 mb-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary text-on-primary text-xs font-bold">3</span>
                <h2 class="text-lg font-bold text-on-surface">ค่าใช้จ่ายเพิ่มเติม</h2>
                <span class="text-xs text-outline font-medium">(ไม่บังคับ)</span>
            </div>
            <a href="<?= APP_URL ?>/admin/settings.php#expense-types"
               class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                <span class="material-symbols-outlined text-[16px]">tune</span>
                จัดการประเภทค่าใช้จ่าย
            </a>
        </div>
        <p class="text-xs text-on-surface-variant mb-4 md:ml-9">ติ๊กรายการที่ต้องการเรียกเก็บเดือนนี้ เช่น ค่าขยะ ค่าบำรุงมิเตอร์ แล้วกรอกจำนวนเงิน</p>

        <?php if (empty($expenseTypes)): ?>
        <div class="rounded-xl border border-dashed border-outline-variant bg-surface-container-low px-5 py-6 text-center">
            <span class="material-symbols-outlined text-[32px] text-outline-variant block mb-1">playlist_add</span>
            <p class="text-sm text-on-surface-variant">ยังไม่มีประเภทค่าใช้จ่ายในระบบ</p>
            <a href="<?= APP_URL ?>/admin/settings.php#expense-types" class="text-sm font-semibold text-primary hover:underline">
                ไปเพิ่มที่หน้าตั้งค่าระบบ
            </a>
            <p class="text-xs text-outline mt-1">หรือกด "เพิ่มรายการอื่น" ด้านล่างเพื่อเรียกเก็บแบบครั้งเดียว</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
            <?php foreach ($expenseTypes as $et): ?>
            <div class="expense-card flex items-center gap-3 border-2 border-outline-variant rounded-xl p-3 transition-all">
                <input type="checkbox" id="expense-<?= (int)$et['id'] ?>"
                       class="expense-toggle w-5 h-5 rounded border-outline-variant text-primary focus:ring-primary cursor-pointer flex-shrink-0"
                       data-id="<?= (int)$et['id'] ?>"
                       data-name="<?= sanitize($et['name']) ?>"
                       data-group="<?= $et['bill_group'] ?>"
                       data-default="<?= number_format((float)$et['default_amount'], 2, '.', '') ?>"
                       onchange="onExpenseToggle(this)">
                <label for="expense-<?= (int)$et['id'] ?>" class="flex items-center gap-2 flex-1 min-w-0 cursor-pointer select-none">
                    <span class="material-symbols-outlined text-outline text-[20px] flex-shrink-0"><?= sanitize($et['icon'] ?: 'receipt_long') ?></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-on-surface truncate"><?= sanitize($et['name']) ?></span>
                        <span class="inline-block mt-0.5 text-[10px] font-bold px-1.5 py-0.5 rounded-full <?= $et['bill_group'] === 'utility' ? 'text-blue-700 bg-blue-50 border border-blue-200' : 'text-teal-700 bg-teal-50 border border-teal-200' ?>">
                            <?= $et['bill_group'] === 'utility' ? 'บิลค่าน้ำ/ไฟ' : 'บิลค่าเช่า' ?>
                        </span>
                    </span>
                </label>
                <div class="relative w-24 flex-shrink-0">
                    <span class="absolute left-2 top-1/2 -translate-y-1/2 text-xs text-outline pointer-events-none">฿</span>
                    <input type="number" step="0.01" min="0" placeholder="0.00"
                           class="expense-amount w-full border border-outline-variant rounded-lg pl-5 pr-2 py-2 text-right text-sm font-bold text-on-surface disabled:bg-surface-container-low disabled:text-outline outline-none focus:border-primary"
                           value="<?= (float)$et['default_amount'] > 0 ? number_format((float)$et['default_amount'], 2, '.', '') : '' ?>"
                           disabled oninput="recalcExtras()">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- รายการเฉพาะกิจที่พิมพ์เอง -->
        <div id="customCharges" class="space-y-2 mt-4"></div>

        <button type="button" onclick="addCustomCharge()"
                class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
            <span class="material-symbols-outlined text-[18px]">add_circle</span>
            เพิ่มรายการอื่น
        </button>

        <div class="mt-5 pt-4 border-t border-outline-variant flex justify-between items-center">
            <span class="text-sm font-semibold text-on-surface-variant">รวมค่าใช้จ่ายเพิ่มเติม</span>
            <span class="text-xl font-bold text-primary" id="extraTotal">฿ 0.00</span>
        </div>
    </div>

    <!-- Step 4: Summary & Save -->
    <div class="bg-white border border-outline-variant rounded-2xl p-6 mb-6 shadow-sm">
        <div class="flex items-center gap-2 mb-4">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary text-on-primary text-xs font-bold">4</span>
            <h2 class="text-lg font-bold text-on-surface">สรุปบิลประจำเดือน</h2>
        </div>
        <div class="space-y-3">
            <div class="flex justify-between text-sm"><span class="text-gray-500">ค่าเช่ารายเดือน</span><span class="font-semibold" id="sRent">฿ 0.00</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-500">ค่าบริการอื่นๆ</span><span class="font-semibold" id="sFees">฿ 0.00</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-500">ค่าน้ำประปา</span><span class="font-semibold text-blue-600" id="sWater">฿ 0.00</span></div>
            <div class="flex justify-between text-sm"><span class="text-gray-500">ค่าไฟฟ้า</span><span class="font-semibold text-orange-600" id="sElectric">฿ 0.00</span></div>
            <div id="sExtraRows" class="space-y-3"></div>
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
            บันทึก &amp; สร้างบิล
        </button>
    </div>
</div>

<!-- Empty state (when no room selected) -->
<div id="emptyState" class="text-center py-20">
    <span class="material-symbols-outlined text-6xl text-outline-variant mb-4 block">edit_note</span>
    <h3 class="text-xl font-semibold text-on-surface-variant mb-2">เลือกห้องเพื่อเริ่มบันทึก</h3>
    <p class="text-sm text-outline">เลือกห้องพักจาก dropdown ด้านบน แล้วกรอกค่ามิเตอร์และค่าใช้จ่ายเพิ่มเติม</p>
</div>

<?php
$appUrl = APP_URL;
$extraScripts = <<<JS
<script>
const APP_URL = '{$appUrl}';
const WATER_RATE = {$waterRate};
const ELECTRIC_RATE = {$electricRate};
let meterData = { waterPrev: 0, electricPrev: 0, rent: 0, fees: 0, extras: 0 };
let customChargeSeq = 0;

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

                // เติมค่าที่เคยบันทึกไว้ของเดือนนี้กลับเข้าฟอร์ม
                document.getElementById('waterCurrent').value = data.water_current > 0 ? data.water_current : '';
                document.getElementById('electricCurrent').value = data.electric_current > 0 ? data.electric_current : '';
                calcMeter('water');
                calcMeter('electric');

                applyCharges(data.additional_charges || []);
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

// ---------- ค่าใช้จ่ายเพิ่มเติม ----------

function onExpenseToggle(cb) {
    const card = cb.closest('.expense-card');
    const amountInput = card.querySelector('.expense-amount');

    amountInput.disabled = !cb.checked;
    if (cb.checked) {
        if (!amountInput.value && parseFloat(cb.dataset.default) > 0) {
            amountInput.value = parseFloat(cb.dataset.default).toFixed(2);
        }
        amountInput.focus();
        amountInput.select();
    }
    styleExpenseCard(cb);
    recalcExtras();
}

function styleExpenseCard(cb) {
    cb.closest('.expense-card').classList.toggle('is-selected', cb.checked);
}

function addCustomCharge(description, amount, group) {
    const wrap = document.getElementById('customCharges');
    const id = 'cc' + (++customChargeSeq);

    const row = document.createElement('div');
    row.className = 'custom-charge flex flex-col sm:flex-row gap-2 sm:items-center border border-outline-variant rounded-xl p-3 bg-surface-container-low';
    row.innerHTML =
        '<input type="text" class="cc-desc form-input flex-1 text-sm" placeholder="ชื่อรายการ เช่น ค่าซ่อมประตู" maxlength="200">' +
        '<select class="cc-group form-input w-full sm:w-40 text-sm">' +
            '<option value="rent">รวมบิลค่าเช่า</option>' +
            '<option value="utility">รวมบิลค่าน้ำ/ไฟ</option>' +
        '</select>' +
        '<div class="relative w-full sm:w-28 flex-shrink-0">' +
            '<span class="absolute left-2 top-1/2 -translate-y-1/2 text-xs text-outline pointer-events-none">฿</span>' +
            '<input type="number" step="0.01" min="0" placeholder="0.00" class="cc-amount w-full border border-outline-variant rounded-lg pl-5 pr-2 py-2 text-right text-sm font-bold outline-none focus:border-primary">' +
        '</div>' +
        '<button type="button" class="cc-remove p-2 rounded-lg text-error hover:bg-error-container/30 transition-colors flex-shrink-0 self-end sm:self-auto" title="ลบรายการ">' +
            '<span class="material-symbols-outlined text-[20px]">delete</span>' +
        '</button>';

    wrap.appendChild(row);

    row.querySelector('.cc-desc').value = description || '';
    row.querySelector('.cc-group').value = group === 'utility' ? 'utility' : 'rent';
    if (amount !== undefined && amount !== null && parseFloat(amount) > 0) {
        row.querySelector('.cc-amount').value = parseFloat(amount).toFixed(2);
    }

    row.querySelector('.cc-amount').addEventListener('input', recalcExtras);
    row.querySelector('.cc-desc').addEventListener('input', recalcExtras);
    row.querySelector('.cc-group').addEventListener('change', recalcExtras);
    row.querySelector('.cc-remove').addEventListener('click', function () {
        row.remove();
        recalcExtras();
    });

    if (!description) row.querySelector('.cc-desc').focus();
    recalcExtras();
    return row;
}

function collectCharges() {
    const list = [];

    document.querySelectorAll('.expense-toggle').forEach(function (cb) {
        if (!cb.checked) return;
        const amount = parseFloat(cb.closest('.expense-card').querySelector('.expense-amount').value) || 0;
        if (amount <= 0) return;
        list.push({
            expense_type_id: parseInt(cb.dataset.id, 10),
            description: cb.dataset.name,
            amount: amount,
            bill_group: cb.dataset.group
        });
    });

    document.querySelectorAll('.custom-charge').forEach(function (row) {
        const description = row.querySelector('.cc-desc').value.trim();
        const amount = parseFloat(row.querySelector('.cc-amount').value) || 0;
        if (!description || amount <= 0) return;
        list.push({
            expense_type_id: null,
            description: description,
            amount: amount,
            bill_group: row.querySelector('.cc-group').value
        });
    });

    return list;
}

function recalcExtras() {
    const charges = collectCharges();
    const rows = document.getElementById('sExtraRows');

    rows.innerHTML = '';
    let total = 0;

    charges.forEach(function (c) {
        total += c.amount;
        const line = document.createElement('div');
        line.className = 'flex justify-between text-sm';
        const label = document.createElement('span');
        label.className = 'text-gray-500';
        label.textContent = c.description;
        const value = document.createElement('span');
        value.className = 'font-semibold text-primary';
        value.textContent = formatCurrency(c.amount);
        line.appendChild(label);
        line.appendChild(value);
        rows.appendChild(line);
    });

    meterData.extras = total;
    document.getElementById('extraTotal').textContent = formatCurrency(total);
    updateTotal();
}

function applyCharges(saved) {
    // ล้างสถานะเดิมก่อนเติมของเดือน/ห้องที่เลือกใหม่
    document.querySelectorAll('.expense-toggle').forEach(function (cb) {
        cb.checked = false;
        const amountInput = cb.closest('.expense-card').querySelector('.expense-amount');
        amountInput.disabled = true;
        amountInput.value = parseFloat(cb.dataset.default) > 0 ? parseFloat(cb.dataset.default).toFixed(2) : '';
        styleExpenseCard(cb);
    });
    document.getElementById('customCharges').innerHTML = '';

    saved.forEach(function (c) {
        const cb = c.expense_type_id
            ? document.querySelector('.expense-toggle[data-id="' + c.expense_type_id + '"]')
            : null;

        if (cb) {
            cb.checked = true;
            const amountInput = cb.closest('.expense-card').querySelector('.expense-amount');
            amountInput.disabled = false;
            amountInput.value = parseFloat(c.amount).toFixed(2);
            styleExpenseCard(cb);
        } else {
            // ประเภทถูกปิด/ลบไปแล้ว หรือเป็นรายการเฉพาะกิจ — แสดงเป็นแถวพิมพ์เอง
            addCustomCharge(c.description, c.amount, c.bill_group);
        }
    });

    recalcExtras();
}

function updateTotal() {
    const total = (meterData.rent || 0) + (meterData.fees || 0)
                + (meterData.waterCost || 0) + (meterData.electricCost || 0)
                + (meterData.extras || 0);
    document.getElementById('sTotal').textContent = formatCurrency(total);
}

async function saveAll() {
    const roomId = document.getElementById('roomSelect').value;
    const month = document.getElementById('billingMonth').value + '-01';
    const waterVal = parseFloat(document.getElementById('waterCurrent').value) || 0;
    const electricVal = parseFloat(document.getElementById('electricCurrent').value) || 0;
    const charges = collectCharges();

    if (!roomId) { Toast.warning('กรุณาเลือกห้อง'); return; }
    if (waterVal <= 0 && electricVal <= 0 && charges.length === 0) {
        Toast.warning('กรุณากรอกค่ามิเตอร์ หรือค่าใช้จ่ายเพิ่มเติมอย่างน้อย 1 รายการ');
        return;
    }

    // เตือนถ้ามีรายการที่ติ๊ก/พิมพ์ไว้แต่ยังไม่ได้ใส่ยอด
    const incomplete = document.querySelectorAll('.expense-toggle:checked').length
                     + document.querySelectorAll('.custom-charge').length
                     - charges.length;
    if (incomplete > 0 && !confirm('มีค่าใช้จ่ายเพิ่มเติม ' + incomplete + ' รายการที่ยังไม่ได้กรอกชื่อหรือจำนวนเงิน รายการเหล่านี้จะไม่ถูกบันทึก ต้องการบันทึกต่อหรือไม่?')) {
        return;
    }

    const btn = document.getElementById('saveBtn');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<div class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div> กำลังบันทึก...';

    const formData = new FormData();
    formData.append('room_id', roomId);
    formData.append('billing_month', month);
    formData.append('water_reading', waterVal);
    formData.append('electric_reading', electricVal);
    formData.append('charges', JSON.stringify(charges));

    try {
        const resp = await fetch(APP_URL + '/api/meters.php?action=save', { method: 'POST', body: formData, credentials: 'same-origin' });
        const data = await resp.json();
        if (data.success) {
            Toast.success('บันทึกและสร้างบิลสำเร็จ! 🎉');
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

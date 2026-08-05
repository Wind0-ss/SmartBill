<?php
/**
 * LIFF App — Bill View & Payment for Tenants
 * Opens inside LINE app via LIFF SDK
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$liffId = getSetting('liff_id', '');
$promptpay = getSetting('promptpay_number', '');
$companyName = getSetting('company_name', 'Smart Bill');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Smart Bill — ดูบิลและชำระเงิน</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Sarabun:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap">
    <script charset="utf-8" src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        :root {
            --primary: #0058be;
            --primary-container: #2170e4;
            --on-primary: #ffffff;
            --secondary: #006c49;
            --secondary-container: #6cf8bb;
            --error: #ba1a1a;
            --surface: #f9f9ff;
            --surface-container: #e7eeff;
            --surface-container-low: #f0f3ff;
            --surface-container-lowest: #ffffff;
            --on-surface: #111c2d;
            --on-surface-variant: #424754;
            --outline: #727785;
            --outline-variant: #c2c6d6;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Sarabun', sans-serif;
            background: var(--surface);
            color: var(--on-surface);
            min-height: 100vh;
            padding-bottom: env(safe-area-inset-bottom, 0);
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-container) 100%);
            color: white;
            padding: 24px 20px 20px;
            text-align: center;
        }
        .header h1 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
        .header p { font-size: 13px; opacity: 0.85; }
        .header .avatar {
            width: 56px; height: 56px; border-radius: 50%;
            border: 3px solid rgba(255,255,255,0.3);
            margin: 0 auto 12px;
            object-fit: cover;
        }

        /* Loading */
        .loading-screen {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            min-height: 80vh; gap: 16px;
        }
        .loading-screen .spinner {
            width: 40px; height: 40px;
            border: 3px solid var(--outline-variant);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Card */
        .card {
            background: var(--surface-container-lowest);
            border: 1px solid var(--outline-variant);
            border-radius: 16px;
            margin: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--outline-variant);
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-body { padding: 20px; }

        /* Bill Items */
        .bill-item {
            display: flex; justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(194,198,214,0.2);
            font-size: 14px;
        }
        .bill-item:last-child { border-bottom: none; }
        .bill-item .label { color: var(--on-surface-variant); }
        .bill-item .amount { font-weight: 600; }

        .bill-total {
            display: flex; justify-content: space-between;
            padding: 16px 0 0;
            margin-top: 8px;
            border-top: 2px solid var(--primary);
            font-size: 18px; font-weight: 700;
        }
        .bill-total .amount { color: var(--primary); }

        /* Buttons */
        .btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 14px;
            border: none; border-radius: 12px;
            font-family: inherit; font-size: 15px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
            text-decoration: none;
        }
        .btn:active { transform: scale(0.98); }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-container); }
        .btn-success { background: var(--secondary); color: white; }
        .btn-outline {
            background: transparent; color: var(--primary);
            border: 1.5px solid var(--outline-variant);
        }
        .btn-outline:hover { background: var(--surface-container-low); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* Badge */
        .badge {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 100px;
            font-size: 12px; font-weight: 600;
        }
        .badge-warning { background: #fff3e0; color: #e65100; }
        .badge-success { background: #e8f5e9; color: #2e7d32; }
        .badge-error { background: #fce4ec; color: #c62828; }
        .badge-info { background: #e3f2fd; color: #1565c0; }

        /* QR Section */
        .qr-section {
            text-align: center; padding: 24px 20px;
        }
        .qr-section img {
            width: 200px; height: 200px;
            border: 1px solid var(--outline-variant);
            border-radius: 12px; margin: 16px auto;
        }
        .promptpay-number {
            font-size: 20px; font-weight: 700;
            color: var(--primary); letter-spacing: 1px;
            margin: 8px 0;
        }

        /* Upload */
        .upload-area {
            border: 2px dashed var(--outline-variant);
            border-radius: 12px; padding: 32px 20px;
            text-align: center; cursor: pointer;
            transition: all 0.2s;
        }
        .upload-area:hover { border-color: var(--primary); background: var(--surface-container-low); }
        .upload-area.has-image { border-color: var(--secondary); border-style: solid; }
        .upload-preview {
            max-width: 200px; max-height: 200px;
            border-radius: 8px; margin: 12px auto;
            display: block;
        }

        /* Not registered */
        .register-prompt {
            text-align: center; padding: 60px 24px;
        }
        .register-prompt .icon { font-size: 64px; color: var(--outline); margin-bottom: 16px; }

        /* Status bar */
        .status-bar {
            background: var(--surface-container);
            padding: 12px 20px;
            font-size: 13px; color: var(--on-surface-variant);
            text-align: center;
        }

        .tabs {
            display: flex; gap: 0; margin: 0 16px;
        }
        .tab {
            flex: 1; padding: 12px; text-align: center;
            font-size: 13px; font-weight: 600;
            background: var(--surface-container-lowest);
            border: 1px solid var(--outline-variant);
            cursor: pointer; transition: all 0.2s;
            color: var(--on-surface-variant);
        }
        .tab:first-child { border-radius: 12px 0 0 12px; }
        .tab:last-child { border-radius: 0 12px 12px 0; }
        .tab.active {
            background: var(--primary); color: white;
            border-color: var(--primary);
        }

        .hidden { display: none !important; }
    </style>
</head>
<body>
    <!-- Loading Screen -->
    <div id="loadingScreen" class="loading-screen">
        <div class="spinner"></div>
        <p style="color: var(--on-surface-variant); font-size: 14px;">กำลังโหลดข้อมูล...</p>
    </div>

    <!-- Not Registered -->
    <div id="notRegistered" class="hidden">
        <div class="header">
            <h1>🏢 <?= htmlspecialchars($companyName) ?></h1>
            <p>ระบบจัดการบิลหอพัก</p>
        </div>
        <div class="register-prompt">
            <span class="material-symbols-outlined icon">person_add</span>
            <h2 style="font-size: 20px; margin-bottom: 8px;">ยังไม่ได้ลงทะเบียน</h2>
            <p style="color: var(--on-surface-variant); font-size: 14px; margin-bottom: 24px;">
                กรุณาพิมพ์ "ลงทะเบียน" ในแชท LINE<br>
                เพื่อเชื่อมต่อกับห้องพักของคุณ
            </p>
            <button class="btn btn-primary" onclick="liff.closeWindow()" style="max-width: 200px; margin: 0 auto;">
                กลับไปแชท
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div id="mainContent" class="hidden">
        <!-- Header with user info -->
        <div class="header">
            <img id="userAvatar" class="avatar" src="" alt="">
            <h1 id="userName">กำลังโหลด...</h1>
            <p id="userRoom">-</p>
        </div>

        <!-- Tabs -->
        <div class="tabs" style="margin-top: -1px;">
            <div class="tab active" onclick="switchTab('bill')">📋 บิลล่าสุด</div>
            <div class="tab" onclick="switchTab('pay')">💳 ชำระเงิน</div>
            <div class="tab" onclick="switchTab('history')">📊 ประวัติ</div>
        </div>

        <!-- Bill Tab -->
        <div id="tabBill">
            <div id="billContent"></div>
        </div>

        <!-- Pay Tab -->
        <div id="tabPay" class="hidden">
            <div class="card">
                <div class="card-header">
                    <span style="font-weight: 600;">💳 ชำระเงิน</span>
                    <span id="payAmount" class="badge badge-info">-</span>
                </div>
                <div class="qr-section">
                    <p style="font-size: 13px; color: var(--on-surface-variant);">สแกน QR Code เพื่อชำระผ่าน PromptPay</p>
                    <img id="qrImage" src="" alt="PromptPay QR Code">
                    <div class="promptpay-number"><?= htmlspecialchars($promptpay) ?></div>
                    <p style="font-size: 12px; color: var(--outline);">หมายเลข PromptPay</p>
                </div>
            </div>

            <!-- Slip Upload -->
            <div class="card">
                <div class="card-header">
                    <span style="font-weight: 600;">📸 อัปโหลดสลิป</span>
                </div>
                <div class="card-body">
                    <div class="upload-area" id="uploadArea" onclick="document.getElementById('slipInput').click()">
                        <span class="material-symbols-outlined" style="font-size: 48px; color: var(--outline); display: block;">cloud_upload</span>
                        <p style="font-size: 14px; font-weight: 500; margin-top: 8px;">แตะเพื่อถ่ายรูปหรือเลือกรูปสลิป</p>
                        <p style="font-size: 12px; color: var(--outline); margin-top: 4px;">รองรับ JPG, PNG (ไม่เกิน 10MB)</p>
                        <img id="slipPreview" class="upload-preview hidden" src="" alt="Preview">
                    </div>
                    <input type="file" id="slipInput" accept="image/*" capture="environment" style="display:none" onchange="previewSlip(this)">
                    <button id="submitSlipBtn" class="btn btn-success" style="margin-top: 16px;" onclick="submitSlip()" disabled>
                        <span class="material-symbols-outlined">send</span>
                        ส่งสลิป
                    </button>
                </div>
            </div>
        </div>

        <!-- History Tab -->
        <div id="tabHistory" class="hidden">
            <div id="historyContent" class="card">
                <div class="card-header">
                    <span style="font-weight: 600;">📊 ประวัติบิล</span>
                </div>
                <div class="card-body" id="historyList">
                    <p style="text-align: center; color: var(--outline); padding: 24px;">กำลังโหลด...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
    const APP_URL = '<?= APP_URL ?>';
    const LIFF_ID = '<?= htmlspecialchars($liffId) ?>';
    let currentInvoiceId = null;
    let tenantData = null;
    let lineUserId = null;

    // Get invoice_id from URL if present
    const urlParams = new URLSearchParams(window.location.search);
    const paramInvoiceId = urlParams.get('invoice_id');
    const paramAction = urlParams.get('action');

    async function initApp() {
        try {
            await liff.init({ liffId: LIFF_ID });

            if (!liff.isLoggedIn()) {
                liff.login();
                return;
            }

            const profile = await liff.getProfile();
            const accessToken = liff.getAccessToken();

            // Fetch tenant data from server
            const resp = await fetch(APP_URL + '/api/liff.php?action=get_profile', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ access_token: accessToken, line_user_id: profile.userId })
            });
            const data = await resp.json();

            document.getElementById('loadingScreen').classList.add('hidden');

            if (!data.success || !data.tenant) {
                document.getElementById('notRegistered').classList.remove('hidden');
                return;
            }

            tenantData = data.tenant;
            lineUserId = profile.userId;

            // Show main content
            document.getElementById('mainContent').classList.remove('hidden');
            document.getElementById('userAvatar').src = profile.pictureUrl || '';
            document.getElementById('userName').textContent = tenantData.first_name + ' ' + (tenantData.last_name || '');
            document.getElementById('userRoom').textContent = '🏠 ห้อง ' + tenantData.room_number + (tenantData.building_name ? ' — ' + tenantData.building_name : '');

            // Load bill
            if (paramInvoiceId) {
                await loadBill(paramInvoiceId);
            } else {
                await loadLatestBill();
            }

            // Load history
            loadHistory();

            // Generate QR
            generateQR();

            // Switch to pay tab if requested
            if (paramAction === 'pay') {
                switchTab('pay');
            }

        } catch (err) {
            console.error('LIFF init error:', err);
            document.getElementById('loadingScreen').innerHTML = `
                <span class="material-symbols-outlined" style="font-size:48px; color:var(--error)">error</span>
                <p style="color:var(--error); font-size:14px;">เกิดข้อผิดพลาด กรุณาลองใหม่</p>
                <button class="btn btn-outline" style="max-width:200px;margin-top:16px" onclick="location.reload()">ลองใหม่</button>
            `;
        }
    }

    async function loadBill(invoiceId) {
        const resp = await fetch(APP_URL + '/api/liff.php?action=get_bill&invoice_id=' + invoiceId + '&line_user_id=' + encodeURIComponent(lineUserId || ''));
        const data = await resp.json();
        if (data.success) {
            renderBill(data.invoice, data.items);
        }
    }

    async function loadLatestBill() {
        if (!tenantData) return;
        const resp = await fetch(APP_URL + '/api/liff.php?action=get_bills&tenant_id=' + tenantData.id + '&line_user_id=' + encodeURIComponent(lineUserId || ''));
        const data = await resp.json();
        if (data.success && data.bills.length > 0) {
            // Load latest unpaid bill first, or just latest
            const unpaid = data.bills.find(b => b.status !== 'paid' && b.status !== 'cancelled');
            const bill = unpaid || data.bills[0];
            await loadBill(bill.id);
        } else {
            document.getElementById('billContent').innerHTML = `
                <div class="card"><div class="card-body" style="text-align:center;padding:40px">
                    <span class="material-symbols-outlined" style="font-size:48px;color:var(--outline)">receipt_long</span>
                    <p style="margin-top:12px;color:var(--on-surface-variant)">ยังไม่มีบิลในระบบ</p>
                </div></div>`;
        }
    }

    function renderBill(invoice, items) {
        currentInvoiceId = invoice.id;
        const statusMap = {
            'draft': { label: 'รอส่ง', class: 'badge-info' },
            'sent': { label: 'รอชำระ', class: 'badge-warning' },
            'paid': { label: 'ชำระแล้ว', class: 'badge-success' },
            'overdue': { label: 'เลยกำหนด', class: 'badge-error' }
        };
        const status = statusMap[invoice.status] || { label: invoice.status, class: 'badge-info' };

        let itemsHtml = items.map(item => `
            <div class="bill-item">
                <span class="label">${item.description}</span>
                <span class="amount">฿${parseFloat(item.amount).toLocaleString('th-TH', {minimumFractionDigits: 2})}</span>
            </div>
        `).join('');

        document.getElementById('billContent').innerHTML = `
            <div class="card">
                <div class="card-header">
                    <span style="font-weight:600">📋 ${invoice.invoice_number}</span>
                    <span class="badge ${status.class}">${status.label}</span>
                </div>
                <div class="card-body">
                    <div style="display:flex;justify-content:space-between;margin-bottom:16px">
                        <div>
                            <div style="font-size:12px;color:var(--outline)">รอบบิล</div>
                            <div style="font-weight:600">${invoice.billing_month_thai || invoice.billing_month}</div>
                        </div>
                        <div style="text-align:right">
                            <div style="font-size:12px;color:var(--outline)">ครบกำหนด</div>
                            <div style="font-weight:600">${invoice.due_date_formatted || invoice.due_date}</div>
                        </div>
                    </div>
                    ${itemsHtml}
                    <div class="bill-total">
                        <span>💰 ยอดรวม</span>
                        <span class="amount">฿${parseFloat(invoice.total_amount).toLocaleString('th-TH', {minimumFractionDigits: 2})}</span>
                    </div>
                </div>
            </div>
            ${invoice.status !== 'paid' ? `
            <div style="padding: 0 16px 16px;">
                <button class="btn btn-primary" onclick="switchTab('pay')">
                    <span class="material-symbols-outlined">payment</span>
                    ชำระเงิน ฿${parseFloat(invoice.total_amount).toLocaleString('th-TH', {minimumFractionDigits: 2})}
                </button>
            </div>` : `
            <div style="padding: 0 16px 16px;">
                <div class="status-bar" style="border-radius:12px;background:#e8f5e9;color:#2e7d32">
                    ✅ ชำระเงินเรียบร้อยแล้ว
                </div>
            </div>`}
        `;

        // Update pay tab amount
        document.getElementById('payAmount').textContent = '฿' + parseFloat(invoice.total_amount).toLocaleString('th-TH', {minimumFractionDigits: 2});
    }

    async function loadHistory() {
        if (!tenantData) return;
        const resp = await fetch(APP_URL + '/api/liff.php?action=get_bills&tenant_id=' + tenantData.id + '&line_user_id=' + encodeURIComponent(lineUserId || ''));
        const data = await resp.json();
        if (data.success && data.bills.length > 0) {
            document.getElementById('historyList').innerHTML = data.bills.map(b => {
                const statusMap = { 'draft': '📋', 'sent': '📨', 'paid': '✅', 'overdue': '⚠️' };
                return `
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid rgba(194,198,214,0.2);cursor:pointer" onclick="loadBill(${b.id});switchTab('bill')">
                        <div>
                            <div style="font-size:14px;font-weight:500">${statusMap[b.status] || ''} ${b.billing_month_thai || b.billing_month}</div>
                            <div style="font-size:12px;color:var(--outline)">${b.invoice_number}</div>
                        </div>
                        <div style="text-align:right">
                            <div style="font-size:14px;font-weight:600">฿${parseFloat(b.total_amount).toLocaleString('th-TH', {minimumFractionDigits: 2})}</div>
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            document.getElementById('historyList').innerHTML = '<p style="text-align:center;color:var(--outline);padding:24px">ไม่มีประวัติบิล</p>';
        }
    }

    function generateQR() {
        const promptpay = '<?= htmlspecialchars($promptpay) ?>';
        // Use PromptPay QR API
        const amount = document.getElementById('payAmount')?.textContent?.replace(/[^0-9.]/g, '') || '0';
        document.getElementById('qrImage').src = `https://promptpay.io/${promptpay}/${amount}.png`;
    }

    function switchTab(tab) {
        document.querySelectorAll('.tab').forEach((t, i) => {
            t.classList.toggle('active', ['bill','pay','history'][i] === tab);
        });
        document.getElementById('tabBill').classList.toggle('hidden', tab !== 'bill');
        document.getElementById('tabPay').classList.toggle('hidden', tab !== 'pay');
        document.getElementById('tabHistory').classList.toggle('hidden', tab !== 'history');

        if (tab === 'pay') generateQR();
    }

    function previewSlip(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const preview = document.getElementById('slipPreview');
                preview.src = e.target.result;
                preview.classList.remove('hidden');
                document.getElementById('uploadArea').classList.add('has-image');
                document.getElementById('submitSlipBtn').disabled = false;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    async function submitSlip() {
        if (!currentInvoiceId) { alert('ไม่พบบิล'); return; }
        const fileInput = document.getElementById('slipInput');
        if (!fileInput.files[0]) { alert('กรุณาเลือกรูปสลิป'); return; }

        const btn = document.getElementById('submitSlipBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner" style="width:20px;height:20px;border:2px solid rgba(255,255,255,0.3);border-top-color:white;border-radius:50%;animation:spin 0.6s linear infinite;display:inline-block"></span> กำลังส่ง...';

        const formData = new FormData();
        formData.append('invoice_id', currentInvoiceId);
        formData.append('slip', fileInput.files[0]);
        formData.append('room_number', tenantData.room_number);

        try {
            const resp = await fetch(APP_URL + '/api/payments.php?action=upload_slip', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            if (data.success) {
                btn.innerHTML = '✅ ส่งสลิปเรียบร้อย!';
                btn.style.background = 'var(--secondary)';
                setTimeout(() => {
                    alert('📸 ส่งสลิปเรียบร้อย!\nกำลังรอแอดมินตรวจสอบ...');
                    if (liff.isInClient()) liff.closeWindow();
                    else location.reload();
                }, 500);
            } else {
                alert('❌ ' + (data.message || 'เกิดข้อผิดพลาด'));
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">send</span> ส่งสลิป';
            }
        } catch (err) {
            alert('❌ เกิดข้อผิดพลาด กรุณาลองใหม่');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined">send</span> ส่งสลิป';
        }
    }

    // Initialize
    initApp();
    </script>
</body>
</html>

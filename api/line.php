<?php
/**
 * LINE Webhook Endpoint
 * Handles tenant registration flow and bill queries
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/line_helpers.php';

// Verify LINE signature
$channelSecret = getSetting('line_channel_secret', '');
$requestBody = file_get_contents('php://input');
$hash = hash_hmac('sha256', $requestBody, $channelSecret, true);
$signature = base64_encode($hash);
$headerSignature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

if ($channelSecret && !hash_equals($signature, $headerSignature)) {
    http_response_code(400);
    exit;
}

$events = json_decode($requestBody, true)['events'] ?? [];
$db = getDB();

foreach ($events as $event) {
    // Handle follow event (when user adds bot as friend)
    if ($event['type'] === 'follow') {
        $userId = $event['source']['userId'];
        $replyToken = $event['replyToken'];
        
        // Check if already registered
        $stmt = $db->prepare("SELECT t.first_name, r.room_number FROM tenants t JOIN rooms r ON t.room_id = r.id WHERE t.line_user_id = ? AND t.is_active = 1");
        $stmt->execute([$userId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            replyLineText($replyToken, "สวัสดีค่ะ คุณ{$existing['first_name']} 🏠\nห้อง {$existing['room_number']}\n\nพิมพ์ \"บิล\" เพื่อดูบิลล่าสุด\nพิมพ์ \"เมนู\" เพื่อดูคำสั่งทั้งหมด");
        } else {
            replyLineText($replyToken, "สวัสดีค่ะ 🏢 ยินดีต้อนรับสู่ Smart Bill!\n\nพิมพ์ \"ลงทะเบียน\" เพื่อเชื่อมต่อกับห้องพักของคุณ");
        }
        continue;
    }

    if ($event['type'] !== 'message') continue;

    $userId = $event['source']['userId'];
    $replyToken = $event['replyToken'];

    // Handle image messages (slip upload via chat)
    if ($event['message']['type'] === 'image') {
        handleSlipUpload($userId, $event['message']['id'], $replyToken);
        continue;
    }

    if ($event['message']['type'] !== 'text') continue;

    $text = trim($event['message']['text']);
    $textLower = mb_strtolower($text);

    // Check if user is already registered
    $registeredTenant = $db->prepare("
        SELECT t.id, t.first_name, t.last_name, r.room_number 
        FROM tenants t JOIN rooms r ON t.room_id = r.id 
        WHERE t.line_user_id = ? AND t.is_active = 1
    ");
    $registeredTenant->execute([$userId]);
    $tenant = $registeredTenant->fetch();

    // Check active registration session
    $sessionStmt = $db->prepare("SELECT * FROM tenant_line_sessions WHERE line_user_id = ? ORDER BY id DESC LIMIT 1");
    $sessionStmt->execute([$userId]);
    $session = $sessionStmt->fetch();

    // =========================================
    // Registration Flow
    // =========================================
    
    // Start registration
    if (in_array($textLower, ['ลงทะเบียน', 'register', 'สมัคร'])) {
        if ($tenant) {
            replyLineText($replyToken, "✅ คุณลงทะเบียนแล้ว!\nห้อง: {$tenant['room_number']}\nชื่อ: {$tenant['first_name']} {$tenant['last_name']}\n\nพิมพ์ \"บิล\" เพื่อดูบิลล่าสุด");
            continue;
        }
        
        // Create or reset session
        $db->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);
        $db->prepare("INSERT INTO tenant_line_sessions (line_user_id, step) VALUES (?, 'ask_room')")->execute([$userId]);
        
        replyLineText($replyToken, "🏠 ลงทะเบียนเข้าใช้งาน\n━━━━━━━━━━━━━\n\nกรุณาพิมพ์ เลขห้อง ของคุณ\nเช่น 101, A201");
        continue;
    }

    // Handle registration steps
    if ($session && $session['step'] !== 'done') {
        if ($session['step'] === 'ask_room') {
            // Validate room exists
            $roomCheck = $db->prepare("
                SELECT r.id, r.room_number, t.id as tenant_id, t.first_name
                FROM rooms r 
                JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
                WHERE r.room_number = ?
            ");
            $roomCheck->execute([$text]);
            $roomData = $roomCheck->fetch();

            if (!$roomData) {
                replyLineText($replyToken, "❌ ไม่พบห้อง \"$text\" ในระบบ\nหรือห้องนี้ยังไม่มีผู้เช่า\n\nกรุณาพิมพ์เลขห้องใหม่ หรือพิมพ์ \"ยกเลิก\" เพื่อยกเลิก");
                continue;
            }

            // Check if already linked to another LINE account
            $linkedCheck = $db->prepare("SELECT line_user_id FROM tenants WHERE id = ? AND line_user_id IS NOT NULL AND line_user_id != ''");
            $linkedCheck->execute([$roomData['tenant_id']]);
            if ($linkedCheck->fetch()) {
                replyLineText($replyToken, "⚠️ ห้อง $text ลงทะเบียนกับบัญชี LINE อื่นแล้ว\nกรุณาติดต่อผู้ดูแลระบบ");
                $db->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);
                continue;
            }

            // Save room and ask for phone
            $db->prepare("UPDATE tenant_line_sessions SET step = 'ask_phone', room_number = ?, updated_at = NOW() WHERE line_user_id = ?")->execute([$text, $userId]);
            
            replyLineText($replyToken, "✅ พบห้อง $text\nผู้เช่า: {$roomData['first_name']}\n\n🔒 เพื่อยืนยันตัวตน\nกรุณาพิมพ์ เบอร์โทรศัพท์ ที่ลงทะเบียนไว้");
            continue;
        }

        if ($session['step'] === 'ask_phone') {
            $phone = preg_replace('/[^0-9]/', '', $text);
            
            // Verify phone matches tenant
            $verifyStmt = $db->prepare("
                SELECT t.id, t.first_name, t.last_name, r.room_number
                FROM tenants t 
                JOIN rooms r ON t.room_id = r.id
                WHERE r.room_number = ? AND t.is_active = 1
                  AND REPLACE(REPLACE(t.phone, '-', ''), ' ', '') = ?
            ");
            $verifyStmt->execute([$session['room_number'], $phone]);
            $verified = $verifyStmt->fetch();

            if (!$verified) {
                replyLineText($replyToken, "❌ เบอร์โทรไม่ตรงกับข้อมูลในระบบ\nกรุณาพิมพ์เบอร์โทรที่ถูกต้อง\n\nหรือพิมพ์ \"ยกเลิก\" เพื่อเริ่มใหม่");
                continue;
            }

            // Link LINE user ID to tenant
            $db->prepare("UPDATE tenants SET line_user_id = ? WHERE id = ?")->execute([$userId, $verified['id']]);
            $db->prepare("UPDATE tenant_line_sessions SET step = 'done', updated_at = NOW() WHERE line_user_id = ?")->execute([$userId]);
            $db->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);

            replyLineText($replyToken, "🎉 ลงทะเบียนสำเร็จ!\n━━━━━━━━━━━━━\nชื่อ: {$verified['first_name']} {$verified['last_name']}\nห้อง: {$verified['room_number']}\n━━━━━━━━━━━━━\n\nตอนนี้คุณจะได้รับแจ้งเตือนบิลผ่าน LINE แล้ว 📱\n\nพิมพ์ \"บิล\" เพื่อดูบิลล่าสุด\nพิมพ์ \"เมนู\" เพื่อดูคำสั่งทั้งหมด");
            continue;
        }
    }

    // Cancel registration
    if (in_array($textLower, ['ยกเลิก', 'cancel'])) {
        $db->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);
        replyLineText($replyToken, "❎ ยกเลิกการลงทะเบียนแล้ว\nพิมพ์ \"ลงทะเบียน\" เพื่อเริ่มใหม่");
        continue;
    }

    // =========================================
    // Registered User Commands
    // =========================================
    
    if (!$tenant) {
        replyLineText($replyToken, "👋 สวัสดีค่ะ!\nคุณยังไม่ได้ลงทะเบียน\n\nพิมพ์ \"ลงทะเบียน\" เพื่อเชื่อมต่อกับห้องพักของคุณ");
        continue;
    }

    // View latest bill
    if (in_array($textLower, ['บิล', 'ดูบิล', 'bill', 'แจ้งหนี้'])) {
        $billStmt = $db->prepare("
            SELECT i.id FROM invoices i 
            JOIN tenants t ON i.tenant_id = t.id
            WHERE t.line_user_id = ? AND i.status != 'cancelled'
            ORDER BY i.billing_month DESC, i.invoice_type ASC LIMIT 1
        ");
        $billStmt->execute([$userId]);
        $latestBill = $billStmt->fetchColumn();

        if ($latestBill) {
            $result = sendFlexBillNotification($latestBill);
            if (!$result['success']) {
                replyLineText($replyToken, "❌ " . $result['message']);
            }
            // Flex Message is sent via push, no reply needed
        } else {
            replyLineText($replyToken, "📋 ยังไม่มีบิลในระบบ\nรอแอดมินสร้างบิลให้ก่อนนะคะ");
        }
        continue;
    }

    // Show menu
    if (in_array($textLower, ['เมนู', 'menu', 'help', 'ช่วยเหลือ'])) {
        replyLineText($replyToken, "📋 คำสั่งที่ใช้ได้\n━━━━━━━━━━━━━\n\n🏠 \"บิล\" — ดูบิลล่าสุด\n📱 \"ลงทะเบียน\" — เชื่อมต่อห้องพัก\n❓ \"เมนู\" — แสดงคำสั่งนี้\n\nหรือส่งรูปสลิปโอนเงินมาได้เลย 📸");
        continue;
    }

    // Default response
    replyLineText($replyToken, "สวัสดีค่ะ คุณ{$tenant['first_name']} 🏠\n\nพิมพ์ \"บิล\" เพื่อดูบิลล่าสุด\nพิมพ์ \"เมนู\" เพื่อดูคำสั่งทั้งหมด\n\nหรือส่งรูปสลิปมาได้เลยค่ะ 📸");
}

http_response_code(200);
echo json_encode(['status' => 'ok']);

/**
 * Handle slip image upload from LINE chat
 */
function handleSlipUpload(string $userId, string $messageId, string $replyToken): void {
    $db = getDB();
    
    // Check if user is registered
    $tenant = $db->prepare("
        SELECT t.id, t.first_name, r.room_number 
        FROM tenants t JOIN rooms r ON t.room_id = r.id 
        WHERE t.line_user_id = ? AND t.is_active = 1
    ");
    $tenant->execute([$userId]);
    $tenantData = $tenant->fetch();

    if (!$tenantData) {
        replyLineText($replyToken, "❌ คุณยังไม่ได้ลงทะเบียน\nพิมพ์ \"ลงทะเบียน\" ก่อนส่งสลิป");
        return;
    }

    // Find latest unpaid invoice
    $invoice = $db->prepare("
        SELECT i.id, i.total_amount, i.invoice_number
        FROM invoices i 
        WHERE i.tenant_id = ? AND i.status IN ('draft', 'sent', 'overdue')
        ORDER BY i.billing_month DESC LIMIT 1
    ");
    $invoice->execute([$tenantData['id']]);
    $inv = $invoice->fetch();

    if (!$inv) {
        replyLineText($replyToken, "✅ ไม่มีบิลค้างชำระค่ะ\nขอบคุณนะคะ 🙏");
        return;
    }

    // Download image from LINE
    $accessToken = getSetting('line_channel_token', '');
    $ch = curl_init("https://api-data.line.me/v2/bot/message/{$messageId}/content");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken]
    ]);
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($imageData)) {
        replyLineText($replyToken, "❌ ไม่สามารถรับรูปภาพได้ กรุณาส่งใหม่อีกครั้ง");
        return;
    }

    // Save image
    $filename = 'slip_line_' . time() . '_' . $tenantData['id'] . '.jpg';
    $filepath = SLIP_UPLOAD_DIR . '/' . $filename;
    file_put_contents($filepath, $imageData);

    // Create payment record
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO payments (invoice_id, tenant_id, amount, payment_method, payment_date, status)
            VALUES (?, ?, ?, 'promptpay', NOW(), 'pending')
        ")->execute([$inv['id'], $tenantData['id'], $inv['total_amount']]);
        $paymentId = $db->lastInsertId();

        $db->prepare("
            INSERT INTO payment_slips (payment_id, image_path)
            VALUES (?, ?)
        ")->execute([$paymentId, 'uploads/slips/' . $filename]);

        $db->commit();

        replyLineText($replyToken, "📸 ได้รับสลิปแล้วค่ะ!\n━━━━━━━━━━━━━\nห้อง: {$tenantData['room_number']}\nบิลเลขที่: {$inv['invoice_number']}\nยอด: ฿" . number_format($inv['total_amount'], 2) . "\n━━━━━━━━━━━━━\nกำลังตรวจสอบ... รอแอดมินยืนยันนะคะ ⏳");
    } catch (Exception $e) {
        $db->rollBack();
        replyLineText($replyToken, "❌ เกิดข้อผิดพลาด กรุณาส่งสลิปใหม่อีกครั้ง");
    }
}

<?php
/**
 * LINE Webhook Endpoint
 * Handles tenant registration (room picker + phone verify) and bill queries
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
    $eventType  = $event['type'] ?? '';
    $userId     = $event['source']['userId'] ?? '';
    $replyToken = $event['replyToken'] ?? '';
    if ($userId === '' || $replyToken === '') continue;

    // ผู้เช่าที่ผูก LINE แล้ว
    $registeredTenant = $db->prepare("
        SELECT t.id, t.first_name, t.last_name, r.room_number
        FROM tenants t JOIN rooms r ON t.room_id = r.id
        WHERE t.line_user_id = ? AND t.is_active = 1
    ");
    $registeredTenant->execute([$userId]);
    $tenant = $registeredTenant->fetch();

    // =========================================
    // Follow: เพิ่มเพื่อน → ส่งหน้าเลือกห้องให้เลยถ้ายังไม่ลงทะเบียน
    // =========================================
    if ($eventType === 'follow') {
        if ($tenant) {
            replyLineText($replyToken, "สวัสดีค่ะ คุณ{$tenant['first_name']} 🏠\nห้อง {$tenant['room_number']}\n\nพิมพ์ \"บิล\" เพื่อดูบิลล่าสุด\nพิมพ์ \"เมนู\" เพื่อดูคำสั่งทั้งหมด",
                [['label' => '📄 ดูบิล', 'text' => 'บิล']]);
        } else {
            startRegistration($userId, $replyToken, "สวัสดีค่ะ 🏢 ยินดีต้อนรับสู่ Smart Bill!\n\nลงทะเบียนเพื่อรับบิลค่าเช่า ค่าน้ำ ค่าไฟ ผ่าน LINE\nเริ่มจากกดเลือกห้องของคุณด้านล่างได้เลยค่ะ 👇");
        }
        continue;
    }

    // =========================================
    // Postback: ปุ่มจาก Flex / quick reply
    // =========================================
    if ($eventType === 'postback') {
        parse_str($event['postback']['data'] ?? '', $pb);
        $pbAction = $pb['action'] ?? '';

        if ($pbAction === 'register') {
            if ($tenant) {
                replyLineText($replyToken, "✅ คุณลงทะเบียนแล้ว\nห้อง: {$tenant['room_number']}", [['label' => '📄 ดูบิล', 'text' => 'บิล']]);
            } else {
                startRegistration($userId, $replyToken);
            }
        } elseif ($pbAction === 'select_room') {
            if ($tenant) {
                replyLineText($replyToken, "✅ คุณลงทะเบียนแล้ว\nห้อง: {$tenant['room_number']}", [['label' => '📄 ดูบิล', 'text' => 'บิล']]);
            } else {
                selectRoom($userId, (int)($pb['room_id'] ?? 0), $replyToken);
            }
        } elseif ($pbAction === 'locked') {
            replyLineText($replyToken, "🔒 ห้องนี้ลงทะเบียนกับบัญชี LINE อื่นไปแล้วค่ะ\n\nถ้าเป็นห้องของคุณจริง กรุณาติดต่อเจ้าของหอพักเพื่อปลดการผูกบัญชีเดิมก่อน",
                [['label' => '🏠 เลือกห้องอื่น', 'data' => 'action=register']]);
        } elseif ($pbAction === 'cancel') {
            cancelRegistration($userId, $replyToken);
        }
        continue;
    }

    if ($eventType !== 'message') continue;

    // Handle image messages (slip upload via chat)
    if ($event['message']['type'] === 'image') {
        handleSlipUpload($userId, $event['message']['id'], $replyToken);
        continue;
    }

    if ($event['message']['type'] !== 'text') continue;

    $text = trim($event['message']['text']);
    $textLower = mb_strtolower($text);

    // Check active registration session
    $sessionStmt = $db->prepare("SELECT * FROM tenant_line_sessions WHERE line_user_id = ? ORDER BY id DESC LIMIT 1");
    $sessionStmt->execute([$userId]);
    $session = $sessionStmt->fetch();

    // =========================================
    // Registration Flow (เลือกห้องจากปุ่ม → ยืนยันเบอร์โทร)
    // =========================================

    if (in_array($textLower, ['ลงทะเบียน', 'register', 'สมัคร'])) {
        if ($tenant) {
            replyLineText($replyToken, "✅ คุณลงทะเบียนแล้ว!\nห้อง: {$tenant['room_number']}\nชื่อ: {$tenant['first_name']} {$tenant['last_name']}\n\nพิมพ์ \"บิล\" เพื่อดูบิลล่าสุด",
                [['label' => '📄 ดูบิล', 'text' => 'บิล']]);
        } else {
            startRegistration($userId, $replyToken);
        }
        continue;
    }

    if (in_array($textLower, ['ยกเลิก', 'cancel'])) {
        cancelRegistration($userId, $replyToken);
        continue;
    }

    // รอเบอร์โทรยืนยันตัวตน
    if (!$tenant && $session && $session['step'] === 'ask_phone') {
        verifyPhone($userId, $session, $text, $replyToken);
        continue;
    }

    // ยังไม่ลงทะเบียน พิมพ์อะไรมาก็ส่งหน้าเลือกห้องให้ (ไม่ต้องรู้คำสั่ง)
    if (!$tenant) {
        startRegistration($userId, $replyToken, "👋 สวัสดีค่ะ!\nคุณยังไม่ได้ลงทะเบียนรับบิล\nกดเลือกห้องของคุณด้านล่างได้เลยค่ะ 👇");
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
            $result = sendBillNotification($latestBill);
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

/**
 * เริ่มลงทะเบียน: ล้าง session เดิม แล้วส่งหน้าเลือกห้อง (Flex)
 */
function startRegistration(string $userId, string $replyToken, string $intro = ''): void {
    $db = getDB();
    $db->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);
    $db->prepare("INSERT INTO tenant_line_sessions (line_user_id, step) VALUES (?, 'ask_room')")->execute([$userId]);

    $picker = buildRoomPickerFlex();
    if (!$picker) {
        replyLineText($replyToken, "ขออภัยค่ะ ตอนนี้ยังไม่มีห้องที่เปิดให้ลงทะเบียน\nกรุณาติดต่อเจ้าของหอพักเพื่อเพิ่มข้อมูลผู้เช่าก่อนค่ะ");
        return;
    }

    $messages = [];
    if ($intro !== '') $messages[] = lineTextMessage($intro);
    $messages[] = $picker;
    replyLineMessage($replyToken, $messages);
}

/**
 * ผู้ใช้กดเลือกห้อง → จำห้องไว้ใน session แล้วขอเบอร์โทรยืนยัน
 */
function selectRoom(string $userId, int $roomId, string $replyToken): void {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT r.id, r.room_number, t.line_user_id
        FROM rooms r JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
        WHERE r.id = ?
    ");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    if (!$room) {
        replyLineText($replyToken, "❌ ไม่พบห้องนี้ หรือห้องยังไม่มีผู้เช่าในระบบ\nกรุณาเลือกห้องใหม่อีกครั้งค่ะ",
            [['label' => '🏠 เลือกห้องใหม่', 'data' => 'action=register']]);
        return;
    }
    if (!empty($room['line_user_id'])) {
        replyLineText($replyToken, "🔒 ห้อง {$room['room_number']} ลงทะเบียนกับบัญชี LINE อื่นไปแล้วค่ะ\nถ้าเป็นห้องของคุณจริง กรุณาติดต่อเจ้าของหอพัก",
            [['label' => '🏠 เลือกห้องอื่น', 'data' => 'action=register']]);
        return;
    }

    $db->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);
    try {
        $db->prepare("INSERT INTO tenant_line_sessions (line_user_id, step, room_number, room_id) VALUES (?, 'ask_phone', ?, ?)")
           ->execute([$userId, $room['room_number'], $room['id']]);
    } catch (PDOException $e) {
        // ยังไม่ได้รัน database/migrate_line_room_picker.php — ใช้เลขห้องแทนไปก่อน
        $db->prepare("INSERT INTO tenant_line_sessions (line_user_id, step, room_number) VALUES (?, 'ask_phone', ?)")
           ->execute([$userId, $room['room_number']]);
    }

    replyLineText($replyToken,
        "✅ เลือกห้อง {$room['room_number']}\n━━━━━━━━━━━━━\n\n🔒 ขั้นตอนสุดท้าย — ยืนยันตัวตน\nกรุณาพิมพ์ เบอร์โทรศัพท์ ที่แจ้งไว้กับเจ้าของหอพัก\n\nตัวอย่าง: 0812345678",
        [['label' => '🏠 เลือกห้องใหม่', 'data' => 'action=register'], ['label' => '❎ ยกเลิก', 'data' => 'action=cancel']]);
}

/**
 * เทียบเบอร์โทร: ตัดทุกอย่างที่ไม่ใช่ตัวเลข, +66 → 0, แล้วเทียบ 9 หลักท้าย
 */
function normalizePhone(string $phone): string {
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) === 11 && substr($d, 0, 2) === '66') $d = '0' . substr($d, 2);
    return substr($d, -9);
}

/**
 * ตรวจเบอร์โทรกับผู้เช่าของห้องที่เลือกไว้ แล้วผูก LINE
 */
function verifyPhone(string $userId, array $session, string $text, string $replyToken): void {
    $db = getDB();
    $retryButtons = [['label' => '🏠 เลือกห้องใหม่', 'data' => 'action=register'], ['label' => '❎ ยกเลิก', 'data' => 'action=cancel']];

    $input = normalizePhone($text);
    if (strlen($input) < 9) {
        replyLineText($replyToken, "กรุณาพิมพ์เบอร์โทรศัพท์เป็นตัวเลข 10 หลักค่ะ\nตัวอย่าง: 0812345678", $retryButtons);
        return;
    }

    if (!empty($session['room_id'])) {
        $stmt = $db->prepare("
            SELECT t.id, t.first_name, t.last_name, t.phone, t.line_user_id, r.room_number
            FROM tenants t JOIN rooms r ON t.room_id = r.id
            WHERE r.id = ? AND t.is_active = 1
        ");
        $stmt->execute([$session['room_id']]);
    } else {
        $stmt = $db->prepare("
            SELECT t.id, t.first_name, t.last_name, t.phone, t.line_user_id, r.room_number
            FROM tenants t JOIN rooms r ON t.room_id = r.id
            WHERE r.room_number = ? AND t.is_active = 1
        ");
        $stmt->execute([$session['room_number']]);
    }
    $candidate = $stmt->fetch();

    if (!$candidate || normalizePhone((string)$candidate['phone']) !== $input) {
        replyLineText($replyToken, "❌ เบอร์โทรไม่ตรงกับข้อมูลของห้อง {$session['room_number']}\nกรุณาลองใหม่ หรือติดต่อเจ้าของหอพักหากไม่แน่ใจว่าแจ้งเบอร์ไหนไว้", $retryButtons);
        return;
    }
    if (!empty($candidate['line_user_id'])) {
        $db->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);
        replyLineText($replyToken, "🔒 ห้องนี้เพิ่งถูกลงทะเบียนด้วยบัญชีอื่นไปแล้วค่ะ กรุณาติดต่อเจ้าของหอพัก");
        return;
    }

    $db->prepare("UPDATE tenants SET line_user_id = ? WHERE id = ?")->execute([$userId, $candidate['id']]);
    $db->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);

    replyLineText($replyToken,
        "🎉 ลงทะเบียนสำเร็จ!\n━━━━━━━━━━━━━\nชื่อ: {$candidate['first_name']} {$candidate['last_name']}\nห้อง: {$candidate['room_number']}\n━━━━━━━━━━━━━\n\nต่อไปจะได้รับแจ้งบิลค่าเช่า ค่าน้ำ ค่าไฟ ทาง LINE นี้อัตโนมัติ 📱\nชำระแล้วส่งรูปสลิปมาในแชทนี้ได้เลยค่ะ 📸",
        [['label' => '📄 ดูบิลล่าสุด', 'text' => 'บิล'], ['label' => '📋 เมนู', 'text' => 'เมนู']]);
}

/**
 * ยกเลิกการลงทะเบียนที่ค้างอยู่
 */
function cancelRegistration(string $userId, string $replyToken): void {
    getDB()->prepare("DELETE FROM tenant_line_sessions WHERE line_user_id = ?")->execute([$userId]);
    replyLineText($replyToken, "❎ ยกเลิกการลงทะเบียนแล้ว\nพร้อมเมื่อไหร่กดปุ่มด้านล่างเพื่อเริ่มใหม่ได้เลยค่ะ",
        [['label' => '🏠 ลงทะเบียน', 'data' => 'action=register']]);
}

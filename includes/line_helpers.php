<?php
/**
 * LINE Helper Functions
 * Push messaging and bill/payment notification helpers
 */

/**
 * Push message to a LINE user (not reply)
 */
function pushLineMessage(string $userId, array $messages): bool {
    if (APP_ENV === 'development') error_log('[LINE push] ' . json_encode($messages, JSON_UNESCAPED_UNICODE));
    $accessToken = getSetting('line_channel_token', '');
    if (empty($accessToken) || empty($userId)) return false;

    $data = [
        'to' => $userId,
        'messages' => $messages
    ];

    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * Reply to a LINE message
 */
function replyLineMessage(string $replyToken, array $messages): bool {
    // โหมดพัฒนา: เก็บ payload ไว้ดูใน error log (ทดสอบ flow ได้โดยไม่ต้องต่อ LINE จริง)
    if (APP_ENV === 'development') error_log('[LINE reply] ' . json_encode($messages, JSON_UNESCAPED_UNICODE));
    $accessToken = getSetting('line_channel_token', '');
    if (empty($accessToken)) return false;

    $data = [
        'replyToken' => $replyToken,
        'messages' => $messages
    ];

    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * Reply with plain text
 */
function replyLineText(string $replyToken, string $text, array $quickReply = []): bool {
    return replyLineMessage($replyToken, [lineTextMessage($text, $quickReply)]);
}

/**
 * สร้าง text message object (แนบปุ่ม quick reply ได้)
 * $quickReply = [['label' => 'ยกเลิก', 'data' => 'action=cancel'], ['label' => 'ดูบิล', 'text' => 'บิล']]
 */
function lineTextMessage(string $text, array $quickReply = []): array {
    $msg = ['type' => 'text', 'text' => $text];
    if ($quickReply) {
        $items = [];
        foreach ($quickReply as $q) {
            $action = isset($q['data'])
                ? ['type' => 'postback', 'label' => $q['label'], 'data' => $q['data'], 'displayText' => $q['label']]
                : ['type' => 'message',  'label' => $q['label'], 'text' => $q['text'] ?? $q['label']];
            $items[] = ['type' => 'action', 'action' => $action];
        }
        $msg['quickReply'] = ['items' => $items];
    }
    return $msg;
}

/**
 * ปุ่มห้อง 1 ช่องใน Flex เลือกห้อง
 * - ยังไม่ผูก LINE → ปุ่มสีน้ำเงิน กดแล้วไปขั้นยืนยันเบอร์โทร
 * - ผูกแล้ว → ปุ่มสีเทา 🔒 กดแล้วบอกว่าลงทะเบียนไปแล้ว
 */
function roomPickerButton(array $room): array {
    $locked = (bool)$room['is_linked'];
    $label = mb_substr(($locked ? '🔒 ' : 'ห้อง ') . $room['room_number'], 0, 20);
    return [
        'type'   => 'button',
        'flex'   => 1,
        'height' => 'sm',
        'style'  => $locked ? 'secondary' : 'primary',
        'color'  => $locked ? '#E0E0E0' : '#0058be',
        'action' => [
            'type'        => 'postback',
            'label'       => $label,
            'data'        => ($locked ? 'action=locked' : 'action=select_room') . '&room_id=' . (int)$room['id'],
            'displayText' => $locked ? 'ห้อง ' . $room['room_number'] . ' (ลงทะเบียนแล้ว)' : 'เลือกห้อง ' . $room['room_number'],
        ],
    ];
}

/**
 * Flex Message ให้ผู้เช่ากดเลือกห้องตอนลงทะเบียน
 * แสดงเฉพาะห้องที่มีผู้เช่าอยู่ (มีเบอร์โทรให้ยืนยัน) แยก bubble ตามอาคาร
 * คืน null ถ้ายังไม่มีห้องให้เลือกเลย
 */
function buildRoomPickerFlex(): ?array {
    $db = getDB();
    $rooms = $db->query("
        SELECT r.id, r.room_number, COALESCE(b.name, '') AS building_name,
               (t.line_user_id IS NOT NULL AND t.line_user_id != '') AS is_linked
        FROM rooms r
        JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
        LEFT JOIN buildings b ON r.building_id = b.id
        ORDER BY b.name, LENGTH(r.room_number), r.room_number
    ")->fetchAll();
    if (!$rooms) return null;

    $groups = [];
    foreach ($rooms as $r) $groups[$r['building_name']][] = $r;

    $bubbles = [];
    foreach ($groups as $building => $list) {
        // 2 ปุ่มต่อแถว, 8 แถวต่อ bubble — เกินกว่านั้นแบ่งเป็นหลาย bubble
        $chunks = array_chunk($list, 16);
        foreach ($chunks as $ci => $chunk) {
            $title = $building !== '' ? $building : 'เลือกห้องของคุณ';
            if (count($chunks) > 1) $title .= ' (' . ($ci + 1) . '/' . count($chunks) . ')';

            $rows = [];
            foreach (array_chunk($chunk, 2) as $pair) {
                $cells = array_map('roomPickerButton', $pair);
                if (count($cells) === 1) $cells[] = ['type' => 'filler', 'flex' => 1];
                $rows[] = ['type' => 'box', 'layout' => 'horizontal', 'spacing' => 'sm', 'contents' => $cells];
            }

            $bubbles[] = [
                'type' => 'bubble',
                'size' => 'mega',
                'header' => [
                    'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '16px', 'backgroundColor' => '#0058be',
                    'contents' => [
                        ['type' => 'text', 'text' => '🏠 ' . $title, 'weight' => 'bold', 'size' => 'lg', 'color' => '#FFFFFF', 'wrap' => true],
                        ['type' => 'text', 'text' => 'กดเลือกห้องที่คุณพักอยู่', 'size' => 'xs', 'color' => '#D6E3FF', 'margin' => 'xs'],
                    ],
                ],
                'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'paddingAll' => '12px', 'contents' => $rows],
                'footer' => [
                    'type' => 'box', 'layout' => 'vertical', 'paddingAll' => '12px',
                    'contents' => [
                        ['type' => 'text', 'text' => '🔒 = ห้องที่ลงทะเบียนแล้ว', 'size' => 'xxs', 'color' => '#727785'],
                        ['type' => 'text', 'text' => 'ไม่พบห้องของคุณ? ติดต่อเจ้าของหอพัก', 'size' => 'xxs', 'color' => '#727785'],
                    ],
                ],
            ];
        }
    }

    // LINE carousel รองรับสูงสุด 12 bubbles
    $bubbles = array_slice($bubbles, 0, 12);

    return [
        'type'     => 'flex',
        'altText'  => '🏠 เลือกห้องของคุณเพื่อลงทะเบียนรับบิล',
        'contents' => count($bubbles) === 1 ? $bubbles[0] : ['type' => 'carousel', 'contents' => $bubbles],
    ];
}

/**
 * อีโมจิประกอบรายการในข้อความแจ้งบิล
 */
function billItemEmoji(string $itemType, string $description): string {
    if ($itemType === 'rent')     return '🏠';
    if ($itemType === 'water')    return '🚰';
    if ($itemType === 'electric') return '⚡';
    if (mb_strpos($description, 'ขยะ') !== false)         return '🗑️';
    if (mb_strpos($description, 'มิเตอร์') !== false)      return '📋';
    if (mb_strpos($description, 'อินเทอร์เน็ต') !== false)  return '🌐';
    if (mb_strpos($description, 'จอดรถ') !== false)        return '🚗';
    if (mb_strpos($description, 'ส่วนกลาง') !== false)     return '🏢';
    return '📌';
}

/**
 * แสดงเลขมิเตอร์: จำนวนเต็มไม่ต้องมีทศนิยม
 */
function formatMeterUnits(float $n): string {
    return (floor($n) == $n) ? number_format($n) : number_format($n, 2);
}

/**
 * สร้างข้อความแจ้งค่าใช้จ่ายรวมทุกรายการของห้องในรอบเดือน
 * (ค่าเช่า + ค่าน้ำ + ค่าไฟ + ค่าใช้จ่ายเพิ่มเติม) เป็นข้อความเดียว
 *
 * $invoices  บิลทุกใบของห้อง/รอบเดือน (rent, utility)
 * $items     รายการในบิลทุกใบ (item_type, description, amount, sort_order)
 * $readings  ค่ามิเตอร์ของรอบเดือน (meter_type, previous_reading, current_reading, consumption, unit_price, total_cost)
 * $tenant    first_name, last_name
 * $room      room_number, building_name
 */
function buildCombinedBillText(array $invoices, array $items, array $readings, array $tenant, array $room): string {
    $line = "━━━━━━━━━━━━━━━━━━━━";
    $first = $invoices[0];

    $invoiceNumbers = implode(' / ', array_column($invoices, 'invoice_number'));
    $tenantName = trim($tenant['first_name'] . ' ' . ($tenant['last_name'] ?? ''));
    $issueDate = formatDate($first['created_at'] ?? date('Y-m-d'), 'd/m/Y');
    $dueDate = formatDate($first['due_date'], 'd/m/Y');

    $t  = "🏠 แจ้งค่าใช้จ่ายประจำเดือน " . formatThaiMonth($first['billing_month']) . "\n";
    $t .= "$line\n";
    $t .= "📋 เลขที่ใบแจ้งหนี้: $invoiceNumbers\n";
    $t .= "👤 ชื่อผู้เช่า: $tenantName\n";
    $t .= "🏠 ห้อง: " . formatRoomDisplay($room) . "\n";
    $t .= "📅 วันที่ออกใบแจ้งหนี้: $issueDate\n";
    $t .= "⏰ กำหนดชำระ: $dueDate\n";

    // ---------- รายละเอียดสาธารณูปโภค ----------
    $byType = [];
    foreach ($readings as $r) $byType[$r['meter_type']] = $r;

    $extras = array_values(array_filter($items, function ($i) { return $i['item_type'] === 'other'; }));

    if (!empty($byType) || !empty($extras)) {
        $t .= "\n⚡ รายละเอียดการใช้สาธารณูปโภค:\n$line\n";
        $meterLabels = [
            'water'    => ['🚰', 'ค่าน้ำประปา', 'รวมค่าน้ำ'],
            'electric' => ['⚡', 'ค่าไฟฟ้า', 'รวมค่าไฟ'],
        ];
        foreach ($meterLabels as $type => [$emoji, $label, $sumLabel]) {
            if (empty($byType[$type])) continue;
            $r = $byType[$type];
            $t .= "$emoji $label:\n";
            $t .= "   หน่วยก่อนหน้า: " . formatMeterUnits((float)$r['previous_reading']) . " หน่วย\n";
            $t .= "   หน่วยปัจจุบัน: " . formatMeterUnits((float)$r['current_reading']) . " หน่วย\n";
            $t .= "   จำนวนที่ใช้: " . formatMeterUnits(max(0, (float)$r['consumption'])) . " หน่วย\n";
            $t .= "   อัตรา: " . number_format($r['unit_price'], 2) . " บาท/หน่วย\n";
            $t .= "   $sumLabel: " . number_format(max(0, (float)$r['total_cost']), 2) . " บาท\n\n";
        }
        foreach ($extras as $i) {
            $t .= billItemEmoji('other', $i['description']) . " {$i['description']}: " . number_format($i['amount'], 2) . " บาท\n";
        }
    }

    // ---------- สรุปค่าใช้จ่าย ----------
    $labels = ['rent' => 'ค่าเช่าห้อง', 'water' => 'ค่าน้ำประปา', 'electric' => 'ค่าไฟฟ้า'];
    $order  = ['rent' => 1, 'water' => 2, 'electric' => 3, 'other' => 4];
    usort($items, function ($a, $b) use ($order) {
        $byType = ($order[$a['item_type']] ?? 9) <=> ($order[$b['item_type']] ?? 9);
        return $byType ?: ((int)$a['sort_order'] <=> (int)$b['sort_order']);
    });

    $t .= "\n💰 สรุปค่าใช้จ่าย:\n$line\n";
    foreach ($items as $i) {
        $label = $labels[$i['item_type']] ?? $i['description'];
        $t .= billItemEmoji($i['item_type'], $i['description']) . " $label: " . number_format($i['amount'], 2) . " บาท\n";
    }

    $grandTotal = 0;
    foreach ($invoices as $inv) {
        if ((float)$inv['discount'] > 0) $t .= "🔖 ส่วนลด: -" . number_format($inv['discount'], 2) . " บาท\n";
        if ((float)$inv['penalty'] > 0)  $t .= "⚠️ ค่าปรับ: " . number_format($inv['penalty'], 2) . " บาท\n";
        $grandTotal += (float)$inv['total_amount'];
    }
    $t .= "$line\n";
    $t .= "💵 รวมทั้งสิ้น: " . number_format($grandTotal, 2) . " บาท\n";

    // ---------- ช่องทางชำระเงิน ----------
    $bankName    = trim((string)getSetting('bank_name', ''));
    $bankAccName = trim((string)getSetting('bank_account_name', ''));
    $bankAccNo   = trim((string)getSetting('bank_account_number', ''));
    $promptpay   = trim((string)getSetting('promptpay_number', ''));
    $ppName      = trim((string)getSetting('promptpay_name', ''));
    $phone       = trim((string)getSetting('company_phone', ''));
    $liffId      = trim((string)getSetting('liff_id', ''));

    if ($bankAccNo !== '' || $promptpay !== '') {
        $t .= "\n🏦 ช่องทางการชำระเงิน:\n";
        if ($bankAccNo !== '') {
            $t .= "📱 " . trim("$bankName $bankAccName") . ": $bankAccNo\n";
        }
        if ($promptpay !== '') {
            $t .= "📱 พร้อมเพย์" . ($ppName !== '' ? " ($ppName)" : '') . ": $promptpay\n";
        }
    }
    if ($liffId !== '') {
        $t .= "📲 ดูบิล / แนบสลิป: https://liff.line.me/$liffId\n";
    }

    $t .= "\n📞 สอบถามเพิ่มเติม: $phone\n";
    $t .= "\n✅ กรุณาชำระเงินภายในกำหนด\n";
    $t .= "🙏 ขอบคุณค่ะ";

    return $t;
}

/**
 * ส่งแจ้งค่าใช้จ่ายของห้องทั้งรอบเดือนเป็นข้อความเดียวผ่าน LINE
 * (รวมบิลค่าเช่าและบิลค่าน้ำ/ไฟเข้าด้วยกัน)
 */
function sendCombinedBillNotification(int $roomId, string $billingMonth): array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT i.*, r.room_number, b.name as building_name,
               t.id as tenant_id, t.first_name, t.last_name, t.line_user_id
        FROM invoices i
        JOIN rooms r ON i.room_id = r.id
        LEFT JOIN buildings b ON r.building_id = b.id
        JOIN tenants t ON i.tenant_id = t.id
        WHERE i.room_id = ? AND i.billing_month = ? AND i.status != 'cancelled'
        ORDER BY FIELD(i.invoice_type, 'rent', 'utility'), i.id
    ");
    $stmt->execute([$roomId, $billingMonth]);
    $invoices = $stmt->fetchAll();

    if (!$invoices) {
        return ['success' => false, 'message' => 'ไม่พบบิลของห้องนี้ในรอบเดือนที่เลือก'];
    }

    $data = $invoices[0];
    if (empty($data['line_user_id'])) {
        return ['success' => false, 'message' => 'ผู้เช่าห้อง ' . $data['room_number'] . ' ยังไม่ได้ลงทะเบียน LINE'];
    }

    $invoiceIds = array_map('intval', array_column($invoices, 'id'));
    $in = implode(',', array_fill(0, count($invoiceIds), '?'));

    $items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id IN ($in) ORDER BY invoice_id, sort_order");
    $items->execute($invoiceIds);
    $itemList = $items->fetchAll();

    $readings = $db->prepare("
        SELECT m.meter_type, mr.previous_reading, mr.current_reading, mr.consumption, mr.unit_price, mr.total_cost
        FROM meter_readings mr
        JOIN meters m ON mr.meter_id = m.id
        WHERE mr.room_id = ? AND mr.billing_month = ?
    ");
    $readings->execute([$roomId, $billingMonth]);

    $text = buildCombinedBillText(
        $invoices,
        $itemList,
        $readings->fetchAll(),
        ['first_name' => $data['first_name'], 'last_name' => $data['last_name']],
        ['room_number' => $data['room_number'], 'building_name' => $data['building_name']]
    );

    $sent = pushLineMessage($data['line_user_id'], [['type' => 'text', 'text' => $text]]);

    if ($sent) {
        $db->prepare("UPDATE invoices SET sent_via_line = 1, sent_at = NOW(), status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END WHERE id IN ($in)")
           ->execute($invoiceIds);

        $log = $db->prepare("
            INSERT INTO notifications (tenant_id, invoice_id, channel, type, message, status, sent_at)
            VALUES (?, ?, 'line', 'bill_sent', ?, 'sent', NOW())
        ");
        foreach ($invoiceIds as $id) {
            $log->execute([$data['tenant_id'], $id, 'แจ้งค่าใช้จ่ายรวมห้อง ' . $data['room_number']]);
        }

        return ['success' => true, 'message' => 'ส่งแจ้งเตือนไปยัง LINE สำเร็จ'];
    }

    return ['success' => false, 'message' => 'ส่ง LINE ไม่สำเร็จ กรุณาตรวจสอบ LINE Channel Token'];
}

/**
 * ส่งแจ้งบิลจาก invoice id — จะส่งรวมบิลทุกใบของห้องในรอบเดือนเดียวกัน
 */
function sendBillNotification(int $invoiceId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT room_id, billing_month FROM invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv) {
        return ['success' => false, 'message' => 'ไม่พบบิล'];
    }
    return sendCombinedBillNotification((int)$inv['room_id'], $inv['billing_month']);
}

/**
 * Send payment confirmation to tenant via LINE
 */
function sendPaymentConfirmation(int $paymentId): bool {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT p.*, i.invoice_number, i.total_amount, i.billing_month,
               r.room_number, t.line_user_id, t.first_name
        FROM payments p
        JOIN invoices i ON p.invoice_id = i.id
        JOIN rooms r ON i.room_id = r.id
        JOIN tenants t ON i.tenant_id = t.id
        WHERE p.id = ?
    ");
    $stmt->execute([$paymentId]);
    $data = $stmt->fetch();

    if (!$data || empty($data['line_user_id'])) return false;

    $text = "✅ ชำระเงินสำเร็จ!\n"
          . "━━━━━━━━━━━━━\n"
          . "ห้อง: " . $data['room_number'] . "\n"
          . "เลขที่บิล: " . $data['invoice_number'] . "\n"
          . "ยอด: ฿" . number_format($data['total_amount'], 2) . "\n"
          . "วันที่ชำระ: " . date('d/m/Y H:i') . "\n"
          . "━━━━━━━━━━━━━\n"
          . "ขอบคุณที่ชำระตรงเวลา 🙏";

    return pushLineMessage($data['line_user_id'], [['type' => 'text', 'text' => $text]]);
}

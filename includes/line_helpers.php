<?php
/**
 * LINE Helper Functions
 * Flex Message builder, Push messaging, and notification helpers
 */

/**
 * Push message to a LINE user (not reply)
 */
function pushLineMessage(string $userId, array $messages): bool {
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
function replyLineText(string $replyToken, string $text): bool {
    return replyLineMessage($replyToken, [['type' => 'text', 'text' => $text]]);
}

/**
 * Build Flex Message for bill notification
 */
function buildBillFlexMessage(array $invoice, array $items, array $tenant, array $room): array {
    $liffId = getSetting('liff_id', '');
    $liffUrl = "https://liff.line.me/{$liffId}";

    $statusLabels = [
        'draft' => '📋 ยังไม่ส่ง',
        'sent' => '📨 รอชำระ',
        'paid' => '✅ ชำระแล้ว',
        'overdue' => '⚠️ เลยกำหนด'
    ];
    $statusColors = [
        'draft' => '#727785',
        'sent' => '#0058be',
        'paid' => '#006c49',
        'overdue' => '#ba1a1a'
    ];

    $billingMonth = formatThaiMonth($invoice['billing_month']);
    $statusText = $statusLabels[$invoice['status']] ?? $invoice['status'];
    $statusColor = $statusColors[$invoice['status']] ?? '#727785';

    // Build item rows
    $itemContents = [];
    foreach ($items as $item) {
        $itemContents[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $item['description'],
                    'size' => 'sm',
                    'color' => '#424754',
                    'flex' => 3,
                    'wrap' => true
                ],
                [
                    'type' => 'text',
                    'text' => '฿' . number_format($item['amount'], 2),
                    'size' => 'sm',
                    'color' => '#111c2d',
                    'align' => 'end',
                    'flex' => 2,
                    'weight' => 'bold'
                ]
            ]
        ];
    }

    $flexContent = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '🏢 Smart Bill',
                            'weight' => 'bold',
                            'size' => 'lg',
                            'color' => '#0058be',
                            'flex' => 3
                        ],
                        [
                            'type' => 'text',
                            'text' => $statusText,
                            'size' => 'xs',
                            'color' => $statusColor,
                            'align' => 'end',
                            'flex' => 2,
                            'weight' => 'bold'
                        ]
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => 'ใบแจ้งหนี้ประจำเดือน ' . $billingMonth,
                    'size' => 'xs',
                    'color' => '#727785',
                    'margin' => 'sm'
                ]
            ],
            'backgroundColor' => '#f0f3ff',
            'paddingAll' => '20px'
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => array_merge(
                [
                    // Room info
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => 'ห้อง ' . $room['room_number'],
                                'weight' => 'bold',
                                'size' => 'md',
                                'color' => '#111c2d'
                            ],
                            [
                                'type' => 'text',
                                'text' => $room['building_name'] ?? '',
                                'size' => 'sm',
                                'color' => '#727785',
                                'align' => 'end'
                            ]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => $tenant['first_name'] . ' ' . ($tenant['last_name'] ?? ''),
                        'size' => 'sm',
                        'color' => '#424754',
                        'margin' => 'sm'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'lg'
                    ],
                    [
                        'type' => 'text',
                        'text' => 'รายละเอียด',
                        'size' => 'xs',
                        'color' => '#727785',
                        'weight' => 'bold',
                        'margin' => 'lg'
                    ]
                ],
                $itemContents,
                [
                    [
                        'type' => 'separator',
                        'margin' => 'lg'
                    ],
                    // Total
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => '💰 ยอดรวมทั้งสิ้น',
                                'size' => 'md',
                                'color' => '#111c2d',
                                'weight' => 'bold',
                                'flex' => 3
                            ],
                            [
                                'type' => 'text',
                                'text' => '฿' . number_format($invoice['total_amount'], 2),
                                'size' => 'lg',
                                'color' => '#0058be',
                                'align' => 'end',
                                'weight' => 'bold',
                                'flex' => 2
                            ]
                        ]
                    ],
                    // Due date
                    [
                        'type' => 'text',
                        'text' => '📅 ครบกำหนดชำระ ' . formatDate($invoice['due_date'], 'd/m/Y'),
                        'size' => 'xs',
                        'color' => '#727785',
                        'margin' => 'md'
                    ]
                ]
            ),
            'paddingAll' => '20px',
            'spacing' => 'sm'
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'uri',
                        'label' => '💳 ชำระเงิน',
                        'uri' => $liffUrl . '?action=pay&invoice_id=' . $invoice['id']
                    ],
                    'style' => 'primary',
                    'color' => '#0058be',
                    'height' => 'md'
                ],
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'uri',
                        'label' => '📄 ดูรายละเอียด',
                        'uri' => $liffUrl . '?invoice_id=' . $invoice['id']
                    ],
                    'style' => 'secondary',
                    'height' => 'sm',
                    'margin' => 'sm'
                ]
            ],
            'paddingAll' => '20px',
            'spacing' => 'sm'
        ]
    ];

    return [
        'type' => 'flex',
        'altText' => '📄 ใบแจ้งหนี้ ห้อง ' . $room['room_number'] . ' ยอด ฿' . number_format($invoice['total_amount'], 2),
        'contents' => $flexContent
    ];
}

/**
 * Send bill notification via LINE Flex Message
 */
function sendFlexBillNotification(int $invoiceId): array {
    $db = getDB();

    // Get invoice data
    $stmt = $db->prepare("
        SELECT i.*, r.room_number, b.name as building_name,
               t.id as tenant_id, t.first_name, t.last_name, t.phone, t.line_user_id
        FROM invoices i
        JOIN rooms r ON i.room_id = r.id
        LEFT JOIN buildings b ON r.building_id = b.id
        JOIN tenants t ON i.tenant_id = t.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $data = $stmt->fetch();

    if (!$data) {
        return ['success' => false, 'message' => 'ไม่พบบิล'];
    }

    if (empty($data['line_user_id'])) {
        return ['success' => false, 'message' => 'ผู้เช่าห้อง ' . $data['room_number'] . ' ยังไม่ได้ลงทะเบียน LINE'];
    }

    // Get invoice items
    $items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order");
    $items->execute([$invoiceId]);
    $itemList = $items->fetchAll();

    // Build Flex Message
    $invoice = $data;
    $tenant = ['first_name' => $data['first_name'], 'last_name' => $data['last_name']];
    $room = ['room_number' => $data['room_number'], 'building_name' => $data['building_name']];

    $flexMessage = buildBillFlexMessage($invoice, $itemList, $tenant, $room);

    // Send via LINE Push
    $sent = pushLineMessage($data['line_user_id'], [$flexMessage]);

    if ($sent) {
        // Update invoice status
        $db->prepare("UPDATE invoices SET sent_via_line = 1, sent_at = NOW(), status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END WHERE id = ?")
           ->execute([$invoiceId]);

        // Log notification
        $db->prepare("
            INSERT INTO notifications (tenant_id, invoice_id, channel, type, message, status, sent_at)
            VALUES (?, ?, 'line', 'bill_sent', ?, 'sent', NOW())
        ")->execute([$data['tenant_id'], $invoiceId, 'Flex Message บิลห้อง ' . $data['room_number']]);

        return ['success' => true, 'message' => 'ส่งแจ้งเตือนไปยัง LINE สำเร็จ'];
    }

    return ['success' => false, 'message' => 'ส่ง LINE ไม่สำเร็จ กรุณาตรวจสอบ LINE Channel Token'];
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

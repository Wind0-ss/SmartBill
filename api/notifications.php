<?php
/**
 * Notifications API — Fetch admin notifications from activity logs + payments + LINE events
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    // Get recent notifications for the bell icon
    case 'recent':
        requireLoginAPI();

        // Gather notifications from multiple sources
        $notifications = [];

        // 1. Pending payments (slip uploaded, waiting approval)
        $pendingPayments = $db->query("
            SELECT p.id, p.amount, p.created_at,
                   r.room_number,
                   CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            JOIN rooms r ON i.room_id = r.id
            JOIN tenants t ON p.tenant_id = t.id
            WHERE p.status = 'pending'
            ORDER BY p.created_at DESC LIMIT 10
        ")->fetchAll();

        foreach ($pendingPayments as $p) {
            $notifications[] = [
                'id' => 'pay_' . $p['id'],
                'type' => 'payment_pending',
                'icon' => 'receipt_long',
                'color' => 'text-tertiary',
                'bg' => 'bg-orange-50',
                'title' => 'สลิปรอตรวจสอบ',
                'message' => "ห้อง {$p['room_number']} — {$p['tenant_name']} ส่งสลิป ฿" . number_format($p['amount'], 2),
                'url' => APP_URL . '/admin/payments.php',
                'time' => $p['created_at'],
                'time_ago' => timeAgo($p['created_at'])
            ];
        }

        // 2. Overdue invoices
        $overdue = $db->query("
            SELECT i.id, i.total_amount, i.due_date, r.room_number,
                   CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name
            FROM invoices i
            JOIN rooms r ON i.room_id = r.id
            JOIN tenants t ON i.tenant_id = t.id
            WHERE i.status = 'overdue'
            ORDER BY i.due_date ASC LIMIT 5
        ")->fetchAll();

        foreach ($overdue as $o) {
            $notifications[] = [
                'id' => 'overdue_' . $o['id'],
                'type' => 'invoice_overdue',
                'icon' => 'warning',
                'color' => 'text-error',
                'bg' => 'bg-red-50',
                'title' => 'บิลเลยกำหนด',
                'message' => "ห้อง {$o['room_number']} — ฿" . number_format($o['total_amount'], 2) . " (ครบกำหนด " . formatDate($o['due_date'], 'd/m/Y') . ")",
                'url' => APP_URL . '/admin/bills.php?status=overdue',
                'time' => $o['due_date'],
                'time_ago' => 'เลยกำหนด'
            ];
        }

        // 3. New tenant LINE registrations (recent)
        $recentRegistrations = $db->query("
            SELECT t.id, t.first_name, t.last_name, t.updated_at, r.room_number
            FROM tenants t
            JOIN rooms r ON t.room_id = r.id
            WHERE t.line_user_id IS NOT NULL AND t.line_user_id != ''
              AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY t.updated_at DESC LIMIT 5
        ")->fetchAll();

        foreach ($recentRegistrations as $reg) {
            $notifications[] = [
                'id' => 'reg_' . $reg['id'],
                'type' => 'line_registered',
                'icon' => 'person_add',
                'color' => 'text-secondary',
                'bg' => 'bg-green-50',
                'title' => 'ลงทะเบียน LINE',
                'message' => "ห้อง {$reg['room_number']} — {$reg['first_name']} {$reg['last_name']} เชื่อมต่อ LINE แล้ว",
                'url' => APP_URL . '/admin/tenants.php',
                'time' => $reg['updated_at'],
                'time_ago' => timeAgo($reg['updated_at'])
            ];
        }

        // 4. Recent activity (last 24h)
        $recentActivity = $db->query("
            SELECT al.*, u.full_name
            FROM activity_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND al.action NOT LIKE '%login%' AND al.action NOT LIKE '%logout%'
            ORDER BY al.created_at DESC LIMIT 5
        ")->fetchAll();

        foreach ($recentActivity as $act) {
            $notifications[] = [
                'id' => 'act_' . $act['id'],
                'type' => 'activity',
                'icon' => 'info',
                'color' => 'text-primary',
                'bg' => 'bg-blue-50',
                'title' => $act['full_name'] ?? 'ระบบ',
                'message' => $act['action'],
                'url' => '#',
                'time' => $act['created_at'],
                'time_ago' => timeAgo($act['created_at'])
            ];
        }

        // Sort by time DESC
        usort($notifications, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));

        // Limit to 15
        $notifications = array_slice($notifications, 0, 15);

        // Count unread (pending payments = most important)
        $unreadCount = count($pendingPayments) + count($overdue);

        jsonResponse([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

/**
 * Format time ago in Thai
 */
function timeAgo(string $datetime): string {
    $now = time();
    $diff = $now - strtotime($datetime);

    if ($diff < 60) return 'เมื่อสักครู่';
    if ($diff < 3600) return floor($diff / 60) . ' นาทีที่แล้ว';
    if ($diff < 86400) return floor($diff / 3600) . ' ชม.ที่แล้ว';
    if ($diff < 604800) return floor($diff / 86400) . ' วันที่แล้ว';
    return formatDate($datetime, 'd/m/Y');
}

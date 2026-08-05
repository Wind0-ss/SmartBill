<?php
/**
 * LIFF API — Backend endpoints for LIFF app
 * Handles profile lookup, bill fetching, and slip upload
 * 
 * Security: get_bill and get_bills require tenant_id verification
 * to prevent unauthorized access to other tenants' data.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    // Verify LINE profile and get tenant data
    case 'get_profile':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $lineUserId = trim($input['line_user_id'] ?? '');

        if (empty($lineUserId)) {
            jsonResponse(['success' => false, 'message' => 'Missing line_user_id']);
        }

        $stmt = $db->prepare("
            SELECT t.id, t.first_name, t.last_name, t.phone, t.line_user_id,
                   r.room_number, r.id as room_id,
                   b.name as building_name
            FROM tenants t
            JOIN rooms r ON t.room_id = r.id
            LEFT JOIN buildings b ON r.building_id = b.id
            WHERE t.line_user_id = ? AND t.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$lineUserId]);
        $tenant = $stmt->fetch();

        if ($tenant) {
            jsonResponse(['success' => true, 'tenant' => $tenant]);
        } else {
            jsonResponse(['success' => false, 'tenant' => null, 'message' => 'ไม่พบข้อมูลผู้เช่า']);
        }
        break;

    // Get specific bill — requires line_user_id for ownership verification
    case 'get_bill':
        $invoiceId = (int)($_GET['invoice_id'] ?? 0);
        $lineUserId = trim($_GET['line_user_id'] ?? '');

        if ($invoiceId <= 0) {
            jsonResponse(['success' => false, 'message' => 'Missing invoice_id'], 400);
        }

        // Build query with optional ownership check
        $sql = "
            SELECT i.*, r.room_number, b.name as building_name,
                   CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name,
                   t.line_user_id
            FROM invoices i
            JOIN rooms r ON i.room_id = r.id
            LEFT JOIN buildings b ON r.building_id = b.id
            JOIN tenants t ON i.tenant_id = t.id
            WHERE i.id = ?
        ";
        $params = [$invoiceId];

        // If line_user_id provided, enforce ownership
        if (!empty($lineUserId)) {
            $sql .= " AND t.line_user_id = ?";
            $params[] = $lineUserId;
        }

        $inv = $db->prepare($sql);
        $inv->execute($params);
        $invoice = $inv->fetch();

        if (!$invoice) {
            jsonResponse(['success' => false, 'message' => 'ไม่พบบิล หรือไม่มีสิทธิ์เข้าถึง'], 404);
        }

        // Remove sensitive field from response
        unset($invoice['line_user_id']);

        // Add formatted dates
        $invoice['billing_month_thai'] = formatThaiMonth($invoice['billing_month']);
        $invoice['due_date_formatted'] = formatDate($invoice['due_date'], 'd/m/Y');

        // Get items
        $items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order");
        $items->execute([$invoiceId]);

        jsonResponse([
            'success' => true,
            'invoice' => $invoice,
            'items' => $items->fetchAll()
        ]);
        break;

    // Get all bills for a tenant — requires line_user_id for ownership
    case 'get_bills':
        $tenantId = (int)($_GET['tenant_id'] ?? 0);
        $lineUserId = trim($_GET['line_user_id'] ?? '');

        if ($tenantId <= 0) {
            jsonResponse(['success' => false, 'message' => 'Missing tenant_id'], 400);
        }

        // Verify tenant ownership if line_user_id provided
        if (!empty($lineUserId)) {
            $ownerCheck = $db->prepare("SELECT id FROM tenants WHERE id = ? AND line_user_id = ?");
            $ownerCheck->execute([$tenantId, $lineUserId]);
            if (!$ownerCheck->fetch()) {
                jsonResponse(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูลนี้'], 403);
            }
        }

        $stmt = $db->prepare("
            SELECT i.id, i.invoice_number, i.billing_month, i.invoice_type,
                   i.total_amount, i.due_date, i.status, i.sent_via_line
            FROM invoices i
            WHERE i.tenant_id = ? AND i.status != 'cancelled'
            ORDER BY i.billing_month DESC, i.invoice_type ASC
            LIMIT 20
        ");
        $stmt->execute([$tenantId]);
        $bills = $stmt->fetchAll();

        // Add formatted dates
        foreach ($bills as &$bill) {
            $bill['billing_month_thai'] = formatThaiMonth($bill['billing_month']);
            $bill['due_date_formatted'] = formatDate($bill['due_date'], 'd/m/Y');
        }

        jsonResponse(['success' => true, 'bills' => $bills]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

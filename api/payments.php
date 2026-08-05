<?php
/**
 * Payments API — Approve/Reject, Upload slip
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/line_helpers.php';

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'approve':
        requireLoginAPI();
        $input = getInput();
        $paymentId = (int)($input['payment_id'] ?? 0);
        
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE payments SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
               ->execute([$_SESSION['user_id'], $paymentId]);
            
            // Update invoice status
            $stmt = $db->prepare("SELECT invoice_id FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $invId = $stmt->fetchColumn();
            
            if ($invId) {
                $db->prepare("UPDATE invoices SET status = 'paid', updated_at = NOW() WHERE id = ?")->execute([$invId]);
            }
            
            $db->commit();
            
            // Send LINE confirmation to tenant
            sendPaymentConfirmation($paymentId);
            
            logActivity($_SESSION['user_id'], 'อนุมัติการชำระเงิน', 'payment', $paymentId);
            jsonResponse(['success' => true, 'message' => 'อนุมัติเรียบร้อย และแจ้งผู้เช่าผ่าน LINE แล้ว']);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
        break;
    
    case 'reject':
        requireLoginAPI();
        $input = getInput();
        $paymentId = (int)($input['payment_id'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        
        $db->prepare("UPDATE payments SET status = 'rejected', reject_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
           ->execute([$reason, $_SESSION['user_id'], $paymentId]);
        
        logActivity($_SESSION['user_id'], 'ปฏิเสธการชำระเงิน: ' . $reason, 'payment', $paymentId);
        jsonResponse(['success' => true, 'message' => 'ปฏิเสธเรียบร้อย']);
        break;
    
    case 'upload_slip':
        // Public endpoint — no login required (for tenant)
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $roomNumber = trim($_POST['room_number'] ?? '');
        
        if ($invoiceId <= 0 || empty($_FILES['slip'])) {
            jsonResponse(['success' => false, 'message' => 'ข้อมูลไม่ครบ'], 400);
        }
        
        $upload = uploadFile($_FILES['slip'], SLIP_UPLOAD_DIR);
        if (!$upload['success']) {
            jsonResponse(['success' => false, 'message' => $upload['message']], 400);
        }
        
        $db->beginTransaction();
        try {
            $invoice = $db->prepare("SELECT * FROM invoices WHERE id = ?");
            $invoice->execute([$invoiceId]);
            $inv = $invoice->fetch();
            
            if (!$inv) {
                jsonResponse(['success' => false, 'message' => 'ไม่พบบิล'], 404);
            }
            
            // Create payment
            $db->prepare("
                INSERT INTO payments (invoice_id, tenant_id, amount, payment_method, payment_date, status)
                VALUES (?, ?, ?, 'bank_transfer', NOW(), 'pending')
            ")->execute([$invoiceId, $inv['tenant_id'], $inv['total_amount']]);
            $paymentId = $db->lastInsertId();
            
            // Save slip
            $db->prepare("
                INSERT INTO payment_slips (payment_id, image_path)
                VALUES (?, ?)
            ")->execute([$paymentId, 'uploads/slips/' . $upload['filename']]);
            
            // Update invoice status
            $db->prepare("UPDATE invoices SET status = 'sent' WHERE id = ? AND status = 'draft'")
               ->execute([$invoiceId]);
            
            $db->commit();
            jsonResponse(['success' => true, 'message' => 'อัปโหลดสลิปเรียบร้อย ระบบจะตรวจสอบภายใน 24 ชม.']);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
        break;
    
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

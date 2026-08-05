<?php
/**
 * AutoBill — Helper Functions
 * Utility functions used across the application
 */

/**
 * Format number as Thai Baht currency
 */
function formatCurrency(float $amount): string {
    return '฿' . number_format($amount, 2);
}

/**
 * Format date to Thai format
 */
function formatDate(?string $date, string $format = 'd/m/Y'): string {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

/**
 * Format date to Thai month name
 */
function formatThaiMonth(string $date): string {
    $months = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม',
        4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน',
        7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน',
        10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
    ];
    $m = (int)date('n', strtotime($date));
    $y = (int)date('Y', strtotime($date)) + 543;
    return $months[$m] . ' ' . $y;
}

/**
 * Generate automatic invoice number: INV-YYYYMM-XXXX
 */
function generateInvoiceNumber(string $billingMonth, string $type = 'rent'): string {
    $db = getDB();
    $basePrefix = getSetting('invoice_prefix', 'INV');
    $prefix = $basePrefix . ($type === 'utility' ? '-U' : '-R');
    $monthStr = date('Ym', strtotime($billingMonth));
    
    $stmt = $db->prepare("
        SELECT invoice_number FROM invoices 
        WHERE invoice_number LIKE ? 
        ORDER BY invoice_number DESC LIMIT 1
    ");
    $stmt->execute([$prefix . '-' . $monthStr . '-%']);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $parts = explode('-', $last);
        $seq = (int)end($parts) + 1;
    } else {
        $seq = 1;
    }
    
    return $prefix . '-' . $monthStr . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Handle file upload
 */
function uploadFile(array $file, string $targetDir, array $allowedTypes = ['image/jpeg', 'image/png', 'image/webp']): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed with error code: ' . $file['error']];
    }
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'message' => 'ประเภทไฟล์ไม่รองรับ กรุณาอัปโหลด JPG, PNG หรือ WebP'];
    }
    
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'ไฟล์มีขนาดเกิน 10MB'];
    }
    
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $filepath = $targetDir . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename, 'path' => $filepath];
    }
    
    return ['success' => false, 'message' => 'ไม่สามารถบันทึกไฟล์ได้'];
}

/**
 * Sanitize input to prevent XSS
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Send JSON response and exit
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get request input (POST/PUT/JSON body)
 */
function getInput(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
    
    return $_POST;
}

/**
 * Redirect with optional flash message
 */
function redirect(string $url, string $message = null, string $type = 'success'): void {
    if ($message) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Get and clear flash message
 */
function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Calculate utility cost for given consumption
 */
function calculateUtilityCost(float $consumption, string $type, int $buildingId = null, int $roomId = null): float {
    $db = getDB();
    
    // Try room-specific rate first, then building, then global
    $stmt = $db->prepare("
        SELECT rate_per_unit FROM utility_rates 
        WHERE rate_type = ? AND is_active = 1
          AND (room_id = ? OR (room_id IS NULL AND building_id = ?) OR (room_id IS NULL AND building_id IS NULL))
          AND effective_date <= CURDATE()
          AND ? BETWEEN min_units AND max_units
        ORDER BY room_id DESC, building_id DESC, effective_date DESC
        LIMIT 1
    ");
    $stmt->execute([$type, $roomId, $buildingId, $consumption]);
    $rate = $stmt->fetchColumn();
    
    if (!$rate) {
        $rate = ($type === 'water') ? getSetting('water_rate', 18.00) : getSetting('electric_rate', 7.50);
    }
    
    return $consumption * $rate;
}

/**
 * Get the current utility rate for a type
 */
function getUtilityRate(string $type, int $buildingId = null, int $roomId = null): float {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT rate_per_unit FROM utility_rates 
        WHERE rate_type = ? AND is_active = 1
          AND (room_id = ? OR (room_id IS NULL AND building_id = ?) OR (room_id IS NULL AND building_id IS NULL))
          AND effective_date <= CURDATE()
        ORDER BY room_id DESC, building_id DESC, effective_date DESC
        LIMIT 1
    ");
    $stmt->execute([$type, $roomId, $buildingId]);
    $rate = $stmt->fetchColumn();
    
    return $rate ?: (($type === 'water') ? getSetting('water_rate', 18.00) : getSetting('electric_rate', 7.50));
}

/**
 * Format room display with building name
 */
function formatRoomDisplay(array $room): string {
    $parts = ['ห้อง ' . $room['room_number']];
    if (!empty($room['building_name'])) {
        $parts[] = $room['building_name'];
    }
    if (!empty($room['tenant_name'])) {
        $parts[] = '(' . $room['tenant_name'] . ')';
    }
    return implode(' - ', $parts);
}

/**
 * Get status badge HTML
 */
function statusBadge(string $status): string {
    $classes = [
        'paid'      => 'bg-green-50 text-green-700 border-green-200',
        'approved'  => 'bg-green-50 text-green-700 border-green-200',
        'active'    => 'bg-green-50 text-green-700 border-green-200',
        'occupied'  => 'bg-blue-50 text-blue-700 border-blue-200',
        'sent'      => 'bg-blue-50 text-blue-700 border-blue-200',
        'pending'   => 'bg-orange-50 text-orange-700 border-orange-200',
        'draft'     => 'bg-gray-50 text-gray-600 border-gray-200',
        'vacant'    => 'bg-gray-50 text-gray-600 border-gray-200',
        'overdue'   => 'bg-red-50 text-red-700 border-red-200',
        'rejected'  => 'bg-red-50 text-red-700 border-red-200',
        'cancelled' => 'bg-red-50 text-red-700 border-red-200',
        'expired'   => 'bg-red-50 text-red-700 border-red-200',
    ];
    
    $labels = [
        'paid' => 'ชำระแล้ว', 'approved' => 'อนุมัติ', 'active' => 'ใช้งาน',
        'occupied' => 'มีผู้เช่า', 'sent' => 'ส่งแล้ว', 'pending' => 'รอดำเนินการ',
        'draft' => 'แบบร่าง', 'vacant' => 'ว่าง', 'overdue' => 'เลยกำหนด',
        'rejected' => 'ปฏิเสธ', 'cancelled' => 'ยกเลิก', 'expired' => 'หมดอายุ',
        'maintenance' => 'ซ่อมบำรุง', 'reserved' => 'จอง',
    ];
    
    $class = $classes[$status] ?? 'bg-gray-50 text-gray-600 border-gray-200';
    $label = $labels[$status] ?? ucfirst($status);
    
    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border ' . $class . '">' . $label . '</span>';
}

/**
 * Pagination helper
 */
function paginate(int $total, int $perPage = 10, int $currentPage = 1): array {
    $totalPages = max(1, ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    
    return [
        'total' => $total,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages
    ];
}

<?php
/**
 * Admin Management Page — Manage admin users and generate invitation links
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLogin();
requireRole('super_admin', 'admin');

$db = getDB();
$pageTitle = 'ผู้ดูแลระบบ';
$currentUser = currentUser();

// Get config status
$lineLoginChannelId = getSetting('line_login_channel_id', '');
$lineLoginChannelSecret = getSetting('line_login_channel_secret', '');
$hasLineConfig = !empty($lineLoginChannelId) && !empty($lineLoginChannelSecret);

// Fetch current user details to check LINE binding
$stmt = $db->prepare("SELECT line_user_id FROM users WHERE id = ?");
$stmt->execute([$currentUser['id']]);
$currentUserLineId = $stmt->fetchColumn();

// --- Action Handlers ---

// 1. Generate Invitation Link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_invite') {
    if (!$hasLineConfig) {
        redirect(APP_URL . '/admin/admins.php', 'ไม่สามารถสร้างลิงก์เชิญได้ เนื่องจากยังไม่ได้ตั้งค่า LINE Login', 'error');
    }
    
    $roleId = (int)($_POST['role_id'] ?? 2); // default to Admin (2)
    $expiryHours = (int)($_POST['expiry'] ?? 24);
    
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + ($expiryHours * 3600));
    
    try {
        $stmt = $db->prepare("
            INSERT INTO admin_invitations (token, role_id, expires_at, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$token, $roleId, $expiresAt, $currentUser['id']]);
        
        logActivity($currentUser['id'], 'สร้างลิงก์เชิญแอดมินใหม่ (Role ID: ' . $roleId . ')', 'invite', null);
        redirect(APP_URL . '/admin/admins.php', 'สร้างลิงก์เชิญสำเร็จแล้ว!');
    } catch (PDOException $e) {
        redirect(APP_URL . '/admin/admins.php', 'เกิดข้อผิดพลาด: ' . $e->getMessage(), 'error');
    }
}

// 2. Revoke/Delete Invitation
if (isset($_GET['action']) && $_GET['action'] === 'revoke_invite' && isset($_GET['id'])) {
    $inviteId = (int)$_GET['id'];
    try {
        // Delete or mark expired
        $stmt = $db->prepare("DELETE FROM admin_invitations WHERE id = ? AND is_used = 0");
        $stmt->execute([$inviteId]);
        
        logActivity($currentUser['id'], 'เพิกถอนลิงก์เชิญแอดมิน (ID: ' . $inviteId . ')', 'invite', $inviteId);
        redirect(APP_URL . '/admin/admins.php', 'เพิกถอนลิงก์เชิญสำเร็จ');
    } catch (PDOException $e) {
        redirect(APP_URL . '/admin/admins.php', 'เกิดข้อผิดพลาด: ' . $e->getMessage(), 'error');
    }
}

// 3. Toggle Admin Active Status
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && isset($_GET['id'])) {
    $targetUserId = (int)$_GET['id'];
    
    // Prevent self-deactivation
    if ($targetUserId === $currentUser['id']) {
        redirect(APP_URL . '/admin/admins.php', 'ไม่สามารถปิดการใช้งานบัญชีตัวเองได้', 'error');
    }
    
    try {
        // Get current status
        $stmt = $db->prepare("SELECT is_active, role_id FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch();
        
        if ($targetUser) {
            // Prevent modifying super_admin if not super_admin themselves
            if ($targetUser['role_id'] == 1 && $currentUser['role'] !== 'super_admin') {
                redirect(APP_URL . '/admin/admins.php', 'คุณไม่มีสิทธิ์ปิดการใช้งาน Super Admin', 'error');
            }
            
            $newStatus = $targetUser['is_active'] ? 0 : 1;
            $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus, $targetUserId]);
            
            $statusText = $newStatus ? 'เปิดใช้งาน' : 'ระงับการใช้งาน';
            logActivity($currentUser['id'], $statusText . 'ผู้ดูแลระบบ (ID: ' . $targetUserId . ')', 'user', $targetUserId);
            redirect(APP_URL . '/admin/admins.php', 'อัปเดตสถานะผู้ใช้งานสำเร็จ');
        }
    } catch (PDOException $e) {
        redirect(APP_URL . '/admin/admins.php', 'เกิดข้อผิดพลาด: ' . $e->getMessage(), 'error');
    }
}

// --- Data Fetching ---

// 1. Get Roles for form dropdown (only Admin and Staff)
$roles = $db->query("SELECT id, display_name FROM roles WHERE name IN ('admin', 'staff')")->fetchAll();

// 2. Get Admin & Staff users
$admins = $db->query("
    SELECT u.*, r.display_name as role_name, r.name as role_code
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE r.name IN ('super_admin', 'admin', 'staff')
    ORDER BY u.created_at ASC
")->fetchAll();

// 3. Get Active Invitations
$invitations = $db->query("
    SELECT ai.*, r.display_name as role_name, u.full_name as inviter_name
    FROM admin_invitations ai
    JOIN roles r ON ai.role_id = r.id
    JOIN users u ON ai.created_by = u.id
    ORDER BY ai.created_at DESC
")->fetchAll();

include dirname(__DIR__) . '/components/navbar.php';
?>

<div class="mb-8">
    <h1 class="text-3xl font-bold text-on-surface">จัดการผู้ดูแลระบบ</h1>
    <p class="text-on-surface-variant text-sm mt-1">จัดการรายชื่อแอดมิน, พนักงาน และสร้างลิงก์ลงทะเบียนด้วย LINE Login</p>
</div>

<!-- LINE Account Binding Alert -->
<?php if (empty($currentUserLineId)): ?>
<div class="mb-6 p-5 rounded-xl bg-orange-50 border border-orange-200 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div class="flex gap-3">
        <span class="material-symbols-outlined text-orange-600 text-[24px] flex-shrink-0">warning</span>
        <div>
            <h4 class="text-sm font-bold text-orange-800">บัญชีของคุณยังไม่ได้ผูกกับ LINE Login</h4>
            <p class="text-xs text-orange-600 mt-0.5">เพื่อความปลอดภัยสูงสุดและให้สามารถเข้าสู่ระบบในครั้งถัดไปได้ด้วย LINE กรุณาผูกบัญชีของคุณทันที</p>
        </div>
    </div>
    <a href="../api/line-login-redirect.php" class="btn btn-secondary text-xs font-semibold whitespace-nowrap bg-orange-600 hover:bg-orange-700 text-white border-none py-2 px-4 rounded-lg flex items-center gap-2">
        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
            <path d="M24 10.304c0-5.369-5.383-9.738-12-9.738-6.616 0-12 4.369-12 9.738 0 4.814 4.269 8.846 10.036 9.564.39.084.922.258 1.058.592.12.296.079.759.038 1.057l-.171 1.026c-.052.312-.252 1.22.108 1.666.216.268.57.292.797.234.227-.058 2.433-1.603 3.427-2.529.742-.693 1.037-1.077 1.451-1.644 2.871-1.745 4.656-4.524 4.656-7.765zm-14.773 3.66c0 .356-.289.645-.644.645h-2.129v-5.614c0-.356.289-.645.644-.645.356 0 .644.289.644.645v4.324h1.485c.355 0 .644.289.644.645zm2.576 0c0 .356-.288.645-.644.645-.355 0-.644-.289-.644-.645v-5.614c0-.356.289-.645.644-.645s.644.289.644.645v5.614zm4.184-1.921c0 .324-.131.62-.344.837l-1.398 1.397v-1.61c0-.356-.289-.645-.644-.645-.356 0-.644.289-.644.645v3.298c0 .356.288.645.644.645h2.129c.355 0 .644-.289.644-.645v-2.079l.957-.957c.214-.217.345-.512.345-.837s-.131-.62-.345-.837c-.439-.44-.44-.439-.877-.879-.217-.215-.512-.346-.838-.346s-.621.131-.837.346l-1.579 1.578v-1.733c0-.356-.289-.645-.644-.645-.356 0-.644.289-.644.645v3.298c0 .356.289.645.644.645h2.129c.356 0 .644-.289.644-.645v-2.079zm2.842 0c0 .356-.289.645-.644.645h-1.485v.851h1.485c.355 0 .644.289.644.645s-.289.645-.644.645h-2.129c-.356 0-.644-.289-.644-.645v-5.614c0-.356.289-.645.644-.645h2.129c.355 0 .644.289.644.645s-.289.645-.644.645h-1.485v.85h1.485c.355 0 .644.289.644.645s-.289.647-.644.647z"/>
        </svg>
        ผูกบัญชี LINE ตอนนี้
    </a>
</div>
<?php endif; ?>

<!-- Check Settings Alert -->
<?php if (!$hasLineConfig): ?>
<div class="mb-6 p-5 rounded-xl bg-red-50 border border-red-200 flex items-start gap-3">
    <span class="material-symbols-outlined text-red-600 text-[24px] flex-shrink-0">error</span>
    <div>
        <h4 class="text-sm font-bold text-red-800">ระบบ LINE Login ยังไม่เปิดใช้งาน</h4>
        <p class="text-xs text-red-600 mt-1">กรุณาไปที่หน้า <a href="settings.php" class="underline font-bold">ตั้งค่าระบบ</a> เพื่อกรอก LINE Login Channel ID และ Channel Secret ก่อนใช้งานสร้างลิงก์เชิญผู้ดูแล</p>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Left Panel: Invite Generator -->
    <section class="lg:col-span-5 space-y-6">
        <div class="bg-white border border-outline-variant rounded-xl p-6 shadow-sm">
            <div class="flex items-center gap-3 mb-6">
                <span class="material-symbols-outlined text-primary p-2 bg-primary-fixed/30 rounded-lg">share_reviews</span>
                <h2 class="text-xl font-semibold text-on-surface">สร้างลิงก์คำเชิญแอดมิน</h2>
            </div>
            
            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="action" value="generate_invite">
                
                <div>
                    <label class="form-label" for="role_id">สิทธิ์ผู้ดูแลที่ต้องการเชิญ</label>
                    <select id="role_id" name="role_id" class="form-input" <?= !$hasLineConfig ? 'disabled' : '' ?>>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= sanitize($role['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="form-label" for="expiry">ระยะเวลาหมดอายุลิงก์</label>
                    <select id="expiry" name="expiry" class="form-input" <?= !$hasLineConfig ? 'disabled' : '' ?>>
                        <option value="24">24 ชั่วโมง (1 วัน)</option>
                        <option value="72">72 ชั่วโมง (3 วัน)</option>
                        <option value="168" selected>7 วัน</option>
                        <option value="720">30 วัน</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary w-full mt-4" <?= !$hasLineConfig ? 'disabled' : '' ?>>
                    <span class="material-symbols-outlined text-[18px]">link</span>
                    สร้างลิงก์เชิญ
                </button>
            </form>
        </div>
        
        <!-- Active Invites List -->
        <div class="bg-white border border-outline-variant rounded-xl shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex items-center justify-between">
                <h3 class="font-bold text-sm text-on-surface uppercase tracking-wider">ลิงก์เชิญแอดมินทั้งหมด</h3>
                <span class="text-xs text-outline"><?= count($invitations) ?> ลิงก์</span>
            </div>
            
            <div class="divide-y divide-outline-variant/50 max-h-[360px] overflow-y-auto">
                <?php if (empty($invitations)): ?>
                    <div class="p-6 text-center text-sm text-on-surface-variant">ยังไม่มีลิงก์เชิญที่สร้าง</div>
                <?php else: ?>
                    <?php foreach ($invitations as $invite): 
                        $inviteUrl = APP_URL . '/invite.php?token=' . $invite['token'];
                        $isExpired = strtotime($invite['expires_at']) < time();
                        $statusClass = 'bg-gray-100 text-gray-700';
                        $statusLabel = 'ยังไม่ใช้';
                        
                        if ($invite['is_used']) {
                            $statusClass = 'bg-green-50 text-green-700 border-green-200 border';
                            $statusLabel = 'ใช้งานแล้ว';
                        } elseif ($isExpired) {
                            $statusClass = 'bg-red-50 text-red-700 border-red-200 border';
                            $statusLabel = 'หมดอายุ';
                        } else {
                            $statusClass = 'bg-blue-50 text-blue-700 border-blue-200 border';
                            $statusLabel = 'ใช้งานได้';
                        }
                    ?>
                    <div class="p-4 space-y-2.5">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-bold text-primary"><?= sanitize($invite['role_name']) ?></span>
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-semibold <?= $statusClass ?>">
                                <?= $statusLabel ?>
                            </span>
                        </div>
                        
                        <?php if (!$invite['is_used'] && !$isExpired): ?>
                        <div class="flex gap-1.5 items-center">
                            <input type="text" readonly value="<?= $inviteUrl ?>" 
                                   class="bg-surface-container text-[11px] font-mono p-2 rounded-lg border border-outline-variant flex-grow select-all focus:outline-none"
                                   id="inviteToken_<?= $invite['id'] ?>">
                            <button onclick="copyInviteLink('<?= $inviteUrl ?>', this)" 
                                    class="p-2 bg-surface-container border border-outline-variant hover:bg-primary-fixed/20 hover:text-primary rounded-lg transition-colors flex-shrink-0 text-on-surface-variant"
                                    title="คัดลอกลิงก์">
                                <span class="material-symbols-outlined text-[16px]">content_copy</span>
                            </button>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex justify-between items-center text-[10px] text-outline">
                            <span>ผู้สร้าง: <?= sanitize($invite['inviter_name']) ?></span>
                            <span>หมดอายุ: <?= formatDate($invite['expires_at'], 'd/m H:i') ?></span>
                        </div>
                        
                        <?php if (!$invite['is_used']): ?>
                        <div class="text-right">
                            <a href="admins.php?action=revoke_invite&id=<?= $invite['id'] ?>" 
                               class="text-[10px] text-error font-semibold hover:underline flex items-center gap-1 justify-end"
                               onclick="return confirm('คุณต้องการเพิกถอนลิงก์เชิญนี้ใช่หรือไม่?')">
                                <span class="material-symbols-outlined text-[12px]">delete</span>
                                ลบลิงก์
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
    
    <!-- Right Panel: Admin List -->
    <section class="lg:col-span-7">
        <div class="bg-white border border-outline-variant rounded-xl overflow-hidden shadow-sm">
            <div class="px-6 py-4 border-b border-outline-variant bg-surface-container-low flex justify-between items-center">
                <h3 class="text-lg font-semibold text-on-surface">รายชื่อผู้ดูแลระบบทั้งหมด</h3>
                <span class="px-3 py-1 bg-primary-fixed/20 text-primary text-xs font-bold rounded-full"><?= count($admins) ?> คน</span>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-surface-container-low border-b border-outline-variant">
                            <th class="px-6 py-3.5 text-xs font-semibold text-on-surface-variant uppercase tracking-wider">ชื่อ</th>
                            <th class="px-6 py-3.5 text-xs font-semibold text-on-surface-variant uppercase tracking-wider">สิทธิ์</th>
                            <th class="px-6 py-3.5 text-xs font-semibold text-on-surface-variant uppercase tracking-wider text-center">ผูก LINE</th>
                            <th class="px-6 py-3.5 text-xs font-semibold text-on-surface-variant uppercase tracking-wider">เข้าสู่ระบบล่าสุด</th>
                            <th class="px-6 py-3.5 text-xs font-semibold text-on-surface-variant uppercase tracking-wider text-center">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/50">
                        <?php foreach ($admins as $admin): 
                            $isSelf = $admin['id'] === $currentUser['id'];
                        ?>
                        <tr class="hover:bg-surface-container-low/30 transition-colors">
                            <td class="px-6 py-4 flex items-center gap-3">
                                <?php if ($admin['avatar']): ?>
                                    <img src="<?= sanitize($admin['avatar']) ?>" class="w-8 h-8 rounded-full border border-outline-variant flex-shrink-0" alt="Avatar">
                                <?php else: ?>
                                    <div class="w-8 h-8 rounded-full bg-primary-fixed flex items-center justify-center text-primary font-bold text-xs flex-shrink-0">
                                        <?= mb_substr($admin['full_name'], 0, 1) ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div class="text-sm font-semibold text-on-surface">
                                        <?= sanitize($admin['full_name']) ?>
                                        <?php if ($isSelf): ?>
                                            <span class="ml-1 text-[10px] font-bold text-primary bg-primary-fixed/30 px-1.5 py-0.5 rounded">ตัวคุณ</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[10px] text-outline font-mono"><?= sanitize($admin['username']) ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-xs font-medium text-on-surface"><?= sanitize($admin['role_name']) ?></span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($admin['line_user_id']): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-green-50 text-green-700 border border-green-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-600"></span>
                                        ผูกแล้ว
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-gray-50 text-gray-500 border border-gray-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                        ยังไม่ผูก
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-on-surface-variant font-mono">
                                <?= $admin['last_login'] ? formatDate($admin['last_login'], 'd M Y H:i') : '-' ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($isSelf): ?>
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold bg-green-50 text-green-700 border border-green-200">ใช้งาน</span>
                                <?php else: ?>
                                    <a href="admins.php?action=toggle_status&id=<?= $admin['id'] ?>" 
                                       class="inline-flex px-2.5 py-1 rounded-lg text-[10px] font-bold border transition-all cursor-pointer <?= $admin['is_active'] ? 'bg-green-50 text-green-700 border-green-200 hover:bg-red-50 hover:text-red-700 hover:border-red-200' : 'bg-red-50 text-red-700 border-red-200 hover:bg-green-50 hover:text-green-700 hover:border-green-200' ?>"
                                       onclick="return confirm('คุณต้องการอัปเดตสถานะการใช้งานของแอดมินคนนี้ใช่หรือไม่?')">
                                        <?= $admin['is_active'] ? 'เปิดใช้งาน' : 'ถูกระงับ' ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
function copyInviteLink(url, btn) {
    navigator.clipboard.writeText(url).then(function() {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="material-symbols-outlined text-[16px] text-green-600">done</span>';
        btn.classList.add('bg-green-50', 'border-green-300');
        
        setTimeout(function() {
            btn.innerHTML = originalHtml;
            btn.classList.remove('bg-green-50', 'border-green-300');
        }, 1500);
    });
}
</script>

<?php include dirname(__DIR__) . '/components/footer.php'; ?>

<?php
/**
 * Shared Navigation Bar Component
 * Used across all admin pages
 */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$user = currentUser();
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Smart Bill' ?> — ระบบบริหารหอพัก</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Sarabun:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#0058be",
                        "primary-container": "#2170e4",
                        "on-primary": "#ffffff",
                        "on-primary-container": "#fefcff",
                        "secondary": "#006c49",
                        "secondary-container": "#6cf8bb",
                        "secondary-fixed-dim": "#4edea3",
                        "on-secondary": "#ffffff",
                        "on-secondary-container": "#00714d",
                        "tertiary": "#924700",
                        "tertiary-container": "#b75b00",
                        "tertiary-fixed-dim": "#ffb786",
                        "on-tertiary": "#ffffff",
                        "error": "#ba1a1a",
                        "error-container": "#ffdad6",
                        "on-error": "#ffffff",
                        "on-error-container": "#93000a",
                        "surface": "#f9f9ff",
                        "surface-dim": "#cfdaf2",
                        "surface-container-lowest": "#ffffff",
                        "surface-container-low": "#f0f3ff",
                        "surface-container": "#e7eeff",
                        "surface-container-high": "#dee8ff",
                        "surface-container-highest": "#d8e3fb",
                        "on-surface": "#111c2d",
                        "on-surface-variant": "#424754",
                        "outline": "#727785",
                        "outline-variant": "#c2c6d6",
                        "inverse-surface": "#263143",
                        "inverse-on-surface": "#ecf1ff",
                        "background": "#f9f9ff",
                        "on-background": "#111c2d",
                        "primary-fixed": "#d8e2ff",
                    },
                    fontFamily: {
                        sans: ["Inter", "Sarabun", "sans-serif"],
                    },
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body class="bg-surface text-on-surface min-h-screen flex flex-col">

<!-- Flash Message -->
<?php if ($flash): ?>
<div id="flashMessage" data-type="<?= $flash['type'] ?>" data-message="<?= htmlspecialchars($flash['message']) ?>"></div>
<?php endif; ?>

<!-- Top Navigation Bar -->
<header class="bg-surface border-b border-outline-variant sticky top-0 z-50">
    <div class="flex justify-between items-center w-full px-4 md:px-12 max-w-[1280px] mx-auto h-16">
        <!-- Logo + Nav -->
        <div class="flex items-center gap-10">
            <a href="<?= APP_URL ?>/admin/" class="text-2xl font-extrabold text-primary tracking-tight no-underline">
                Smart Bill
            </a>
            <nav class="hidden md:flex gap-6 items-center">
                <?php
                $navItems = [
                    ['url' => APP_URL . '/admin/', 'page' => 'index', 'label' => 'ภาพรวม', 'icon' => 'dashboard'],
                    ['url' => APP_URL . '/admin/buildings.php', 'page' => 'buildings', 'label' => 'อาคาร', 'icon' => 'apartment'],
                    ['url' => APP_URL . '/admin/rooms.php', 'page' => 'rooms', 'label' => 'ห้องพัก', 'icon' => 'meeting_room'],
                    ['url' => APP_URL . '/admin/tenants.php', 'page' => 'tenants', 'label' => 'ผู้เช่า', 'icon' => 'group'],
                    ['url' => APP_URL . '/admin/meters.php', 'page' => 'meters', 'label' => 'บันทึกมิเตอร์', 'icon' => 'edit_note'],
                    ['url' => APP_URL . '/admin/bills.php', 'page' => 'bills', 'label' => 'บิล', 'icon' => 'receipt_long'],
                    ['url' => APP_URL . '/admin/payments.php', 'page' => 'payments', 'label' => 'การชำระ', 'icon' => 'payments'],
                    ['url' => APP_URL . '/admin/reports.php', 'page' => 'reports', 'label' => 'รายงาน', 'icon' => 'assessment'],
                ];
                if (hasRole('super_admin', 'admin')) {
                    $navItems[] = ['url' => APP_URL . '/admin/admins.php', 'page' => 'admins', 'label' => 'ผู้ดูแลระบบ', 'icon' => 'admin_panel_settings'];
                }
                $navItems[] = ['url' => APP_URL . '/admin/settings.php', 'page' => 'settings', 'label' => 'ตั้งค่า', 'icon' => 'settings'];
                foreach ($navItems as $item):
                    $isActive = $currentPage === $item['page'];
                ?>
                <a href="<?= $item['url'] ?>" 
                   class="<?= $isActive 
                       ? 'text-primary border-b-2 border-primary font-bold pb-1' 
                       : 'text-on-surface-variant font-medium hover:text-primary transition-colors' ?> text-sm">
                    <?= $item['label'] ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <!-- Right Actions -->
        <div class="flex items-center gap-3">
            <!-- Notifications -->
            <div class="relative" id="notifDropdown">
                <button onclick="toggleNotifications()" class="p-2 rounded-full hover:bg-surface-container transition-colors relative" title="การแจ้งเตือน" id="notifBtn">
                    <span class="material-symbols-outlined text-on-surface-variant">notifications</span>
                    <span id="notifBadge" class="absolute top-1 right-1 min-w-[18px] h-[18px] bg-error text-white text-[10px] font-bold rounded-full flex items-center justify-center px-1 hidden">0</span>
                </button>

                <!-- Notification Panel -->
                <div id="notifPanel" class="hidden absolute right-0 top-12 w-96 max-h-[480px] bg-white border border-outline-variant rounded-xl shadow-2xl z-50 overflow-hidden flex flex-col">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-outline-variant bg-surface-container-low">
                        <h3 class="text-sm font-bold text-on-surface flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">notifications</span>
                            การแจ้งเตือน
                        </h3>
                        <span id="notifCount" class="text-[11px] text-on-surface-variant"></span>
                    </div>
                    <div id="notifList" class="overflow-y-auto flex-1" style="max-height: 400px;">
                        <div class="flex items-center justify-center py-12 text-on-surface-variant">
                            <div class="spinner" style="width:24px;height:24px;border:2px solid var(--outline-variant);border-top-color:var(--primary);border-radius:50%;animation:spin 0.8s linear infinite"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- User Dropdown -->
            <div class="relative" id="userDropdown">
                <button onclick="document.getElementById('userMenu').classList.toggle('hidden')" 
                        class="flex items-center gap-2 p-1 rounded-full hover:bg-surface-container transition-colors">
                    <div class="w-8 h-8 rounded-full bg-primary-fixed flex items-center justify-center text-primary font-bold text-sm">
                        <?= mb_substr($user['full_name'] ?? 'A', 0, 1) ?>
                    </div>
                    <span class="hidden md:block text-sm font-medium text-on-surface"><?= sanitize($user['full_name'] ?? 'Admin') ?></span>
                    <span class="material-symbols-outlined text-on-surface-variant text-[18px] hidden md:block">expand_more</span>
                </button>

                <!-- Dropdown Menu -->
                <div id="userMenu" class="hidden absolute right-0 top-12 w-56 bg-white border border-outline-variant rounded-xl shadow-lg py-2 z-50">
                    <div class="px-4 py-2 border-b border-outline-variant">
                        <p class="text-sm font-semibold text-on-surface"><?= sanitize($user['full_name'] ?? 'Admin') ?></p>
                        <p class="text-xs text-on-surface-variant"><?= sanitize($user['role_display'] ?? 'Admin') ?></p>
                    </div>
                    <a href="<?= APP_URL ?>/admin/reports.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-on-surface-variant hover:bg-surface-container-low transition-colors">
                        <span class="material-symbols-outlined text-[18px]">assessment</span>
                        รายงาน
                    </a>
                    <a href="<?= APP_URL ?>/admin/settings.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-on-surface-variant hover:bg-surface-container-low transition-colors">
                        <span class="material-symbols-outlined text-[18px]">settings</span>
                        ตั้งค่าระบบ
                    </a>
                    <hr class="my-1 border-outline-variant">
                    <a href="<?= APP_URL ?>/api/auth.php?action=logout" class="flex items-center gap-3 px-4 py-2.5 text-sm text-error hover:bg-error-container/20 transition-colors">
                        <span class="material-symbols-outlined text-[18px]">logout</span>
                        ออกจากระบบ
                    </a>
                </div>
            </div>

            <!-- Mobile Menu Toggle -->
            <button class="md:hidden p-2 rounded-full hover:bg-surface-container" onclick="toggleMobileNav()">
                <span class="material-symbols-outlined text-on-surface-variant">menu</span>
            </button>
        </div>
    </div>
</header>

<!-- Mobile Navigation Overlay -->
<div id="mobileNavOverlay" class="fixed inset-0 bg-black/30 z-40 hidden md:hidden" onclick="toggleMobileNav()"></div>
<nav id="mobileNav" class="fixed top-0 right-0 h-full w-72 bg-white shadow-xl z-50 transform translate-x-full transition-transform md:hidden"
     style="transition: transform 0.3s ease">
    <div class="p-4 border-b border-outline-variant flex justify-between items-center">
        <span class="text-lg font-bold text-primary">Smart Bill</span>
        <button onclick="toggleMobileNav()">
            <span class="material-symbols-outlined text-on-surface-variant">close</span>
        </button>
    </div>
    <div class="py-2">
        <?php foreach ($navItems as $item): ?>
        <a href="<?= $item['url'] ?>" 
           class="flex items-center gap-3 px-4 py-3 text-sm <?= $currentPage === $item['page'] ? 'text-primary bg-primary-fixed/30 font-bold' : 'text-on-surface-variant hover:bg-surface-container-low' ?> transition-colors">
            <span class="material-symbols-outlined text-[20px]"><?= $item['icon'] ?></span>
            <?= $item['label'] ?>
        </a>
        <?php endforeach; ?>
    </div>
</nav>

<!-- Main Content -->
<main class="flex-1 max-w-[1280px] mx-auto w-full px-4 md:px-12 py-8">

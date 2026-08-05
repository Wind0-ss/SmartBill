
</main>

<!-- Footer -->
<footer class="bg-surface-container-low border-t border-outline-variant mt-auto">
    <div class="flex flex-col md:flex-row justify-between items-center w-full py-6 px-4 md:px-12 max-w-[1280px] mx-auto gap-4">
        <div class="flex flex-col items-center md:items-start">
            <span class="text-sm font-bold text-on-surface">Smart Bill</span>
            <p class="text-xs text-on-surface-variant mt-1">© <?= date('Y') ?> Smart Bill. Minimalist Property Management.</p>
        </div>
        <div class="flex gap-6">
            <a href="#" class="text-on-surface-variant hover:text-primary transition-colors text-xs font-semibold">Support</a>
            <a href="#" class="text-on-surface-variant hover:text-primary transition-colors text-xs font-semibold">Privacy Policy</a>
            <a href="#" class="text-on-surface-variant hover:text-primary transition-colors text-xs font-semibold">Terms of Service</a>
        </div>
    </div>
</footer>

<!-- Shared JS -->
<script>
    // Set base URL for API calls - must be before app.js
    window.__APP_URL__ = '<?= APP_URL ?>';
</script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>

<!-- Close user dropdown on outside click -->
<script>
document.addEventListener('click', (e) => {
    const dropdown = document.getElementById('userDropdown');
    const menu = document.getElementById('userMenu');
    if (dropdown && menu && !dropdown.contains(e.target)) {
        menu.classList.add('hidden');
    }
});

function toggleMobileNav() {
    const nav = document.getElementById('mobileNav');
    const overlay = document.getElementById('mobileNavOverlay');
    if (nav.style.transform === 'translateX(0px)') {
        nav.style.transform = 'translateX(100%)';
        overlay.classList.add('hidden');
    } else {
        nav.style.transform = 'translateX(0px)';
        overlay.classList.remove('hidden');
    }
}

// === Notification System ===
let notifLoaded = false;

function toggleNotifications() {
    const panel = document.getElementById('notifPanel');
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden') && !notifLoaded) {
        fetchNotifications();
        notifLoaded = true;
    }
}

async function fetchNotifications() {
    try {
        const data = await API.get('/api/notifications.php?action=recent');
        if (!data.success) return;

        const badge = document.getElementById('notifBadge');
        const countEl = document.getElementById('notifCount');

        // Update badge
        if (data.unread_count > 0) {
            badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }

        countEl.textContent = data.notifications.length + ' รายการ';

        // Render
        const list = document.getElementById('notifList');
        if (data.notifications.length === 0) {
            list.innerHTML = `
                <div class="flex flex-col items-center justify-center py-12 text-on-surface-variant">
                    <span class="material-symbols-outlined text-[40px] text-outline-variant mb-2">notifications_off</span>
                    <p class="text-sm">ไม่มีการแจ้งเตือน</p>
                </div>`;
            return;
        }

        list.innerHTML = data.notifications.map(n => `
            <a href="${n.url}" class="flex gap-3 px-4 py-3 hover:bg-surface-container-low transition-colors border-b border-outline-variant/30 no-underline" style="text-decoration:none">
                <div class="flex-shrink-0 w-9 h-9 rounded-full ${n.bg} flex items-center justify-center">
                    <span class="material-symbols-outlined text-[18px] ${n.color}">${n.icon}</span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-[13px] font-semibold text-on-surface truncate">${n.title}</p>
                    <p class="text-[12px] text-on-surface-variant mt-0.5 line-clamp-2" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">${n.message}</p>
                    <p class="text-[11px] text-outline mt-1">${n.time_ago}</p>
                </div>
            </a>
        `).join('');

    } catch (err) {
        document.getElementById('notifList').innerHTML = `
            <div class="flex items-center justify-center py-12 text-on-surface-variant">
                <p class="text-sm">ไม่สามารถโหลดได้</p>
            </div>`;
    }
}

// Close notification panel on outside click
document.addEventListener('click', (e) => {
    const notifDropdown = document.getElementById('notifDropdown');
    const notifPanel = document.getElementById('notifPanel');
    if (notifDropdown && notifPanel && !notifDropdown.contains(e.target)) {
        notifPanel.classList.add('hidden');
    }
});

// Auto-fetch badge count on page load + refresh every 30s
(async () => {
    try {
        const data = await API.get('/api/notifications.php?action=recent');
        if (data.success && data.unread_count > 0) {
            const badge = document.getElementById('notifBadge');
            badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
            badge.classList.remove('hidden');
        }
    } catch(e) {}
})();
setInterval(async () => {
    try {
        const data = await API.get('/api/notifications.php?action=recent');
        if (data.success) {
            const badge = document.getElementById('notifBadge');
            if (data.unread_count > 0) {
                badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
            if (notifLoaded) {
                // refresh content if panel was opened before
                const panel = document.getElementById('notifPanel');
                if (!panel.classList.contains('hidden')) fetchNotifications();
            }
        }
    } catch(e) {}
}, 30000);
</script>

<?php if (isset($extraScripts)) echo $extraScripts; ?>

</body>
</html>

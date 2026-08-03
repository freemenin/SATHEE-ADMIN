<?php
$current_page = basename($_SERVER['PHP_SELF']);

if (!function_exists('isActive')) {
    function isActive($pages) {
        global $current_page;
        return in_array($current_page, (array)$pages) ? 'sa-nav__menu-item--active' : '';
    }
}

if (!function_exists('isOpen')) {
    function isOpen($pages) {
        global $current_page;
        return in_array($current_page, (array)$pages) ? 'sa-nav__menu-item--open' : '';
    }
}

if (!function_exists('activeLink')) {
    function activeLink($page) {
        global $current_page;
        return $current_page === $page ? 'sa-nav__link--active' : '';
    }
}

require_once __DIR__ . '/permission_helper.php';

// Build the sidebar entirely from the pages the current user's role is permitted
// to view (role_page_permissions.can_view = 1). Never hardcode role checks here -
// the menu must stay in sync with whatever is configured in Role Permissions.
$sidebarMenuPages = getAllowedMenuPages();

$sidebarGroups = [];
foreach ($sidebarMenuPages as $sidebarMenuPage) {
    if (strtoupper((string)($sidebarMenuPage['page_code'] ?? '')) === 'DASHBOARD') {
        continue;
    }
    $sidebarGroupName = trim($sidebarMenuPage['menu_group'] ?? '');
    if ($sidebarGroupName === '') {
        $sidebarGroupName = 'Main';
    }
    $sidebarGroups[$sidebarGroupName][] = $sidebarMenuPage;
}
?>

<style>
    :root {
        --sidebar-bg: #1f2933;
        --sidebar-bg-2: #111827;
        --sidebar-card: rgba(255,255,255,.06);
        --sidebar-border: rgba(255,255,255,.09);
        --sidebar-text: #d1d5db;
        --sidebar-muted: #9ca3af;
        --sidebar-active: #facc15;
        --sidebar-active-soft: rgba(250, 204, 21, .14);
    }

    .sa-app__sidebar {
        background: var(--sidebar-bg);
    }

    .sa-sidebar {
        background: linear-gradient(180deg, #263238 0%, #111827 100%);
        color: #ffffff;
        border-right: 1px solid rgba(255,255,255,.06);
    }

    /* =========================
       Improved Brand Header
    ========================= */
    .sa-sidebar__header {
        height: 78px;
        display: flex;
        align-items: center;
        padding: 12px;
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
    }

    .sa-sidebar__brand {
        width: 100%;
        min-height: 54px;
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 8px 9px;
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
        transition: all 0.18s ease;
    }

    .sa-sidebar__brand:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
    }

    .sa-sidebar__brand-logo {
        width: 42px;
        height: 42px;
        min-width: 42px;
        border-radius: 14px;
        background: #facc15;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border: 1px solid rgba(17, 24, 39, 0.08);
    }

    .sa-sidebar__brand-logo img {
        width: 38px;
        height: 38px;
        object-fit: contain;
        background: #ffffff;
        border-radius: 12px;
        padding: 3px;
    }

    .sa-sidebar__brand-info {
        min-width: 0;
        flex: 1;
    }

    .sa-sidebar__brand-title {
        color: #111827;
        font-size: 14px;
        font-weight: 950;
        letter-spacing: 0.5px;
        line-height: 1.1;
    }

    .sa-sidebar__brand-subtitle {
        color: #6b7280;
        font-size: 11px;
        font-weight: 750;
        margin-top: 3px;
        line-height: 1.1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sa-sidebar__brand-badge {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
        border-radius: 999px;
        padding: 4px 7px;
        font-size: 10px;
        font-weight: 900;
        text-transform: uppercase;
    }

    /* =========================
       Sidebar Body
    ========================= */
    .sa-sidebar__body {
        padding: 12px 9px;
        height: calc(100vh - 78px);
        overflow-y: auto;
    }

    .sa-sidebar__body::-webkit-scrollbar {
        width: 5px;
    }

    .sa-sidebar__body::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,.18);
        border-radius: 20px;
    }

    /* =========================
       Quick Actions
    ========================= */
    .sa-sidebar-quick {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 7px;
        margin: 2px 5px 12px;
    }

    .sa-sidebar-quick a {
        background: var(--sidebar-card);
        border: 1px solid var(--sidebar-border);
        color: #ffffff;
        text-decoration: none;
        border-radius: 12px;
        padding: 9px 8px;
        font-size: 12px;
        font-weight: 850;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: all .18s ease;
    }

    .sa-sidebar-quick a:hover {
        background: var(--sidebar-active);
        color: #111827;
        border-color: var(--sidebar-active);
    }

    /* =========================
       Sidebar Search
    ========================= */
    .sa-sidebar-search {
        margin: 0 5px 12px;
        position: relative;
    }

    .sa-sidebar-search input {
        width: 100%;
        border: 1px solid var(--sidebar-border);
        background: rgba(255,255,255,.06);
        color: #ffffff;
        border-radius: 12px;
        height: 38px;
        padding: 0 12px 0 34px;
        font-size: 13px;
        font-weight: 700;
        outline: none;
    }

    .sa-sidebar-search input::placeholder {
        color: #9ca3af;
    }

    .sa-sidebar-search span {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 14px;
    }

    /* =========================
       Navigation
    ========================= */
    .sa-nav__section-title {
        color: var(--sidebar-muted);
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .6px;
        padding: 13px 12px 8px;
    }

    .sa-nav__menu {
        padding-left: 0;
        list-style: none;
        margin: 0;
    }

    .sa-nav__menu-item {
        margin-bottom: 3px;
    }

    .sa-nav__link {
        color: var(--sidebar-text) !important;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 10px 12px;
        border-radius: 11px;
        font-size: 13px;
        font-weight: 750;
        transition: all .16s ease;
        position: relative;
    }

    .sa-nav__link:hover {
        background: rgba(255,255,255,.08);
        color: #ffffff !important;
    }

    .sa-nav__menu-item--active > .sa-nav__link,
    .sa-nav__link--active {
        background: var(--sidebar-active) !important;
        color: #111827 !important;
        font-weight: 950;
        box-shadow: 0 8px 18px rgba(250, 204, 21, .22);
    }

    .sa-nav__menu-item--active > .sa-nav__link::before,
    .sa-nav__link--active::before {
        content: "";
        position: absolute;
        left: -7px;
        top: 9px;
        bottom: 9px;
        width: 3px;
        background: var(--sidebar-active);
        border-radius: 10px;
    }

    .sa-nav__icon {
        width: 19px;
        min-width: 19px;
        height: 19px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: inherit;
        opacity: .95;
    }

    .sa-nav__icon svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
    }

    .sa-nav__title {
        flex: 1;
        line-height: 1.25;
    }

    .sa-nav__arrow {
        width: 14px;
        height: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        opacity: .75;
        transition: transform .18s ease;
        font-size: 17px;
        font-weight: 900;
    }

    .sa-nav__menu-item--open > .sa-nav__link .sa-nav__arrow {
        transform: rotate(-90deg);
    }

    .sa-nav__menu--sub {
        display: none;
        padding: 5px 0 7px 31px;
    }

    .sa-nav__menu-item--open > .sa-nav__menu--sub {
        display: block;
    }

    .sa-nav__menu--sub .sa-nav__menu-item {
        margin-bottom: 2px;
    }

    .sa-nav__menu--sub .sa-nav__link {
        padding: 8px 10px;
        font-size: 12.5px;
        font-weight: 700;
        color: #cbd5e1 !important;
        border-radius: 9px;
    }

    .sa-nav__menu--sub .sa-nav__link:hover {
        background: rgba(255,255,255,.07);
        color: #ffffff !important;
    }

    .sa-nav__menu--sub .sa-nav__link--active {
        background: var(--sidebar-active-soft) !important;
        color: var(--sidebar-active) !important;
        font-weight: 950;
        box-shadow: none;
    }

    .sa-sidebar-divider {
        height: 1px;
        background: rgba(255,255,255,.09);
        margin: 11px 10px;
    }

    .sa-sidebar-footer-note {
        margin: 12px 5px 6px;
        padding: 12px 13px;
        color: #cbd5e1;
        font-size: 11px;
        line-height: 1.5;
        border-radius: 14px;
        background: rgba(255,255,255,.06);
        border: 1px solid rgba(255,255,255,.08);
    }

    .sa-sidebar-footer-note strong {
        color: #ffffff;
        font-size: 12px;
    }

    .sa-sidebar-footer-note span {
        color: #9ca3af;
    }

    .sa-nav__menu-item.is-hidden-by-search {
        display: none !important;
    }

    @media (max-width: 767px) {
        .sa-sidebar__header {
            height: 70px;
            padding: 10px;
        }

        .sa-sidebar__brand {
            min-height: 50px;
            border-radius: 14px;
        }

        .sa-sidebar__brand-logo {
            width: 38px;
            height: 38px;
            min-width: 38px;
            border-radius: 12px;
        }

        .sa-sidebar__brand-logo img {
            width: 34px;
            height: 34px;
        }

        .sa-sidebar__brand-title {
            font-size: 13px;
        }

        .sa-sidebar__brand-subtitle {
            font-size: 10px;
        }

        .sa-sidebar__body {
            height: calc(100vh - 70px);
            padding: 10px 8px;
        }

        .sa-nav__link {
            padding: 12px 13px;
            font-size: 14px;
        }

        .sa-nav__menu--sub .sa-nav__link {
            font-size: 13.5px;
            padding: 10px;
        }

        .sa-sidebar-quick {
            grid-template-columns: 1fr;
        }
    }
    .demo-header-4 {
    height: 76px;
    padding: 12px;
    background: linear-gradient(135deg, #111827 0%, #1f2937 55%, #263238 100%);
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.demo-brand-4 {
    width: 100%;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 10px;
    background: rgba(255, 255, 255, 0.07);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 15px;
    text-decoration: none;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.24);
    backdrop-filter: blur(8px);
    transition: all 0.18s ease;
}

.demo-brand-4:hover {
    background: rgba(255, 255, 255, 0.10);
    border-color: rgba(250, 204, 21, 0.45);
    transform: translateY(-1px);
}

.demo-brand-4-logo-wrap {
    flex: 1;
    min-width: 0;
    height: 36px;
    display: flex;
    align-items: center;
    background: #2f3845;
    border-radius: 11px;
    padding: 5px 8px;
}

.demo-brand-4-logo-wrap img {
    width: 142px;
    max-width: 100%;
    height: auto;
    object-fit: contain;
    display: block;
}

.demo-brand-4-meta {
    min-width: 50px;
    height: 36px;
    border-left: 1px solid rgba(255, 255, 255, 0.16);
    padding-left: 10px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.demo-brand-4-meta span {
    color: #facc15;
    font-size: 10px;
    font-weight: 900;
    line-height: 1;
    letter-spacing: 0.4px;
}

.demo-brand-4-meta strong {
    color: #ffffff;
    font-size: 12px;
    font-weight: 950;
    margin-top: 3px;
    line-height: 1;
}

@media (max-width: 767px) {
    .demo-header-4 {
        height: 70px;
        padding: 10px;
    }

    .demo-brand-4 {
        height: 50px;
        border-radius: 14px;
    }

    .demo-brand-4-logo-wrap {
        height: 34px;
        padding: 5px 7px;
    }

    .demo-brand-4-logo-wrap img {
        width: 132px;
    }

    .demo-brand-4-meta {
        height: 34px;
        min-width: 46px;
    }
}
</style>

<!-- sa-app__sidebar -->
<div class="sa-app__sidebar">
    <div class="sa-sidebar">

      <!-- Sidebar Header Demo 2 -->
<!-- Sidebar Header Demo 4 - Dark Background -->
<div class="sa-sidebar__header demo-header-4">
    <a class="demo-brand-4" href="dashboard.php">
        <div class="demo-brand-4-logo-wrap">
            <img src="https://app.mysathee.com/uploads/SATHEE.png" alt="SATHEE Logo">
        </div>

        <div class="demo-brand-4-meta">
            <span>CRM</span>
            <strong>Admin</strong>
        </div>
    </a>
</div>
        <!-- Sidebar Body -->
        <div class="sa-sidebar__body" data-simplebar="">
            <!-- Sidebar Search -->
            <div class="sa-sidebar-search">
                <span>🔎</span>
                <input type="text" id="sidebarMenuSearch" placeholder="Search menu...">
            </div>

            <ul class="sa-nav sa-nav--sidebar" data-sa-collapse="">
                <li class="sa-nav__section">
                    <div class="sa-nav__section-title">
                        <span>Application</span>
                    </div>

                    <ul class="sa-nav__menu sa-nav__menu--root" id="sidebarRootMenu">
                        <li class="sa-nav__menu-item sa-nav__menu-item--has-icon <?= isActive('dashboard.php') ?>">
                            <a href="dashboard.php" class="sa-nav__link <?= activeLink('dashboard.php') ?>">
                                <span class="sa-nav__icon">
                                    <i class="bi bi-speedometer2"></i>
                                </span>
                                <span class="sa-nav__title">Dashboard</span>
                            </a>
                        </li>

                        <?php if (empty($sidebarGroups)): ?>
                            <li class="sa-nav__menu-item">
                                <span class="sa-nav__link" style="opacity:.6;cursor:default;">
                                    <span class="sa-nav__title">No menu items available</span>
                                </span>
                            </li>
                        <?php endif; ?>

                        <?php foreach ($sidebarGroups as $sidebarGroupName => $sidebarGroupItems): ?>

                            <?php if (count($sidebarGroupItems) === 1):
                                $sidebarSingle = $sidebarGroupItems[0];
                                $sidebarSingleUrl = trim($sidebarSingle['page_url'] ?? '');
                                if ($sidebarSingleUrl === '') {
                                    continue;
                                }
                                $sidebarSingleIcon = trim($sidebarSingle['icon_class'] ?? '') ?: 'bi bi-circle';
                            ?>
                                <li class="sa-nav__menu-item sa-nav__menu-item--has-icon <?= isActive($sidebarSingleUrl) ?>">
                                    <a href="<?= htmlspecialchars($sidebarSingleUrl, ENT_QUOTES, 'UTF-8') ?>" class="sa-nav__link <?= activeLink($sidebarSingleUrl) ?>">
                                        <span class="sa-nav__icon">
                                            <i class="<?= htmlspecialchars($sidebarSingleIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                        </span>
                                        <span class="sa-nav__title"><?= htmlspecialchars($sidebarSingle['page_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </a>
                                </li>

                            <?php else:
                                $sidebarGroupUrls = [];
                                foreach ($sidebarGroupItems as $sidebarGroupItem) {
                                    $sidebarItemUrl = trim($sidebarGroupItem['page_url'] ?? '');
                                    if ($sidebarItemUrl !== '') {
                                        $sidebarGroupUrls[] = $sidebarItemUrl;
                                    }
                                }
                                if (empty($sidebarGroupUrls)) {
                                    continue;
                                }
                                $sidebarGroupIcon = trim($sidebarGroupItems[0]['icon_class'] ?? '') ?: 'bi bi-folder';
                            ?>
                                <li class="sa-nav__menu-item sa-nav__menu-item--has-icon <?= isOpen($sidebarGroupUrls) ?>" data-sa-collapse-item="">
                                    <a href="#" class="sa-nav__link" data-sa-collapse-trigger="">
                                        <span class="sa-nav__icon">
                                            <i class="<?= htmlspecialchars($sidebarGroupIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                                        </span>
                                        <span class="sa-nav__title"><?= htmlspecialchars($sidebarGroupName, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="sa-nav__arrow">‹</span>
                                    </a>

                                    <ul class="sa-nav__menu sa-nav__menu--sub" data-sa-collapse-content="">
                                        <?php foreach ($sidebarGroupItems as $sidebarItem):
                                            $sidebarItemUrl = trim($sidebarItem['page_url'] ?? '');
                                            if ($sidebarItemUrl === '') {
                                                continue;
                                            }
                                        ?>
                                            <li class="sa-nav__menu-item">
                                                <a href="<?= htmlspecialchars($sidebarItemUrl, ENT_QUOTES, 'UTF-8') ?>" class="sa-nav__link <?= activeLink($sidebarItemUrl) ?>">
                                                    <span class="sa-nav__title"><?= htmlspecialchars($sidebarItem['page_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </li>
                            <?php endif; ?>

                        <?php endforeach; ?>

                    </ul>
                </li>
            </ul>

            <div class="sa-sidebar-footer-note">
                <strong>SATHEE Admin Panel</strong><br>
                <span>CRM Dashboard · Inventory · Orders</span>
            </div>
        </div>
    </div>

    <div class="sa-app__sidebar-shadow"></div>
    <div class="sa-app__sidebar-backdrop" data-sa-close-sidebar=""></div>
</div>
<!-- sa-app__sidebar / end -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    const collapseTriggers = document.querySelectorAll('[data-sa-collapse-trigger]');
    const searchInput = document.getElementById('sidebarMenuSearch');
    const rootMenu = document.getElementById('sidebarRootMenu');

    /*
      Force all sidebar dropdowns closed on page load.
      This fixes the issue where all menus expand automatically.
    */
    document.querySelectorAll('[data-sa-collapse-item]').forEach(function (item) {
        item.classList.remove('sa-nav__menu-item--open');
    });

    collapseTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
            e.preventDefault();

            const parent = this.closest('[data-sa-collapse-item]');
            if (!parent) return;

            const parentRoot = parent.parentElement;
            const alreadyOpen = parent.classList.contains('sa-nav__menu-item--open');

            /*
              Close all other menus first
            */
            if (parentRoot) {
                parentRoot.querySelectorAll('[data-sa-collapse-item]').forEach(function (item) {
                    item.classList.remove('sa-nav__menu-item--open');
                });
            }

            /*
              If clicked menu was already open, keep it closed.
              If it was closed, open it.
            */
            if (!alreadyOpen) {
                parent.classList.add('sa-nav__menu-item--open');
            }
        });
    });

    if (searchInput && rootMenu) {
        searchInput.addEventListener('input', function () {
            const query = this.value.trim().toLowerCase();
            const menuItems = rootMenu.querySelectorAll(':scope > .sa-nav__menu-item');

            menuItems.forEach(function (item) {
                const text = item.textContent.toLowerCase();

                if (!query) {
                    item.classList.remove('is-hidden-by-search');
                    item.classList.remove('sa-nav__menu-item--open');
                    return;
                }

                if (text.includes(query)) {
                    item.classList.remove('is-hidden-by-search');

                    if (item.querySelector('.sa-nav__menu--sub')) {
                        item.classList.add('sa-nav__menu-item--open');
                    }
                } else {
                    item.classList.add('is-hidden-by-search');
                }
            });
        });
    }
});
</script>

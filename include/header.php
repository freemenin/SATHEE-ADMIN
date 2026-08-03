<?php
include('head.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sa_toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);

$user_name = $_SESSION['user_name'] ?? 'Guest';
$user_email = $_SESSION['user_email'] ?? 'guest@example.com';

$profile_image = isset($_SESSION['profile_image']) && $_SESSION['profile_image']
    ? 'https://app.mysathee.com/' . htmlspecialchars($_SESSION['profile_image'], ENT_QUOTES, 'UTF-8')
    : 'images/customers/customer-4-64x64.jpg';
?>

<!-- sa-app -->
<div class="sa-app sa-app--desktop-sidebar-shown sa-app--mobile-sidebar-hidden sa-app--toolbar-fixed">

    <!-- sa-app__content -->
    <div class="sa-app__content">

        <?php include('slider.php'); ?>

        <div class="sa-app__content">

            <!-- sa-app__toolbar -->
            <div class="sa-toolbar sa-toolbar--search-hidden sa-app__toolbar">
                <div class="sa-toolbar__body">

                    <!-- Menu Button -->
                    <div class="sa-toolbar__item">
                        <button class="sa-toolbar__button" type="button" aria-label="Menu" data-sa-toggle-sidebar="">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M1,11V9h18v2H1z M1,3h18v2H1V3z M15,17H1v-2h14V17z"></path>
                            </svg>
                        </button>
                    </div>

                    <!-- Global Search -->
                    <div class="sa-toolbar__item sa-toolbar__item--search">
                        <form id="globalSearchForm" class="sa-search sa-search--state--pending" action="order_list.php" method="get" autocomplete="off">
                            <div class="sa-search__body">
                                <label class="visually-hidden" for="input-search">Search for:</label>

                                <div class="sa-search__icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 16 16" fill="currentColor">
                                        <path d="M11.736 10.321C12.522 9.247 13 7.933 13 6.5C13 2.91 10.09 0 6.5 0C2.91 0 0 2.91 0 6.5C0 10.09 2.91 13 6.5 13C7.933 13 9.247 12.522 10.321 11.736L14.828 16.243L16.243 14.828L11.736 10.321ZM6.5 2C8.985 2 11 4.015 11 6.5C11 8.985 8.985 11 6.5 11C4.015 11 2 8.985 2 6.5C2 4.015 4.015 2 6.5 2Z"></path>
                                    </svg>
                                </div>

                                <input
                                    type="text"
                                    name="q"
                                    id="input-search"
                                    class="sa-search__input"
                                    placeholder="Search orders, customers, products..."
                                    autocomplete="off"
                                />

                                <button class="sa-search__cancel d-sm-none" type="button" aria-label="Close search">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="currentColor">
                                        <path d="M10.8,10.8c-0.4,0.4-1,0.4-1.4,0L6,7.4l-3.4,3.4c-0.4,0.4-1,0.4-1.4,0s-0.4-1,0-1.4L4.6,6L1.2,2.6c-0.4-0.4-0.4-1,0-1.4s1-0.4,1.4,0L6,4.6l3.4-3.4c0.4-0.4,1-0.4,1.4,0s0.4,1,0,1.4L7.4,6l3.4,3.4C11.2,9.8,11.2,10.4,10.8,10.8z"></path>
                                    </svg>
                                </button>

                                <div class="sa-search__field"></div>
                            </div>

                            <div class="sa-search__dropdown" id="searchDropdown" style="display: none;">
                                <div class="sa-search__dropdown-wrapper">
                                    <div class="search-results-custom"></div>
                                </div>
                            </div>

                            <div class="sa-search__backdrop"></div>
                        </form>
                    </div>

                    <div class="mx-auto"></div>

                    <!-- Mobile Search Button -->
                    <div class="sa-toolbar__item d-sm-none">
                        <button class="sa-toolbar__button" type="button" aria-label="Show search" data-sa-action="show-search">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M11.736 10.321C12.522 9.247 13 7.933 13 6.5C13 2.91 10.09 0 6.5 0C2.91 0 0 2.91 0 6.5C0 10.09 2.91 13 6.5 13C7.933 13 9.247 12.522 10.321 11.736L14.828 16.243L16.243 14.828L11.736 10.321ZM6.5 2C8.985 2 11 4.015 11 6.5C11 8.985 8.985 11 6.5 11C4.015 11 2 8.985 2 6.5C2 4.015 4.015 2 6.5 2Z"></path>
                            </svg>
                        </button>
                    </div>

                    <!-- Notification -->
                    <div class="sa-toolbar__item dropdown">
                        <button
                            class="sa-toolbar__button"
                            type="button"
                            id="dropdownMenuButton2"
                            data-bs-toggle="dropdown"
                            data-bs-reference="parent"
                            data-bs-offset="0,1"
                            aria-expanded="false"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M8,13c0,0-5.2,0-7,0c0-1-0.1-1.9,1-1.9C2,5,4,2,6,2c0-1.1,0-2,2-2c1.9,0,2,0.9,2,2c2,0,4,3,4,9c1,0,1,1,1,2C12.7,13,8,13,8,13z M6,14h4c0,1.1,0,2-2,2C6,16,6,15.1,6,14z"></path>
                            </svg>
                            <span class="sa-toolbar__button-indicator">0</span>
                        </button>

                        <div class="dropdown-menu dropdown-menu-end py-0" aria-labelledby="dropdownMenuButton2">
                            <div class="sa-notifications">
                                <div class="sa-notifications__header">
                                    <div class="sa-notifications__header-title">Notifications</div>
                                    <a class="sa-notifications__header-action" href="#">Clear</a>
                                </div>

                                <div style="padding:24px;text-align:center;">
                                    <div style="font-weight:800;color:#111827;">No new notifications</div>
                                    <div style="font-size:12px;color:#6b7280;margin-top:4px;">You are all caught up.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- User Dropdown -->
                    <div class="dropdown sa-toolbar__item">
                        <button
                            class="sa-toolbar-user"
                            type="button"
                            id="dropdownMenuButton"
                            data-bs-toggle="dropdown"
                            data-bs-offset="0,1"
                            aria-expanded="false"
                        >
                            <span class="sa-toolbar-user__avatar sa-symbol sa-symbol--shape--rounded">
                                <img src="<?= $profile_image ?>" width="64" height="64" alt="User" />
                            </span>

                            <span class="sa-toolbar-user__info">
                                <span class="sa-toolbar-user__title"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="sa-toolbar-user__subtitle"><?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </button>

                        <ul class="dropdown-menu dropdown-menu-end user-menu-clean" aria-labelledby="dropdownMenuButton">
                            <li class="user-menu-head">
                                <div class="user-menu-name"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="user-menu-email"><?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?></div>
                            </li>

                            <li><a class="dropdown-item" href="view_profile.php">View Profile</a></li>
                            <li><a class="dropdown-item" href="user_profile.php">Edit Profile</a></li>
                            <li><a class="dropdown-item" href="order_list.php">Orders</a></li>
                            <li><hr class="dropdown-divider" /></li>
                            <li><a class="dropdown-item text-danger" href="logout.php">Sign Out</a></li>
                        </ul>
                    </div>

                </div>

                <div class="sa-toolbar__shadow"></div>
            </div>
            <!-- sa-app__toolbar / end -->

            <div class="sa-app__body">

<style>
.search-results-custom {
    background: #ffffff;
    border-radius: 10px;
    box-shadow: 0 8px 28px rgba(15, 23, 42, 0.18);
    overflow: hidden;
}

.search-results-custom .list-group {
    margin: 0;
}

.search-results-custom .list-group-item {
    border: 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 14px;
    padding: 10px 13px;
    cursor: pointer;
    color: #111827;
    font-weight: 600;
}

.search-results-custom .list-group-item:last-child {
    border-bottom: 0;
}

.search-results-custom .list-group-item:hover {
    background-color: #f8fafc;
}

.user-menu-clean {
    min-width: 230px;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    padding: 8px;
    box-shadow: 0 16px 40px rgba(15, 23, 42, 0.16);
}

.user-menu-clean .dropdown-item {
    border-radius: 10px;
    padding: 9px 10px;
    font-size: 13px;
    font-weight: 700;
}

.user-menu-clean .dropdown-item:hover {
    background: #f3f4f6;
}

.user-menu-head {
    padding: 10px;
    background: #f9fafb;
    border-radius: 12px;
    margin-bottom: 6px;
}

.user-menu-name {
    font-size: 13px;
    font-weight: 900;
    color: #111827;
}

.user-menu-email {
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    word-break: break-word;
    margin-top: 2px;
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const input = document.getElementById("input-search");
    const dropdown = document.getElementById("searchDropdown");
    const form = document.getElementById("globalSearchForm");

    if (!input || !dropdown || !form) {
        return;
    }

    const results = dropdown.querySelector(".search-results-custom");

    if (!results) {
        return;
    }

    const routes = {
        orders: "order_list.php",
        products: "product_list.php",
        inventory: "inventory_list.php",
        customers: "customer_list.php",
        distributors: "distributor_list.php",
        vendors: "vendor_list.php",
        material: "raw_material_manage.php"
    };

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    input.addEventListener("input", function () {
        const query = this.value.trim();

        if (query.length > 0) {
            const safeQuery = escapeHtml(query);

            dropdown.style.display = "block";

            results.innerHTML = `
                <div class="p-2 custom-result-box">
                    <div class="list-group">
                        <a href="#" class="list-group-item list-group-item-action" data-type="orders">🔍 Search Orders for "<strong>${safeQuery}</strong>"</a>
                        <a href="#" class="list-group-item list-group-item-action" data-type="products">📦 Search Products for "<strong>${safeQuery}</strong>"</a>
                        <a href="#" class="list-group-item list-group-item-action" data-type="inventory">📊 Search Inventory for "<strong>${safeQuery}</strong>"</a>
                        <a href="#" class="list-group-item list-group-item-action" data-type="customers">👤 Search Customers for "<strong>${safeQuery}</strong>"</a>
                        <a href="#" class="list-group-item list-group-item-action" data-type="distributors">🏢 Search Distributors for "<strong>${safeQuery}</strong>"</a>
                        <a href="#" class="list-group-item list-group-item-action" data-type="vendors">🏬 Search Vendors for "<strong>${safeQuery}</strong>"</a>
                        <a href="#" class="list-group-item list-group-item-action" data-type="material">🧪 Search Material for "<strong>${safeQuery}</strong>"</a>
                    </div>
                </div>
            `;
        } else {
            dropdown.style.display = "none";
        }
    });

    results.addEventListener("click", function (e) {
        const item = e.target.closest("[data-type]");

        if (!item) {
            return;
        }

        e.preventDefault();

        const type = item.dataset.type;
        const query = input.value.trim();

        if (query && routes[type]) {
            window.location.href = routes[type] + "?q=" + encodeURIComponent(query);
        }
    });

    form.addEventListener("submit", function (e) {
        e.preventDefault();

        const query = input.value.trim();

        if (query) {
            window.location.href = routes.orders + "?q=" + encodeURIComponent(query);
        }
    });

    document.addEventListener("click", function (e) {
        if (!form.contains(e.target)) {
            dropdown.style.display = "none";
        }
    });
});
</script>

<?php if ($sa_toast && !empty($sa_toast['message'])):
    $sa_toast_type = in_array($sa_toast['type'] ?? '', ['success', 'danger', 'warning', 'info'], true)
        ? $sa_toast['type']
        : 'info';
    $sa_toast_title = $sa_toast['title'] ?? ($sa_toast_type === 'success' ? 'Success' : ($sa_toast_type === 'danger' ? 'Error' : 'Notice'));
    $sa_toast_bg = [
        'success' => '#16a34a',
        'danger'  => '#dc2626',
        'warning' => '#d97706',
        'info'    => '#2563eb',
    ][$sa_toast_type];
?>
<script>
document.addEventListener("DOMContentLoaded", function () {
    var mount = document.querySelector(".sa-app__toasts");
    if (!mount) { return; }

    var wrapper = document.createElement("div");
    wrapper.className = "toast align-items-center border-0 show mb-2";
    wrapper.setAttribute("role", "alert");
    wrapper.setAttribute("aria-live", "assertive");
    wrapper.setAttribute("aria-atomic", "true");
    wrapper.style.background = "<?= $sa_toast_bg ?>";
    wrapper.style.color = "#ffffff";
    wrapper.style.minWidth = "300px";

    wrapper.innerHTML =
        '<div class="d-flex">' +
            '<div class="toast-body">' +
                '<strong><?= htmlspecialchars($sa_toast_title, ENT_QUOTES, "UTF-8") ?></strong><br>' +
                '<?= htmlspecialchars($sa_toast['message'], ENT_QUOTES, 'UTF-8') ?>' +
            '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
        '</div>';

    mount.appendChild(wrapper);

    if (window.bootstrap && window.bootstrap.Toast) {
        var toast = new bootstrap.Toast(wrapper, { delay: 5000 });
        toast.show();
    } else {
        setTimeout(function () { wrapper.remove(); }, 5000);
    }

    wrapper.querySelector(".btn-close").addEventListener("click", function () {
        wrapper.remove();
    });
});
</script>
<?php endif; ?>
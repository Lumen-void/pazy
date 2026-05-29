<?php
/** @var array $config */
/** @var string $title */
/** @var string $content */
/** @var array|null $user */

$currentPage = isset($_GET['page']) ? (string) $_GET['page'] : '';
if ($currentPage === '') {
    $derived = trim(request_path($config), '/');
    if ($derived === '' || $derived === 'index.php') {
        $currentPage = 'home';
    } else {
        $segments = explode('/', $derived);
        $currentPage = (string) ($segments[0] ?? 'home');
    }
}

if ($currentPage === 'reimbursements') {
    $currentPage = 'expenses';
}

$isPublicPage = function_exists('web_is_public_page') ? web_is_public_page($currentPage) : in_array($currentPage, ['home', 'about', 'features', 'pricing', 'contact', 'login'], true);
$useAppShell = $user && ! $isPublicPage;
$cleanLabel = static function (string $value): string {
    $clean = preg_replace('/\b(?:Demo|UAE|India)\b\s*/i', '', $value);
    $normalized = trim((string) $clean);
    return $normalized !== '' ? $normalized : $value;
};
$cssVersion = is_file(__DIR__.'/../public/assets/css/app.css') ? (string) filemtime(__DIR__.'/../public/assets/css/app.css') : '1';
$jsVersion = is_file(__DIR__.'/../public/assets/js/app.js') ? (string) filemtime(__DIR__.'/../public/assets/js/app.js') : '1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | <?= e($config['app']['name']) ?></title>
    <link rel="stylesheet" href="<?= e(base_url($config, 'assets/css/app.css?v='.$cssVersion)) ?>">
</head>
<body class="page-<?= e($currentPage) ?> <?= $isPublicPage ? 'is-public' : 'is-app' ?>">
<div class="backdrop"></div>
<div class="layout-root <?= $useAppShell ? '' : 'auth-mode marketing-mode' ?>">
    <?php if ($useAppShell): ?>
        <aside class="sidebar" id="appSidebar">
            <div class="brand-lockup">
                <div class="brand-mark">P</div>
                <div>
                    <h1><?= e($config['app']['name']) ?></h1>
                    <p><?= e($cleanLabel((string) $user['company_name'])) ?></p>
                </div>
            </div>

            <nav class="nav-links">
                <?php
                $pagePermission = [
                    'payments' => 'payments.manage',
                    'bulk-payout' => 'payments.manage',
                    'connected-banking' => 'payments.manage',
                    'cards' => 'payments.manage',
                    'upi' => 'payments.manage',
                    'credit-line' => 'payments.manage',
                    'vendors' => 'vendors.manage',
                    'procurement' => 'procurement.manage',
                    'invoices' => 'invoices.manage',
                    'matching' => 'invoices.manage',
                    'approvals' => 'approvals.decide',
                    'tax' => 'tax.manage',
                    'notifications' => 'notifications.manage',
                    'integrations' => 'integrations.manage',
                    'reports' => 'reports.read',
                    'audit' => 'reports.read',
                    'expenses' => 'expenses.manage',
                ];

                $navGroups = [
                    [
                        'label' => 'Workspace',
                        'pages' => [
                            'dashboard' => 'Home',
                            'explore' => 'Explore',
                            'inbox' => 'Operations Inbox',
                        ],
                    ],
                    [
                        'label' => 'Finance',
                        'pages' => [
                            'payments' => 'Bill Pay',
                            'bulk-payout' => 'Bulk Payout',
                            'connected-banking' => 'Connected Banking',
                            'cards' => 'Cards',
                            'upi' => 'UPI',
                            'credit-line' => 'Credit Line',
                        ],
                    ],
                    [
                        'label' => 'Operations',
                        'pages' => [
                            'vendors' => 'Vendors',
                            'procurement' => 'Procurement',
                            'invoices' => 'Invoices',
                            'expenses' => 'Reimbursements',
                            'approvals' => 'Approvals',
                            'matching' => '3-Way Matching',
                        ],
                    ],
                    [
                        'label' => 'Governance',
                        'pages' => [
                            'tax' => 'Taxation',
                            'notifications' => 'Alerts',
                            'reports' => 'Reports',
                            'audit' => 'Audit Trail',
                        ],
                    ],
                    [
                        'label' => 'Admin',
                        'pages' => [
                            'integrations' => 'Settings & Integrations',
                        ],
                    ],
                ];

                $isVisible = static function (string $pageKey) use ($pagePermission): bool {
                    $permission = $pagePermission[$pageKey] ?? null;
                    if (! is_string($permission) || $permission === '') {
                        return true;
                    }

                    return Auth::can($permission);
                };

                foreach ($navGroups as $group):
                    $pages = $group['pages'];
                    $visiblePages = array_filter($pages, static fn (string $pageKey): bool => $isVisible($pageKey), ARRAY_FILTER_USE_KEY);
                    if ($visiblePages === []) {
                        continue;
                    }
                    $groupHasActive = array_key_exists($currentPage, $visiblePages);
                    ?>
                    <details class="nav-group" <?= $groupHasActive ? 'open' : '' ?>>
                        <summary>
                            <span class="nav-group-title"><?= e((string) $group['label']) ?></span>
                        </summary>
                        <div class="nav-group-links">
                            <?php foreach ($visiblePages as $pageKey => $label):
                                $isActive = $currentPage === $pageKey;
                                $lettersOnly = preg_replace('/[^A-Za-z]/', '', $label);
                                $shortKey = strtoupper(substr($lettersOnly !== null ? $lettersOnly : 'NA', 0, 2));
                                if ($shortKey === '') {
                                    $shortKey = 'NA';
                                }
                                ?>
                                <a class="<?= $isActive ? 'active' : '' ?>" href="<?= e(base_url($config, 'index.php?page='.$pageKey)) ?>">
                                    <span class="nav-key"><?= e($shortKey) ?></span>
                                    <span class="nav-label"><?= e($label) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="<?= e(base_url($config, 'index.php')) ?>" class="logout-form">
                <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="ghost">Sign Out</button>
            </form>

            <div class="sidebar-footer">
                <span>Secure Mode</span>
                <strong>Audit Enabled</strong>
            </div>
        </aside>
        <button class="sidebar-scrim" type="button" aria-label="Close navigation" data-app-sidebar-close></button>
    <?php endif; ?>

    <main class="main main-<?= e($currentPage) ?>">
        <?php if ($isPublicPage): ?>
            <header class="public-nav">
                <div class="site-container public-nav-inner">
                    <a class="public-brand" href="<?= e(base_url($config, 'index.php?page=home')) ?>">
                        <span class="brand-mark mini">P</span>
                        <span><?= e($config['app']['name']) ?></span>
                    </a>
                    <button type="button" class="public-nav-toggle" data-public-nav-toggle aria-controls="publicNavPanel" aria-expanded="false">Menu</button>
                    <div class="public-nav-panel" id="publicNavPanel">
                        <nav class="public-links">
                            <a href="<?= e(base_url($config, 'index.php?page=home')) ?>" class="<?= $currentPage === 'home' ? 'active' : '' ?>">Home</a>
                            <a href="<?= e(base_url($config, 'index.php?page=about')) ?>" class="<?= $currentPage === 'about' ? 'active' : '' ?>">About</a>
                            <a href="<?= e(base_url($config, 'index.php?page=features')) ?>" class="<?= $currentPage === 'features' ? 'active' : '' ?>">Features</a>
                            <a href="<?= e(base_url($config, 'index.php?page=pricing')) ?>" class="<?= $currentPage === 'pricing' ? 'active' : '' ?>">Pricing</a>
                            <a href="<?= e(base_url($config, 'index.php?page=contact')) ?>" class="<?= $currentPage === 'contact' ? 'active' : '' ?>">Contact</a>
                        </nav>
                        <div class="public-actions">
                            <?php if ($user): ?>
                                <a class="pill-link" href="<?= e(base_url($config, 'index.php?page=dashboard')) ?>">Dashboard</a>
                                <form method="post" action="<?= e(base_url($config, 'index.php')) ?>" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                                    <input type="hidden" name="action" value="logout">
                                    <button type="submit" class="ghost">Sign Out</button>
                                </form>
                            <?php else: ?>
                                <a class="pill-link" href="<?= e(base_url($config, 'index.php?page=login')) ?>">Sign In</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </header>
        <?php endif; ?>

        <?php if ($useAppShell): ?>
            <header class="topbar card">
                <div class="topbar-title-wrap">
                    <button type="button" class="sidebar-toggle" data-app-sidebar-toggle aria-controls="appSidebar" aria-expanded="false">Menu</button>
                    <div>
                        <div class="eyebrow">Role: <?= e((string) $user['role']) ?></div>
                        <h2><?= e($title) ?></h2>
                        <p class="muted topbar-note">Finance operations console</p>
                    </div>
                </div>
                <div class="topbar-actions">
                    <form method="post" action="<?= e(base_url($config, 'index.php')) ?>" class="inline-form company-switch">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token($config)) ?>">
                        <input type="hidden" name="action" value="switch_company">
                        <select name="company_id" onchange="this.form.submit()">
                            <?php foreach (($user['memberships'] ?? []) as $membership): ?>
                                <option value="<?= e((string) $membership['company_id']) ?>" <?= (int) $membership['company_id'] === (int) $user['company_id'] ? 'selected' : '' ?>>
                                    <?= e($cleanLabel((string) $membership['company_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <span class="pill"><?= e($user['email']) ?></span>
                </div>
            </header>
        <?php endif; ?>

        <?php if ($error = flash_get('error')): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success = flash_get('success')): ?>
            <div class="alert success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($isPublicPage): ?>
            <div class="public-content">
                <?= $content ?>
            </div>
        <?php else: ?>
            <?= $content ?>
        <?php endif; ?>

        <?php if ($isPublicPage): ?>
            <footer class="site-footer">
                <div class="site-container">
                    <div class="footer-grid">
                        <section>
                            <h3><?= e($config['app']['name']) ?></h3>
                            <p class="muted">Finance automation for AP, procurement, reimbursements, payments, and compliance operations.</p>
                        </section>
                        <section>
                            <h4>Product</h4>
                            <a href="<?= e(base_url($config, 'index.php?page=features')) ?>">Features</a>
                            <a href="<?= e(base_url($config, 'index.php?page=pricing')) ?>">Pricing</a>
                            <a href="<?= e(base_url($config, 'index.php?page=contact')) ?>">Contact</a>
                        </section>
                        <section>
                            <h4>Company</h4>
                            <a href="<?= e(base_url($config, 'index.php?page=about')) ?>">About</a>
                            <a href="<?= e(base_url($config, 'index.php?page=home')) ?>">Homepage</a>
                            <?php if ($user): ?>
                                <a href="<?= e(base_url($config, 'index.php?page=dashboard')) ?>">Dashboard</a>
                            <?php else: ?>
                                <a href="<?= e(base_url($config, 'index.php?page=login')) ?>">Sign In</a>
                            <?php endif; ?>
                        </section>
                        <section>
                            <h4>Contact</h4>
                            <span class="muted">sales@pazy.local</span>
                            <span class="muted">support@pazy.local</span>
                            <span class="muted">Mon-Fri, 9:00-18:00 IST</span>
                        </section>
                    </div>
                    <div class="footer-bottom">
                        <span>&copy; <?= e(gmdate('Y')) ?> <?= e($config['app']['name']) ?>. All rights reserved.</span>
                        <span class="muted">Built with PHP, JS, CSS, and MySQL</span>
                    </div>
                </div>
            </footer>
        <?php else: ?>
            <footer class="app-footer top-space">
                <span class="muted">&copy; <?= e(gmdate('Y')) ?> <?= e($config['app']['name']) ?> · Secure finance operations workspace</span>
            </footer>
        <?php endif; ?>
    </main>
</div>
<script src="<?= e(base_url($config, 'assets/js/app.js?v='.$jsVersion)) ?>"></script>
</body>
</html>

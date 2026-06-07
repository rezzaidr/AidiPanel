<?php
/**
 * AidiPanel — English strings (default locale).
 *
 * UI copy is multilingual from the start: never hardcode visible text in views,
 * always go through t('key'). Add other locales as app/Lang/<code>.php with the
 * same keys. Use {placeholder} tokens for dynamic values, e.g.
 *   t('sites.count', ['n' => 4])  with  'sites.count' => '{n} sites on this server'
 */

declare(strict_types=1);

return [
    // App
    'app.name'              => 'AidiPanel',

    // Top-bar navigation
    'nav.dashboard'         => 'Dashboard',
    'nav.sites'             => 'Sites',
    'nav.admin'             => 'Admin Area',

    // Top-bar actions
    'topbar.search'         => 'Search or command',
    'topbar.server_tooltip' => 'Server this panel runs on',
    'topbar.theme'          => 'Theme (coming soon)',
    'topbar.settings'       => 'Settings (coming soon)',
    'topbar.account'        => 'Account',
    'topbar.logout'         => 'Log out',

    // Generic actions / labels
    'action.manage'         => 'Manage',
    'action.open'           => 'Open',
    'common.online'         => 'Online',
    'common.soon'           => 'Soon',

    // Admin Area landing
    'admin.title'           => 'Admin Area',
    'admin.subtitle'        => 'Server-wide settings and services',
    'admin.services.title'  => 'Services',
    'admin.services.desc'   => 'Start, stop and restart Nginx, PHP-FPM, MariaDB, Redis',
    'admin.php.title'       => 'PHP Versions',
    'admin.php.desc'        => 'Installed PHP versions and pool status',
    'admin.cache.title'     => 'FastCGI Cache',
    'admin.cache.desc'      => 'Server-level page cache status and purge',
    'admin.ssl.title'       => 'SSL / TLS',
    'admin.ssl.desc'        => 'Certificates and auto-renewal across sites',
    'admin.users.title'     => 'Panel Users',
    'admin.users.desc'      => 'Accounts that can sign in to AidiPanel',
    'admin.logs.title'      => 'System Logs',
    'admin.logs.desc'       => 'Recent panel and server activity',
    'admin.security.title'  => 'Security',
    'admin.security.desc'   => 'Firewall, Fail2ban, SSH hardening',
    'admin.tuning.title'    => 'Server Tuning',
    'admin.tuning.desc'     => 'Auto-tune PHP-FPM, Nginx, MariaDB, delivery defaults',
    'admin.backups.title'   => 'Backups',
    'admin.backups.desc'    => 'Scheduled and on-demand server backups',
];

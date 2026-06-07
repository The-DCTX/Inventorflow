<?php
function render_head(string $title = '', array $extra_css = []): void {
    $app = APP_NAME; $full_title = $title ? $title . ' — ' . $app : $app;
    $theme = h(APP_THEME);
    echo '<!DOCTYPE html>
<html lang="fr" data-theme="' . $theme . '">
<head>
<meta charset="UTF-8">
<script>(function(){var t=localStorage.getItem("if_theme");if(t==="acid"||t==="dark"||t==="aurora")document.documentElement.setAttribute("data-theme",t);})()</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . h($full_title) . '</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="' . APP_URL . '/assets/css/app.css?v=' . @filemtime(__DIR__ . '/../assets/css/app.css') . '">
</head>
<body>';
}

function render_sidebar(string $active = ''): void {
    $user = current_user();
    $client_id = current_client_id();
    $clients = is_superadmin() ? get_clients() : [];
    $current_client = null;
    if ($client_id) {
        $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$client_id]);
        $current_client = $stmt->fetch();
    }

    // Count machines with issues (offline > 30min OR security incidents)
    $alert_count = 0;
    $incident_count = 0;
    try {
        if ($client_id) {
            // Machines offline (no heartbeat > 30min)
            $off_stmt = db()->prepare(
                'SELECT COUNT(DISTINCT a.id) FROM assets a
                 LEFT JOIN (SELECT asset_id, MAX(collected_at) as last_seen FROM monitoring_snapshots GROUP BY asset_id) ms ON ms.asset_id = a.id
                 WHERE a.client_id = ? AND a.status = "active"
                 AND ms.last_seen IS NOT NULL
                 AND ms.last_seen < DATE_SUB(NOW(), INTERVAL 48 HOUR)'
            );
            $off_stmt->execute([$client_id]);
            $alert_count = (int)$off_stmt->fetchColumn();

            // Security incidents last 48h
            $inc_stmt = db()->prepare(
                'SELECT COUNT(DISTINCT se.asset_id) FROM security_events se
                 JOIN assets a ON se.asset_id = a.id
                 WHERE a.client_id = ? AND se.detected_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)'
            );
            $inc_stmt->execute([$client_id]);
            $incident_count = (int)$inc_stmt->fetchColumn();
        }
    } catch (\Throwable $e) { $alert_count = 0; $incident_count = 0; }

    $nav_items = [
        ['id' => 'overview',  'label' => 'Vue globale',  'icon' => 'layers',     'url' => APP_URL . '/pages/overview.php'],
        ['id' => 'dashboard', 'label' => 'Tableau de bord', 'icon' => 'grid',       'url' => APP_URL . '/'],
        ['id' => 'assets',    'label' => 'Parc informatique','icon' => 'monitor',    'url' => APP_URL . '/pages/assets.php'],
        ['id' => 'employees', 'label' => 'Utilisateurs',     'icon' => 'users',      'url' => APP_URL . '/pages/employees.php'],
        ['id' => 'departments','label'=> 'Départements',     'icon' => 'building',   'url' => APP_URL . '/pages/departments.php'],
        ['id' => 'monitoring', 'label' => 'Supervision', 'icon' => 'wifi', 'url' => APP_URL . '/pages/monitoring.php'],
        ['id' => 'licenses', 'label' => 'Licences',           'icon' => 'key',        'url' => APP_URL . '/pages/licenses.php'],
        ['id' => 'billing',  'label' => 'Facturation',      'icon' => 'tag',        'url' => APP_URL . '/pages/billing.php'],
        ['id' => 'settings',  'label' => 'Paramètres',       'icon' => 'settings',   'url' => APP_URL . '/pages/settings.php'],
    ];
    if (is_superadmin()) {
        array_splice($nav_items, 3, 0, [['id' => 'clients', 'label' => 'Clients', 'icon' => 'briefcase', 'url' => APP_URL . '/pages/clients.php']]);
    }

    // Grouped navigation
    $nav_groups = [
        ['label' => null, 'items' => [
            ['id' => 'overview',  'label' => 'Vue globale',  'icon' => 'layers', 'url' => APP_URL . '/pages/overview.php'],
            ['id' => 'monitoring','label' => 'Supervision',  'icon' => 'wifi',   'url' => APP_URL . '/pages/monitoring.php'],
        ]],
        ['label' => 'Parc & Équipe', 'items' => [
            ['id' => 'assets',      'label' => 'Parc informatique', 'icon' => 'monitor',  'url' => APP_URL . '/pages/assets.php'],
            ['id' => 'employees',   'label' => 'Utilisateurs',      'icon' => 'users',    'url' => APP_URL . '/pages/employees.php'],
            ['id' => 'departments', 'label' => 'Départements',      'icon' => 'building', 'url' => APP_URL . '/pages/departments.php'],
        ]],
        ['label' => 'Finance', 'items' => [
            ['id' => 'billing',  'label' => 'Facturation', 'icon' => 'tag', 'url' => APP_URL . '/pages/billing.php'],
            ['id' => 'licenses', 'label' => 'Licences',    'icon' => 'key', 'url' => APP_URL . '/pages/licenses.php'],
        ]],
        ['label' => 'Admin', 'items' => [
            ['id' => 'settings',     'label' => 'Paramètres',   'icon' => 'settings',  'url' => APP_URL . '/pages/settings.php'],
            ['id' => 'apikeys',      'label' => 'Clés API',      'icon' => 'key',       'url' => APP_URL . '/pages/apikeys.php'],
            ['id' => 'users-admin',  'label' => 'Utilisateurs',  'icon' => 'users',     'url' => APP_URL . '/pages/users-admin.php', 'superadmin' => true],
            ['id' => 'backups',      'label' => 'Sauvegardes',   'icon' => 'download',  'url' => APP_URL . '/pages/backups.php', 'superadmin' => true],
            ['id' => 'updates',      'label' => 'Mises à jour',  'icon' => 'download',  'url' => APP_URL . '/pages/updates.php', 'superadmin' => true],
        ]],
    ];
    if (is_superadmin()) {
        $nav_groups[3]['items'][] = ['id' => 'clients', 'label' => 'Clients', 'icon' => 'briefcase', 'url' => APP_URL . '/pages/clients.php'];
        $nav_groups[3]['items'][] = ['id' => 'audit-log', 'label' => 'Journal d\'audit', 'icon' => 'clock', 'url' => APP_URL . '/pages/audit-log.php'];
    }
    ?>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="brand">
                <svg class="brand-icon" viewBox="0 0 32 32" fill="none">
                    <rect width="32" height="32" rx="8" fill="url(#brand-grad)"/>
                    <path d="M8 10h16M8 16h10M8 22h13" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="24" cy="22" r="3" fill="#fff" opacity=".9"/>
                    <defs><linearGradient id="brand-grad" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse"><stop stop-color="#4f7ef8"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs>
                </svg>
                <span class="brand-name"><?= h(APP_NAME) ?></span>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <?php if ($client_id): ?>
        <div class="client-selector">
            <?php if (is_superadmin()): ?>
            <label class="selector-label">Client actif</label>
            <form method="POST" action="<?= APP_URL ?>/api/switch-client.php" id="clientSwitchForm">
                <select name="client_id" class="selector-select" onchange="document.getElementById('clientSwitchForm').submit()">
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $c['id'] == $client_id ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php else: ?>
            <div class="client-badge">
                <svg class="icon-xs"><use href="#icon-briefcase"/></svg>
                <span><?= h($current_client['name'] ?? 'Client') ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <nav class="sidebar-nav">
            <?php foreach ($nav_groups as $gi => $group): ?>
            <?php if ($group['label']): ?>
            <div class="nav-group-label"><?= h($group['label']) ?></div>
            <?php elseif ($gi > 0): ?>
            <div class="nav-separator"></div>
            <?php endif; ?>
            <?php foreach ($group['items'] as $item): ?>
            <a href="<?= $item['url'] ?>" class="nav-item <?= $active === $item['id'] ? 'active' : '' ?>">
                <svg class="nav-icon"><use href="#icon-<?= $item['icon'] ?>"/></svg>
                <span class="nav-label"><?= h($item['label']) ?></span>
                <?php if ($item['id'] === 'monitoring' && ($alert_count > 0 || $incident_count > 0)): ?>
                <span style="margin-left:auto;min-width:18px;height:18px;background:var(--danger);color:#fff;border-radius:9px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0"><?= $alert_count + $incident_count ?></span>
                <?php endif; ?>
                <?php if ($active === $item['id']): ?>
                <span class="nav-active-indicator"></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar"><?= strtoupper(substr($user['full_name'] ?? $user['username'], 0, 2)) ?></div>
                <div class="user-details">
                    <span class="user-name"><?= h($user['full_name'] ?? $user['username']) ?></span>
                    <span class="user-role"><?= h($user['role']) ?></span>
                </div>
            </div>
            <a href="<?= APP_URL ?>/logout.php" class="logout-btn" title="Déconnexion">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </div>
    <?php
}

function render_update_banner(): void {
    if (!function_exists('is_superadmin') || !is_superadmin()) return;
    if (!function_exists('latest_available_version')) return;
    $latest = latest_available_version();
    if (!$latest || !version_compare($latest, APP_VERSION, '>')) return;
    ?>
    <div class="update-banner" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:9px 16px;margin:0 0 14px;background:var(--accent-dim,#15301f);border:1px solid var(--accent,#3fb950);border-radius:10px;font-size:13px;color:var(--text,#c9d1d9)">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent,#3fb950)" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span><strong>Mise à jour disponible :</strong> InventorFlow <?= h($latest) ?> &nbsp;(installé : <?= h(APP_VERSION) ?>)</span>
        <a href="<?= APP_URL ?>/pages/updates.php" style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:4px 12px;background:var(--accent,#3fb950);color:#0d1117;font-weight:700;border-radius:7px;text-decoration:none">Voir et installer →</a>
    </div>
    <?php
}

function render_topbar(string $title = '', string $subtitle = ''): void {
    render_update_banner();
    ?>
    <div class="topbar">
        <button class="menu-btn" id="menuBtn" aria-label="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title">
            <?php if ($title): ?>
            <h1 class="page-title"><?= h($title) ?></h1>
            <?php if ($subtitle): ?><p class="page-subtitle"><?= h($subtitle) ?></p><?php endif; ?>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-left:auto">
          <?php
          try {
              $cid_top = current_client_id();
              if ($cid_top) {
                  $last_hb = db()->prepare('SELECT MAX(collected_at) FROM monitoring_snapshots ms JOIN assets a ON ms.asset_id=a.id WHERE a.client_id=?');
                  $last_hb->execute([$cid_top]);
                  $last_hb_time = $last_hb->fetchColumn();

                  $total_inc = db()->prepare('SELECT COUNT(DISTINCT se.asset_id) FROM security_events se JOIN assets a ON se.asset_id=a.id WHERE a.client_id=? AND se.detected_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)');
                  $total_inc->execute([$cid_top]);
                  $inc_cnt = (int)$total_inc->fetchColumn();

                  if ($inc_cnt > 0): ?>
                  <a href="<?= APP_URL ?>/pages/monitoring.php" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:var(--danger-dim);border:1px solid rgba(255,71,87,.3);border-radius:20px;font-size:12px;font-weight:600;color:var(--danger);text-decoration:none">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                      <?= $inc_cnt ?> incident<?= $inc_cnt > 1 ? 's' : '' ?>
                  </a>
                  <?php endif; ?>
                  <?php if ($last_hb_time): ?>
                  <span style="font-size:11.5px;color:var(--text-muted)">Dernier heartbeat : <?= time_ago($last_hb_time) ?></span>
                  <?php endif; ?>
              <?php } ?>
          <?php } catch (\Throwable $e) {} ?>
        </div>
        <div class="topbar-actions" id="topbarActions"></div>
    </div>
    <?php
}

function render_icons(): void {
    echo '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
    <symbol id="icon-grid" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></symbol>
    <symbol id="icon-monitor" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></symbol>
    <symbol id="icon-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></symbol>
    <symbol id="icon-building" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21V7l9-4 9 4v14"/><path d="M9 21V9h6v12"/></symbol>
    <symbol id="icon-briefcase" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="12"/></symbol>
    <symbol id="icon-settings" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></symbol>
    <symbol id="icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></symbol>
    <symbol id="icon-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></symbol>
    <symbol id="icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></symbol>
    <symbol id="icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></symbol>
    <symbol id="icon-filter" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></symbol>
    <symbol id="icon-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></symbol>
    <symbol id="icon-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></symbol>
    <symbol id="icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></symbol>
    <symbol id="icon-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>
    <symbol id="icon-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>
    <symbol id="icon-apple" viewBox="0 0 24 24" fill="currentColor"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/></symbol>
    <symbol id="icon-windows" viewBox="0 0 24 24" fill="currentColor"><path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.9-1.801"/></symbol>
    <symbol id="icon-linux" viewBox="0 0 24 24" fill="currentColor"><path d="M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489.117.712.42 1.205.84 1.472.99.624 2.16.222 2.166.22.004-.004.01-.01.013-.013-.28.052-.85.23-1.27.682-.41.45-.644 1.08-.646 1.86-.002 1.086.618 2.43 1.773 3.452 1.155 1.022 2.59 1.585 3.943 1.585 1.35 0 2.786-.563 3.944-1.585 1.155-1.022 1.776-2.366 1.773-3.452-.002-.78-.236-1.41-.646-1.86-.42-.452-.99-.63-1.27-.682.003.003.01.009.014.013.006.002 1.176.404 2.166-.22.42-.267.723-.76.84-1.472.123-.805-.01-1.657-.287-2.489-.59-1.77-1.831-3.47-2.716-4.521-.75-1.067-.974-1.928-1.05-3.02-.065-1.49 1.056-5.965-3.17-6.298C12.82.008 12.66 0 12.504 0zm-1.4 18.4c-.023-.007.002.001 0 0zm2.8 0c-.002.001.023-.007 0 0z"/></symbol>
    <symbol id="icon-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></symbol>
    <symbol id="icon-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></symbol>
    <symbol id="icon-tag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></symbol>
    <symbol id="icon-cpu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></symbol>
    <symbol id="icon-wifi" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.55a11 11 0 0114.08 0"/><path d="M1.42 9a16 16 0 0121.16 0"/><path d="M8.53 16.11a6 6 0 016.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></symbol>
    <symbol id="icon-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>
    <symbol id="icon-arrow-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></symbol>
    <symbol id="icon-arrow-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></symbol>
    <symbol id="icon-chevron-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></symbol>
    <symbol id="icon-layers" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></symbol>
    <symbol id="icon-key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></symbol>
    <symbol id="icon-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
    <symbol id="icon-log-out" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></symbol>
    </svg>';
}

function render_footer(): void {
    echo '
    <div id="toast-container" class="toast-container"></div>
    <div id="page-progress"></div>

    <!-- PANEL ATTRIBUTION LICENCES -->
    <div id="lic-assign-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100" onclick="closeLicAssignPanel()"></div>
    <div id="lic-assign-panel" style="display:none;position:fixed;top:0;right:0;height:100vh;width:420px;max-width:100vw;background:var(--bg-card);border-left:1px solid var(--border);z-index:1101;flex-direction:column;overflow:hidden">
        <div style="display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border);flex-shrink:0">
            <div style="flex:1;min-width:0">
                <div style="font-size:15px;font-weight:700;color:var(--text-primary)" id="lap-title">Attribuer des licences</div>
                <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px" id="lap-subtitle"></div>
            </div>
            <button onclick="closeLicAssignPanel()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:12px 0" id="lap-list">
            <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:13px">Chargement…</div>
        </div>
    </div>

    <style>
    .lap-item{display:flex;align-items:center;gap:12px;padding:10px 20px;transition:background var(--transition);cursor:pointer;border-bottom:1px solid var(--border)}
    .lap-item:last-child{border-bottom:none}
    .lap-item:hover{background:var(--bg-hover)}
    .lap-item.assigned{background:rgba(34,211,160,.06)}
    .lap-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
    .lap-name{font-size:13.5px;font-weight:600;color:var(--text-primary)}
    .lap-meta{font-size:12px;color:var(--text-muted);margin-top:2px}
    .lap-badge{font-size:11px;font-weight:700;padding:3px 8px;border-radius:20px;flex-shrink:0}
    .lap-badge.on{background:rgba(34,211,160,.15);color:var(--success)}
    .lap-badge.off{background:var(--bg-elevated);color:var(--text-muted)}
    .lap-badge.full{background:rgba(255,71,87,.12);color:var(--danger)}
    .lap-toggle{width:32px;height:32px;border-radius:50%;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all var(--transition)}
    .lap-toggle.add{background:var(--accent-dim);color:var(--accent)}
    .lap-toggle.add:hover{background:var(--accent);color:#fff}
    .lap-toggle.remove{background:rgba(255,71,87,.12);color:var(--danger)}
    .lap-toggle.remove:hover{background:var(--danger);color:#fff}
    </style>

    <script>
    let _lapTarget = null, _lapEntityId = null, _lapEntityName = \'\';

    async function openLicAssignPanel(target, entityId, entityName) {
        _lapTarget = target; _lapEntityId = entityId; _lapEntityName = entityName;
        document.getElementById(\'lap-title\').textContent = target === \'asset\' ? \'Licences machine\' : \'Licences utilisateur\';
        document.getElementById(\'lap-subtitle\').textContent = entityName;
        document.getElementById(\'lic-assign-backdrop\').style.display = \'\';
        const panel = document.getElementById(\'lic-assign-panel\');
        panel.style.display = \'flex\';
        document.body.style.overflow = \'hidden\';
        await _lapLoad();
    }

    function closeLicAssignPanel() {
        document.getElementById(\'lic-assign-backdrop\').style.display = \'none\';
        document.getElementById(\'lic-assign-panel\').style.display = \'none\';
        document.body.style.overflow = \'\';
    }

    async function _lapLoad() {
        const list = document.getElementById(\'lap-list\');
        list.innerHTML = \'<div style="text-align:center;padding:40px;color:var(--text-muted);font-size:13px">Chargement…</div>\';
        try {
            const r = await fetch(`${APP_URL}/api/assign-licenses.php?available=1&target=${_lapTarget}&entity_id=${_lapEntityId}`, {credentials:\'same-origin\'});
            const d = await r.json();
            _lapRender(d.data || []);
        } catch(e) {
            list.innerHTML = \'<div style="padding:20px;color:var(--danger);font-size:13px">Erreur de chargement</div>\';
        }
    }

    const CAT_COLORS_LAP = {
        office:\'#2563eb\',security:\'#22d3a0\',os:\'#9d7bff\',
        productivity:\'#f5a623\',development:\'#38d9f5\',
        design:\'#fb923c\',erp:\'#ff4757\',other:\'#64748b\'
    };

    function _lapRender(lics) {
        const list = document.getElementById(\'lap-list\');
        if (!lics.length) {
            list.innerHTML = \'<div style="text-align:center;padding:40px;color:var(--text-muted);font-size:13px">Aucune licence \'+(_lapTarget===\'asset\'?\'machine\':\'utilisateur\')+\' configurée.<br><a href="\'+APP_URL+\'/pages/licenses.php" style="color:var(--accent)">Gérer les licences →</a></div>\';
            return;
        }

        // Grouper par catégorie
        const cats = {};
        lics.forEach(l => { (cats[l.category] = cats[l.category]||[]).push(l); });

        list.textContent = \'\';
        Object.entries(cats).forEach(([cat, items]) => {
            const hdr = document.createElement(\'div\');
            hdr.style.cssText = \'padding:10px 20px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted)\';
            hdr.textContent = {office:\'Bureautique\',security:\'Sécurité\',os:\'Système\',productivity:\'Productivité\',development:\'Développement\',design:\'Design\',erp:\'ERP / Compta\',other:\'Autre\'}[cat]||cat;
            list.appendChild(hdr);

            items.forEach(l => {
                const item = document.createElement(\'div\');
                item.className = \'lap-item\' + (l.is_assigned ? \' assigned\' : \'\');
                item.dataset.id = l.id;

                const dot = document.createElement(\'div\');
                dot.className = \'lap-dot\';
                dot.style.background = CAT_COLORS_LAP[l.category] || \'#64748b\';

                const info = document.createElement(\'div\');
                info.style.flex = \'1\'; info.style.minWidth = \'0\';
                const name = document.createElement(\'div\'); name.className = \'lap-name\';
                name.textContent = l.name + (l.vendor ? \' (\'+l.vendor+\')\' : \'\');
                const meta = document.createElement(\'div\'); meta.className = \'lap-meta\';
                const cost = parseFloat(l.cost_per_seat||0);
                const avail = parseInt(l.available_seats);
                meta.textContent = (avail > 0 ? avail+\' siège\'+(avail>1?\'s\':\'\')+ \' dispo\' : \'Complet\')
                    + (cost > 0 ? \' · \'+cost.toLocaleString(\'fr-FR\',{minimumFractionDigits:2})+\'€\' : \'\');
                info.appendChild(name); info.appendChild(meta);

                const badge = document.createElement(\'span\');
                badge.className = \'lap-badge \' + (l.is_assigned ? \'on\' : (avail <= 0 ? \'full\' : \'off\'));
                badge.textContent = l.is_assigned ? \'Attribuée\' : (avail <= 0 ? \'Complet\' : \'Disponible\');

                const btn = document.createElement(\'button\');
                btn.className = \'lap-toggle \' + (l.is_assigned ? \'remove\' : \'add\');
                btn.disabled = !l.is_assigned && avail <= 0;
                btn.innerHTML = l.is_assigned
                    ? \'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>\'
                    : \'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>\';
                btn.title = l.is_assigned ? \'Révoquer\' : \'Attribuer\';

                btn.addEventListener(\'click\', async (e) => {
                    e.stopPropagation();
                    btn.disabled = true;
                    try {
                        if (l.is_assigned) {
                            await api(APP_URL+\'/api/assign-licenses.php\', {method:\'DELETE\', body:{assignment_id:l.assignment_id, license_id:l.id}});
                            toast(l.name+\' révoquée\', \'success\');
                        } else {
                            await api(APP_URL+\'/api/assign-licenses.php\', {method:\'POST\', body:{license_id:l.id, target:_lapTarget, entity_id:_lapEntityId}});
                            toast(l.name+\' attribuée\', \'success\');
                        }
                        await _lapLoad();
                    } catch(err) { btn.disabled = false; }
                });

                item.appendChild(dot); item.appendChild(info); item.appendChild(badge); item.appendChild(btn);
                list.appendChild(item);
            });
        });
    }
    </script>

    <!-- COMMAND PALETTE -->
    <div class="cmd-backdrop" id="cmd-backdrop" onclick="closeCmdPalette()">
      <div class="cmd-palette" onclick="event.stopPropagation()">
        <div class="cmd-input-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input class="cmd-input" id="cmd-input" placeholder="Rechercher une page, un poste…" autocomplete="off">
          <span class="cmd-hint">Esc</span>
        </div>
        <div class="cmd-results" id="cmd-results"></div>
        <div class="cmd-footer">
          <span><kbd class="cmd-key">↵</kbd> Naviguer</span>
          <span><kbd class="cmd-key">↑↓</kbd> Sélectionner</span>
          <span><kbd class="cmd-key">Esc</kbd> Fermer</span>
        </div>
      </div>
    </div>
    <script>
    const CMD_NAV = [
      {label:"Vue globale",      group:"Navigation", icon:"grid",       url:"' . APP_URL . '/pages/overview.php"},
      {label:"Supervision",      group:"Navigation", icon:"wifi",       url:"' . APP_URL . '/pages/monitoring.php"},
      {label:"Parc informatique",group:"Navigation", icon:"monitor",    url:"' . APP_URL . '/pages/assets.php"},
      {label:"Utilisateurs",     group:"Navigation", icon:"users",      url:"' . APP_URL . '/pages/employees.php"},
      {label:"Facturation",      group:"Navigation", icon:"tag",        url:"' . APP_URL . '/pages/billing.php"},
      {label:"Licences",         group:"Navigation", icon:"key",        url:"' . APP_URL . '/pages/licenses.php"},
      {label:"Supervision",      group:"Navigation", icon:"wifi",       url:"' . APP_URL . '/pages/monitoring.php"},
      {label:"Paramètres",       group:"Navigation", icon:"settings",   url:"' . APP_URL . '/pages/settings.php"},
    ];
    let cmdIdx = -1;
    function openCmdPalette() {
      document.getElementById("cmd-backdrop").classList.add("active");
      const inp = document.getElementById("cmd-input");
      inp.value = ""; renderCmdResults(""); cmdIdx = -1;
      setTimeout(() => inp.focus(), 50);
    }
    function closeCmdPalette() {
      document.getElementById("cmd-backdrop").classList.remove("active");
    }
    function renderCmdResults(q) {
      const el = document.getElementById("cmd-results");
      el.textContent = "";
      const filtered = q ? CMD_NAV.filter(i => i.label.toLowerCase().includes(q.toLowerCase())) : CMD_NAV;
      if (!filtered.length) {
        const empty = document.createElement("div"); empty.className = "cmd-empty";
        empty.textContent = "Aucun résultat pour \"" + q + "\""; el.appendChild(empty); return;
      }
      filtered.forEach((item, i) => {
        const row = document.createElement("div"); row.className = "cmd-item" + (i === cmdIdx ? " selected" : "");
        row.dataset.url = item.url;
        const svg = document.createElementNS("http://www.w3.org/2000/svg","svg"); svg.setAttribute("viewBox","0 0 24 24"); svg.setAttribute("fill","none"); svg.setAttribute("stroke","currentColor"); svg.setAttribute("stroke-width","2");
        const use = document.createElementNS("http://www.w3.org/2000/svg","use"); use.setAttributeNS("http://www.w3.org/1999/xlink","href","#icon-"+item.icon); svg.appendChild(use);
        const lbl = document.createElement("span"); lbl.className = "cmd-item-label"; lbl.textContent = item.label;
        const grp = document.createElement("span"); grp.className = "cmd-item-group"; grp.textContent = item.group;
        row.appendChild(svg); row.appendChild(lbl); row.appendChild(grp);
        row.addEventListener("click", () => { closeCmdPalette(); window.location.href = item.url; });
        el.appendChild(row);
      });
    }
    document.getElementById("cmd-input").addEventListener("input", e => { cmdIdx = -1; renderCmdResults(e.target.value); });
    document.getElementById("cmd-input").addEventListener("keydown", e => {
      const items = document.querySelectorAll(".cmd-item");
      if (e.key === "ArrowDown") { e.preventDefault(); cmdIdx = Math.min(cmdIdx+1, items.length-1); items.forEach((el,i) => el.classList.toggle("selected", i===cmdIdx)); items[cmdIdx]?.scrollIntoView({block:"nearest"}); }
      else if (e.key === "ArrowUp") { e.preventDefault(); cmdIdx = Math.max(cmdIdx-1, 0); items.forEach((el,i) => el.classList.toggle("selected", i===cmdIdx)); items[cmdIdx]?.scrollIntoView({block:"nearest"}); }
      else if (e.key === "Enter") { const sel = items[cmdIdx] || items[0]; if (sel?.dataset.url) { closeCmdPalette(); window.location.href = sel.dataset.url; } }
      else if (e.key === "Escape") { closeCmdPalette(); }
    });
    document.addEventListener("keydown", e => {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") { e.preventDefault(); openCmdPalette(); }
    });
    </script>
    <script src="' . APP_URL . '/assets/js/app.js?v=' . @filemtime(__DIR__ . '/../assets/js/app.js') . '"></script>
    <footer style="position:fixed;bottom:8px;right:14px;z-index:50;font-size:11.5px;color:var(--text-muted);opacity:.6;pointer-events:none">
      &copy; 2026 <strong>DCTX</strong> &mdash; InventorFlow
    </footer>
    </body></html>';
}

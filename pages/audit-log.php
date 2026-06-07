<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();
if (!is_superadmin()) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();

$action = trim($_GET['action'] ?? '');
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['p'] ?? 1));
$per    = 50;
$offset = ($page - 1) * $per;

$where = []; $params = [];
if ($action !== '') { $where[] = 'action = ?'; $params[] = $action; }
if ($q !== '')      { $where[] = '(username LIKE ? OR details LIKE ? OR entity_type LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cs = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $wsql");
$cs->execute($params);
$total = (int)$cs->fetchColumn();
$pages = max(1, (int)ceil($total / $per));

$stmt = $pdo->prepare("SELECT * FROM audit_logs $wsql ORDER BY id DESC LIMIT $per OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$all_actions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$LABELS = [
    'login_success'     => ['Connexion',               '#22d3a0'],
    'login_failed'      => ['Échec de connexion',       '#ff4757'],
    'user_create'       => ['Compte créé',             '#4f7ef8'],
    'user_update'       => ['Compte modifié',          '#4f7ef8'],
    'user_delete'       => ['Compte supprimé',         '#ff4757'],
    'password_change'   => ['Mot de passe changé',     '#f5a623'],
    'totp_enable'       => ['TOTP activé',             '#22d3a0'],
    'totp_disable'      => ['TOTP désactivé',          '#f5a623'],
    'totp_reset'        => ['TOTP réinitialisé',       '#f5a623'],
    'totp_backup_regen' => ['Codes de secours régénérés', '#9d7bff'],
    'restore'           => ['Restauration',            '#ff4757'],
    'settings_update'   => ['Paramètres modifiés',     '#38d9f5'],
    'client_create'     => ['Client créé',             '#4f7ef8'],
    'client_update'     => ['Client modifié',          '#4f7ef8'],
];

render_head('Journal d\'audit');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('audit-log'); ?>
<div class="main-wrapper">
<?php render_topbar('Journal d\'audit', $total . ' évènement' . ($total > 1 ? 's' : '')); ?>
<main class="main-content">

<div class="card mb-24">
    <div class="card-body">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;flex:0 0 220px">
                <label class="form-label">Action</label>
                <select name="action" class="form-control">
                    <option value="">Toutes les actions</option>
                    <?php foreach ($all_actions as $a): ?>
                    <option value="<?= h($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= h($LABELS[$a][0] ?? $a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:180px">
                <label class="form-label">Recherche</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="utilisateur, détail, entité…">
            </div>
            <button class="btn btn-primary"><svg><use href="#icon-search"/></svg> Filtrer</button>
            <?php if ($action || $q): ?><a class="btn btn-ghost" href="<?= APP_URL ?>/pages/audit-log.php">Réinitialiser</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="table-wrapper">
<table id="main-table">
    <thead>
        <tr>
            <th>Date</th><th>Utilisateur</th><th>Action</th><th>Entité</th><th>Détails</th><th>IP</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$logs): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:30px">Aucun évènement.</td></tr>
    <?php endif; ?>
    <?php foreach ($logs as $l):
        [$lbl, $color] = $LABELS[$l['action']] ?? [$l['action'], 'var(--text-muted)'];
        $det = $l['details'] ? json_decode($l['details'], true) : null;
    ?>
    <tr>
        <td style="font-size:12.5px;color:var(--text-muted);white-space:nowrap"><?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?></td>
        <td style="font-size:13px"><?= $l['username'] ? h($l['username']) : '<span class="text-muted">—</span>' ?></td>
        <td><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:20px;color:<?= $color ?>;background:rgba(127,127,127,.12)"><?= h($lbl) ?></span></td>
        <td style="font-size:12.5px;color:var(--text-secondary)"><?= $l['entity_type'] ? h($l['entity_type']) . ($l['entity_id'] ? ' #' . (int)$l['entity_id'] : '') : '—' ?></td>
        <td style="font-size:12px;font-family:monospace;color:var(--text-muted);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $det ? h(implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($det), $det))) : '—' ?></td>
        <td style="font-size:12px;font-family:monospace;color:var(--text-muted)"><?= h($l['ip_address'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div style="display:flex;gap:8px;justify-content:center;margin-top:20px;align-items:center">
    <?php $qs = fn($p) => APP_URL . '/pages/audit-log.php?' . http_build_query(array_filter(['action' => $action, 'q' => $q, 'p' => $p])); ?>
    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= $qs($page - 1) ?>">← Précédent</a><?php endif; ?>
    <span style="font-size:13px;color:var(--text-muted)">Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= $qs($page + 1) ?>">Suivant →</a><?php endif; ?>
</div>
<?php endif; ?>

</main>
<?php render_footer(); ?>

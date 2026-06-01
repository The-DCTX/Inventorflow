<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$pdo = db();
$clients = get_clients();
$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/pages/clients.php'); exit; }


$CAT = [
    'office'       => ['label'=>'Bureautique',   'color'=>'#2563eb', 'icon'=>'layers'],
    'security'     => ['label'=>'Sécurité',       'color'=>'#22d3a0', 'icon'=>'key'],
    'os'           => ['label'=>'Système',         'color'=>'#9d7bff', 'icon'=>'cpu'],
    'productivity' => ['label'=>'Productivité',   'color'=>'#f5a623', 'icon'=>'users'],
    'development'  => ['label'=>'Développement',  'color'=>'#38d9f5', 'icon'=>'cpu'],
    'design'       => ['label'=>'Design',          'color'=>'#fb923c', 'icon'=>'tag'],
    'erp'          => ['label'=>'ERP / Compta',   'color'=>'#ff4757', 'icon'=>'briefcase'],
    'other'        => ['label'=>'Autre',           'color'=>'#64748b', 'icon'=>'layers'],
];
$TYPE = [
    'subscription'=>'Abonnement','perpetual'=>'Perpétuel','concurrent'=>'Concurrent',
    'per_device'=>'Par poste','per_user'=>'Par utilisateur','oem'=>'OEM',
];
$PERIOD = ['monthly'=>'Mensuel','annual'=>'Annuel','one_time'=>'Ponctuel'];

// Load licenses
$stmt = $pdo->prepare('SELECT l.*,
    (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id=l.id AND la.asset_id IS NOT NULL) as assigned_devices,
    (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id=l.id AND la.employee_id IS NOT NULL) as assigned_users,
    (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id=l.id) as total_assigned
    FROM licenses l WHERE l.client_id=? AND l.active=1 ORDER BY l.category,l.name');
$stmt->execute([$client_id]);
$licenses = $stmt->fetchAll();

// KPIs
$total_seats = array_sum(array_column($licenses,'total_seats'));
$total_used  = array_sum(array_column($licenses,'total_assigned'));
$total_cost_monthly = 0;
$expiring_soon = 0;
$expired = 0;
foreach ($licenses as $lic) {
    $monthly = match($lic['billing_period']) {
        'monthly'  => (float)$lic['cost_per_seat'] * (int)$lic['total_seats'],
        'annual'   => (float)$lic['cost_per_seat'] * (int)$lic['total_seats'] / 12,
        default    => 0,
    };
    $total_cost_monthly += $monthly;
    if ($lic['renewal_date']) {
        $days = (strtotime($lic['renewal_date']) - time()) / 86400;
        if ($days < 0)  $expired++;
        elseif ($days <= 90) $expiring_soon++;
    }
}
$compliance_rate = $total_seats > 0 ? round($total_used / $total_seats * 100) : 0;

$employees = get_employees($client_id);

render_head('Licences');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('licenses'); ?>
<div class="main-wrapper">
<?php render_topbar('Gestion des licences', count($licenses).' licences · '.count($clients).' clients'); ?>
<main class="main-content">

<!-- TABS -->
<div class="tabs mb-24" id="ltabs">
    <button class="tab active" onclick="ltab(this,'lt-overview')">
        <svg class="icon-xs" style="margin-right:5px"><use href="#icon-grid"/></svg>Vue d'ensemble
    </button>
    <button class="tab" onclick="ltab(this,'lt-catalog')">
        <svg class="icon-xs" style="margin-right:5px"><use href="#icon-layers"/></svg>Catalogue
    </button>
    <button class="tab" onclick="ltab(this,'lt-assign')">
        <svg class="icon-xs" style="margin-right:5px"><use href="#icon-users"/></svg>Attributions
    </button>
    <button class="tab" onclick="ltab(this,'lt-library')">
        <svg class="icon-xs" style="margin-right:5px"><use href="#icon-download"/></svg>Bibliothèque
    </button>
</div>

<!-- ══ OVERVIEW ══ -->
<div id="lt-overview">
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr));margin-bottom:24px">
    <div class="kpi-card blue"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-layers"/></svg></div></div>
        <div class="kpi-value"><?= count($licenses) ?></div><div class="kpi-label">Licences gérées</div></div>
    <div class="kpi-card purple"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-users"/></svg></div></div>
        <div class="kpi-value"><?= $total_used ?> / <?= $total_seats ?></div><div class="kpi-label">Sièges utilisés</div></div>
    <div class="kpi-card <?= $compliance_rate > 100 ? 'red' : ($compliance_rate > 85 ? 'amber' : 'green') ?>">
        <div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-check"/></svg></div></div>
        <div class="kpi-value"><?= $compliance_rate ?>%</div><div class="kpi-label">Taux de conformité</div></div>
    <div class="kpi-card amber"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-calendar"/></svg></div></div>
        <div class="kpi-value"><?= number_format($total_cost_monthly,0,',',' ') ?> €</div><div class="kpi-label">Coût/mois (HT)</div></div>
    <?php if ($expiring_soon > 0): ?>
    <div class="kpi-card amber"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-alert"/></svg></div></div>
        <div class="kpi-value"><?= $expiring_soon ?></div><div class="kpi-label">Expirent dans 90j</div></div>
    <?php endif; ?>
    <?php if ($expired > 0): ?>
    <div class="kpi-card red"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-alert"/></svg></div></div>
        <div class="kpi-value"><?= $expired ?></div><div class="kpi-label">Licences expirées</div></div>
    <?php endif; ?>
</div>

<!-- Compliance par licence -->
<div class="grid-2" style="gap:16px">
<div class="card">
    <div class="card-header"><div class="card-title">Conformité des licences</div><div class="card-subtitle">Sièges utilisés / total</div></div>
    <div class="card-body" style="padding:0">
    <?php foreach ($licenses as $lic):
        $used = (int)$lic['total_assigned'];
        $total = (int)$lic['total_seats'];
        $pct = $total > 0 ? min(round($used/$total*100), 100) : 0;
        $over = $used > $total;
        $warn = !$over && $pct >= 85;
        $color = $over ? 'red' : ($warn ? 'amber' : 'green');
        $cat = $CAT[$lic['category']] ?? $CAT['other'];
        $days_left = $lic['renewal_date'] ? (strtotime($lic['renewal_date'])-time())/86400 : null;
    ?>
    <div style="padding:12px 20px;border-bottom:1px solid var(--border-subtle)" onclick="openAssignPanel(<?= $lic['id'] ?>)" style="cursor:pointer">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;cursor:pointer">
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:10px;height:10px;border-radius:50%;background:<?= h($cat['color']) ?>;flex-shrink:0"></div>
                <span style="font-weight:600;font-size:13.5px"><?= h($lic['name']) ?></span>
                <span style="font-size:11.5px;color:var(--text-muted)"><?= h($lic['vendor']??'') ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <?php if ($days_left !== null): ?>
                <?php
                    $exp_color = $days_left < 0 ? 'var(--danger)' : ($days_left <= 30 ? 'var(--warning)' : ($days_left <= 90 ? '#f5a623' : 'var(--text-muted)'));
                    $exp_label = $days_left < 0 ? 'Expiré' : 'J-'.round($days_left);
                ?>
                <span style="font-size:11.5px;font-weight:600;color:<?= $exp_color ?>"><?= $exp_label ?></span>
                <?php endif; ?>
                <span style="font-size:13px;font-weight:700;font-variant-numeric:tabular-nums"><?= $used ?> / <?= $total ?></span>
                <?php if ($over): ?>
                <span class="badge badge-repair" style="font-size:11px">Dépassé</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress" style="height:6px">
            <div class="progress-bar <?= $color ?>" style="width:<?= $pct ?>%;<?= $over?'background:var(--danger)':'' ?>"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- Alertes expiration -->
<div class="card">
    <div class="card-header"><div class="card-title">Calendrier de renouvellement</div></div>
    <div class="card-body" style="padding:0">
    <?php
    $renewals = array_filter($licenses, fn($l) => $l['renewal_date']);
    usort($renewals, fn($a,$b) => strtotime($a['renewal_date']) - strtotime($b['renewal_date']));
    if (empty($renewals)):
    ?>
    <div class="empty-state" style="padding:30px"><svg><use href="#icon-calendar"/></svg><p>Aucune date de renouvellement</p></div>
    <?php else: foreach ($renewals as $lic):
        $days = (strtotime($lic['renewal_date'])-time())/86400;
        $expired_l = $days < 0;
        $exp_bg = $expired_l ? 'var(--danger-dim)' : ($days <= 30 ? 'var(--warning-dim)' : ($days <= 90 ? 'rgba(245,166,35,0.06)' : 'transparent'));
        $exp_color = $expired_l ? 'var(--danger)' : ($days <= 30 ? 'var(--warning)' : ($days <= 90 ? '#f5a623' : 'var(--text-muted)'));
        $cat = $CAT[$lic['category']] ?? $CAT['other'];
        $monthly = match($lic['billing_period']){
            'monthly'=>(float)$lic['cost_per_seat']*(int)$lic['total_seats'],
            'annual'=>(float)$lic['cost_per_seat']*(int)$lic['total_seats'],
            default=>0
        };
    ?>
    <div style="padding:11px 20px;border-bottom:1px solid var(--border-subtle);background:<?= $exp_bg ?>">
        <div style="display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:8px;height:8px;border-radius:50%;background:<?= h($cat['color']) ?>"></div>
                <div>
                    <div style="font-weight:600;font-size:13px"><?= h($lic['name']) ?></div>
                    <div style="font-size:11.5px;color:var(--text-muted)"><?= date('d/m/Y',strtotime($lic['renewal_date'])) ?></div>
                </div>
            </div>
            <div style="text-align:right">
                <div style="font-weight:700;font-size:13px;color:<?= $exp_color ?>"><?= $expired_l ? 'EXPIRÉ' : 'J-'.round($days) ?></div>
                <div style="font-size:11.5px;color:var(--text-muted)"><?= number_format($monthly,2,',',' ') ?> €</div>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>
</div>
</div><!-- /lt-overview -->

<!-- ══ CATALOGUE ══ -->
<div id="lt-catalog" style="display:none">
<div class="toolbar">
    <?php if (is_superadmin()): ?>
    <select id="lcat-client" class="filter-select" onchange="filterLicenses()">
        <?php foreach ($clients as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $c['id']==$client_id?'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select id="lcat-cat" class="filter-select" onchange="filterLicenses()">
        <option value="">Toutes les catégories</option>
        <?php foreach ($CAT as $k=>$v): ?>
        <option value="<?= h($k) ?>"><?= h($v['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" style="margin-left:auto" onclick="openLicModal()">
        <svg><use href="#icon-plus"/></svg> Nouvelle licence
    </button>
</div>

<div id="licenses-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px">
<?php foreach ($licenses as $lic):
    $cat = $CAT[$lic['category']] ?? $CAT['other'];
    $used = (int)$lic['total_assigned'];
    $total = (int)$lic['total_seats'];
    $pct = $total > 0 ? min(round($used/$total*100),100) : 0;
    $over = $used > $total;
    $barcolor = $over ? 'var(--danger)' : ($pct >= 85 ? 'var(--warning)' : 'var(--success)');
    $days_left = $lic['renewal_date'] ? (strtotime($lic['renewal_date'])-time())/86400 : null;
    $monthly_cost = match($lic['billing_period']){
        'monthly'=>(float)$lic['cost_per_seat']*(int)$lic['total_seats'],
        'annual'=>(float)$lic['cost_per_seat']*(int)$lic['total_seats']/12,
        default=>0
    };
?>
<div class="card lic-card" data-cat="<?= h($lic['category']) ?>" style="border-left:3px solid <?= h($cat['color']) ?>;transition:transform var(--transition)">
    <div class="card-body" style="padding:16px">
        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
            <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
                    <span style="font-weight:700;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($lic['name']) ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                    <span style="font-size:11.5px;color:var(--text-muted)"><?= h($lic['vendor']??'—') ?></span>
                    <span style="color:var(--border)">·</span>
                    <span class="badge" style="background:rgba(255,255,255,0.06);color:var(--text-secondary);border:1px solid var(--border);font-size:11px"><?= h($cat['label']) ?></span>
                </div>
            </div>
            <div style="display:flex;gap:4px;flex-shrink:0;margin-left:8px">
                <button class="btn btn-ghost btn-icon btn-sm" onclick="openLicModal(<?= $lic['id'] ?>)"><svg><use href="#icon-edit"/></svg></button>
                <button class="btn btn-ghost btn-icon btn-sm" onclick="delLic(<?= $lic['id'] ?>,'<?= h(addslashes($lic['name'])) ?>')"><svg style="color:var(--danger)"><use href="#icon-trash"/></svg></button>
            </div>
        </div>

        <!-- Compliance bar -->
        <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                <span style="font-size:12px;color:var(--text-secondary)">Sièges utilisés</span>
                <span style="font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;color:<?= $over?'var(--danger)':($pct>=85?'var(--warning)':'var(--success)') ?>"><?= $used ?> / <?= $total ?></span>
            </div>
            <div class="progress">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $barcolor ?>;transition:width 600ms ease"></div>
            </div>
            <?php if ($over): ?>
            <div style="font-size:11px;color:var(--danger);margin-top:3px;font-weight:600">⚠ <?= ($used-$total) ?> siège<?= ($used-$total)>1?'s':'' ?> en dépassement</div>
            <?php endif; ?>
        </div>

        <!-- Meta grid -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;margin-bottom:12px">
            <div style="padding:7px;background:var(--bg-elevated);border-radius:var(--radius-sm)">
                <div style="color:var(--text-muted);margin-bottom:2px">Type</div>
                <div style="font-weight:600"><?= h($TYPE[$lic['license_type']]??$lic['license_type']) ?></div>
                <div style="margin-top:4px">
                    <?php if ($lic['target']==='asset'): ?>
                    <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:rgba(56,217,245,.12);color:#38d9f5">Machine</span>
                    <?php else: ?>
                    <span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;background:rgba(79,126,248,.12);color:var(--accent)">Utilisateur</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="padding:7px;background:var(--bg-elevated);border-radius:var(--radius-sm)">
                <div style="color:var(--text-muted);margin-bottom:2px">Coût/mois</div>
                <div style="font-weight:600;color:var(--accent)"><?= number_format($monthly_cost,2,',',' ') ?> €</div>
            </div>
            <?php if ($days_left !== null): ?>
            <div style="padding:7px;background:var(--bg-elevated);border-radius:var(--radius-sm);border:1px solid <?= $days_left<0?'rgba(255,71,87,.3)':($days_left<=30?'rgba(245,166,35,.3)':'transparent') ?>">
                <div style="color:var(--text-muted);margin-bottom:2px">Renouvellement</div>
                <div style="font-weight:600;color:<?= $days_left<0?'var(--danger)':($days_left<=30?'var(--warning)':'var(--text-primary)') ?>"><?= date('d/m/Y',strtotime($lic['renewal_date'])) ?></div>
            </div>
            <?php endif; ?>
            <div style="padding:7px;background:var(--bg-elevated);border-radius:var(--radius-sm)">
                <div style="color:var(--text-muted);margin-bottom:2px">Coût / Revente</div>
                <div style="font-weight:600"><?= number_format($lic['cost_per_seat'],2,',',' ') ?> €
                <?php if ((float)$lic['sell_price_per_seat'] > 0): ?>
                    <span style="color:var(--text-muted);font-weight:400"> → </span>
                    <span style="color:var(--success);font-weight:700"><?= number_format($lic['sell_price_per_seat'],2,',',' ') ?> €</span>
                <?php endif; ?>
                </div>
                <?php
                $margin_unit = (float)$lic['sell_price_per_seat'] - (float)$lic['cost_per_seat'];
                if ($margin_unit > 0): ?>
                <div style="font-size:11px;color:var(--success);margin-top:2px;font-weight:600">+<?= number_format($margin_unit,2,',',' ') ?> €/siège</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <button class="btn btn-secondary" style="width:100%;justify-content:center" onclick="openAssignPanel(<?= $lic['id'] ?>)">
            <svg><use href="#icon-users"/></svg> Gérer les attributions
        </button>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($licenses)): ?>
<div class="card" style="grid-column:1/-1"><div class="empty-state">
    <svg><use href="#icon-layers"/></svg>
    <h3>Aucune licence</h3>
    <button class="btn btn-primary" onclick="openLicModal()"><svg><use href="#icon-plus"/></svg> Ajouter</button>
</div></div>
<?php endif; ?>
</div>
</div><!-- /lt-catalog -->

<!-- ══ ATTRIBUTIONS ══ -->
<div id="lt-assign" style="display:none">
<div class="toolbar">
    <select id="la-license" class="filter-select" onchange="loadAssignments()" style="min-width:220px">
        <option value="">Sélectionner une licence</option>
        <?php foreach ($licenses as $lic): ?>
        <option value="<?= $lic['id'] ?>"><?= h($lic['name']) ?> (<?= $lic['total_assigned'] ?>/<?= $lic['total_seats'] ?>)</option>
        <?php endforeach; ?>
    </select>
    <div style="margin-left:auto">
        <button class="btn btn-primary" id="btn-add-assign" onclick="openAssignModal()" disabled>
            <svg><use href="#icon-plus"/></svg> Attribuer
        </button>
    </div>
</div>
<div id="assign-list"><div class="empty-state"><svg><use href="#icon-layers"/></svg><p>Sélectionnez une licence</p></div></div>
</div><!-- /lt-assign -->

<!-- ══ BIBLIOTHÈQUE ══ -->
<div id="lt-library" style="display:none">
<div class="toolbar">
    <div class="search-box">
        <svg><use href="#icon-search"/></svg>
        <input type="text" id="lib-search" class="search-input" placeholder="Rechercher Microsoft, Adobe, antivirus…" oninput="filterLib()">
    </div>
    <select id="lib-cat" class="filter-select" onchange="filterLib()">
        <option value="">Toutes les catégories</option>
        <?php foreach ($CAT as $k=>$v): ?>
        <option value="<?= h($k) ?>"><?= h($v['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <div style="margin-left:auto;font-size:12.5px;color:var(--text-muted)"><?= count($pdo->query('SELECT id FROM license_templates')->fetchAll()) ?> logiciels référencés · Prix HT — TVA 20%</div>
</div>

<?php
$tpl_stmt = $pdo->query('SELECT * FROM license_templates ORDER BY sort_order, name');
$templates = $tpl_stmt->fetchAll();
$tpl_by_cat = [];
foreach ($templates as $t) $tpl_by_cat[$t['category']][] = $t;
$PERIOD_SHORT = ['monthly'=>'/mois','annual'=>'/an','one_time'=>''];
$UNIT_SHORT = ['per_device'=>'/poste','per_user'=>'/util.','flat'=>'','per_hour'=>'/h'];
?>

<div id="lib-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
<?php foreach ($tpl_by_cat as $cat => $tpls):
    $cat_info = $CAT[$cat] ?? $CAT['other']; ?>
<div class="lib-section-header" data-cat="<?= h($cat) ?>" style="grid-column:1/-1;display:flex;align-items:center;gap:10px;margin-top:8px">
    <div style="height:1px;flex:0 0 16px;background:var(--border)"></div>
    <span style="font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);font-weight:700;white-space:nowrap"><?= h($cat_info['label']) ?></span>
    <div style="height:1px;flex:1;background:var(--border)"></div>
</div>
<?php foreach ($tpls as $t):
    $ttc = round($t['price_ht'] * (1 + $t['vat_rate']/100), 2);
    $unit_s = $UNIT_SHORT[$t['unit']] ?? '';
    $period_s = $PERIOD_SHORT[$t['billing_period']] ?? '';
?>
<div class="card lib-card" data-cat="<?= h($t['category']) ?>" data-name="<?= h(strtolower($t['name'].' '.$t['vendor'])) ?>"
     style="border-left:3px solid <?= h($t['color']) ?>;cursor:pointer;transition:transform var(--transition),border-color var(--transition)"
     onclick="addFromLib(<?= $t['id'] ?>)"
     onmouseenter="this.style.transform='translateY(-2px)';this.style.borderColor='var(--accent)'"
     onmouseleave="this.style.transform='';this.style.borderColor='<?= h($t['color']) ?>'">
    <div class="card-body" style="padding:14px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($t['name']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= h($t['vendor']) ?></div>
            </div>
            <div style="width:28px;height:28px;border-radius:var(--radius-sm);background:rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-left:8px">
                <svg class="icon-sm" style="color:var(--accent)"><use href="#icon-plus"/></svg>
            </div>
        </div>
        <?php if ($t['description']): ?>
        <div style="font-size:11.5px;color:var(--text-secondary);margin-bottom:10px;line-height:1.5"><?= h($t['description']) ?></div>
        <?php endif; ?>
        <div style="display:flex;align-items:baseline;gap:8px;padding-top:10px;border-top:1px solid var(--border-subtle)">
            <div>
                <div style="font-size:18px;font-weight:800;color:<?= h($t['color']) ?>;line-height:1">
                    <?= number_format($t['price_ht'],2,',',' ') ?> €<span style="font-size:11px;font-weight:500;color:var(--text-muted)"> HT<?= $unit_s.$period_s ?></span>
                </div>
                <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px">
                    <?= number_format($ttc,2,',',' ') ?> € TTC<?= $unit_s.$period_s ?>
                </div>
            </div>
            <span class="badge badge-stock" style="margin-left:auto;font-size:11px"><?= h($PERIOD_SHORT[$t['billing_period']]??'') ?></span>
        </div>
    </div>
</div>
<?php endforeach; endforeach; ?>
</div>
</div><!-- /lt-library -->

</main>
</div>

<!-- ════ MODAL LICENCE ════ -->
<div class="modal-backdrop" id="modal-lic" style="display:none">
<div class="modal modal-lg"><div class="modal-header">
    <h2 class="modal-title" id="lic-modal-title">Nouvelle licence</h2>
    <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
</div>
<div class="modal-body">
<form id="form-lic" autocomplete="off">
<input type="hidden" name="id" id="lic-id">
<div class="form-row">
    <div class="form-group" style="grid-column:1/-1">
        <label>Nom <span class="required">*</span></label>
        <input type="text" name="name" id="lic-name" class="form-control" required placeholder="Microsoft 365 Business">
    </div>
</div>
<div class="form-row">
    <div class="form-group">
        <label>Éditeur</label>
        <input type="text" name="vendor" id="lic-vendor" class="form-control" placeholder="Microsoft">
    </div>
    <div class="form-group">
        <label>Catégorie</label>
        <select name="category" id="lic-cat" class="form-control">
            <?php foreach ($CAT as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v['label']) ?></option><?php endforeach; ?>
        </select>
    </div>
</div>
<div class="form-row">
    <div class="form-group">
        <label>Attribuable à</label>
        <select name="target" id="lic-target" class="form-control">
            <option value="employee">Licence Utilisateur — nominative (Office, Adobe…)</option>
            <option value="asset">Licence Machine — par poste (antivirus, OS…)</option>
        </select>
    </div>
    <div class="form-group">
        <label>Type de licence</label>
        <select name="license_type" id="lic-type" class="form-control">
            <?php foreach ($TYPE as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Nombre de sièges</label>
        <input type="number" name="total_seats" id="lic-seats" class="form-control" min="1" value="1" required>
    </div>
</div>
<div class="form-row">
    <div class="form-group">
        <label>Coût d'achat HT (€) <span style="font-size:11px;color:var(--text-muted)">Ce que vous payez</span></label>
        <input type="number" name="cost_per_seat" id="lic-cost" class="form-control" step="0.01" min="0" value="0" oninput="updateTtcPreview();updateMarginPreview()">
    </div>
    <div class="form-group">
        <label>Prix de revente HT (€) <span style="font-size:11px;color:var(--text-muted)">Ce que vous facturez</span></label>
        <input type="number" name="sell_price_per_seat" id="lic-sell" class="form-control" step="0.01" min="0" value="0" oninput="updateMarginPreview()">
    </div>
</div>
<div id="margin-preview" style="display:none;padding:8px 12px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;margin-bottom:12px"></div>
<div class="form-row">
    <div class="form-group">
        <label>TVA (%)</label>
        <input type="number" name="vat_rate" id="lic-vat" class="form-control" step="0.1" min="0" max="100" value="20" oninput="updateTtcPreview()">
    </div>
</div>
<div class="form-row">
    <div class="form-group">
        <label>Période</label>
        <select name="billing_period" id="lic-period" class="form-control">
            <?php foreach ($PERIOD as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
        </select>
    </div>
</div>
<div id="lic-price-preview" style="padding:10px 14px;background:var(--accent-dim);border:1px solid rgba(79,126,248,.2);border-radius:var(--radius-sm);font-size:13px;margin-bottom:16px;display:none">
    <span style="color:var(--text-secondary)">Prix TTC : </span>
    <span id="lic-ttc-val" style="font-weight:700;color:var(--accent)">—</span>
    <span id="lic-ttc-unit" style="color:var(--text-muted)"></span>
</div>
<div class="form-row">
    <div class="form-group">
        <label>Date d'achat</label>
        <input type="date" name="purchase_date" id="lic-purchase" class="form-control">
    </div>
    <div class="form-group">
        <label>Date de renouvellement</label>
        <input type="date" name="renewal_date" id="lic-renewal" class="form-control">
    </div>
</div>
<div class="form-group">
    <label>Clé de licence <span style="color:var(--text-muted);font-weight:400">(masquée à l'affichage)</span></label>
    <input type="text" name="product_key" id="lic-key" class="form-control" placeholder="XXXXX-XXXXX-XXXXX" style="font-family:monospace">
</div>
<div class="form-row">
    <div class="form-group">
        <label>Contact éditeur</label>
        <input type="text" name="vendor_contact" id="lic-contact" class="form-control" placeholder="support@microsoft.com">
    </div>
</div>
<div class="form-group">
    <label>Notes</label>
    <textarea name="notes" id="lic-notes" class="form-control" rows="2"></textarea>
</div>
</form>
</div>
<div class="modal-footer">
    <button class="btn btn-secondary" data-modal-close>Annuler</button>
    <button class="btn btn-primary" onclick="saveLic()"><svg><use href="#icon-check"/></svg> Enregistrer</button>
</div></div></div>

<!-- ════ PANEL ATTRIBUTIONS ════ -->
<div id="assign-panel-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);z-index:200;display:none" onclick="closeAssignPanel()"></div>
<div id="assign-panel" style="display:none;position:fixed;top:0;right:0;width:460px;max-width:100vw;height:100vh;background:var(--bg-modal);border-left:1px solid var(--border-active);z-index:201;display:flex;flex-direction:column;transform:translateX(100%);transition:transform 280ms cubic-bezier(.4,0,.2,1)">
    <div style="padding:20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
        <div>
            <div id="ap-title" style="font-size:16px;font-weight:700"></div>
            <div id="ap-subtitle" style="font-size:12.5px;color:var(--text-secondary);margin-top:2px"></div>
        </div>
        <button class="btn btn-ghost btn-icon" onclick="closeAssignPanel()"><svg><use href="#icon-x"/></svg></button>
    </div>

    <!-- Compliance bar in panel -->
    <div style="padding:14px 20px;border-bottom:1px solid var(--border-subtle)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <span style="font-size:12.5px;color:var(--text-secondary)">Occupation des sièges</span>
            <span id="ap-seats" style="font-size:13px;font-weight:700"></span>
        </div>
        <div class="progress"><div id="ap-bar" class="progress-bar green" style="width:0%;transition:width 400ms ease"></div></div>
    </div>

    <!-- Tabs -->
    <div style="display:flex;border-bottom:1px solid var(--border);padding:0 20px">
        <button class="tab active" style="padding:10px 14px;font-size:13px" onclick="apTab(this,'ap-devices')">Postes</button>
        <button class="tab" style="padding:10px 14px;font-size:13px" onclick="apTab(this,'ap-users')">Utilisateurs</button>
    </div>

    <div style="flex:1;overflow-y:auto">
        <!-- Add form -->
        <div style="padding:14px 20px;background:var(--bg-elevated);border-bottom:1px solid var(--border-subtle)">
            <div id="ap-devices">
                <div style="display:flex;gap:8px">
                    <select id="ap-device-select" class="form-control" style="flex:1">
                        <option value="">Sélectionner un poste…</option>
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="assignItem('device')"><svg><use href="#icon-plus"/></svg> Attribuer</button>
                </div>
            </div>
            <div id="ap-users" style="display:none">
                <div style="display:flex;gap:8px">
                    <select id="ap-user-select" class="form-control" style="flex:1">
                        <option value="">Sélectionner un utilisateur…</option>
                    </select>
                    <button class="btn btn-primary btn-sm" onclick="assignItem('user')"><svg><use href="#icon-plus"/></svg> Attribuer</button>
                </div>
            </div>
        </div>

        <div id="ap-list" style="padding:0"></div>
    </div>
</div>

<?php
$tpl_json = json_encode(array_map(fn($t)=>array_map(fn($v)=>$v===null?'':$v,$t),
    $pdo->query('SELECT * FROM license_templates ORDER BY sort_order')->fetchAll()),
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$lic_json = json_encode(array_map(fn($l)=>array_map(fn($v)=>$v===null?'':$v,$l),$licenses), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$assets_json = json_encode(array_map(fn($a)=>['id'=>$a['id'],'hostname'=>$a['hostname'],'os_type'=>$a['os_type']], array_filter(
    $pdo->prepare('SELECT id,hostname,os_type FROM assets WHERE client_id=? AND status="active" ORDER BY hostname')->execute([$client_id]) ? $pdo->prepare('SELECT id,hostname,os_type FROM assets WHERE client_id=? AND status="active" ORDER BY hostname')->execute([$client_id]) ? [] : [] : []
)), JSON_HEX_TAG|JSON_HEX_QUOT);

// Proper assets query
$as = $pdo->prepare('SELECT id,hostname,os_type FROM assets WHERE client_id=? AND status="active" ORDER BY hostname');
$as->execute([$client_id]);
$assets_data = $as->fetchAll();
$assets_json = json_encode(array_map(fn($a)=>['id'=>$a['id'],'hostname'=>$a['hostname'],'os_type'=>$a['os_type']],$assets_data), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$emps_json = json_encode(array_map(fn($e)=>['id'=>$e['id'],'name'=>$e['first_name'].' '.$e['last_name'],'position'=>$e['position']??''],$employees), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<script>
const APP_URL  = '<?= APP_URL ?>';
const LIB_TEMPLATES = <?= $tpl_json ?>;
const LICS     = <?= $lic_json ?>;
const ASSETS   = <?= $assets_json ?>;
const EMPS     = <?= $emps_json ?>;
let currentLicId = null;


// ── LIBRARY ───────────────────────────────────────────
function filterLib() {
    const q = (document.getElementById('lib-search')?.value || '').toLowerCase();
    const cat = document.getElementById('lib-cat')?.value || '';
    document.querySelectorAll('.lib-card').forEach(c => {
        const matchQ = !q || c.dataset.name.includes(q);
        const matchC = !cat || c.dataset.cat === cat;
        c.style.display = matchQ && matchC ? '' : 'none';
    });
    document.querySelectorAll('.lib-section-header').forEach(h => {
        const cat_h = h.dataset.cat;
        const visible = [...document.querySelectorAll(`.lib-card[data-cat="${cat_h}"]`)].some(c => c.style.display !== 'none');
        h.style.display = visible ? '' : 'none';
    });
}

function updateTtcPreview() {
    const ht = parseFloat(document.getElementById('lic-cost')?.value) || 0;
    const vat = parseFloat(document.getElementById('lic-vat')?.value) || 20;
    const ttc = ht * (1 + vat/100);
    const preview = document.getElementById('lic-price-preview');
    const val = document.getElementById('lic-ttc-val');
    if (ht > 0 && preview && val) {
        val.textContent = ttc.toFixed(2).replace('.', ',') + ' €';
        preview.style.display = 'block';
    } else if (preview) {
        preview.style.display = 'none';
    }
}

function updateMarginPreview() {
    const cost = parseFloat(document.getElementById('lic-cost')?.value) || 0;
    const sell = parseFloat(document.getElementById('lic-sell')?.value) || 0;
    const seats = parseInt(document.getElementById('lic-seats')?.value) || 1;
    const el = document.getElementById('margin-preview');
    if (!el) return;
    if (cost <= 0 && sell <= 0) { el.style.display='none'; return; }
    const margin_unit = sell - cost;
    const margin_total = margin_unit * seats;
    if (sell === 0 || sell === cost) {
        el.style.cssText = 'display:block;padding:8px 12px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;margin-bottom:12px;background:var(--bg-elevated);color:var(--text-muted)';
        el.textContent = 'Marge : 0 € (pass-through sans markup)';
    } else if (margin_unit > 0) {
        el.style.cssText = 'display:block;padding:8px 12px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;margin-bottom:12px;background:rgba(34,211,160,.1);color:var(--success)';
        el.textContent = '+ ' + margin_unit.toFixed(2).replace('.',',') + ' €/siège · ' + margin_total.toFixed(2).replace('.',',') + ' €/mois sur ' + seats + ' sièges';
    } else {
        el.style.cssText = 'display:block;padding:8px 12px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;margin-bottom:12px;background:rgba(255,71,87,.1);color:var(--danger)';
        el.textContent = margin_unit.toFixed(2).replace('.',',') + ' €/siège (revente en dessous du coût !)';
    }
}

async function addFromLib(tplId) {
    // Load template from hidden data attribute (injected via PHP)
    const tpl = LIB_TEMPLATES.find(t => t.id == tplId);
    if (!tpl) return;

    document.getElementById('lic-modal-title').textContent = 'Ajouter : ' + tpl.name;
    document.getElementById('form-lic').reset();
    document.getElementById('lic-id').value = '';
    document.getElementById('lic-name').value = tpl.name;
    const vendorEl = document.getElementById('lic-vendor'); if (vendorEl) vendorEl.value = tpl.vendor;
    document.getElementById('lic-cat').value = tpl.category;
    document.getElementById('lic-type').value = tpl.license_type;
    // Type d'attribution déduit du produit : sécurité/OS/par-poste = Machine, sinon Utilisateur (modifiable)
    const _tgt = document.getElementById('lic-target');
    if (_tgt) _tgt.value = (['security','os'].includes(tpl.category) || ['per_device','oem'].includes(tpl.license_type)) ? 'asset' : 'employee';
    document.getElementById('lic-cost').value = tpl.price_ht;
    const vatEl = document.getElementById('lic-vat'); if (vatEl) vatEl.value = tpl.vat_rate || '20';
    document.getElementById('lic-period').value = tpl.billing_period;
    document.getElementById('lic-seats').value = 1;
    updateTtcPreview();
    Modal.open('modal-lic');
}

// ── TABS ──────────────────────────────────────────────
function ltab(btn, id) {
    document.querySelectorAll('#ltabs .tab').forEach(t=>t.classList.remove('active'));
    btn.classList.add('active');
    ['lt-overview','lt-catalog','lt-assign','lt-library'].forEach(t=>{
        document.getElementById(t).style.display = t===id?'block':'none';
    });
}

// ── FILTER ────────────────────────────────────────────
function filterLicenses() {
    const cat = document.getElementById('lcat-cat')?.value || '';
    document.querySelectorAll('.lic-card').forEach(c=>{
        c.style.display = !cat || c.dataset.cat===cat ? '' : 'none';
    });
}

// ── LICENSE MODAL ─────────────────────────────────────
function openLicModal(id) {
    const l = id ? LICS.find(x=>x.id==id) : null;
    document.getElementById('lic-modal-title').textContent = l ? 'Modifier : '+l.name : 'Nouvelle licence';
    document.getElementById('form-lic').reset();
    document.getElementById('lic-id').value = '';
    if (l) {
        const f = {'lic-id':'id','lic-name':'name','lic-vendor':'vendor','lic-cat':'category',
                   'lic-target':'target','lic-type':'license_type','lic-seats':'total_seats',
                   'lic-cost':'cost_per_seat','lic-sell':'sell_price_per_seat',
                   'lic-period':'billing_period','lic-purchase':'purchase_date','lic-renewal':'renewal_date',
                   'lic-key':'product_key','lic-contact':'vendor_contact','lic-notes':'notes'};
        Object.entries(f).forEach(([el,k])=>{ const e=document.getElementById(el); if(e) e.value=l[k]||''; });
        updateMarginPreview();
    }
    Modal.open('modal-lic');
}

async function saveLic() {
    const data = Object.fromEntries(new FormData(document.getElementById('form-lic')));
    data.vat_rate = document.getElementById('lic-vat')?.value || '20';
    try {
        await api(`${APP_URL}/api/licenses.php`, {method:data.id?'PUT':'POST', body:data});
        toast(data.id?'Licence mise à jour':'Licence créée','success');
        Modal.close('modal-lic');
        setTimeout(()=>location.reload(),700);
    } catch(e) {}
}

async function delLic(id, name) {
    if (!confirm(`Supprimer "${name}" et toutes ses attributions ?`)) return;
    try {
        await api(`${APP_URL}/api/licenses.php`, {method:'DELETE', body:{id}});
        toast('Licence supprimée','success');
        setTimeout(()=>location.reload(),600);
    } catch(e) {}
}

// ── ASSIGN PANEL ──────────────────────────────────────
function openAssignPanel(licId) {
    currentLicId = licId;
    const l = LICS.find(x=>x.id==licId);
    if (!l) return;

    // Afficher uniquement l'onglet correspondant au target de la licence
    const isAsset = l.target === 'asset';
    const tabBtns = document.querySelectorAll('#assign-panel .tab');
    const tabDevBtn = tabBtns[0], tabUsrBtn = tabBtns[1];
    const divDevices = document.getElementById('ap-devices');
    const divUsers   = document.getElementById('ap-users');
    if (tabDevBtn) tabDevBtn.style.display = isAsset ? '' : 'none';
    if (tabUsrBtn) tabUsrBtn.style.display = isAsset ? 'none' : '';
    if (isAsset) {
        divDevices.style.display='block'; divUsers.style.display='none';
        if(tabDevBtn){tabDevBtn.classList.add('active');} if(tabUsrBtn){tabUsrBtn.classList.remove('active');}
    } else {
        divUsers.style.display='block'; divDevices.style.display='none';
        if(tabUsrBtn){tabUsrBtn.classList.add('active');} if(tabDevBtn){tabDevBtn.classList.remove('active');}
    }

    // Populate selects
    const ds = document.getElementById('ap-device-select');
    const us = document.getElementById('ap-user-select');
    ds.textContent = ''; us.textContent = '';
    const dopt = document.createElement('option'); dopt.value=''; dopt.textContent='Sélectionner un poste…'; ds.appendChild(dopt);
    ASSETS.forEach(a=>{ const o=document.createElement('option'); o.value=a.id; o.textContent=a.hostname+' ('+a.os_type+')'; ds.appendChild(o); });
    const uopt = document.createElement('option'); uopt.value=''; uopt.textContent='Sélectionner un utilisateur…'; us.appendChild(uopt);
    EMPS.forEach(e=>{ const o=document.createElement('option'); o.value=e.id; o.textContent=e.name+(e.position?' — '+e.position:''); us.appendChild(o); });

    document.getElementById('ap-title').textContent = l.name;
    document.getElementById('ap-subtitle').textContent = (l.vendor||'') + ' · ' + (l.total_seats) + ' siège'+((l.total_seats)>1?'s':'');
    updateSeatsBar(l);

    document.getElementById('assign-panel-backdrop').style.display = 'block';
    const panel = document.getElementById('assign-panel');
    panel.style.display = 'flex';
    requestAnimationFrame(()=>{ requestAnimationFrame(()=>{ panel.style.transform='translateX(0)'; }); });
    document.body.style.overflow = 'hidden';
    loadAssignList(licId);
}

function closeAssignPanel() {
    const panel = document.getElementById('assign-panel');
    panel.style.transform = 'translateX(100%)';
    panel.addEventListener('transitionend', ()=>{
        panel.style.display='none';
        document.getElementById('assign-panel-backdrop').style.display='none';
        document.body.style.overflow='';
    }, {once:true});
}

function updateSeatsBar(l) {
    const used = parseInt(l.total_assigned)||0;
    const total = parseInt(l.total_seats)||1;
    const pct = Math.min(Math.round(used/total*100),100);
    const over = used > total;
    document.getElementById('ap-seats').textContent = used + ' / ' + total;
    document.getElementById('ap-seats').style.color = over ? 'var(--danger)' : (pct>=85?'var(--warning)':'var(--success)');
    const bar = document.getElementById('ap-bar');
    bar.style.width = pct+'%';
    bar.style.background = over ? 'var(--danger)' : (pct>=85?'var(--warning)':'var(--success)');
}

function apTab(btn, id) {
    document.querySelectorAll('#assign-panel .tab').forEach(t=>t.classList.remove('active'));
    btn.classList.add('active');
    ['ap-devices','ap-users'].forEach(t=>{ document.getElementById(t).style.display=t===id?'block':'none'; });
}

async function loadAssignList(licId) {
    const list = document.getElementById('ap-list');
    list.textContent = '';
    try {
        const res = await fetch(`${APP_URL}/api/license-assignments.php?license_id=${licId}`);
        const data = await res.json();
        const assigns = data.data || [];
        if (!assigns.length) {
            const msg = document.createElement('div');
            msg.className = 'empty-state'; msg.style.padding='30px';
            const msgP = document.createElement('p'); msgP.textContent = 'Aucune attribution';
            msg.appendChild(msgP); list.appendChild(msg);
            return;
        }
        assigns.forEach(a=>{
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:11px 20px;border-bottom:1px solid var(--border-subtle)';
            const info = document.createElement('div');
            const name = document.createElement('div');
            name.style.cssText = 'font-weight:600;font-size:13.5px';
            name.textContent = a.hostname || a.employee_name || '—';
            const sub = document.createElement('div');
            sub.style.cssText = 'font-size:11.5px;color:var(--text-muted)';
            sub.textContent = a.hostname ? (a.os_type + (a.model?' · '+a.model:'')) : (a.position||'Utilisateur');
            info.appendChild(name); info.appendChild(sub);
            const btn = document.createElement('button');
            btn.className = 'btn btn-ghost btn-icon btn-sm';
            btn.title = 'Retirer';
            const trashSvg = document.createElementNS('http://www.w3.org/2000/svg','svg');
            trashSvg.style.color = 'var(--danger)';
            const trashUse = document.createElementNS('http://www.w3.org/2000/svg','use');
            trashUse.setAttributeNS('http://www.w3.org/1999/xlink','href','#icon-trash');
            trashSvg.appendChild(trashUse); btn.appendChild(trashSvg);
            btn.addEventListener('click', async ()=>{
                try {
                    await api(`${APP_URL}/api/license-assignments.php`, {method:'DELETE', body:{id:a.id,license_id:licId}});
                    toast('Attribution retirée','success');
                    loadAssignList(licId);
                    // Refresh seats
                    const lr = await fetch(`${APP_URL}/api/licenses.php`);
                    const ld = await lr.json();
                    const updated = (ld.data||[]).find(x=>x.id==licId);
                    if (updated) updateSeatsBar(updated);
                } catch(e) {}
            });
            row.appendChild(info); row.appendChild(btn);
            list.appendChild(row);
        });
    } catch(e) { list.textContent = 'Erreur de chargement'; }
}

async function assignItem(type) {
    const sel = type==='device' ? document.getElementById('ap-device-select') : document.getElementById('ap-user-select');
    const val = sel.value;
    if (!val || !currentLicId) return;
    const body = {license_id: currentLicId};
    if (type==='device') body.asset_id = val; else body.employee_id = val;
    try {
        await api(`${APP_URL}/api/license-assignments.php`, {method:'POST', body});
        toast('Licence attribuée','success');
        sel.value = '';
        loadAssignList(currentLicId);
    } catch(e) {}
}

// ── ATTRIBUTION TAB ───────────────────────────────────
async function loadAssignments() {
    const licId = document.getElementById('la-license').value;
    document.getElementById('btn-add-assign').disabled = !licId;
    if (!licId) return;
    const res = await fetch(`${APP_URL}/api/license-assignments.php?license_id=${licId}`);
    const data = await res.json();
    const list = document.getElementById('assign-list');
    list.textContent = '';
    // render as table
    const assigns = data.data || [];
    if (!assigns.length) {
        const msg = document.createElement('div');
        msg.className = 'empty-state';
        const esvg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        const euse = document.createElementNS('http://www.w3.org/2000/svg','use');
        euse.setAttributeNS('http://www.w3.org/1999/xlink','href','#icon-users');
        esvg.appendChild(euse);
        const ep = document.createElement('p'); ep.textContent = 'Aucune attribution pour cette licence';
        msg.appendChild(esvg); msg.appendChild(ep);
        list.appendChild(msg); return;
    }
    const tbl = document.createElement('div'); tbl.className = 'table-wrapper';
    tbl.innerHTML = '<table><thead><tr><th>Assigné à</th><th>Type</th><th>Détail</th><th>Depuis</th><th style="cursor:default">Actions</th></tr></thead><tbody id="assign-tbody"></tbody></table>';
    list.appendChild(tbl);
    const tbody = document.getElementById('assign-tbody');
    assigns.forEach(a=>{
        const tr = document.createElement('tr');

        const td1 = document.createElement('td');
        td1.style.fontWeight = '600';
        td1.textContent = a.hostname || a.employee_name || '—';

        const td2 = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = 'badge ' + (a.hostname ? 'badge-win' : 'badge-stock');
        badge.textContent = a.hostname ? 'Poste' : 'Utilisateur';
        td2.appendChild(badge);

        const td3 = document.createElement('td');
        td3.className = 'text-muted';
        td3.textContent = a.hostname
            ? (a.os_type + (a.model ? ' · ' + a.model : ''))
            : (a.position || '—');

        const td4 = document.createElement('td');
        td4.className = 'text-muted';
        td4.textContent = a.assigned_at || '—';

        const td5 = document.createElement('td');
        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-ghost btn-icon btn-sm';
        const delSvg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        delSvg.style.color = 'var(--danger)';
        const delUse = document.createElementNS('http://www.w3.org/2000/svg','use');
        delUse.setAttributeNS('http://www.w3.org/1999/xlink','href','#icon-trash');
        delSvg.appendChild(delUse); delBtn.appendChild(delSvg);
        delBtn.addEventListener('click', ()=>delAssign(a.id, a.license_id));
        td5.appendChild(delBtn);

        tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
        tr.appendChild(td4); tr.appendChild(td5);
        tbody.appendChild(tr);
    });
}

async function delAssign(id, licId) {
    if (!confirm('Retirer cette attribution ?')) return;
    try {
        await api(`${APP_URL}/api/license-assignments.php`, {method:'DELETE', body:{id,license_id:licId}});
        toast('Attribution retirée','success');
        loadAssignments();
    } catch(e) {}
}

function openAssignModal() { openAssignPanel(parseInt(document.getElementById('la-license').value)||0); }
</script>
<?php render_footer(); ?>

<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$pdo = db();

// ── LABELS (définis en haut, disponibles partout) ─────────
$unit_labels   = ['per_device'=>'/poste','per_user'=>'/utilisateur','flat'=>'forfait','per_hour'=>'/heure'];
$period_labels = ['monthly'=>'Mensuel','annual'=>'Annuel','one_time'=>'Ponctuel'];
$cat_labels    = ['monitoring'=>'Supervision','support'=>'Support','security'=>'Sécurité','backup'=>'Sauvegarde','infrastructure'=>'Infrastructure','other'=>'Autre'];
$cat_colors    = ['monitoring'=>'#38d9f5','support'=>'#9d7bff','security'=>'#22d3a0','backup'=>'#f5a623','infrastructure'=>'#4f7ef8','other'=>'#64748b'];

// ── DATA ──────────────────────────────────────────────────
$clients  = get_clients();
$services = $pdo->query('SELECT * FROM billing_services ORDER BY category, price')->fetchAll();

function calc_rev(array $sub, PDO $pdo): array {
    $price = (float)($sub['custom_price'] !== null && $sub['custom_price'] !== '' ? $sub['custom_price'] : $sub['service_price']);
    $disc  = (float)($sub['discount_pct'] ?? 0);
    $unit  = $sub['unit'];
    $cid   = (int)$sub['client_id'];

    if ($sub['qty_override'] !== null && $sub['qty_override'] !== '') {
        $qty = (int)$sub['qty_override'];
    } elseif ($unit === 'per_device') {
        $s = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE client_id=? AND status="active"');
        $s->execute([$cid]); $qty = (int)$s->fetchColumn();
    } elseif ($unit === 'per_user') {
        $s = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id=? AND active=1');
        $s->execute([$cid]); $qty = (int)$s->fetchColumn();
    } elseif ($unit === 'flat') {
        $qty = 1;
    } else {
        $qty = 0;
    }

    $net = $price * $qty * (1 - $disc / 100);
    $monthly = match($sub['billing_period']) {
        'annual'   => $net / 12,
        'one_time' => 0,
        default    => $net,
    };
    return ['qty' => $qty, 'net' => round($net, 2), 'monthly' => round($monthly, 2)];
}

$sub_stmt = $pdo->query('SELECT bs.*, s.name as service_name, s.unit, s.billing_period,
    s.price as service_price, s.color, s.category, c.name as client_name
    FROM billing_subscriptions bs
    JOIN billing_services s ON bs.service_id = s.id
    JOIN clients c ON bs.client_id = c.id
    WHERE bs.active = 1
    ORDER BY c.name, s.category');
$all_subs = $sub_stmt->fetchAll();

$mrr = 0; $by_client = []; $by_category = [];
foreach ($all_subs as &$sub) {
    $sub['_rev'] = calc_rev($sub, $pdo);
    $mrr += $sub['_rev']['monthly'];
    $cn = $sub['client_name'];
    $by_client[$cn] = ($by_client[$cn] ?? 0) + $sub['_rev']['monthly'];
    $cat = $sub['category'];
    $by_category[$cat] = ($by_category[$cat] ?? 0) + $sub['_rev']['monthly'];
}
unset($sub);
arsort($by_client);

$arr = $mrr * 12;
$d = $pdo->query('SELECT COUNT(*) FROM assets WHERE status="active"');
$total_devices = (int)$d->fetchColumn();

$subs_by_client = [];
foreach ($all_subs as $s) {
    $subs_by_client[$s['client_id']][] = $s;
}

$donut_data = array_values(array_map(fn($cat, $rev) => [
    'label' => $cat_labels[$cat] ?? $cat,
    'value' => round($rev, 2),
    'color' => $cat_colors[$cat] ?? '#64748b',
], array_keys($by_category), array_values($by_category)));

render_head('Facturation');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('billing'); ?>
<div class="main-wrapper">
<?php render_topbar('Facturation', 'Revenus & abonnements'); ?>
<main class="main-content">

<div class="tabs mb-24" id="btabs">
    <button class="tab active" onclick="btab(this,'td')"><svg class="icon-xs" style="margin-right:5px"><use href="#icon-grid"/></svg>Dashboard</button>
    <button class="tab" onclick="btab(this,'ts')"><svg class="icon-xs" style="margin-right:5px"><use href="#icon-tag"/></svg>Catalogue</button>
    <button class="tab" onclick="btab(this,'tp')">
        <svg class="icon-xs" style="margin-right:5px"><use href="#icon-layers"/></svg>Packs
    </button>
    <button class="tab" onclick="btab(this,'ta')"><svg class="icon-xs" style="margin-right:5px"><use href="#icon-layers"/></svg>Abonnements</button>
</div>

<!-- ══════════════ DASHBOARD ══════════════ -->
<div id="td">
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
    <div class="kpi-card green"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-arrow-up"/></svg></div></div>
        <div class="kpi-value"><?= number_format($mrr,0,',',' ') ?> €</div><div class="kpi-label">MRR mensuel</div></div>
    <div class="kpi-card blue"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-calendar"/></svg></div></div>
        <div class="kpi-value"><?= number_format($arr,0,',',' ') ?> €</div><div class="kpi-label">ARR annuel</div></div>
    <div class="kpi-card purple"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-briefcase"/></svg></div></div>
        <div class="kpi-value"><?= count($by_client) ?></div><div class="kpi-label">Clients actifs</div></div>
    <div class="kpi-card mac"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-monitor"/></svg></div></div>
        <div class="kpi-value"><?= $total_devices ?></div><div class="kpi-label">Postes facturables</div></div>
    <div class="kpi-card amber"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-layers"/></svg></div></div>
        <div class="kpi-value"><?= count($all_subs) ?></div><div class="kpi-label">Abonnements</div></div>
    <?php if (count($by_client) > 0): ?>
    <div class="kpi-card lin"><div class="kpi-header"><div class="kpi-icon"><svg class="icon-md"><use href="#icon-tag"/></svg></div></div>
        <div class="kpi-value"><?= number_format($mrr/count($by_client),0,',',' ') ?> €</div><div class="kpi-label">Revenu moy./client</div></div>
    <?php endif; ?>
</div>

<?php if (empty($all_subs)): ?>
<div class="card"><div class="empty-state" style="padding:60px">
    <svg><use href="#icon-tag"/></svg>
    <h3>Aucun abonnement configuré</h3>
    <p>Assignez des services à vos clients pour voir votre tableau de bord revenus.</p>
    <button class="btn btn-primary" onclick="btab(document.querySelectorAll('#btabs .tab')[2],'ta');openSubModal()">
        <svg><use href="#icon-plus"/></svg> Ajouter un abonnement
    </button>
</div></div>
<?php else: ?>

<div class="grid-2" style="gap:16px;margin-bottom:16px">
    <div class="card">
        <div class="card-header"><div class="card-title">Revenus par client</div><div class="card-subtitle">Mensuel récurrent</div></div>
        <div class="card-body" style="padding:0">
        <?php foreach ($by_client as $cn => $rev):
            $pct = $mrr > 0 ? ($rev/$mrr*100) : 0; ?>
        <div style="padding:12px 20px;border-bottom:1px solid var(--border-subtle)">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <span style="font-weight:600;font-size:13.5px"><?= h($cn) ?></span>
                <span style="font-weight:800;font-size:15px;color:var(--success)"><?= number_format($rev,2,',',' ') ?> €<span style="font-size:11px;color:var(--text-muted)">/mois</span></span>
            </div>
            <div class="progress"><div class="progress-bar green" style="width:<?= round($pct) ?>%"></div></div>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px"><?= round($pct) ?>% du MRR · <?= number_format($rev*12,0,',',' ') ?> €/an</div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><div class="card-title">Répartition par service</div></div>
        <div class="card-body" style="display:flex;align-items:center;gap:28px">
            <div id="donut-billing" class="donut-wrap"></div>
            <div class="legend" style="flex:1">
            <?php foreach ($by_category as $cat => $rev):
                $pct = $mrr > 0 ? round($rev/$mrr*100) : 0; ?>
            <div class="legend-item">
                <div class="legend-dot" style="background:<?= h($cat_colors[$cat]??'#64748b') ?>"></div>
                <span class="legend-name"><?= h($cat_labels[$cat]??$cat) ?></span>
                <span class="legend-value"><?= number_format($rev,0,',',' ') ?> €</span>
                <span class="legend-pct"><?= $pct ?>%</span>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php foreach ($subs_by_client as $cid => $csubs):
    $cmrr = array_sum(array_column(array_map(fn($s)=>$s['_rev'],$csubs),'monthly'));
    $cname = $csubs[0]['client_name']; ?>
<div class="card mb-16">
    <div class="card-header">
        <div><div class="card-title"><?= h($cname) ?></div>
            <div class="card-subtitle"><?= count($csubs) ?> service<?= count($csubs)>1?'s':'' ?> actif<?= count($csubs)>1?'s':'' ?></div></div>
        <div style="text-align:right">
            <div style="font-size:22px;font-weight:800;color:var(--success)"><?= number_format($cmrr,2,',',' ') ?> €<span style="font-size:12px;color:var(--text-muted)">/mois</span></div>
            <div style="font-size:12px;color:var(--text-muted)"><?= number_format($cmrr*12,0,',',' ') ?> €/an</div>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table style="width:100%">
        <thead><tr>
            <?php foreach (['Service','Unité','Qté','Prix unit.','Remise','Total/mois'] as $h): ?>
            <th style="padding:8px 16px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);font-weight:700;background:var(--bg-elevated);text-align:<?= in_array($h,['Qté','Prix unit.','Remise','Total/mois'])?'right':'left' ?>"><?= $h ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($csubs as $sub):
            $r = $sub['_rev'];
            $uprice = (float)($sub['custom_price'] !== null && $sub['custom_price'] !== '' ? $sub['custom_price'] : $sub['service_price']); ?>
        <tr style="border-bottom:1px solid var(--border-subtle)">
            <td style="padding:10px 16px">
                <div style="display:flex;align-items:center;gap:8px">
                    <div style="width:8px;height:8px;border-radius:50%;background:<?= h($sub['color']) ?>;flex-shrink:0"></div>
                    <span style="font-weight:600"><?= h($sub['service_name']) ?></span>
                </div>
                <div style="font-size:11.5px;color:var(--text-muted);margin-left:16px"><?= h($period_labels[$sub['billing_period']]??$sub['billing_period']) ?></div>
            </td>
            <td style="padding:10px 16px;color:var(--text-secondary)"><?= h($unit_labels[$sub['unit']]??$sub['unit']) ?></td>
            <td style="padding:10px 16px;text-align:right;font-weight:700"><?= $r['qty'] ?: '<span style="color:var(--text-muted)">—</span>' ?></td>
            <td style="padding:10px 16px;text-align:right;font-variant-numeric:tabular-nums"><?= number_format($uprice,2,',',' ') ?> €</td>
            <td style="padding:10px 16px;text-align:right;color:<?= $sub['discount_pct']>0?'var(--success)':'var(--text-muted)' ?>"><?= $sub['discount_pct']>0?'-'.$sub['discount_pct'].'%':'—' ?></td>
            <td style="padding:10px 16px;text-align:right;font-weight:800;font-size:15px;color:var(--success);font-variant-numeric:tabular-nums">
                <?= $sub['billing_period']==='one_time'?'<span style="color:var(--text-muted);font-size:12px">ponctuel</span>':number_format($r['monthly'],2,',',' ').' €' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ══════════════ CATALOGUE ══════════════ -->
<div id="ts" style="display:none">
<div class="toolbar">
    <span style="font-size:13.5px;color:var(--text-secondary)">Définissez vos tarifs — utilisés automatiquement lors de l'assignation.</span>
    <button class="btn btn-primary" style="margin-left:auto" onclick="openServiceModal()">
        <svg><use href="#icon-plus"/></svg> Nouveau service
    </button>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
<?php $cat_groups=[];
foreach ($services as $s) $cat_groups[$s['category']][] = $s;
foreach ($cat_groups as $cat => $cservices): ?>
<div style="grid-column:1/-1;margin-top:8px;display:flex;align-items:center;gap:10px">
    <div style="height:1px;flex:0 0 16px;background:var(--border)"></div>
    <span style="font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--text-muted);font-weight:700;white-space:nowrap"><?= h($cat_labels[$cat]??$cat) ?></span>
    <div style="height:1px;flex:1;background:var(--border)"></div>
</div>
<?php foreach ($cservices as $s): ?>
<div class="card" style="border-left:3px solid <?= h($s['color']) ?>">
    <div class="card-body" style="padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:15px;margin-bottom:4px"><?= h($s['name']) ?></div>
                <?php if ($s['description']): ?>
                <div style="font-size:12.5px;color:var(--text-secondary)"><?= h($s['description']) ?></div>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:4px;margin-left:10px;flex-shrink:0">
                <button class="btn btn-ghost btn-icon btn-sm" onclick="openServiceModal(<?= $s['id'] ?>)"><svg><use href="#icon-edit"/></svg></button>
                <button class="btn btn-ghost btn-icon btn-sm" onclick="deleteSvc(<?= $s['id'] ?>,'<?= h(addslashes($s['name'])) ?>')"><svg style="color:var(--danger)"><use href="#icon-trash"/></svg></button>
            </div>
        </div>
        <div style="display:flex;align-items:baseline;gap:6px;padding-top:12px;border-top:1px solid var(--border-subtle)">
            <span style="font-size:26px;font-weight:800;color:<?= h($s['color']) ?>"><?= number_format($s['price'],2,',',' ') ?> €</span>
            <span style="font-size:13px;color:var(--text-muted)"><?= h($unit_labels[$s['unit']]??'') ?></span>
            <span style="margin-left:auto"><span class="badge badge-stock"><?= h($period_labels[$s['billing_period']]??'') ?></span></span>
        </div>
    </div>
</div>
<?php endforeach; endforeach; ?>
</div>
</div>

<!-- ══════════════ ABONNEMENTS ══════════════ -->
<div id="ta" style="display:none">
<div class="toolbar">
    <select id="filter-sub-client" class="filter-select" onchange="filterSubs()">
        <option value="">Tous les clients</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" style="margin-left:auto" onclick="openSubModal()">
        <svg><use href="#icon-plus"/></svg> Assigner un service
    </button>
</div>
<div class="table-wrapper">
<table id="subs-tbl">
    <thead><tr>
        <th>Client</th><th>Service</th><th>Unité</th>
        <th>Qté</th><th>Prix unit.</th><th>Remise</th><th>Mensuel</th><th>Statut</th>
        <th style="cursor:default">Actions</th>
    </tr></thead>
    <tbody>
    <?php if (empty($all_subs)): ?>
    <tr><td colspan="9"><div class="empty-state"><svg><use href="#icon-layers"/></svg>
        <h3>Aucun abonnement</h3>
        <button class="btn btn-primary" onclick="openSubModal()"><svg><use href="#icon-plus"/></svg> Assigner</button>
    </div></td></tr>
    <?php else: ?>
    <?php foreach ($all_subs as $sub):
        $r=$sub['_rev'];
        $uprice=(float)($sub['custom_price']!==null&&$sub['custom_price']!==''?$sub['custom_price']:$sub['service_price']); ?>
    <tr data-client="<?= $sub['client_id'] ?>">
        <td style="font-weight:600"><?= h($sub['client_name']) ?></td>
        <td><div style="display:flex;align-items:center;gap:8px">
            <div style="width:8px;height:8px;border-radius:50%;background:<?= h($sub['color']) ?>;flex-shrink:0"></div>
            <?= h($sub['service_name']) ?>
        </div></td>
        <td class="text-muted"><?= h($unit_labels[$sub['unit']]??'') ?></td>
        <td><?php if ($sub['qty_override']!==null&&$sub['qty_override']!==''): ?>
            <span class="badge badge-repair"><?= $sub['qty_override'] ?> <small>forcé</small></span>
            <?php else: ?>
            <span class="badge badge-stock"><?= $r['qty'] ?> <small>auto</small></span>
            <?php endif; ?></td>
        <td style="font-variant-numeric:tabular-nums"><?= number_format($uprice,2,',',' ') ?> €</td>
        <td style="color:<?= $sub['discount_pct']>0?'var(--success)':'var(--text-muted)' ?>"><?= $sub['discount_pct']>0?'-'.$sub['discount_pct'].'%':'—' ?></td>
        <td style="font-weight:800;color:var(--success);font-variant-numeric:tabular-nums">
            <?= $sub['billing_period']==='one_time'?'<span class="text-muted">ponctuel</span>':number_format($r['monthly'],2,',',' ').' €' ?>
        </td>
        <td><span class="badge <?= $sub['active']?'badge-active':'badge-retired' ?>"><?= $sub['active']?'Actif':'Inactif' ?></span></td>
        <td><div class="td-actions">
            <button class="btn btn-ghost btn-icon" onclick="openSubModal(<?= $sub['id'] ?>)"><svg><use href="#icon-edit"/></svg></button>
            <button class="btn btn-ghost btn-icon" onclick="delSub(<?= $sub['id'] ?>)"><svg style="color:var(--danger)"><use href="#icon-trash"/></svg></button>
        </div></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
</div>

<!-- ══════════════ PACKS ══════════════ -->
<div id="tp" style="display:none">
<div class="toolbar">
    <div style="font-size:13.5px;color:var(--text-secondary)">Définissez vos packs de services — applicables en un clic depuis chaque poste.</div>
    <button class="btn btn-primary" style="margin-left:auto" onclick="openPackModal()">
        <svg><use href="#icon-plus"/></svg> Nouveau pack
    </button>
</div>
<div id="packs-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px"></div>
</div>
</main>
</div>

<!-- ════════ MODAL SERVICE ════════ -->
<div class="modal-backdrop" id="modal-svc" style="display:none">
<div class="modal"><div class="modal-header">
    <h2 class="modal-title" id="svc-modal-title">Nouveau service</h2>
    <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
</div>
<div class="modal-body">
<form id="form-svc" autocomplete="off">
    <input type="hidden" name="id" id="svc-id">
    <div class="form-group"><label>Nom <span class="required">*</span></label>
        <input type="text" name="name" id="svc-name" class="form-control" required placeholder="Supervision avancée"></div>
    <div class="form-group"><label>Description</label>
        <textarea name="description" id="svc-desc" class="form-control" rows="2"></textarea></div>
    <div class="form-row">
        <div class="form-group"><label>Catégorie</label>
            <select name="category" id="svc-cat" class="form-control">
                <?php foreach ($cat_labels as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
            </select></div>
        <div class="form-group"><label>Couleur</label>
            <input type="color" name="color" id="svc-color" class="form-control" value="#4f7ef8" style="height:40px;cursor:pointer"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>Prix (€) <span class="required">*</span></label>
            <input type="number" name="price" id="svc-price" class="form-control" required step="0.01" min="0"></div>
        <div class="form-group"><label>Unité</label>
            <select name="unit" id="svc-unit" class="form-control">
                <option value="per_device">Par poste</option>
                <option value="per_user">Par utilisateur</option>
                <option value="flat">Forfait</option>
                <option value="per_hour">Par heure</option>
            </select></div>
    </div>
    <div class="form-group"><label>Période</label>
        <select name="billing_period" id="svc-period" class="form-control">
            <option value="monthly">Mensuel</option>
            <option value="annual">Annuel</option>
            <option value="one_time">Ponctuel</option>
        </select></div>
</form>
</div>
<div class="modal-footer">
    <button class="btn btn-secondary" data-modal-close>Annuler</button>
    <button class="btn btn-primary" onclick="saveSvc()"><svg><use href="#icon-check"/></svg> Enregistrer</button>
</div></div></div>

<!-- ════════ MODAL ABONNEMENT ════════ -->
<div class="modal-backdrop" id="modal-sub" style="display:none">
<div class="modal modal-lg">
<div class="modal-header">
    <h2 class="modal-title" id="sub-modal-title">Assigner un service</h2>
    <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
</div>
<div class="modal-body">
<form id="form-sub" autocomplete="off">
<input type="hidden" name="id" id="sub-id">

<!-- Client + Service -->
<div class="form-row">
    <div class="form-group">
        <label>Client <span class="required">*</span></label>
        <select name="client_id" id="sub-client" class="form-control" required onchange="onSubClientChange()">
            <option value="">Sélectionner un client</option>
            <?php foreach ($clients as $c): ?>
            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Service <span class="required">*</span></label>
        <select name="service_id" id="sub-service" class="form-control" required onchange="onSubServiceChange()">
            <option value="">Sélectionner un service</option>
            <?php foreach ($services as $s): ?>
            <option value="<?= $s['id'] ?>"
                data-unit="<?= h($s['unit']) ?>"
                data-price="<?= $s['price'] ?>"
                data-period="<?= h($s['billing_period']) ?>">
                <?= h($s['name']) ?> — <?= number_format($s['price'],2,',',' ') ?> € <?= h($unit_labels[$s['unit']]??'') ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Stats client -->
<div id="sub-client-stats" style="display:none;padding:10px 14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:16px;display:none">
    <div style="display:flex;gap:20px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:6px;font-size:13px">
            <svg class="icon-sm" style="color:var(--accent)"><use href="#icon-monitor"/></svg>
            <span id="stat-devices" style="font-weight:700">—</span>
            <span style="color:var(--text-secondary)">postes actifs</span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;font-size:13px">
            <svg class="icon-sm" style="color:var(--purple)"><use href="#icon-users"/></svg>
            <span id="stat-users" style="font-weight:700">—</span>
            <span style="color:var(--text-secondary)">utilisateurs</span>
        </div>
    </div>
</div>

<!-- Mode d'application -->
<div id="sub-mode-wrap" style="display:none">
    <label style="margin-bottom:10px">Application du service</label>
    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px" id="sub-mode-options">
        <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition)" id="mode-auto-wrap">
            <input type="radio" name="qty_mode" value="auto" checked onchange="onModeChange()" style="margin-top:2px;accent-color:var(--accent)">
            <div>
                <div style="font-weight:600;font-size:13.5px">Automatique</div>
                <div style="font-size:12px;color:var(--text-secondary)" id="mode-auto-desc">Calculé sur le nombre de postes/utilisateurs actifs</div>
            </div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition)" id="mode-pick-wrap">
            <input type="radio" name="qty_mode" value="pick" onchange="onModeChange()" style="margin-top:2px;accent-color:var(--accent)">
            <div>
                <div style="font-weight:600;font-size:13.5px" id="mode-pick-label">Sélection manuelle des postes</div>
                <div style="font-size:12px;color:var(--text-secondary)" id="mode-pick-desc">Choisissez exactement quels postes sont couverts</div>
            </div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition)" id="mode-manual-wrap">
            <input type="radio" name="qty_mode" value="manual" onchange="onModeChange()" style="margin-top:2px;accent-color:var(--accent)">
            <div style="display:flex;align-items:center;gap:10px;flex:1">
                <div>
                    <div style="font-weight:600;font-size:13.5px">Quantité fixe</div>
                    <div style="font-size:12px;color:var(--text-secondary)">Définissez une quantité manuellement</div>
                </div>
                <input type="number" id="sub-qty-input" class="form-control" min="0" placeholder="0"
                    style="width:80px;margin-left:auto;display:none" oninput="updatePreview()">
            </div>
        </label>
    </div>

    <!-- Device picker -->
    <div id="device-picker" style="display:none;margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:12.5px;font-weight:600;color:var(--text-secondary)" id="picker-label">Sélectionnez les postes couverts</span>
            <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAllItems()">Tout sélectionner / désélectionner</button>
        </div>
        <div id="items-grid" style="display:flex;flex-wrap:wrap;gap:7px;max-height:180px;overflow-y:auto;padding:4px"></div>
    </div>
</div>

<!-- Options -->
<div id="sub-options" style="display:none">
    <div style="height:1px;background:var(--border);margin:16px 0"></div>
    <div class="form-row">
        <div class="form-group">
            <label>Remise (%)</label>
            <input type="number" name="discount_pct" id="sub-disc" class="form-control" step="0.5" min="0" max="100" value="0" oninput="updatePreview()">
        </div>
        <div class="form-group">
            <label>Prix personnalisé <small class="text-muted">(vide = catalogue)</small></label>
            <input type="number" name="custom_price" id="sub-cprice" class="form-control" step="0.01" min="0" placeholder="Prix catalogue" oninput="updatePreview()">
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Date début</label>
            <input type="date" name="start_date" id="sub-date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
            <label>Statut</label>
            <select name="active" id="sub-active" class="form-control"><option value="1">Actif</option><option value="0">Inactif</option></select>
        </div>
    </div>
    <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" id="sub-notes" class="form-control" rows="2"></textarea>
    </div>
</div>

<!-- Hidden qty_override field -->
<input type="hidden" name="qty_override" id="sub-qty-hidden">

<!-- PREVIEW -->
<div id="sub-preview" style="display:none;margin-top:16px;padding:16px;background:linear-gradient(135deg,rgba(34,211,160,0.08),rgba(79,126,248,0.08));border:1px solid rgba(34,211,160,0.25);border-radius:var(--radius)">
    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);font-weight:700;margin-bottom:10px">Estimation</div>
    <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap">
        <span id="prev-formula" style="font-size:13px;color:var(--text-secondary)"></span>
        <span style="font-size:11px;color:var(--text-muted)">=</span>
        <span id="prev-monthly" style="font-size:26px;font-weight:800;color:var(--success)">0,00 €</span>
        <span style="font-size:13px;color:var(--text-muted)">/mois</span>
        <span style="font-size:13px;color:var(--text-muted);margin-left:10px">·</span>
        <span id="prev-annual" style="font-size:15px;font-weight:700;color:var(--text-secondary)">0,00 €</span>
        <span style="font-size:13px;color:var(--text-muted)">/an</span>
    </div>
</div>

</form>
</div>
<div class="modal-footer">
    <button class="btn btn-secondary" data-modal-close>Annuler</button>
    <button class="btn btn-primary" onclick="saveSub()"><svg><use href="#icon-check"/></svg> Enregistrer</button>
</div>
</div></div>

<?php
$svcs_json = json_encode(array_map(fn($s)=>array_map(fn($v)=>$v===null?'':$v,$s),$services), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$subs_json = json_encode(array_map(function($s){unset($s['_rev']);return array_map(fn($v)=>$v===null?'':$v,$s);},$all_subs), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$donut_js  = json_encode($donut_data);
?>
<script>
const APP_URL = '<?= APP_URL ?>';
const SERVICES = <?= $svcs_json ?>;
const SUBS     = <?= $subs_json ?>;
let clientDevices = [];
let clientUsers = [];

// ── TABS ──────────────────────────────────────────
function btab(btn, id) {
    document.querySelectorAll('#btabs .tab').forEach(t=>t.classList.remove('active'));
    btn.classList.add('active');
    ['td','ts','ta','tp'].forEach(t=>{ const el=document.getElementById(t); if(el) el.style.display = t===id?'block':'none'; });
}

// ── DONUT ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const data = <?= $donut_js ?>;
    if (data && data.length) renderDonut('donut-billing', data, {size:130,stroke:16});
});

// ── FILTER SUBS ───────────────────────────────────
function filterSubs() {
    const v = document.getElementById('filter-sub-client').value;
    document.querySelectorAll('#subs-tbl tbody tr[data-client]').forEach(r=>{
        r.style.display = !v || r.dataset.client===v ? '' : 'none';
    });
}

// ── SERVICE MODAL ─────────────────────────────────
function openServiceModal(id) {
    const s = id ? SERVICES.find(x=>x.id==id) : null;
    document.getElementById('svc-modal-title').textContent = s ? 'Modifier : '+s.name : 'Nouveau service';
    document.getElementById('form-svc').reset();
    if (s) {
        ['id','name','description','category','color','price','unit','billing_period'].forEach(k=>{
            const el = document.getElementById('svc-'+k.replace('_','-').replace('billing-period','period'));
            if (!el) return;
            el.value = s[k] || '';
        });
        document.getElementById('svc-id').value = s.id;
        document.getElementById('svc-color').value = s.color || '#4f7ef8';
        document.getElementById('svc-period').value = s.billing_period || 'monthly';
    } else {
        document.getElementById('svc-color').value = '#4f7ef8';
    }
    Modal.open('modal-svc');
}

async function saveSvc() {
    const data = Object.fromEntries(new FormData(document.getElementById('form-svc')));
    try {
        await api(`${APP_URL}/api/billing-services.php`, {method: data.id ? 'PUT':'POST', body: data});
        toast(data.id ? 'Service mis à jour':'Service créé', 'success');
        Modal.close('modal-svc');
        setTimeout(()=>location.reload(), 700);
    } catch(e) {}
}

async function deleteSvc(id, name) {
    if (!confirm(`Supprimer "${name}" ? Les abonnements liés seront aussi supprimés.`)) return;
    try {
        await api(`${APP_URL}/api/billing-services.php`, {method:'DELETE', body:{id}});
        toast('Service supprimé','success');
        setTimeout(()=>location.reload(), 600);
    } catch(e) {}
}

// ── SUB MODAL ─────────────────────────────────────
function openSubModal(id) {
    const s = id ? SUBS.find(x=>x.id==id) : null;
    document.getElementById('sub-modal-title').textContent = s ? 'Modifier abonnement':'Assigner un service';
    document.getElementById('form-sub').reset();
    document.getElementById('sub-id').value = '';
    document.getElementById('sub-preview').style.display = 'none';
    document.getElementById('sub-mode-wrap').style.display = 'none';
    document.getElementById('sub-options').style.display = 'none';
    document.getElementById('sub-client-stats').style.display = 'none';
    document.getElementById('device-picker').style.display = 'none';
    document.getElementById('sub-qty-input').style.display = 'none';
    clientDevices = [];

    if (s) {
        document.getElementById('sub-id').value = s.id;
        document.getElementById('sub-client').value = s.client_id;
        document.getElementById('sub-service').value = s.service_id;
        document.getElementById('sub-disc').value = s.discount_pct || 0;
        document.getElementById('sub-cprice').value = s.custom_price || '';
        document.getElementById('sub-date').value = s.start_date || '';
        document.getElementById('sub-notes').value = s.notes || '';
        document.getElementById('sub-active').value = s.active ?? 1;

        // Determine mode from qty_override
        if (s.qty_override !== null && s.qty_override !== '') {
            document.querySelector('[name=qty_mode][value=manual]').checked = true;
            document.getElementById('sub-qty-input').value = s.qty_override;
            document.getElementById('sub-qty-input').style.display = '';
        } else {
            document.querySelector('[name=qty_mode][value=auto]').checked = true;
        }

        onSubClientChange(true);
    }
    Modal.open('modal-sub');
}

async function onSubClientChange(skipServiceReset) {
    const cid = document.getElementById('sub-client').value;
    if (!cid) return;

    // Load client stats
    try {
        const res = await fetch(`${APP_URL}/api/billing-client-info.php?client_id=${cid}`);
        const data = await res.json();
        if (data.data) {
            clientDevices = data.data.devices || [];
            clientUsers   = data.data.users   || [];
            document.getElementById('stat-devices').textContent = data.data.device_count;
            document.getElementById('stat-users').textContent   = data.data.user_count;
            document.getElementById('sub-client-stats').style.display = 'flex';
        }
    } catch(e) {}

    onSubServiceChange();
}

function onSubServiceChange() {
    const sel = document.getElementById('sub-service');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;

    const unit   = opt.dataset.unit;
    const period = opt.dataset.period;
    const modeWrap = document.getElementById('sub-mode-wrap');
    const pickOpt  = document.getElementById('mode-pick-wrap');

    modeWrap.style.display = 'block';
    document.getElementById('sub-options').style.display = 'block';

    // Show/hide device/user picker option
    pickOpt.style.display = (unit === 'per_device' || unit === 'per_user') ? '' : 'none';

    // Update labels based on unit
    const pickLabel = document.getElementById('mode-pick-label');
    const pickDesc  = document.getElementById('mode-pick-desc');
    const pickerLabel = document.getElementById('picker-label');
    if (unit === 'per_user') {
        pickLabel.textContent  = 'Sélection manuelle des utilisateurs';
        pickDesc.textContent   = 'Choisissez exactement quels utilisateurs sont couverts';
        pickerLabel.textContent = 'Sélectionnez les utilisateurs couverts';
    } else {
        pickLabel.textContent  = 'Sélection manuelle des postes';
        pickDesc.textContent   = 'Choisissez exactement quels postes sont couverts';
        pickerLabel.textContent = 'Sélectionnez les postes couverts';
    }

    // Update auto description
    const autoDesc = document.getElementById('mode-auto-desc');
    if (unit === 'per_device')   autoDesc.textContent = `Tous les postes actifs du client (${clientDevices.length})`;
    else if (unit === 'per_user') autoDesc.textContent = `Tous les utilisateurs actifs du client (${clientUsers.length})`;
    else if (unit === 'flat')     autoDesc.textContent = `1 forfait`;
    else                          autoDesc.textContent = `Calculé automatiquement`;

    updatePreview();
}

function onModeChange() {
    const mode = document.querySelector('[name=qty_mode]:checked')?.value;
    document.getElementById('device-picker').style.display  = mode === 'pick' ? 'block' : 'none';
    document.getElementById('sub-qty-input').style.display  = mode === 'manual' ? '' : 'none';

    if (mode === 'pick') renderItemPicker();
    updatePreview();
}

function renderItemPicker() {
    const svcOpt = document.getElementById('sub-service').options[document.getElementById('sub-service').selectedIndex];
    const unit   = svcOpt?.dataset.unit || 'per_device';
    const isUser = unit === 'per_user';
    const items  = isUser ? clientUsers : clientDevices;
    const grid   = document.getElementById('items-grid');
    const prefix = isUser ? 'usr' : 'dev';
    grid.textContent = '';

    if (!items.length) {
        const msg = document.createElement('div');
        msg.style.cssText = 'font-size:13px;color:var(--text-muted);padding:8px';
        msg.textContent = isUser ? 'Aucun utilisateur actif pour ce client' : 'Aucun poste actif pour ce client';
        grid.appendChild(msg);
        return;
    }

    items.forEach(item => {
        const chip = document.createElement('label');
        chip.style.cssText = 'display:flex;align-items:center;gap:6px;padding:5px 11px;border-radius:20px;background:var(--accent-dim);border:1px solid var(--accent);color:var(--accent);cursor:pointer;font-size:12.5px;font-weight:600;transition:all var(--transition)';
        chip.htmlFor = prefix + '-' + item.id;

        const cb = document.createElement('input');
        cb.type = 'checkbox'; cb.id = prefix + '-' + item.id; cb.value = item.id; cb.checked = true;
        cb.style.accentColor = 'var(--accent)';
        cb.addEventListener('change', () => {
            chip.style.background   = cb.checked ? 'var(--accent-dim)' : 'var(--bg-elevated)';
            chip.style.borderColor  = cb.checked ? 'var(--accent)' : 'var(--border)';
            chip.style.color        = cb.checked ? 'var(--accent)' : 'var(--text-secondary)';
            updatePreview();
        });

        const dot = document.createElement('span');
        if (isUser) {
            dot.style.cssText = 'width:20px;height:20px;border-radius:50%;background:var(--purple-dim);color:var(--purple);font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0';
            dot.textContent = (item.first_name[0]||'') + (item.last_name[0]||'');
        } else {
            const osColors = {MAC:'var(--os-mac)',WIN:'var(--os-win)',LIN:'var(--os-lin)'};
            dot.style.cssText = `width:6px;height:6px;border-radius:50%;background:${osColors[item.os_type]||'var(--accent)'};flex-shrink:0`;
        }

        const name = document.createElement('span');
        name.style.fontFamily = isUser ? 'inherit' : 'monospace';
        name.textContent = isUser ? `${item.first_name} ${item.last_name}` : item.hostname;

        chip.appendChild(cb); chip.appendChild(dot); chip.appendChild(name);
        grid.appendChild(chip);
    });
    updatePreview();
}

function toggleAllItems() {
    const cbs = document.querySelectorAll('#items-grid input[type=checkbox]');
    const allChecked = Array.from(cbs).every(c=>c.checked);
    cbs.forEach(cb => { cb.checked = !allChecked; cb.dispatchEvent(new Event('change')); });
}

function getQty() {
    const mode = document.querySelector('[name=qty_mode]:checked')?.value || 'auto';
    const svcOpt = document.getElementById('sub-service').options[document.getElementById('sub-service').selectedIndex];
    const unit = svcOpt?.dataset.unit || '';

    if (mode === 'manual') return parseInt(document.getElementById('sub-qty-input').value) || 0;
    if (mode === 'pick')   return document.querySelectorAll('#items-grid input:checked').length;
    // auto
    if (unit === 'per_device') return clientDevices.length;
    if (unit === 'per_user')   return parseInt(document.getElementById('stat-users').textContent) || 0;
    if (unit === 'flat')       return 1;
    return 0;
}

function updatePreview() {
    const svcOpt = document.getElementById('sub-service').options[document.getElementById('sub-service').selectedIndex];
    if (!svcOpt || !svcOpt.value) return;

    const basePrice = parseFloat(document.getElementById('sub-cprice').value) || parseFloat(svcOpt.dataset.price) || 0;
    const disc      = parseFloat(document.getElementById('sub-disc').value) || 0;
    const period    = svcOpt.dataset.period;
    const unit      = svcOpt.dataset.unit;
    const qty       = getQty();

    const net     = basePrice * qty * (1 - disc/100);
    const monthly = period === 'annual' ? net/12 : (period === 'one_time' ? net : net);
    const annual  = period === 'one_time' ? net : monthly * 12;

    const unitLabels = {per_device:'poste',per_user:'utilisateur',flat:'forfait',per_hour:'heure'};
    const formula = unit === 'flat' || unit === 'per_hour'
        ? `${basePrice.toFixed(2).replace('.',',')} €`
        : `${qty} ${unitLabels[unit]||''} × ${basePrice.toFixed(2).replace('.',',')} €${disc>0?' − '+disc+'%':''}`;

    document.getElementById('prev-formula').textContent = formula;
    document.getElementById('prev-monthly').textContent = monthly.toFixed(2).replace('.',',') + ' €';
    document.getElementById('prev-annual').textContent  = annual.toFixed(2).replace('.',',') + ' €';
    document.getElementById('sub-preview').style.display = 'block';
}

async function saveSub() {
    const form = document.getElementById('form-sub');
    const data = Object.fromEntries(new FormData(form));
    const mode = document.querySelector('[name=qty_mode]:checked')?.value || 'auto';

    if (mode === 'auto') {
        data.qty_override = '';
    } else if (mode === 'pick') {
        const checked = document.querySelectorAll('#devices-grid input:checked').length;
        data.qty_override = checked;
    } else {
        data.qty_override = document.getElementById('sub-qty-input').value || '';
    }
    delete data.qty_mode;

    try {
        await api(`${APP_URL}/api/billing-subscriptions.php`, {method: data.id ? 'PUT':'POST', body: data});
        toast(data.id ? 'Abonnement mis à jour':'Service assigné', 'success');
        Modal.close('modal-sub');
        setTimeout(()=>location.reload(), 700);
    } catch(e) {}
}

async function delSub(id) {
    if (!confirm('Supprimer cet abonnement ?')) return;
    try {
        await api(`${APP_URL}/api/billing-subscriptions.php`, {method:'DELETE', body:{id}});
        toast('Abonnement supprimé','success');
        setTimeout(()=>location.reload(), 600);
    } catch(e) {}
}

// ── PACKS MANAGEMENT ──────────────────────────────────────
const CAT_CFG_PACK = {
    monitoring:     {label:'Supervision',    color:'#38d9f5'},
    security:       {label:'Sécurité',       color:'#22d3a0'},
    backup:         {label:'Sauvegarde',     color:'#f5a623'},
    support:        {label:'Support',        color:'#9d7bff'},
    infrastructure: {label:'Infrastructure', color:'#4f7ef8'},
    other:          {label:'Autre',          color:'#64748b'},
};
const PACK_COLORS = ['#38d9f5','#4f7ef8','#9d7bff','#22d3a0','#f5a623','#fb923c','#ff4757'];

let ALL_PACKS = [];

async function loadPacks() {
    const grid = document.getElementById('packs-grid');
    if (!grid) return;
    try {
        const r = await fetch(APP_URL+'/api/packs.php', {credentials:'same-origin'});
        const d = await r.json();
        ALL_PACKS = d.data || [];
        renderPacksGrid();
    } catch(e) { grid.textContent = 'Erreur de chargement'; }
}

function renderPacksGrid() {
    const grid = document.getElementById('packs-grid');
    if (!grid) return;
    grid.textContent = '';

    ALL_PACKS.forEach(pack => {
        const card = document.createElement('div');
        card.className = 'card';
        card.style.cssText = `border-left:3px solid ${pack.color||'var(--accent)'}`;

        // Header
        const hdr = document.createElement('div');
        hdr.style.cssText = 'padding:16px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border-subtle)';
        const left = document.createElement('div');
        const pname = document.createElement('div');
        pname.style.cssText = 'font-size:15px;font-weight:700;margin-bottom:3px';
        pname.textContent = pack.name;
        const pdesc = document.createElement('div');
        pdesc.style.cssText = 'font-size:12px;color:var(--text-secondary)';
        pdesc.textContent = pack.description || '';
        left.appendChild(pname); left.appendChild(pdesc);

        const actions = document.createElement('div');
        actions.style.cssText = 'display:flex;gap:6px';
        const editBtn = document.createElement('button');
        editBtn.className = 'btn btn-ghost btn-icon btn-sm';
        editBtn.title = 'Modifier';
        editBtn.innerHTML = '<svg><use href="#icon-edit"/></svg>';
        editBtn.addEventListener('click', () => openPackModal(pack));
        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-ghost btn-icon btn-sm';
        delBtn.title = 'Supprimer';
        delBtn.innerHTML = '<svg style="color:var(--danger)"><use href="#icon-trash"/></svg>';
        delBtn.addEventListener('click', () => deletePack(pack.id, pack.name));
        actions.appendChild(editBtn); actions.appendChild(delBtn);
        hdr.appendChild(left); hdr.appendChild(actions);

        // Services in pack
        const body = document.createElement('div');
        body.style.cssText = 'padding:12px 18px';

        const services = SERVICES.filter(s => (pack.service_ids||[]).includes(parseInt(s.id)));
        if (!services.length) {
            const empty = document.createElement('div');
            empty.style.cssText = 'font-size:12.5px;color:var(--text-muted);padding:4px 0';
            empty.textContent = 'Aucun service — cliquez Modifier pour en ajouter';
            body.appendChild(empty);
        } else {
            const list = document.createElement('div');
            list.style.cssText = 'display:flex;flex-direction:column;gap:5px';
            let total = 0;
            services.forEach(s => {
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;align-items:center;justify-content:space-between';
                const lft = document.createElement('div');
                lft.style.cssText = 'display:flex;align-items:center;gap:7px';
                const dot = document.createElement('div');
                dot.style.cssText = `width:6px;height:6px;border-radius:50%;background:${CAT_CFG_PACK[s.category]?.color||'var(--accent)'};flex-shrink:0`;
                const nm = document.createElement('span');
                nm.style.cssText = 'font-size:13px;color:var(--text-primary)';
                nm.textContent = s.name;
                lft.appendChild(dot); lft.appendChild(nm);
                const pr = document.createElement('span');
                const p = parseFloat(s.price||0);
                const perUnit = {per_device:'/poste',per_user:'/util.',flat:'',per_hour:'/h'}[s.unit]||'';
                pr.style.cssText = 'font-size:12px;color:var(--success);font-weight:600';
                pr.textContent = p.toFixed(2).replace('.',',')+'€'+perUnit;
                total += (s.billing_period==='annual'?p/12:s.billing_period==='one_time'?0:p);
                row.appendChild(lft); row.appendChild(pr);
                list.appendChild(row);
            });
            // Total
            const tot = document.createElement('div');
            tot.style.cssText = 'display:flex;justify-content:space-between;padding-top:8px;margin-top:6px;border-top:1px solid var(--border-subtle)';
            const tl = document.createElement('span'); tl.style.cssText='font-size:11.5px;color:var(--text-muted);font-weight:600'; tl.textContent='TOTAL ESTIMÉ';
            const tv = document.createElement('span'); tv.style.cssText='font-size:14px;font-weight:800;color:var(--success)'; tv.textContent=total.toFixed(2).replace('.',',')+'€/mois';
            tot.appendChild(tl); tot.appendChild(tv);
            list.appendChild(tot);
            body.appendChild(list);
        }
        card.appendChild(hdr); card.appendChild(body);
        grid.appendChild(card);
    });

    if (!ALL_PACKS.length) {
        const empty = document.createElement('div');
        empty.className = 'card';
        empty.style.gridColumn = '1/-1';
        empty.innerHTML = '<div class="empty-state" style="padding:40px"><svg><use href="#icon-layers"/></svg><h3>Aucun pack</h3><button class="btn btn-primary" onclick="openPackModal()"><svg><use href="#icon-plus"/></svg> Créer un pack</button></div>';
        grid.appendChild(empty);
    }
}

function openPackModal(pack) {
    const isEdit = !!pack;
    document.getElementById('pack-modal-title').textContent = isEdit ? 'Modifier : '+pack.name : 'Nouveau pack';
    document.getElementById('pack-id').value = isEdit ? pack.id : '';
    document.getElementById('pack-name').value = isEdit ? pack.name : '';
    document.getElementById('pack-desc').value = isEdit ? (pack.description||'') : '';
    document.getElementById('pack-color').value = isEdit ? (pack.color||'#4f7ef8') : PACK_COLORS[ALL_PACKS.length % PACK_COLORS.length];

    // Render service checkboxes grouped by category
    const container = document.getElementById('pack-services-checks');
    container.textContent = '';
    const grouped = {};
    SERVICES.forEach(s => {
        if (!grouped[s.category]) grouped[s.category] = [];
        grouped[s.category].push(s);
    });
    const catOrder = ['monitoring','security','backup','support','infrastructure','other'];
    catOrder.forEach(cat => {
        if (!grouped[cat]) return;
        const grpLabel = document.createElement('div');
        grpLabel.style.cssText = 'font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);padding:10px 0 5px';
        grpLabel.textContent = CAT_CFG_PACK[cat]?.label || cat;
        container.appendChild(grpLabel);
        grouped[cat].forEach(s => {
            const checked = isEdit && (pack.service_ids||[]).includes(parseInt(s.id));
            const lbl = document.createElement('label');
            lbl.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--radius-sm);cursor:pointer;transition:background var(--transition)';
            lbl.addEventListener('mouseenter',()=>lbl.style.background='var(--bg-hover)');
            lbl.addEventListener('mouseleave',()=>lbl.style.background='');
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = s.id; cb.name = 'pack_services';
            cb.checked = checked; cb.style.accentColor='var(--accent)';
            cb.addEventListener('change', updatePackPreviewTotal);
            const dot = document.createElement('div');
            dot.style.cssText = `width:8px;height:8px;border-radius:50%;background:${CAT_CFG_PACK[s.category]?.color||'var(--accent)'};flex-shrink:0`;
            const info = document.createElement('div');
            info.style.flex = '1';
            const nm = document.createElement('div'); nm.style.cssText='font-size:13px;font-weight:500'; nm.textContent=s.name;
            const pr = document.createElement('div'); pr.style.cssText='font-size:11.5px;color:var(--text-muted)';
            const p=parseFloat(s.price||0);
            pr.textContent=p.toFixed(2).replace('.',',')+'€'+(s.unit==='per_device'?'/poste':s.unit==='per_user'?'/util.':'')+' / '+(s.billing_period==='monthly'?'mois':s.billing_period==='annual'?'an':'unique');
            info.appendChild(nm); info.appendChild(pr);
            lbl.appendChild(cb); lbl.appendChild(dot); lbl.appendChild(info);
            container.appendChild(lbl);
        });
    });
    updatePackPreviewTotal();
    Modal.open('modal-pack');
}

function updatePackPreviewTotal() {
    const cbs = document.querySelectorAll('#pack-services-checks input[type=checkbox]:checked');
    let total = 0;
    cbs.forEach(cb => {
        const s = SERVICES.find(x=>String(x.id)===String(cb.value));
        if (!s) return;
        const p=parseFloat(s.price||0);
        total += (s.billing_period==='annual'?p/12:s.billing_period==='one_time'?0:p);
    });
    const el = document.getElementById('pack-preview-total');
    if (el) { el.textContent = total>0 ? total.toFixed(2).replace('.',',')+'€/poste/mois estimé' : '—'; }
}

async function savePack() {
    const id = document.getElementById('pack-id').value;
    const service_ids = [...document.querySelectorAll('#pack-services-checks input:checked')].map(cb=>parseInt(cb.value));
    const data = {
        id: id||undefined,
        name: document.getElementById('pack-name').value.trim(),
        description: document.getElementById('pack-desc').value.trim(),
        color: document.getElementById('pack-color').value,
        service_ids,
    };
    if (!data.name) { toast('Nom obligatoire','warning'); return; }
    try {
        await api(APP_URL+'/api/packs.php', {method: id?'PUT':'POST', body:data});
        toast(id?'Pack mis à jour':'Pack créé','success');
        Modal.close('modal-pack');
        await loadPacks();
    } catch(e) {}
}

async function deletePack(id, name) {
    if (!confirm('Supprimer le pack "'+name+'" ?')) return;
    try {
        await api(APP_URL+'/api/packs.php', {method:'DELETE', body:{id}});
        toast('Pack supprimé','success');
        await loadPacks();
    } catch(e) {}
}

// Load packs when tab is activated
const origBtab = window.btab;
window.btab = function(btn, id) {
    origBtab(btn, id);
    if (id === 'tp') loadPacks();
};

</script>

<!-- ════ MODAL PACK ════ -->
<div class="modal-backdrop" id="modal-pack" style="display:none">
<div class="modal modal-lg"><div class="modal-header">
    <h2 class="modal-title" id="pack-modal-title">Nouveau pack</h2>
    <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
</div>
<div class="modal-body">
    <input type="hidden" id="pack-id">
    <div class="form-row">
        <div class="form-group">
            <label>Nom du pack <span class="required">*</span></label>
            <input type="text" id="pack-name" class="form-control" placeholder="Pack Essentiel">
        </div>
        <div class="form-group">
            <label>Couleur</label>
            <input type="color" id="pack-color" class="form-control" style="height:40px;cursor:pointer">
        </div>
    </div>
    <div class="form-group">
        <label>Description</label>
        <input type="text" id="pack-desc" class="form-control" placeholder="Idéal pour les PME…">
    </div>
    <div style="border-top:1px solid var(--border);margin:16px 0"></div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <label style="margin:0">Services inclus dans ce pack</label>
        <span id="pack-preview-total" style="font-size:13px;font-weight:700;color:var(--success)">—</span>
    </div>
    <div id="pack-services-checks" style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm);padding:0 8px"></div>
</div>
<div class="modal-footer">
    <button class="btn btn-secondary" data-modal-close>Annuler</button>
    <button class="btn btn-primary" onclick="savePack()"><svg><use href="#icon-check"/></svg> Enregistrer</button>
</div></div></div>

<?php render_footer(); ?>

<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$pdo = db();
$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/pages/clients.php'); exit; }


// ── BILLING DATA ─────────────────────────────────────
$unit_labels = ['per_device'=>'/poste','per_user'=>'/util.','flat'=>'forfait','per_hour'=>'/h'];
$period_labels = ['monthly'=>'Mensuel','annual'=>'Annuel','one_time'=>'Ponctuel'];

$sub_stmt = $pdo->prepare('SELECT bs.*, s.name as service_name, s.unit, s.billing_period, s.price as service_price,
    s.color, s.category, c.name as client_name
    FROM billing_subscriptions bs
    JOIN billing_services s ON bs.service_id=s.id
    JOIN clients c ON bs.client_id=c.id
    WHERE bs.active=1 AND bs.client_id=?');
$sub_stmt->execute([$client_id]);
$all_subs = $sub_stmt->fetchAll();

$mrr = 0; $by_client = []; $by_category = [];
$sub_rows = []; $by_period = ['monthly'=>0,'annual'=>0,'one_time'=>0]; $period_count = ['monthly'=>0,'annual'=>0,'one_time'=>0];
foreach ($all_subs as $sub) {
    $price = (float)($sub['custom_price'] ?: $sub['service_price']);
    $disc  = (float)($sub['discount_pct'] ?? 0);
    if ($sub['qty_override'] !== null) $qty = (int)$sub['qty_override'];
    elseif ($sub['unit']==='per_device') { $s=$pdo->prepare('SELECT COUNT(*) FROM assets WHERE client_id=? AND status="active"'); $s->execute([$sub['client_id']]); $qty=(int)$s->fetchColumn(); }
    elseif ($sub['unit']==='per_user') { $s=$pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id=? AND active=1'); $s->execute([$sub['client_id']]); $qty=(int)$s->fetchColumn(); }
    else $qty = 1;
    $net = $price * $qty * (1-$disc/100);
    $bp  = $sub['billing_period'];
    $monthly = match($bp){'annual'=>$net/12,'one_time'=>0,default=>$net};
    $mrr += $monthly;
    $by_client[$sub['client_name']] = ($by_client[$sub['client_name']]??0) + $monthly;
    $by_category[$sub['category']] = ($by_category[$sub['category']]??0) + $monthly;
    $by_period[$bp]     = ($by_period[$bp] ?? 0) + ($bp==='one_time' ? $net : $monthly);
    $period_count[$bp]  = ($period_count[$bp] ?? 0) + 1;
    $sub_rows[] = ['name'=>$sub['service_name'] ?: 'Service', 'qty'=>$qty, 'net'=>$net, 'period'=>$bp, 'category'=>$sub['category']];
}
arsort($by_client);
usort($sub_rows, fn($a,$b)=>$b['net']<=>$a['net']);
$arr = $mrr * 12;

// ── LICENSE DATA ──────────────────────────────────────
// Licences filtrées sur le client sélectionné
$lic_stmt = $pdo->prepare('SELECT l.*,
    (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id=l.id AND la.active=1) as total_assigned
    FROM licenses l WHERE l.active=1 AND l.client_id=?');
$lic_stmt->execute([$client_id]);
$all_lics = $lic_stmt->fetchAll();

$lic_cost_monthly   = 0;
$lic_margin_monthly = 0;
$total_seats = 0; $total_used = 0;
$expiring_90 = 0; $expired_count = 0;
foreach ($all_lics as $l) {
    $seats_used = (int)$l['total_assigned'];
    $factor = match($l['billing_period']) {
        'monthly' => 1,
        'annual'  => 1/12,
        default   => 0,
    };
    // Coût total mensuel (sur tous les sièges achetés)
    $lic_cost_monthly += (float)$l['cost_per_seat'] * (int)$l['total_seats'] * $factor;
    // Marge mensuelle = (revente - coût) × sièges utilisés
    $sell = (float)$l['sell_price_per_seat'];
    $cost = (float)$l['cost_per_seat'];
    if ($sell > 0) {
        $lic_margin_monthly += ($sell - $cost) * $seats_used * $factor;
    }
    $total_seats += (int)$l['total_seats'];
    $total_used  += $seats_used;
    if ($l['renewal_date']) {
        $d = (strtotime($l['renewal_date'])-time())/86400;
        if ($d<0) $expired_count++; elseif ($d<=90) $expiring_90++;
    }
}
$compliance = $total_seats>0 ? round($total_used/$total_seats*100) : 100;
// Marge = revenus abonnements + marge sur licences (si prix de revente renseigné)
$profit_monthly = $mrr + $lic_margin_monthly;

// ── DEVICE STATS ──────────────────────────────────────
$ds = $pdo->prepare('SELECT os_type,COUNT(*) as n FROM assets WHERE status="active" AND client_id=? GROUP BY os_type');
$ds->execute([$client_id]);
$device_stats = array_column($ds->fetchAll(),'n','os_type');
$total_devices = array_sum($device_stats);

// ── TOP CLIENTS ───────────────────────────────────────
$top_clients = array_slice($by_client, 0, 5, true);

// ── EXPIRING LICENSES ─────────────────────────────────
$exp_stmt = $pdo->prepare('SELECT name, vendor, renewal_date, (DATEDIFF(renewal_date,CURDATE())) as days_left, client_id FROM licenses WHERE active=1 AND client_id=? AND renewal_date IS NOT NULL AND renewal_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY renewal_date LIMIT 6');
$exp_stmt->execute([$client_id]);
$expiring_lics = $exp_stmt->fetchAll();

// ── PARC : statut heartbeat + alertes ressources (dernier relevé / poste) ──
$park_stmt = $pdo->prepare("
    SELECT a.id, a.hostname, a.os_type, ms.collected_at, ms.disk_used_gb, ms.disk_total_gb, ms.cpu_pct, ms.thermal_state
    FROM assets a
    LEFT JOIN (
        SELECT m.asset_id, m.collected_at, m.disk_used_gb, m.disk_total_gb, m.cpu_pct, m.thermal_state
        FROM monitoring_snapshots m
        JOIN (SELECT asset_id, MAX(collected_at) mx FROM monitoring_snapshots GROUP BY asset_id) t
          ON t.asset_id = m.asset_id AND t.mx = m.collected_at
    ) ms ON ms.asset_id = a.id
    WHERE a.client_id = ? AND a.status = 'active'");
$park_stmt->execute([$client_id]);
$park = $park_stmt->fetchAll();

$park_online = 0; $park_recent = 0; $park_offline = 0;
$resource_alerts = [];
$now_ts = time();
foreach ($park as $m) {
    if (!$m['collected_at']) { $park_offline++; continue; }
    $age = $now_ts - strtotime($m['collected_at']);
    if     ($age <= 3900)  $park_online++;   // ≤ 65 min
    elseif ($age <= 86400) $park_recent++;   // ≤ 24 h
    else                   $park_offline++;
    $dpct = ($m['disk_total_gb'] > 0) ? (int)round($m['disk_used_gb'] / $m['disk_total_gb'] * 100) : 0;
    $issues = [];
    if ($dpct >= 90) $issues[] = "Disque {$dpct}%";
    if (in_array(strtolower((string)$m['thermal_state']), ['high','critical'], true)) $issues[] = "Thermique " . $m['thermal_state'];
    if ((float)$m['cpu_pct'] >= 90) $issues[] = "CPU " . (int)round($m['cpu_pct']) . "%";
    if ($issues) $resource_alerts[] = ['host'=>$m['hostname'], 'os'=>$m['os_type'], 'issues'=>$issues];
}
$park_total = count($park);

// ── SÉCURITÉ : incidents des 7 derniers jours ──
$sev_count = ['critical'=>0,'high'=>0,'medium'=>0,'low'=>0];
$sc = $pdo->prepare("SELECT se.severity, COUNT(*) n FROM security_events se JOIN assets a ON se.asset_id=a.id
    WHERE a.client_id=? AND se.detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY se.severity");
$sc->execute([$client_id]);
foreach ($sc->fetchAll() as $r) { $sev_count[$r['severity']] = (int)$r['n']; }
$sec_total = array_sum($sev_count);
$se_stmt = $pdo->prepare("SELECT se.event_type, se.severity, se.source_ip, se.detected_at, se.attempt_count, a.hostname
    FROM security_events se JOIN assets a ON se.asset_id=a.id
    WHERE a.client_id=? AND se.detected_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY se.detected_at DESC LIMIT 5");
$se_stmt->execute([$client_id]);
$sec_events = $se_stmt->fetchAll();
$sev_color   = ['critical'=>'var(--danger)','high'=>'#fb923c','medium'=>'#f5a623','low'=>'var(--text-muted)'];
$evt_labels  = ['brute_force'=>'Brute-force','failed_auth'=>'Auth échouée','port_scan'=>'Scan de ports','ssh_success'=>'Connexion SSH','other'=>'Autre'];

render_head('Vue d\'ensemble');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('overview'); ?>
<div class="main-wrapper">
<div class="topbar">
    <button class="menu-btn" id="menuBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
    <div class="topbar-title"><h1 class="page-title">Vue d'ensemble</h1><p class="page-subtitle">Dashboard unifié · <?= date('d F Y') ?></p></div>
    <div style="margin-left:auto;display:flex;gap:8px">
        <button class="btn btn-secondary btn-sm" onclick="openReport('internal')" style="border-color:rgba(245,166,35,0.5);color:var(--warning)" title="Rapport avec marges et coûts — confidentiel">
            <svg><use href="#icon-download"/></svg> Rapport interne
        </button>
        <button class="btn btn-secondary btn-sm" onclick="openReport('client')" style="border-color:rgba(79,126,248,0.4);color:var(--accent)" title="Rapport sans données financières — à envoyer au client">
            <svg><use href="#icon-download"/></svg> Rapport client
        </button>
        <button class="btn btn-secondary btn-sm" id="btn-customize" onclick="toggleCustomize()">
            <svg><use href="#icon-settings"/></svg> Personnaliser
        </button>
    </div>
</div>

<!-- CUSTOMIZE PANEL -->
<div id="customize-panel" style="display:none;border-bottom:1px solid var(--border);background:var(--bg-elevated);padding:16px 24px">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <span style="font-size:12.5px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.06em">Widgets visibles :</span>
        <div id="widget-toggles" style="display:flex;flex-wrap:wrap;gap:8px"></div>
        <button class="btn btn-ghost btn-sm" onclick="resetWidgets()" style="margin-left:auto;color:var(--text-muted)">Réinitialiser</button>
    </div>
</div>

<main class="main-content">
<div id="widgets-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;align-items:start;grid-auto-flow:row dense">

<!-- ── W: MRR ── -->
<div class="widget" data-wid="mrr">
<div class="card" style="background:linear-gradient(135deg,rgba(34,211,160,.08),rgba(79,126,248,.06));border-color:rgba(34,211,160,.2)">
    <div class="card-body" style="padding:20px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <div style="width:36px;height:36px;border-radius:var(--radius-sm);background:var(--success-dim);display:flex;align-items:center;justify-content:center"><svg class="icon-md" style="color:var(--success)"><use href="#icon-arrow-up"/></svg></div>
            <span style="font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">MRR</span>
        </div>
        <div style="font-size:36px;font-weight:800;color:var(--success);font-variant-numeric:tabular-nums;line-height:1"><?= number_format($mrr,0,',',' ') ?> €</div>
        <div style="font-size:12.5px;color:var(--text-muted);margin-top:6px">Revenu mensuel récurrent</div>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border-subtle);font-size:12.5px;color:var(--text-secondary)">
            <span style="font-weight:700;color:var(--text-primary)"><?= number_format($arr,0,',',' ') ?> €</span> ARR annuel
        </div>
    </div>
</div>
</div>

<!-- ── W: MARGE ── -->
<div class="widget" data-wid="profit">
<div class="card" style="background:linear-gradient(135deg,rgba(79,126,248,.08),rgba(157,123,255,.06));border-color:rgba(79,126,248,.2)">
    <div class="card-body" style="padding:20px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <div style="width:36px;height:36px;border-radius:var(--radius-sm);background:var(--accent-dim);display:flex;align-items:center;justify-content:center"><svg class="icon-md" style="color:var(--accent)"><use href="#icon-tag"/></svg></div>
            <span style="font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">Marge estimée</span>
        </div>
        <div style="font-size:36px;font-weight:800;color:<?= $profit_monthly >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;font-variant-numeric:tabular-nums;line-height:1"><?= number_format($profit_monthly,0,',',' ') ?> €</div>
        <div style="font-size:12.5px;color:var(--text-muted);margin-top:6px">MRR + marge licences / mois</div>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border-subtle)">
            <div style="display:flex;justify-content:space-between;font-size:12px">
                <span style="color:var(--text-secondary)">MRR abonnements</span>
                <span style="font-weight:700;color:var(--success)"><?= number_format($mrr,0,',',' ') ?> €</span>
            </div>
            <?php if ($lic_margin_monthly != 0): ?>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-top:4px">
                <span style="color:var(--text-secondary)">Marge licences</span>
                <span style="font-weight:700;color:<?= $lic_margin_monthly >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= ($lic_margin_monthly >= 0 ? '+' : '') . number_format($lic_margin_monthly,0,',',' ') ?> €</span>
            </div>
            <?php endif; ?>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-top:4px">
                <span style="color:var(--text-secondary)">Charge licences</span>
                <span style="font-weight:600;color:var(--text-muted)"><?= number_format($lic_cost_monthly,0,',',' ') ?> €</span>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ── W: COMPLIANCE ── -->
<div class="widget" data-wid="compliance">
<div class="card">
    <div class="card-body" style="padding:20px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <div style="width:36px;height:36px;border-radius:var(--radius-sm);background:<?= $compliance>100?'var(--danger-dim)':($compliance>85?'var(--warning-dim)':'var(--success-dim)') ?>;display:flex;align-items:center;justify-content:center">
                <svg class="icon-md" style="color:<?= $compliance>100?'var(--danger)':($compliance>85?'var(--warning)':'var(--success)') ?>"><use href="#icon-check"/></svg>
            </div>
            <span style="font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">Conformité licences</span>
        </div>
        <!-- Circular progress -->
        <div style="display:flex;align-items:center;gap:20px">
            <?php
            $c_pct = min($compliance, 100);
            $radius = 36; $circ = 2*M_PI*$radius;
            $dash = $c_pct/100*$circ;
            $c_color = $compliance>100?'#ff4757':($compliance>85?'#f5a623':'#22d3a0');
            ?>
            <svg width="90" height="90" viewBox="0 0 90 90" style="flex-shrink:0">
                <circle cx="45" cy="45" r="<?= $radius ?>" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="8"/>
                <circle cx="45" cy="45" r="<?= $radius ?>" fill="none" stroke="<?= $c_color ?>" stroke-width="8"
                    stroke-dasharray="<?= round($dash,1) ?> <?= round($circ,1) ?>"
                    stroke-dashoffset="<?= round($circ/4,1) ?>" stroke-linecap="round"/>
                <text x="45" y="49" text-anchor="middle" font-size="16" font-weight="800" fill="<?= $c_color ?>" font-family="Plus Jakarta Sans"><?= $compliance ?>%</text>
            </svg>
            <div>
                <div style="font-size:13.5px;font-weight:600;margin-bottom:4px"><?= $total_used ?> / <?= $total_seats ?> sièges</div>
                <div style="font-size:12px;color:var(--text-muted)"><?= count($all_lics) ?> licences gérées</div>
                <?php if ($expiring_90>0): ?>
                <div style="font-size:12px;color:var(--warning);margin-top:6px;font-weight:600">⚠ <?= $expiring_90 ?> expirent bientôt</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ── W: TOP CLIENTS ── -->
<div class="widget" data-wid="top_clients" style="grid-column:span 2">
<div class="card">
    <div class="card-header"><div class="card-title">Top clients — MRR</div><div class="card-subtitle">Revenus mensuels récurrents</div></div>
    <div class="card-body" style="padding:0">
    <?php if (empty($top_clients)): ?>
    <div class="empty-state" style="padding:30px"><svg><use href="#icon-briefcase"/></svg><p>Aucun abonnement actif</p></div>
    <?php else: foreach ($top_clients as $cn=>$rev):
        $pct = $mrr>0 ? ($rev/$mrr*100) : 0; ?>
    <div style="padding:12px 20px;border-bottom:1px solid var(--border-subtle)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <span style="font-weight:600;font-size:13.5px"><?= h($cn) ?></span>
            <div style="display:flex;align-items:baseline;gap:6px">
                <span style="font-weight:800;font-size:15px;color:var(--success);font-variant-numeric:tabular-nums"><?= number_format($rev,0,',',' ') ?> €</span>
                <span style="font-size:11.5px;color:var(--text-muted)">/mois</span>
            </div>
        </div>
        <div class="progress"><div class="progress-bar green" style="width:<?= round($pct) ?>%"></div></div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>
</div>

<!-- ── W: LICENCES EXPIRANTES ── -->
<div class="widget" data-wid="expiring">
<div class="card">
    <div class="card-header"><div class="card-title">Renouvellements</div><div class="card-subtitle">Dans les 90 prochains jours</div></div>
    <div class="card-body" style="padding:0">
    <?php if (empty($expiring_lics)): ?>
    <div class="empty-state" style="padding:30px"><svg><use href="#icon-check"/></svg><p style="color:var(--success)">Tout est à jour !</p></div>
    <?php else: foreach ($expiring_lics as $l):
        $d = (int)$l['days_left'];
        $expired_l = $d < 0;
        $col = $expired_l?'var(--danger)':($d<=30?'var(--warning)':'#f5a623');
        $bg  = $expired_l?'var(--danger-dim)':($d<=30?'var(--warning-dim)':'transparent');
    ?>
    <div style="padding:10px 16px;border-bottom:1px solid var(--border-subtle);background:<?= $bg ?>">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
                <div style="font-weight:600;font-size:13px"><?= h($l['name']) ?></div>
                <div style="font-size:11.5px;color:var(--text-muted)"><?= h($l['vendor']??'') ?> · <?= date('d/m/Y',strtotime($l['renewal_date'])) ?></div>
            </div>
            <div style="font-weight:800;font-size:13px;color:<?= $col ?>;white-space:nowrap"><?= $expired_l?'EXPIRÉ':'J-'.$d ?></div>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
</div>
</div>

<!-- ── W: DEVICES ── -->
<div class="widget" data-wid="devices">
<div class="card">
    <div class="card-header"><div class="card-title">Parc actif</div><div class="card-subtitle"><?= $total_devices ?> postes déployés</div></div>
    <div class="card-body">
        <div id="donut-devices" class="donut-wrap" style="margin:0 auto 16px;display:flex;justify-content:center"></div>
        <div class="legend">
            <?php foreach (['MAC'=>'var(--os-mac)','WIN'=>'var(--os-win)','LIN'=>'var(--os-lin)'] as $os=>$col):
                $n = $device_stats[$os] ?? 0;
                if (!$n) continue;
                $pct = $total_devices>0 ? round($n/$total_devices*100) : 0;
            ?>
            <div class="legend-item">
                <div class="legend-dot" style="background:<?= $col ?>"></div>
                <span class="legend-name"><?= $os ?></span>
                <span class="legend-value"><?= $n ?></span>
                <span class="legend-pct"><?= $pct ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</div>

<!-- ── W: RÉPARTITION SERVICES ── -->
<div class="widget" data-wid="services" style="grid-column:span 2">
<?php
$cat_labels = ['monitoring'=>'Supervision','support'=>'Support','security'=>'Sécurité','backup'=>'Sauvegarde','infrastructure'=>'Infrastructure','other'=>'Autre'];
$cat_colors = ['monitoring'=>'#38d9f5','support'=>'#9d7bff','security'=>'#22d3a0','backup'=>'#f5a623','infrastructure'=>'#4f7ef8','other'=>'#64748b'];
?>
<div class="card">
    <div class="card-header"><div class="card-title">Revenus par catégorie de service</div></div>
    <div class="card-body" style="padding:0 20px 20px">
    <?php foreach ($by_category as $cat=>$rev):
        $pct = $mrr>0?round($rev/$mrr*100):0;
        $col = $cat_colors[$cat]??'#64748b';
    ?>
    <div style="margin-top:12px">
        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:8px;height:8px;border-radius:50%;background:<?= $col ?>"></div>
                <span style="font-size:13px;font-weight:500"><?= h($cat_labels[$cat]??$cat) ?></span>
            </div>
            <div style="display:flex;align-items:baseline;gap:6px">
                <span style="font-weight:700;font-variant-numeric:tabular-nums"><?= number_format($rev,0,',',' ') ?> €</span>
                <span style="font-size:11.5px;color:var(--text-muted)"><?= $pct ?>%</span>
            </div>
        </div>
        <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($by_category)): ?>
    <div class="empty-state" style="padding:20px"><p>Aucun abonnement</p></div>
    <?php endif; ?>
    </div>
</div>
</div>

<!-- ── W: COÛTS LICENCES ── -->
<div class="widget" data-wid="lic_costs">
<div class="card">
    <div class="card-header"><div class="card-title" title="Prix d'achat de vos licences (ce que vous payez au fournisseur) — hors revente client">Coûts logiciels</div><div class="card-subtitle">Coût d'achat — interne</div></div>
    <div class="card-body" style="padding:0">
    <?php
    $lic_by_cat = [];
    foreach ($all_lics as $l) {
        $m = match($l['billing_period']){'monthly'=>(float)$l['cost_per_seat']*(int)$l['total_seats'],'annual'=>(float)$l['cost_per_seat']*(int)$l['total_seats']/12,default=>0};
        $lic_by_cat[$l['category']] = ($lic_by_cat[$l['category']]??0)+$m;
    }
    $lic_cat_labels = ['office'=>'Bureautique','security'=>'Sécurité','os'=>'Système','productivity'=>'Productivité','development'=>'Dev','design'=>'Design','erp'=>'ERP','other'=>'Autre'];
    $lic_cat_colors = ['office'=>'#2563eb','security'=>'#22d3a0','os'=>'#9d7bff','productivity'=>'#f5a623','development'=>'#38d9f5','design'=>'#fb923c','erp'=>'#ff4757','other'=>'#64748b'];
    arsort($lic_by_cat);
    foreach ($lic_by_cat as $cat=>$cost):
        $col = $lic_cat_colors[$cat]??'#64748b';
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid var(--border-subtle)">
        <div style="display:flex;align-items:center;gap:8px">
            <div style="width:8px;height:8px;border-radius:50%;background:<?= $col ?>"></div>
            <span style="font-size:13px"><?= h($lic_cat_labels[$cat]??$cat) ?></span>
        </div>
        <span style="font-weight:700;color:var(--text-primary);font-variant-numeric:tabular-nums"><?= number_format($cost,2,',',' ') ?> €/mois</span>
    </div>
    <?php endforeach; ?>
    <?php if (empty($lic_by_cat)): ?>
    <div class="empty-state" style="padding:20px"><p>Aucune licence</p></div>
    <?php else: ?>
    <div style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:12.5px;font-weight:700;color:var(--text-secondary)">Total</span>
        <span style="font-size:16px;font-weight:800;color:var(--accent)"><?= number_format($lic_cost_monthly,2,',',' ') ?> €/mois</span>
    </div>
    <?php endif; ?>
    </div>
</div>
</div>

<!-- ── W: STATUT DU PARC ── -->
<div class="widget" data-wid="park_status">
<div class="card">
    <div class="card-header"><div class="card-title">Statut du parc</div><div class="card-subtitle"><?= $park_total ?> postes actifs</div></div>
    <div class="card-body" style="padding:18px 20px">
        <?php if ($park_total === 0): ?>
        <div class="empty-state" style="padding:20px"><p>Aucun poste actif</p></div>
        <?php else:
            $po=$park_online; $pr=$park_recent; $pf=$park_offline; $pt=max($park_total,1); ?>
        <div style="display:flex;height:10px;border-radius:6px;overflow:hidden;background:var(--border-subtle);margin-bottom:16px">
            <?php if($po):?><div style="width:<?= $po/$pt*100 ?>%;background:var(--success)"></div><?php endif;?>
            <?php if($pr):?><div style="width:<?= $pr/$pt*100 ?>%;background:#f5a623"></div><?php endif;?>
            <?php if($pf):?><div style="width:<?= $pf/$pt*100 ?>%;background:var(--danger)"></div><?php endif;?>
        </div>
        <?php foreach ([['En ligne','var(--success)',$po,'< 65 min'],['Vu récemment','#f5a623',$pr,'< 24 h'],['Hors ligne','var(--danger)',$pf,'> 24 h ou jamais vu']] as [$lbl,$c,$n,$sub]): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:7px 0">
            <div style="width:9px;height:9px;border-radius:50%;background:<?= $c ?>"></div>
            <span style="font-size:13.5px;font-weight:600"><?= $lbl ?></span>
            <span style="font-size:11.5px;color:var(--text-muted)"><?= $sub ?></span>
            <span style="margin-left:auto;font-size:16px;font-weight:800;color:<?= $c ?>;font-variant-numeric:tabular-nums"><?= $n ?></span>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
</div>

<!-- ── W: ALERTES SÉCURITÉ ── -->
<div class="widget" data-wid="security_alerts">
<div class="card">
    <div class="card-header"><div class="card-title">Alertes de sécurité</div><div class="card-subtitle">7 derniers jours</div></div>
    <div class="card-body" style="padding:0">
        <?php if ($sec_total === 0): ?>
        <div class="empty-state" style="padding:30px"><svg><use href="#icon-check"/></svg><p style="color:var(--success)">Aucun incident</p></div>
        <?php else: ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid var(--border-subtle)">
            <?php foreach (['critical'=>'Critique','high'=>'Élevé','medium'=>'Moyen','low'=>'Faible'] as $sv=>$lbl): if(!$sev_count[$sv]) continue; ?>
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:3px 9px;border-radius:14px;background:var(--bg-surface);border:1px solid var(--border);color:<?= $sev_color[$sv] ?>">
                <span style="width:6px;height:6px;border-radius:50%;background:<?= $sev_color[$sv] ?>"></span><?= $lbl ?> <?= $sev_count[$sv] ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php foreach ($sec_events as $e): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 16px;border-bottom:1px solid var(--border-subtle)">
            <div style="min-width:0">
                <div style="font-size:13px;font-weight:600"><?= h($evt_labels[$e['event_type']]??$e['event_type']) ?> <span style="color:var(--text-muted);font-weight:500">· <?= h($e['hostname']) ?></span></div>
                <div style="font-size:11.5px;color:var(--text-muted)"><?= h($e['source_ip']??'') ?><?= $e['attempt_count']>1?' · '.(int)$e['attempt_count'].' tentatives':'' ?></div>
            </div>
            <div style="text-align:right;white-space:nowrap">
                <div style="font-size:11px;font-weight:700;color:<?= $sev_color[$e['severity']]??'var(--text-muted)' ?>;text-transform:uppercase"><?= h($e['severity']) ?></div>
                <div style="font-size:11px;color:var(--text-muted)"><?= time_ago($e['detected_at']) ?></div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
</div>

<!-- ── W: ALERTES RESSOURCES ── -->
<div class="widget" data-wid="resource_alerts">
<div class="card">
    <div class="card-header"><div class="card-title">Alertes ressources</div><div class="card-subtitle">Disque · thermique · CPU</div></div>
    <div class="card-body" style="padding:0">
        <?php if (empty($resource_alerts)): ?>
        <div class="empty-state" style="padding:30px"><svg><use href="#icon-check"/></svg><p style="color:var(--success)">Aucune alerte ressource</p></div>
        <?php else: foreach ($resource_alerts as $ra): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border-subtle)">
            <div style="display:flex;align-items:center;gap:8px;min-width:0">
                <div style="width:8px;height:8px;border-radius:50%;background:var(--os-<?= strtolower(h($ra['os'])) ?>)"></div>
                <span style="font-size:13px;font-weight:600"><?= h($ra['host']) ?></span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                <?php foreach ($ra['issues'] as $iss): ?>
                <span style="font-size:11.5px;font-weight:700;color:var(--warning);background:var(--warning-dim);padding:2px 8px;border-radius:12px"><?= h($iss) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
</div>

<!-- ── W: RÉPARTITION FACTURATION ── -->
<div class="widget" data-wid="billing_mix">
<div class="card">
    <div class="card-header"><div class="card-title">Répartition de la facturation</div><div class="card-subtitle">Par périodicité</div></div>
    <div class="card-body" style="padding:14px 20px">
        <?php if (empty($sub_rows)): ?>
        <div class="empty-state" style="padding:16px"><p>Aucun abonnement</p></div>
        <?php else: foreach (['monthly'=>['Mensuel','var(--success)'],'annual'=>['Annuel','var(--accent)'],'one_time'=>['Ponctuel','#9d7bff']] as $pk=>[$plbl,$pc]):
            if (!$period_count[$pk]) continue; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-subtle)">
            <div style="display:flex;align-items:center;gap:8px">
                <div style="width:9px;height:9px;border-radius:50%;background:<?= $pc ?>"></div>
                <span style="font-size:13.5px;font-weight:600"><?= $plbl ?></span>
                <span style="font-size:11.5px;color:var(--text-muted)"><?= $period_count[$pk] ?> abo.</span>
            </div>
            <span style="font-weight:700;color:var(--text-primary);font-variant-numeric:tabular-nums"><?= number_format($by_period[$pk],0,',',' ') ?> €<?= $pk==='one_time'?'':'/mois' ?></span>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:12px;margin-top:4px">
            <span style="font-size:12.5px;font-weight:700;color:var(--text-secondary)">ARR (récurrent annualisé)</span>
            <span style="font-size:16px;font-weight:800;color:var(--accent)"><?= number_format($arr,0,',',' ') ?> €</span>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- ── W: ABONNEMENTS ── -->
<div class="widget" data-wid="subscriptions" style="grid-column:span 2">
<div class="card">
    <div class="card-header"><div class="card-title">Abonnements actifs</div><div class="card-subtitle"><?= count($sub_rows) ?> services facturés</div></div>
    <div class="card-body" style="padding:0">
        <?php if (empty($sub_rows)): ?>
        <div class="empty-state" style="padding:30px"><svg><use href="#icon-tag"/></svg><p>Aucun abonnement actif</p></div>
        <?php else: foreach ($sub_rows as $sr):
            $pl = $period_labels[$sr['period']] ?? $sr['period'];
            $catc = $cat_colors[$sr['category']] ?? '#64748b';
            $suffix = $sr['period']==='one_time' ? '' : ('/'.($sr['period']==='annual'?'an':'mois')); ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 20px;border-bottom:1px solid var(--border-subtle)">
            <div style="display:flex;align-items:center;gap:9px;min-width:0">
                <div style="width:8px;height:8px;border-radius:50%;background:<?= $catc ?>"></div>
                <span style="font-size:13.5px;font-weight:600"><?= h($sr['name']) ?></span>
                <span style="font-size:11.5px;color:var(--text-muted)">×<?= (int)$sr['qty'] ?> · <?= $pl ?></span>
            </div>
            <span style="font-weight:700;color:var(--text-primary);font-variant-numeric:tabular-nums"><?= number_format($sr['net'],0,',',' ') ?> €<?= $suffix ?></span>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
</div>

</div><!-- /widgets-grid -->
</main>
</div>

<style>
.widget { transition: opacity 250ms ease, transform 250ms ease; }
.widget.hidden { display: none !important; }
.widget-toggle {
    display: flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 20px; font-size: 12.5px; font-weight: 600;
    cursor: pointer; border: 1px solid var(--border); background: var(--bg-surface);
    color: var(--text-secondary); transition: all var(--transition);
}
.widget-toggle.on { background: var(--accent-dim); border-color: var(--accent); color: var(--accent); }
.widget-toggle .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
</style>

<script>
const APP_URL = '<?= APP_URL ?>';
const WIDGET_DEFS = [
    {id:'mrr',        label:'MRR / ARR'},
    {id:'profit',     label:'Marge'},
    {id:'compliance', label:'Conformité'},
    {id:'top_clients',label:'Top clients'},
    {id:'expiring',   label:'Renouvellements'},
    {id:'devices',    label:'Parc'},
    {id:'services',   label:'Revenus/service'},
    {id:'lic_costs',  label:'Coûts licences'},
    {id:'billing_mix',     label:'Répartition facturation'},
    {id:'subscriptions',   label:'Abonnements'},
    {id:'park_status',     label:'Statut parc'},
    {id:'security_alerts', label:'Alertes sécurité'},
    {id:'resource_alerts', label:'Alertes ressources'},
];
const STORAGE_KEY = 'if_widgets_v2';

function loadPrefs() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; } catch(e) { return {}; }
}
function savePrefs(p) { localStorage.setItem(STORAGE_KEY, JSON.stringify(p)); }

function applyWidgetPrefs() {
    const prefs = loadPrefs();
    WIDGET_DEFS.forEach(w => {
        const el = document.querySelector(`.widget[data-wid="${w.id}"]`);
        const visible = prefs[w.id] !== false;
        if (el) el.classList.toggle('hidden', !visible);
    });
}

function buildToggles() {
    const prefs = loadPrefs();
    const container = document.getElementById('widget-toggles');
    container.textContent = '';
    WIDGET_DEFS.forEach(w => {
        const visible = prefs[w.id] !== false;
        const btn = document.createElement('button');
        btn.className = 'widget-toggle' + (visible ? ' on' : '');
        btn.dataset.wid = w.id;

        const dot = document.createElement('span');
        dot.className = 'dot';
        const label = document.createElement('span');
        label.textContent = w.label;

        btn.appendChild(dot);
        btn.appendChild(label);
        btn.addEventListener('click', () => {
            const p = loadPrefs();
            p[w.id] = p[w.id] === false;
            savePrefs(p);
            btn.classList.toggle('on', p[w.id] !== false);
            applyWidgetPrefs();
        });
        container.appendChild(btn);
    });
}

function openReport(version) {
    const clientId = <?= (int)$client_id ?>;
    window.open(APP_URL + '/pages/report.php?client_id=' + clientId + '&version=' + version, '_blank', 'width=900,height=900,scrollbars=yes');
}

function toggleCustomize() {
    const panel = document.getElementById('customize-panel');
    const open = panel.style.display === 'none' || !panel.style.display;
    panel.style.display = open ? 'block' : 'none';
    if (open) buildToggles();
}

function resetWidgets() {
    localStorage.removeItem(STORAGE_KEY);
    document.querySelectorAll('.widget').forEach(w => w.classList.remove('hidden'));
    buildToggles();
}

document.addEventListener('DOMContentLoaded', () => {
    applyWidgetPrefs();
    renderDonut('donut-devices', [
        {label:'macOS',   value:<?= (int)($device_stats['MAC']??0) ?>, color:'var(--os-mac)'},
        {label:'Windows', value:<?= (int)($device_stats['WIN']??0) ?>, color:'var(--os-win)'},
        {label:'Linux',   value:<?= (int)($device_stats['LIN']??0) ?>, color:'var(--os-lin)'},
    ], {size:100, stroke:12});
});
</script>

<?php render_footer(); ?>

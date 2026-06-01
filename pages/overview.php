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

$sub_stmt = $pdo->prepare('SELECT bs.*, s.unit, s.billing_period, s.price as service_price,
    s.color, s.category, c.name as client_name
    FROM billing_subscriptions bs
    JOIN billing_services s ON bs.service_id=s.id
    JOIN clients c ON bs.client_id=c.id
    WHERE bs.active=1 AND bs.client_id=?');
$sub_stmt->execute([$client_id]);
$all_subs = $sub_stmt->fetchAll();

$mrr = 0; $by_client = []; $by_category = [];
foreach ($all_subs as $sub) {
    $price = (float)($sub['custom_price'] ?: $sub['service_price']);
    $disc  = (float)($sub['discount_pct'] ?? 0);
    if ($sub['qty_override'] !== null) $qty = (int)$sub['qty_override'];
    elseif ($sub['unit']==='per_device') { $s=$pdo->prepare('SELECT COUNT(*) FROM assets WHERE client_id=? AND status="active"'); $s->execute([$sub['client_id']]); $qty=(int)$s->fetchColumn(); }
    elseif ($sub['unit']==='per_user') { $s=$pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id=? AND active=1'); $s->execute([$sub['client_id']]); $qty=(int)$s->fetchColumn(); }
    else $qty = 1;
    $net = $price * $qty * (1-$disc/100);
    $monthly = match($sub['billing_period']){'annual'=>$net/12,'one_time'=>0,default=>$net};
    $mrr += $monthly;
    $by_client[$sub['client_name']] = ($by_client[$sub['client_name']]??0) + $monthly;
    $by_category[$sub['category']] = ($by_category[$sub['category']]??0) + $monthly;
}
arsort($by_client);
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
<div id="widgets-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;align-items:start">

<!-- ── W: MRR ── -->
<div class="widget" data-wid="mrr" style="grid-column:1">
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
<div class="widget" data-wid="profit" style="grid-column:2">
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
<div class="widget" data-wid="compliance" style="grid-column:3">
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
<div class="widget" data-wid="top_clients" style="grid-column:1/3">
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
<div class="widget" data-wid="services" style="grid-column:1/3">
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
    <div class="card-header"><div class="card-title">Coûts logiciels</div><div class="card-subtitle">Licences actives</div></div>
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
        <span style="font-weight:700;color:var(--danger);font-variant-numeric:tabular-nums"><?= number_format($cost,2,',',' ') ?> €/mois</span>
    </div>
    <?php endforeach; ?>
    <?php if (empty($lic_by_cat)): ?>
    <div class="empty-state" style="padding:20px"><p>Aucune licence</p></div>
    <?php else: ?>
    <div style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:12.5px;font-weight:700;color:var(--text-secondary)">Total</span>
        <span style="font-size:16px;font-weight:800;color:var(--danger)"><?= number_format($lic_cost_monthly,2,',',' ') ?> €/mois</span>
    </div>
    <?php endif; ?>
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

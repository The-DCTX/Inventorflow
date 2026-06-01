<?php
/**
 * InventorFlow — Rapport (Suivi de parc + Facturation)
 * version=internal : rapport interne avec marges et coûts
 * version=client   : rapport client sans données financières confidentielles
 */
require_once __DIR__ . '/../config/app.php';
require_auth();

$pdo       = db();
$client_id = (int)($_GET['client_id'] ?? current_client_id());
$version   = $_GET['version'] ?? 'internal'; // 'internal' | 'client'
$is_client = $version === 'client';
if (!$client_id) { header('Location: ' . APP_URL . '/'); exit; }

// Infos client
$client_stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$client_stmt->execute([$client_id]);
$client = $client_stmt->fetch();
if (!$client) { header('Location: ' . APP_URL . '/'); exit; }

$report_date = date('d/m/Y');
$report_month = strftime('%B %Y'); // ex: juin 2026
// strftime non dispo sur toutes les configs — fallback
$months_fr = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
$report_month = $months_fr[(int)date('n')] . ' ' . date('Y');

// ── PARC INFORMATIQUE ─────────────────────────────────
$assets_stmt = $pdo->prepare('SELECT a.*,
    CONCAT(e.first_name," ",e.last_name) as assigned_name,
    d.name as dept_name,
    ms.cpu_pct, ms.ram_used_mb, ms.ram_total_mb, ms.disk_used_gb, ms.disk_total_gb,
    ms.collected_at as last_seen
    FROM assets a
    LEFT JOIN employees e ON a.assigned_to = e.id
    LEFT JOIN departments d ON a.department_id = d.id
    LEFT JOIN (
        SELECT ms2.asset_id, ms2.cpu_pct, ms2.ram_used_mb, ms2.ram_total_mb,
               ms2.disk_used_gb, ms2.disk_total_gb, ms2.collected_at
        FROM monitoring_snapshots ms2
        WHERE ms2.collected_at = (SELECT MAX(collected_at) FROM monitoring_snapshots WHERE asset_id = ms2.asset_id)
    ) ms ON ms.asset_id = a.id
    WHERE a.client_id = ?
    ORDER BY a.status, a.hostname');
$assets_stmt->execute([$client_id]);
$assets = $assets_stmt->fetchAll();
$assets_active  = array_filter($assets, fn($a) => $a['status'] === 'active');
$assets_stock   = array_filter($assets, fn($a) => $a['status'] === 'stock');
$assets_repair  = array_filter($assets, fn($a) => $a['status'] === 'repair');
$assets_retired = array_filter($assets, fn($a) => $a['status'] === 'retired');

// ── UTILISATEURS ──────────────────────────────────────
$emp_stmt = $pdo->prepare('SELECT e.*,
    d.name as dept_name,
    COUNT(a.id) as asset_count,
    (SELECT COUNT(*) FROM license_assignments la WHERE la.employee_id=e.id AND la.active=1) as lic_count
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN assets a ON a.assigned_to = e.id AND a.status="active"
    WHERE e.client_id = ? AND e.active = 1
    GROUP BY e.id
    ORDER BY d.name, e.last_name, e.first_name');
$emp_stmt->execute([$client_id]);
$employees = $emp_stmt->fetchAll();

// Grouper par département
$emps_by_dept = [];
foreach ($employees as $emp) {
    $dept = $emp['dept_name'] ?: 'Sans département';
    $emps_by_dept[$dept][] = $emp;
}
ksort($emps_by_dept);

// ── LICENCES ──────────────────────────────────────────
$lic_stmt = $pdo->prepare('SELECT l.*,
    (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id=l.id AND la.active=1) as used_count
    FROM licenses l WHERE l.client_id = ? AND l.active = 1
    ORDER BY l.category, l.name');
$lic_stmt->execute([$client_id]);
$licenses = $lic_stmt->fetchAll();

$CAT_LIC = [
    'office'=>'Bureautique','security'=>'Sécurité','os'=>'Système',
    'productivity'=>'Productivité','development'=>'Développement',
    'design'=>'Design','erp'=>'ERP / Compta','other'=>'Autre'
];
$lic_cost_monthly    = 0; // Ce que tu paies (coût × sièges achetés)
$lic_revenue_monthly = 0; // Ce que tu factures (revente × sièges utilisés)
$lic_margin_monthly  = 0; // Bénéfice net (revenu - coût sur sièges utilisés)
foreach ($licenses as $l) {
    $f    = $l['billing_period'] === 'annual' ? 1/12 : ($l['billing_period'] === 'monthly' ? 1 : 0);
    $cost = (float)$l['cost_per_seat'];
    $sell = (float)$l['sell_price_per_seat'];
    $used = (int)$l['used_count'];
    $total_s = (int)$l['total_seats'];
    $lic_cost_monthly += $cost * $total_s * $f;
    if ($sell > 0) {
        $lic_revenue_monthly += $sell * $used * $f;
        $lic_margin_monthly  += ($sell - $cost) * $used * $f;
    }
}

// ── FACTURATION ───────────────────────────────────────
$sub_stmt = $pdo->prepare('SELECT bs.*, s.name as svc_name, s.category, s.color,
    s.price as service_price, s.unit, s.billing_period,
    (CASE
        WHEN s.unit="per_device" THEN (SELECT COUNT(*) FROM assets WHERE client_id=bs.client_id AND status="active")
        WHEN s.unit="per_user"   THEN (SELECT COUNT(*) FROM employees WHERE client_id=bs.client_id AND active=1)
        ELSE 1
    END) as auto_qty
    FROM billing_subscriptions bs
    JOIN billing_services s ON bs.service_id = s.id
    WHERE bs.client_id = ? AND bs.active = 1
    ORDER BY s.category, s.name');
$sub_stmt->execute([$client_id]);
$subscriptions = $sub_stmt->fetchAll();

$mrr = 0;
$sub_totals = [];
foreach ($subscriptions as &$sub) {
    $price = (float)($sub['custom_price'] ?: $sub['service_price']);
    $disc  = (float)($sub['discount_pct'] ?? 0);
    $qty   = $sub['qty_override'] !== null ? (int)$sub['qty_override'] : (int)$sub['auto_qty'];
    $net   = $price * $qty * (1 - $disc/100);
    $sub['qty_computed']   = $qty;
    $sub['net_monthly']    = $sub['billing_period'] === 'annual' ? $net/12 : ($sub['billing_period'] === 'one_time' ? 0 : $net);
    $sub['net_display']    = $net;
    $mrr += $sub['net_monthly'];
    $sub_totals[] = $sub;
}
unset($sub);

// ── MONITORING RÉSUMÉ ─────────────────────────────────
$mon_stmt = $pdo->prepare('SELECT a.id, a.hostname, a.os_type,
    ms.cpu_pct, ms.ram_used_mb, ms.ram_total_mb, ms.collected_at,
    se.event_count
    FROM assets a
    LEFT JOIN (
        SELECT ms2.asset_id, ms2.cpu_pct, ms2.ram_used_mb, ms2.ram_total_mb, ms2.collected_at
        FROM monitoring_snapshots ms2
        WHERE ms2.collected_at = (SELECT MAX(collected_at) FROM monitoring_snapshots WHERE asset_id = ms2.asset_id)
    ) ms ON ms.asset_id = a.id
    LEFT JOIN (
        SELECT asset_id, COUNT(*) as event_count FROM security_events
        WHERE detected_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY asset_id
    ) se ON se.asset_id = a.id
    WHERE a.client_id = ? AND a.status = "active"
    ORDER BY a.hostname');
$mon_stmt->execute([$client_id]);
$monitored = $mon_stmt->fetchAll();

$arr = $mrr * 12;
// Revenu total = services facturés (MRR) + revenus licences revendues
// Coût total   = charge licences achetées
// Marge nette  = revenu total - coût licences
$total_revenue = $mrr + $lic_revenue_monthly;
$total_profit  = $mrr + $lic_margin_monthly; // = total_revenue - lic_cost (sur sièges utilisés)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport <?= h($client['name']) ?> — <?= h($report_month) ?></title>
<style>
/* ── Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; font-size: 11pt; color: #1a1f2e; background: #fff; line-height: 1.5; }
a { color: #2563eb; text-decoration: none; }

/* ── Variables couleurs ── */
:root {
    --blue:   #2563eb;
    --green:  #16a34a;
    --red:    #dc2626;
    --orange: #d97706;
    --gray:   #64748b;
    --light:  #f8fafc;
    --border: #e2e8f0;
}

/* ── Layout pages ── */
.page { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 16mm 14mm; position: relative; }
.page + .page { page-break-before: always; border-top: none; }

/* ── Cover ── */
.cover { display: flex; flex-direction: column; justify-content: space-between; background: linear-gradient(160deg, #0f172a 0%, #1e3a5f 100%); color: #fff; border-radius: 8px; }
.cover-logo { font-size: 22pt; font-weight: 800; letter-spacing: -0.03em; color: #60a5fa; margin-bottom: 8px; }
.cover-logo span { color: #fff; }
.cover-title { font-size: 32pt; font-weight: 800; line-height: 1.1; color: #fff; margin-bottom: 8px; }
.cover-subtitle { font-size: 14pt; color: #94a3b8; }
.cover-client { font-size: 20pt; font-weight: 700; color: #60a5fa; margin-top: 32px; }
.cover-date { font-size: 11pt; color: #94a3b8; margin-top: 6px; }
.cover-badges { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
.cover-badge { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; padding: 8px 14px; font-size: 10pt; color: #e2e8f0; }
.cover-badge strong { display: block; font-size: 18pt; color: #60a5fa; }
.cover-footer { border-top: 1px solid rgba(255,255,255,0.15); padding-top: 12px; font-size: 9pt; color: #64748b; }

/* ── Section headers ── */
.section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; margin-top: 4px; }
.section-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14pt; flex-shrink: 0; }
.section-title { font-size: 15pt; font-weight: 700; color: #0f172a; }
.section-subtitle { font-size: 9pt; color: var(--gray); }
.page-header-line { border-bottom: 2.5px solid var(--blue); margin-bottom: 16px; padding-bottom: 8px; display: flex; justify-content: space-between; align-items: flex-end; }
.page-header-line .ph-title { font-size: 13pt; font-weight: 700; color: var(--blue); }
.page-header-line .ph-sub { font-size: 8.5pt; color: var(--gray); }

/* ── KPI Cards ── */
.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
.kpi-card { background: var(--light); border: 1px solid var(--border); border-radius: 8px; padding: 12px; }
.kpi-card.blue  { border-left: 3px solid var(--blue); }
.kpi-card.green { border-left: 3px solid var(--green); }
.kpi-card.orange{ border-left: 3px solid var(--orange); }
.kpi-card.red   { border-left: 3px solid var(--red); }
.kpi-label { font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--gray); margin-bottom: 4px; }
.kpi-value { font-size: 20pt; font-weight: 800; line-height: 1; color: #0f172a; }
.kpi-sub { font-size: 8pt; color: var(--gray); margin-top: 3px; }

/* ── Tables ── */
table { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 16px; }
thead th { background: #0f172a; color: #fff; padding: 7px 9px; text-align: left; font-weight: 600; font-size: 8.5pt; letter-spacing: 0.03em; }
thead th:last-child { text-align: right; }
tbody tr:nth-child(even) { background: var(--light); }
tbody td { padding: 6px 9px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tbody td:last-child { text-align: right; font-weight: 600; }
tbody tr:last-child td { border-bottom: none; }
tfoot td { padding: 7px 9px; font-weight: 700; background: #f1f5f9; border-top: 2px solid #cbd5e1; }
tfoot td:last-child { text-align: right; color: var(--blue); font-size: 11pt; }

/* ── Badges inline ── */
.badge { display: inline-block; padding: 2px 7px; border-radius: 20px; font-size: 8pt; font-weight: 700; }
.badge-active  { background: #dcfce7; color: #15803d; }
.badge-stock   { background: #e0f2fe; color: #0369a1; }
.badge-repair  { background: #fef9c3; color: #854d0e; }
.badge-retired { background: #fee2e2; color: #991b1b; }
.badge-mac { background: #f3e8ff; color: #7e22ce; }
.badge-win { background: #e0f2fe; color: #0369a1; }
.badge-lin { background: #fff7ed; color: #c2410c; }
.badge-ok   { background: #dcfce7; color: #15803d; }
.badge-warn { background: #fef9c3; color: #854d0e; }
.badge-off  { background: #f1f5f9; color: #64748b; }

/* ── Progress bar ── */
.bar-wrap { background: #e2e8f0; border-radius: 99px; height: 6px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 99px; }

/* ── Misc ── */
.dept-header { font-size: 9.5pt; font-weight: 700; color: var(--blue); background: #eff6ff; padding: 5px 9px; border-left: 3px solid var(--blue); margin-top: 10px; margin-bottom: 2px; border-radius: 0 4px 4px 0; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.info-block { background: var(--light); border: 1px solid var(--border); border-radius: 8px; padding: 12px; }
.info-row { display: flex; justify-content: space-between; padding: 3px 0; border-bottom: 1px solid var(--border); font-size: 9.5pt; }
.info-row:last-child { border-bottom: none; }
.info-label { color: var(--gray); }
.info-value { font-weight: 600; color: #0f172a; }
.total-line { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: var(--blue); color: #fff; border-radius: 8px; margin-top: 8px; }
.total-label { font-size: 10pt; font-weight: 600; }
.total-value { font-size: 16pt; font-weight: 800; }
.print-only { display: block; }
.no-print { display: none !important; }
.page-num { position: absolute; bottom: 10mm; right: 14mm; font-size: 8pt; color: var(--gray); }
.watermark { font-size: 7pt; color: #cbd5e1; text-align: center; margin-top: 6px; }

/* ── Print ── */
@media print {
    @page { size: A4; margin: 0; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .page { border-radius: 0; padding: 12mm 12mm; }
    .no-print { display: none !important; }
    thead { display: table-header-group; }
}

/* ── Screen only ── */
@media screen {
    body { background: #94a3b8; padding: 20px; }
    .page { box-shadow: 0 4px 24px rgba(0,0,0,.2); border-radius: 4px; margin-bottom: 20px; background: #fff; }
    .print-btn { position: fixed; top: 16px; right: 16px; z-index: 999; }
}
</style>
</head>
<body>

<!-- ── Bouton impression (écran seulement) ── -->
<div class="print-btn no-print" style="display:flex!important;gap:8px;align-items:center">
    <?php if (!$is_client): ?>
    <span style="font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;background:#fef3c7;color:#92400e;border:1px solid #fcd34d">
        🔒 VERSION INTERNE — Confidentiel
    </span>
    <?php else: ?>
    <span style="font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;background:#dcfce7;color:#166534;border:1px solid #86efac">
        📄 VERSION CLIENT
    </span>
    <?php endif; ?>
    <button onclick="window.print()" style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(37,99,235,.4)">
        ⬇ Télécharger PDF
    </button>
    <button onclick="window.close()" style="background:#fff;color:#64748b;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;font-size:13px;cursor:pointer">
        ✕ Fermer
    </button>
</div>

<!-- ════════════════════════════════════════
     PAGE 1 — COUVERTURE
════════════════════════════════════════ -->
<div class="page">
<div class="cover" style="padding:20mm 16mm;min-height:270mm">
    <div>
        <div class="cover-logo">Inventor<span>Flow</span></div>
        <div style="font-size:9pt;color:#94a3b8;margin-bottom:40px">Gestion de parc informatique</div>

        <div class="cover-title"><?= $is_client ? 'Rapport de suivi<br>de parc informatique' : 'Rapport interne<br>de suivi & facturation' ?></div>
        <div class="cover-subtitle"><?= h($report_month) ?></div>

        <div class="cover-client"><?= h($client['name']) ?></div>
        <div class="cover-date">Généré le <?= h($report_date) ?></div>
        <?php if (!$is_client): ?>
        <div style="margin-top:10px;display:inline-block;background:rgba(250,204,21,.15);border:1px solid rgba(250,204,21,.4);border-radius:6px;padding:4px 10px;font-size:8.5pt;color:#fcd34d;font-weight:700">
            🔒 DOCUMENT CONFIDENTIEL — Usage interne uniquement
        </div>
        <?php endif; ?>

        <div class="cover-badges">
            <div class="cover-badge"><strong><?= count($assets_active) ?></strong>Postes actifs</div>
            <div class="cover-badge"><strong><?= count($employees) ?></strong>Utilisateurs</div>
            <div class="cover-badge"><strong><?= count($licenses) ?></strong>Licences</div>
            <?php if (!$is_client): ?>
            <div class="cover-badge"><strong><?= number_format($mrr, 0, ',', ' ') ?> €</strong>MRR</div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <?php if (!$is_client): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px">
            <div style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:8px;padding:12px">
                <div style="font-size:8pt;color:#94a3b8;margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em">Revenus annuels</div>
                <div style="font-size:18pt;font-weight:800;color:#60a5fa"><?= number_format($arr, 0, ',', ' ') ?> €</div>
            </div>
            <div style="background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:8px;padding:12px">
                <div style="font-size:8pt;color:#94a3b8;margin-bottom:4px;text-transform:uppercase;letter-spacing:.06em">Charge licences/mois</div>
                <div style="font-size:18pt;font-weight:800;color:#f59e0b"><?= number_format($lic_cost_monthly, 0, ',', ' ') ?> €</div>
            </div>
        </div>
        <?php endif; ?>
        <div class="cover-footer">
            <?= $is_client ? h($client['name']).' / '.h(APP_NAME).' — '.h($report_date) : 'CONFIDENTIEL — '.$client['name'].' / '.APP_NAME.' — '.$report_date ?>
        </div>
    </div>
</div>
</div>

<!-- ════════════════════════════════════════
     PAGE 2 — PARC INFORMATIQUE
════════════════════════════════════════ -->
<div class="page">
<div class="page-header-line">
    <div><div class="ph-title">🖥 Parc informatique</div></div>
    <div class="ph-sub"><?= h($client['name']) ?> — <?= h($report_month) ?></div>
</div>

<!-- KPIs parc -->
<div class="kpi-grid">
    <div class="kpi-card blue">
        <div class="kpi-label">Postes actifs</div>
        <div class="kpi-value"><?= count($assets_active) ?></div>
        <div class="kpi-sub">en service</div>
    </div>
    <div class="kpi-card green">
        <div class="kpi-label">En stock</div>
        <div class="kpi-value"><?= count($assets_stock) ?></div>
        <div class="kpi-sub">disponibles</div>
    </div>
    <div class="kpi-card orange">
        <div class="kpi-label">En réparation</div>
        <div class="kpi-value"><?= count($assets_repair) ?></div>
        <div class="kpi-sub">en cours</div>
    </div>
    <div class="kpi-card red">
        <div class="kpi-label">Retirés</div>
        <div class="kpi-value"><?= count($assets_retired) ?></div>
        <div class="kpi-sub">hors service</div>
    </div>
</div>

<!-- Table postes -->
<table>
    <thead>
        <tr>
            <th>Hostname</th>
            <th>OS</th>
            <th>Modèle</th>
            <th>Config</th>
            <th>Assigné à</th>
            <th>Département</th>
            <th>Statut</th>
            <th>Supervision</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($assets as $a):
        $os_badges = ['MAC'=>'badge-mac','WIN'=>'badge-win','LIN'=>'badge-lin'];
        $st_badges = ['active'=>'badge-active','stock'=>'badge-stock','repair'=>'badge-repair','retired'=>'badge-retired'];
        $st_labels = ['active'=>'Actif','stock'=>'Stock','repair'=>'Réparation','retired'=>'Retiré'];
        $config = implode(' · ', array_filter([$a['ram_gb'] ? $a['ram_gb'].'Go' : null, $a['cpu'] ? substr($a['cpu'],0,18) : null]));
        // État supervision
        $last = $a['last_seen'] ? (time()-strtotime($a['last_seen']))/3600 : null;
        if ($last === null) { $mon_badge='badge-off'; $mon_label='Non supervisé'; }
        elseif ($last < 2)  { $mon_badge='badge-ok';   $mon_label='En ligne'; }
        elseif ($last < 48) { $mon_badge='badge-warn';  $mon_label='Récent'; }
        else                { $mon_badge='badge-off';   $mon_label='>48h'; }
    ?>
    <tr>
        <td style="font-family:monospace;font-size:8.5pt;font-weight:700"><?= h($a['hostname']) ?></td>
        <td><span class="badge <?= $os_badges[$a['os_type']] ?? '' ?>"><?= h($a['os_type']) ?></span></td>
        <td style="font-size:8.5pt"><?= h($a['model'] ?: $a['brand'] ?: '—') ?></td>
        <td style="font-size:8pt;color:var(--gray)"><?= h($config ?: '—') ?></td>
        <td style="font-size:8.5pt"><?= h($a['assigned_name'] ?: '—') ?></td>
        <td style="font-size:8.5pt"><?= h($a['dept_name'] ?: '—') ?></td>
        <td><span class="badge <?= $st_badges[$a['status']] ?? '' ?>"><?= $st_labels[$a['status']] ?? $a['status'] ?></span></td>
        <td><span class="badge <?= $mon_badge ?>"><?= $mon_label ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr><td colspan="7" style="text-align:left;font-size:8.5pt;color:var(--gray)">Total : <?= count($assets) ?> poste(s)</td><td></td></tr>
    </tfoot>
</table>
<div class="page-num">Page 2</div>
</div>

<!-- ════════════════════════════════════════
     PAGE 3 — UTILISATEURS
════════════════════════════════════════ -->
<div class="page">
<div class="page-header-line">
    <div><div class="ph-title">👥 Utilisateurs</div></div>
    <div class="ph-sub"><?= h($client['name']) ?> — <?= h($report_month) ?></div>
</div>

<div class="kpi-grid">
    <div class="kpi-card blue">
        <div class="kpi-label">Utilisateurs actifs</div>
        <div class="kpi-value"><?= count($employees) ?></div>
        <div class="kpi-sub">comptes actifs</div>
    </div>
    <div class="kpi-card green">
        <div class="kpi-label">Départements</div>
        <div class="kpi-value"><?= count($emps_by_dept) ?></div>
        <div class="kpi-sub">organisés</div>
    </div>
    <div class="kpi-card orange">
        <div class="kpi-label">Avec poste assigné</div>
        <div class="kpi-value"><?= count(array_filter($employees, fn($e)=>$e['asset_count']>0)) ?></div>
        <div class="kpi-sub">sur <?= count($employees) ?></div>
    </div>
    <div class="kpi-card blue">
        <div class="kpi-label">Avec licences</div>
        <div class="kpi-value"><?= count(array_filter($employees, fn($e)=>$e['lic_count']>0)) ?></div>
        <div class="kpi-sub">utilisateurs</div>
    </div>
</div>

<?php foreach ($emps_by_dept as $dept => $emps): ?>
<div class="dept-header"><?= h($dept) ?> — <?= count($emps) ?> utilisateur(s)</div>
<table>
    <thead>
        <tr><th>Nom</th><th>Prénom</th><th>Poste</th><th>Email</th><th>Machines</th><th>Licences</th></tr>
    </thead>
    <tbody>
    <?php foreach ($emps as $emp): ?>
    <tr>
        <td style="font-weight:700"><?= h(strtoupper($emp['last_name'])) ?></td>
        <td><?= h($emp['first_name']) ?></td>
        <td style="font-size:8.5pt;color:var(--gray)"><?= h($emp['position'] ?: '—') ?></td>
        <td style="font-size:8pt"><?= h($emp['email'] ?: '—') ?></td>
        <td style="text-align:right"><?= $emp['asset_count'] > 0 ? '<span class="badge badge-active">'.$emp['asset_count'].'</span>' : '<span style="color:var(--gray)">0</span>' ?></td>
        <td style="text-align:right"><?= $emp['lic_count'] > 0 ? '<span class="badge badge-ok">'.$emp['lic_count'].'</span>' : '<span style="color:var(--gray)">0</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endforeach; ?>
<div class="page-num">Page 3</div>
</div>

<!-- ════════════════════════════════════════
     PAGE 4 — LICENCES
════════════════════════════════════════ -->
<div class="page">
<div class="page-header-line">
    <div><div class="ph-title">🔑 Licences logicielles</div></div>
    <div class="ph-sub"><?= h($client['name']) ?> — <?= h($report_month) ?></div>
</div>

<?php
$PERIOD_SHORT = ['monthly'=>'/mois','annual'=>'/an','one_time'=>'unique'];
$total_s = array_sum(array_column($licenses,'total_seats'));
$used_s  = array_sum(array_column($licenses,'used_count'));

// Charge mensuelle licences = prix_unitaire × sièges_utilisés
// Prix unitaire = sell_price si renseigné, sinon cost_price
$lic_monthly_billed = 0;
foreach ($licenses as $l) {
    $f = $l['billing_period']==='annual' ? 1/12 : ($l['billing_period']==='monthly' ? 1 : 0);
    $unit_price = (float)$l['sell_price_per_seat'] > 0 ? (float)$l['sell_price_per_seat'] : (float)$l['cost_per_seat'];
    $lic_monthly_billed += $unit_price * (int)$l['used_count'] * $f;
}
$grand_total = $mrr + $lic_monthly_billed;
?>

<!-- Bloc récapitulatif charge mensuelle -->
<div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:18px">
    <div style="font-size:9pt;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#0f172a;margin-bottom:10px">Récapitulatif charge mensuelle</div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #e2e8f0;font-size:10pt">
        <div style="color:#475569">Services &amp; abonnements</div>
        <div style="font-weight:600"><?= number_format($mrr,2,',',' ') ?> €</div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #e2e8f0;font-size:10pt">
        <div style="color:#475569">Licences logicielles <span style="font-size:8.5pt;color:#94a3b8">(<?= $used_s ?> sièges actifs)</span></div>
        <div style="font-weight:600"><?= number_format($lic_monthly_billed,2,',',' ') ?> €</div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;margin-top:2px">
        <div style="font-size:11pt;font-weight:800;color:#0f172a">Total mensuel</div>
        <div style="font-size:14pt;font-weight:800;color:#2563eb"><?= number_format($grand_total,2,',',' ') ?> €</div>
    </div>
</div>

<!-- Tableau licences — identique pour les deux versions, prix unitaire = sell ou cost -->
<table style="font-size:9pt">
    <thead>
        <tr>
            <th style="width:36%">Licence</th>
            <th style="width:12%">Cible</th>
            <th style="width:20%">Consommé / Attribué</th>
            <th style="width:16%">Prix unitaire</th>
            <th style="width:16%">Total / mois</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($licenses as $l):
        $used  = (int)$l['used_count'];
        $total = (int)$l['total_seats'];
        $pct   = $total > 0 ? min(100, round($used/$total*100)) : 0;
        $f     = $l['billing_period']==='annual' ? 1/12 : ($l['billing_period']==='monthly' ? 1 : 0);
        $unit  = (float)$l['sell_price_per_seat'] > 0 ? (float)$l['sell_price_per_seat'] : (float)$l['cost_per_seat'];
        $total_month = $unit * $used * $f;
        $per   = $PERIOD_SHORT[$l['billing_period']] ?? '';
    ?>
    <tr>
        <td>
            <div style="font-weight:700"><?= h($l['name']) ?></div>
            <div style="font-size:7.5pt;color:var(--gray)"><?= h($l['vendor'] ?: '') ?><?= $l['vendor'] && $l['category'] ? ' · ' : '' ?><?= h($CAT_LIC[$l['category']] ?? '') ?></div>
        </td>
        <td>
            <span style="font-size:7.5pt;padding:2px 6px;border-radius:20px;<?= $l['target']==='asset' ? 'background:#e0f2fe;color:#0369a1' : 'background:#eff6ff;color:#2563eb' ?>">
                <?= $l['target']==='asset' ? 'Machine' : 'Utilisateur' ?>
            </span>
        </td>
        <td>
            <div style="font-weight:700;font-size:10pt"><?= $used ?> / <?= $total ?> sièges</div>
            <div style="margin-top:3px">
                <div class="bar-wrap" style="max-width:100px"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $pct>=90?'#dc2626':($pct>=75?'#d97706':'#16a34a') ?>"></div></div>
            </div>
            <div style="font-size:7.5pt;color:var(--gray);margin-top:2px"><?= $pct ?>% utilisés</div>
        </td>
        <td style="font-weight:600"><?= number_format($unit,2,',','') ?> €<?= $per ?></td>
        <td style="font-weight:700"><?= $used > 0 ? number_format($total_month,2,',',' ').' €' : '<span style="color:#94a3b8">—</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" style="text-align:left;font-size:8.5pt;color:var(--gray)">Total licences / mois</td>
            <td style="font-weight:800;color:#2563eb"><?= number_format($lic_monthly_billed,2,',',' ') ?> €</td>
        </tr>
    </tfoot>
</table>

<?php if (!$is_client): ?>
<!-- Encart confidentiel interne : coût réel et marge -->
<div style="margin-top:14px;border:1px dashed #fbbf24;border-radius:6px;padding:10px 14px;background:#fffbeb">
    <div style="font-size:7.5pt;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">🔒 Usage interne — Confidentiel</div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:8.5pt">
        <div><div style="color:#78716c">Coût achat réel/mois</div><div style="font-weight:700;color:#dc2626"><?= number_format($lic_cost_monthly,2,',',' ') ?> €</div></div>
        <div><div style="color:#78716c">Revenu licences/mois</div><div style="font-weight:700;color:#16a34a"><?= number_format($lic_revenue_monthly,2,',',' ') ?> €</div></div>
        <div><div style="color:#78716c">Marge nette/mois</div><div style="font-weight:700;color:#16a34a">+<?= number_format($lic_margin_monthly,2,',',' ') ?> €</div></div>
    </div>
</div>
<?php endif; ?>

<div class="page-num">Page 4</div>
</div>

<!-- ════════════════════════════════════════
     PAGE 5 — FACTURATION (interne uniquement)
════════════════════════════════════════ -->
<?php if ($is_client): ?>
<!-- Version client : pas de page facturation confidentielle -->
<?php else: ?>
<div class="page">
<div class="page-header-line">
    <div><div class="ph-title">💶 Facturation</div></div>
    <div class="ph-sub"><?= h($client['name']) ?> — <?= h($report_month) ?></div>
</div>

<div class="two-col" style="margin-bottom:16px">
    <div class="info-block">
        <div style="font-size:9pt;font-weight:700;color:var(--blue);margin-bottom:8px">Résumé mensuel</div>
        <div class="info-row"><span class="info-label">Services (MRR)</span><span class="info-value"><?= number_format($mrr,2,',',' ') ?> €</span></div>
        <div class="info-row"><span class="info-label">Revenus licences revendues</span><span class="info-value" style="color:#16a34a"><?= number_format($lic_revenue_monthly,2,',',' ') ?> €</span></div>
        <div class="info-row" style="border-top:2px solid #e2e8f0;padding-top:6px;margin-top:4px"><span class="info-label" style="font-weight:700">Revenu total</span><span class="info-value" style="color:var(--blue);font-size:11pt"><?= number_format($total_revenue,2,',',' ') ?> €</span></div>
        <div class="info-row"><span class="info-label">Charge licences achat</span><span class="info-value" style="color:var(--red)">−<?= number_format($lic_cost_monthly,2,',',' ') ?> €</span></div>
        <div class="info-row"><span class="info-label">Marge nette estimée</span><span class="info-value" style="color:#16a34a;font-size:11pt;font-weight:700">+<?= number_format($lic_margin_monthly,2,',',' ') ?> €</span></div>
    </div>
    <div class="info-block">
        <div style="font-size:9pt;font-weight:700;color:var(--blue);margin-bottom:8px">Client</div>
        <div class="info-row"><span class="info-label">Nom</span><span class="info-value"><?= h($client['name']) ?></span></div>
        <div class="info-row"><span class="info-label">Code</span><span class="info-value"><?= h($client['code']) ?></span></div>
        <div class="info-row"><span class="info-label">Postes actifs</span><span class="info-value"><?= count($assets_active) ?></span></div>
        <div class="info-row"><span class="info-label">Utilisateurs</span><span class="info-value"><?= count($employees) ?></span></div>
    </div>
</div>

<!-- Détail abonnements -->
<table>
    <thead>
        <tr><th>Service</th><th>Catégorie</th><th>Unité</th><th>Quantité</th><th>Prix unit.</th><th>Remise</th><th>Période</th><th>Montant/mois</th></tr>
    </thead>
    <tbody>
    <?php foreach ($subscriptions as $sub):
        $PERIOD_LBL = ['monthly'=>'Mensuel','annual'=>'Annuel','one_time'=>'Unique'];
        $UNIT_LBL   = ['per_device'=>'/poste','per_user'=>'/util.','flat'=>'forfait','per_hour'=>'/h'];
        $price = (float)($sub['custom_price'] ?: $sub['service_price']);
    ?>
    <tr>
        <td style="font-weight:600"><?= h($sub['svc_name']) ?></td>
        <td style="font-size:8.5pt;color:var(--gray)"><?= h(ucfirst($sub['category'])) ?></td>
        <td style="font-size:8.5pt"><?= $UNIT_LBL[$sub['unit']] ?? $sub['unit'] ?></td>
        <td><?= $sub['qty_computed'] ?></td>
        <td><?= number_format($price,2,',','') ?> €</td>
        <td><?= $sub['discount_pct']>0 ? h($sub['discount_pct']).'%' : '—' ?></td>
        <td><?= $PERIOD_LBL[$sub['billing_period']] ?? $sub['billing_period'] ?></td>
        <td><?= number_format($sub['net_monthly'],2,',',' ') ?> €</td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($subscriptions)): ?>
    <tr><td colspan="8" style="text-align:center;color:var(--gray);padding:16px">Aucun abonnement actif</td></tr>
    <?php endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="7">MRR Total</td>
            <td><?= number_format($mrr,2,',',' ') ?> €</td>
        </tr>
    </tfoot>
</table>

<div class="total-line">
    <div class="total-label">Revenu total mensuel estimé</div>
    <div class="total-value"><?= number_format($total_revenue,2,',',' ') ?> €</div>
</div>

<div style="margin-top:20px;padding:10px 14px;background:#f8fafc;border:1px solid var(--border);border-radius:6px;font-size:8pt;color:var(--gray);line-height:1.7">
    <strong>Note :</strong> Ce document est un récapitulatif de suivi généré par <?= h(APP_NAME) ?> et ne constitue pas une facture officielle. Les montants sont des estimations basées sur les données enregistrées au <?= h($report_date) ?>. Les renouvellements de licences sont à titre indicatif.
</div>

<div class="watermark" style="margin-top:12px">Généré par <?= h(APP_NAME) ?> — Usage interne confidentiel — <?= h($report_date) ?></div>
<div class="page-num">Page 5</div>
</div>
<?php endif; // fin version interne ?>

<script>
// Auto-print si paramètre ?print=1
if (new URLSearchParams(window.location.search).get('print') === '1') {
    window.onload = () => setTimeout(() => window.print(), 500);
}
</script>
</body>
</html>

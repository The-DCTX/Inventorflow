<?php
// recovery.php — Page de restauration de SECOURS, autonome.
// Indépendante de la base et de l'authentification applicative : protégée par un
// mot de passe de secours (config/recovery.php). Utilisable même si l'app est cassée.
session_start();
require_once __DIR__ . '/includes/restore_lib.php';

function rh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$configured = rl_recovery_is_configured();
$authed     = !empty($_SESSION['recovery_ok']);
$error = ''; $result = ''; $explore = null;

if (isset($_GET['logout'])) { unset($_SESSION['recovery_ok']); header('Location: recovery.php'); exit; }

$do = $_POST['do'] ?? '';

if ($do === 'login') {
    if ($configured && rl_recovery_verify($_POST['password'] ?? '')) {
        $_SESSION['recovery_ok'] = true;
        header('Location: recovery.php'); exit;
    }
    $error = $configured ? 'Mot de passe de secours incorrect.'
                         : 'Aucun mot de passe de secours n\'est configuré. Définissez-le dans Sauvegardes → Restauration de secours.';
}

if ($authed && $do === 'explore') {
    $kind = $_POST['kind'] ?? '';
    $name = $_POST['name'] ?? '';
    $explore = ['kind' => $kind, 'name' => basename($name)];
    $explore['data'] = $kind === 'db' ? rl_explore_db($name) : rl_explore_files($name);
}

if ($authed && $do === 'restore') {
    if (($_POST['confirm'] ?? '') !== 'RESTAURER') {
        $error = 'Tapez RESTAURER pour confirmer.';
    } else {
        $db    = isset($_POST['use_db'])    ? basename($_POST['db'] ?? '')    : '';
        $files = isset($_POST['use_files']) ? basename($_POST['files'] ?? '') : '';
        if (!$db && !$files) {
            $error = 'Cochez la base et/ou les fichiers à restaurer.';
        } else {
            $script = realpath(__DIR__ . '/maintenance/restore.sh');
            $cmd = 'sudo -n ' . escapeshellarg($script);
            if ($db)    $cmd .= ' --db '    . escapeshellarg($db);
            if ($files) $cmd .= ' --files ' . escapeshellarg($files);
            $cmd .= ' -y 2>&1';
            exec($cmd, $o, $code);
            $out = implode("\n", $o);
            if ($code !== 0 && preg_match('/sudo:.*(password|terminal|not allowed|no tty)/i', $out)) {
                $error = "Règle sudo absente : sur le serveur, lancez « sudo bash maintenance/enable-oneclick.sh ».";
            } elseif ($code !== 0) {
                $error = "Échec : " . $out;
            } else {
                $result = $out ?: 'Restauration effectuée. Une sauvegarde de sécurité a été créée avant.';
            }
        }
    }
}

$sets = $authed ? rl_list_backups() : [];
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Restauration de secours — InventorFlow</title>
<link rel="stylesheet" href="assets/css/app.css">
<style>
.rec-wrap{max-width:860px;margin:0 auto;padding:32px 20px}
.rec-head{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.rec-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:rgba(255,71,87,.15);color:#ff4757}
.rec-card{background:var(--bg-card,#121a2e);border:1px solid var(--border,rgba(255,255,255,.09));border-radius:12px;padding:20px;margin-bottom:16px}
.rec-set{display:flex;align-items:center;gap:14px;flex-wrap:wrap;border:1px solid var(--border,rgba(255,255,255,.09));border-radius:10px;padding:12px 14px;margin-bottom:10px}
.rec-set .date{font-weight:700;font-size:15px}
.rec-file{font-size:12.5px;color:var(--text-secondary,#7a8499);font-family:monospace}
.rec-explore{background:#07090f;border:1px solid var(--border,rgba(255,255,255,.09));border-radius:8px;padding:12px;max-height:280px;overflow:auto;font-family:monospace;font-size:12px;white-space:pre-wrap}
.rec-warn{background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.3);color:#f5a623;border-radius:8px;padding:12px 14px;font-size:13.5px;margin-bottom:14px}
.rec-err{background:rgba(255,71,87,.12);border:1px solid rgba(255,71,87,.35);color:#ff6b78;border-radius:8px;padding:10px 14px;font-size:13.5px;margin-bottom:14px}
.rec-ok{background:rgba(34,211,160,.12);border:1px solid rgba(34,211,160,.35);color:#22d3a0;border-radius:8px;padding:12px 14px;font-size:13.5px;margin-bottom:14px;font-family:monospace;white-space:pre-wrap}
</style>
</head>
<body>
<div class="rec-wrap">
  <div class="rec-head">
    <h1 style="font-size:22px;margin:0">Restauration de secours</h1>
    <span class="rec-badge">MODE SECOURS</span>
  </div>
  <p style="color:var(--text-secondary,#7a8499);font-size:14px;margin-bottom:20px">
    Page autonome de restauration des sauvegardes — protégée par le mot de passe de secours, indépendante de la connexion habituelle.
  </p>

<?php if (!$authed): ?>
  <?php if ($error): ?><div class="rec-err"><?= rh($error) ?></div><?php endif; ?>
  <div class="rec-card" style="max-width:420px">
    <form method="POST">
      <input type="hidden" name="do" value="login">
      <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px">Mot de passe de secours</label>
      <input type="password" name="password" class="form-control" placeholder="••••••••" required autofocus
             style="width:100%;margin-bottom:12px" <?= $configured ? '' : 'disabled' ?>>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center" <?= $configured ? '' : 'disabled' ?>>
        Accéder
      </button>
      <?php if (!$configured): ?>
      <p style="font-size:12.5px;color:var(--text-muted,#3d4760);margin-top:12px">
        Non configuré. Connectez-vous à l'application → <b>Sauvegardes</b> → définissez le mot de passe de secours.
      </p>
      <?php endif; ?>
    </form>
  </div>
<?php else: ?>
  <div style="display:flex;justify-content:flex-end;margin-bottom:8px">
    <a href="recovery.php?logout=1" class="btn btn-ghost btn-sm">Quitter le mode secours</a>
  </div>

  <?php if ($error): ?><div class="rec-err"><?= rh($error) ?></div><?php endif; ?>
  <?php if ($result): ?><div class="rec-ok"><?= rh($result) ?></div><?php endif; ?>

  <?php if ($explore): ?>
  <div class="rec-card">
    <div style="font-weight:700;margin-bottom:10px">
      Exploration — <?= rh($explore['name']) ?>
      <?php if (isset($explore['data']['count'])): ?>
      <span style="color:var(--text-secondary,#7a8499);font-weight:400">(<?= (int)$explore['data']['count'] ?> entrées)</span>
      <?php endif; ?>
    </div>
    <?php if (isset($explore['data']['error'])): ?>
      <div class="rec-err"><?= rh($explore['data']['error']) ?></div>
    <?php else: ?>
      <div class="rec-explore"><?= rh(implode("\n", $explore['data'][$explore['kind'] === 'db' ? 'tables' : 'entries'] ?? [])) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="rec-warn">
    ⚠ La restauration <b>écrase</b> les données actuelles. Une sauvegarde de sécurité est créée automatiquement avant toute restauration.
  </div>

  <?php if (!$sets): ?>
    <div class="rec-card">Aucune sauvegarde trouvée dans <code>backups/</code>.</div>
  <?php endif; ?>

  <?php foreach ($sets as $s): ?>
  <div class="rec-card">
    <div class="rec-set" style="border:none;padding:0;margin-bottom:14px">
      <span class="date"><?= rh($s['date']) ?></span>
    </div>
    <!-- Exploration -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <?php if ($s['files']): ?>
      <form method="POST"><input type="hidden" name="do" value="explore"><input type="hidden" name="kind" value="files"><input type="hidden" name="name" value="<?= rh($s['files']['name']) ?>">
        <button class="btn btn-ghost btn-sm">Explorer fichiers (<?= round($s['files']['size']/1048576, 1) ?> Mo)</button>
      </form>
      <?php endif; ?>
      <?php if ($s['db']): ?>
      <form method="POST"><input type="hidden" name="do" value="explore"><input type="hidden" name="kind" value="db"><input type="hidden" name="name" value="<?= rh($s['db']['name']) ?>">
        <button class="btn btn-ghost btn-sm">Explorer base (<?= round($s['db']['size']/1024, 0) ?> Ko)</button>
      </form>
      <?php endif; ?>
    </div>
    <!-- Restauration -->
    <form method="POST" onsubmit="return confirm('Restaurer la sauvegarde du <?= rh($s['date']) ?> ? Les données actuelles seront écrasées.');">
      <input type="hidden" name="do" value="restore">
      <input type="hidden" name="db" value="<?= rh($s['db']['name'] ?? '') ?>">
      <input type="hidden" name="files" value="<?= rh($s['files']['name'] ?? '') ?>">
      <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <?php if ($s['db']): ?><label style="font-size:13.5px"><input type="checkbox" name="use_db" checked> Base de données</label><?php endif; ?>
        <?php if ($s['files']): ?><label style="font-size:13.5px"><input type="checkbox" name="use_files"> Fichiers de l'application</label><?php endif; ?>
        <input type="text" name="confirm" placeholder="Tapez RESTAURER" style="max-width:160px" class="form-control">
        <button type="submit" class="btn btn-primary btn-sm" style="background:var(--danger,#ff4757)">Restaurer</button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
<script>
// Réapplique le thème choisi (comme le reste du site) si présent en localStorage.
(function(){var t=localStorage.getItem("if_theme");if(t==="acid"||t==="dark"||t==="aurora")document.documentElement.setAttribute("data-theme",t);})();
</script>
</body>
</html>

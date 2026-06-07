<?php
require_once __DIR__ . '/config/app.php';

if (is_logged_in()) { header('Location: ' . APP_URL . '/'); exit; }

if (!isset($_SESSION['totp_pending'])) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$pending = $_SESSION['totp_pending'];
if ($pending['expires'] < time()) {
    unset($_SESSION['totp_pending']);
    header('Location: ' . APP_URL . '/login.php?expired=1');
    exit;
}

$error      = '';
$use_backup = isset($_GET['backup']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code  = trim($_POST['code'] ?? '');
    $uid   = (int)$pending['user_id'];
    $stmt  = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$uid]);
    $user  = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['totp_pending']);
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }

    $ok = false;
    if ($use_backup) {
        $ok = totp_verify_backup($uid, $code);
    } else {
        $ok = totp_verify($user['totp_secret'], $code);
    }

    if ($ok) {
        complete_login($user);
        header('Location: ' . APP_URL . '/');
        exit;
    }
    $error = $use_backup ? 'Code de secours invalide ou déjà utilisé.' : 'Code incorrect. Vérifiez votre application d\'authentification.';
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vérification 2FA — InventorFlow</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
<style>
.totp-input{
    font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:500;
    letter-spacing:.18em;text-align:center;padding:14px 12px;
    background:var(--bg-elevated);border:2px solid var(--border);
    border-radius:var(--radius-sm);color:var(--text-primary);width:100%;
    transition:border-color .2s;
}
.totp-input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-dim)}
.shield-icon{width:56px;height:56px;border-radius:16px;background:var(--accent-dim);
    display:grid;place-items:center;margin:0 auto 20px;color:var(--accent)}
</style>
</head>
<body>
<div class="login-page">
    <div class="login-card" style="max-width:380px">
        <div class="shield-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <?php if (!$use_backup): ?>
                <path d="M9 12l2 2 4-4"/>
                <?php endif; ?>
            </svg>
        </div>

        <h1 class="login-title" style="font-size:22px">
            <?= $use_backup ? 'Code de secours' : 'Vérification 2FA' ?>
        </h1>
        <p class="login-subtitle" style="font-size:14px">
            <?= $use_backup
                ? 'Entrez l\'un de vos codes de secours à 8 caractères.'
                : 'Entrez le code à 6 chiffres affiché dans votre application d\'authentification.' ?>
        </p>

        <?php if ($error): ?>
        <div class="login-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="totp-form">
            <div class="form-group">
                <?php if (!$use_backup): ?>
                <input type="text" name="code" id="code" class="totp-input"
                    inputmode="numeric" pattern="\d{6}" maxlength="6"
                    placeholder="000 000" autocomplete="one-time-code" required autofocus>
                <?php else: ?>
                <input type="text" name="code" id="code" class="totp-input"
                    maxlength="9" placeholder="XXXX-XXXX"
                    autocomplete="off" required autofocus
                    style="font-size:22px;letter-spacing:.12em">
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px;margin-top:4px">
                Vérifier
            </button>
        </form>

        <div style="text-align:center;margin-top:18px;display:flex;flex-direction:column;gap:8px">
            <?php if (!$use_backup): ?>
            <a href="?backup=1" style="font-size:13px;color:var(--text-secondary)">Utiliser un code de secours</a>
            <?php else: ?>
            <a href="?" style="font-size:13px;color:var(--text-secondary)">← Utiliser mon application 2FA</a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/login.php" style="font-size:13px;color:var(--text-muted)">Recommencer la connexion</a>
        </div>
    </div>
</div>
<script>
<?php if (!$use_backup): ?>
const input = document.getElementById('code');
input.addEventListener('input', () => {
    const v = input.value.replace(/\D/g,'').slice(0,6);
    input.value = v;
    if (v.length === 6) document.getElementById('totp-form').submit();
});
<?php endif; ?>
</script>
</body>
</html>

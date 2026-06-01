<?php
require_once __DIR__ . '/config/app.php';

if (is_logged_in()) {
    header('Location: ' . APP_URL . '/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password && login($username, $password)) {
        header('Location: ' . APP_URL . '/');
        exit;
    }
    $error = 'Identifiants incorrects. Veuillez réessayer.';
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion — InventorFlow</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <svg width="40" height="40" viewBox="0 0 32 32" fill="none">
                <rect width="32" height="32" rx="8" fill="url(#lg)"/>
                <path d="M8 10h16M8 16h10M8 22h13" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
                <circle cx="24" cy="22" r="3" fill="#fff" opacity=".9"/>
                <defs><linearGradient id="lg" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse"><stop stop-color="#4f7ef8"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs>
            </svg>
            <span class="login-logo-text">InventorFlow</span>
        </div>

        <h1 class="login-title">Connexion</h1>
        <p class="login-subtitle">Accédez à votre espace de gestion du parc IT</p>

        <?php if ($error): ?>
        <div class="login-error">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <div class="form-group">
                <label for="username">Identifiant</label>
                <input type="text" id="username" name="username" class="form-control"
                    placeholder="Votre identifiant" autocomplete="username" required
                    value="<?= h($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" class="form-control"
                    placeholder="••••••••" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="width:100%;justify-content:center;margin-top:8px;padding:11px">
                Se connecter
            </button>
        </form>

        <p style="text-align:center;margin-top:20px;font-size:12px;color:var(--text-muted)">
            InventorFlow v<?= APP_VERSION ?> — Gestion de parc IT
        </p>
    </div>
</div>
</body>
</html>

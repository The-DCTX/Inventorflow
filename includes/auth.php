<?php
require_once __DIR__ . '/totp.php';

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_auth(): void {
    if (is_logged_in()) return;

    if (isset($_SESSION['totp_pending'])) {
        header('Location: ' . APP_URL . '/login-totp.php');
        exit;
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Non authentifie']);
        exit;
    }
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

function current_user(): array {
    return $_SESSION['user'] ?? [];
}

function is_superadmin(): bool {
    return ($_SESSION['user']['role'] ?? '') === 'superadmin';
}

function current_client_id(): ?int {
    if (is_superadmin()) {
        if (isset($_SESSION['selected_client_id'])) {
            return (int)$_SESSION['selected_client_id'];
        }
        $first = db()->query('SELECT id FROM clients WHERE active=1 ORDER BY id LIMIT 1')->fetch();
        if ($first) {
            $_SESSION['selected_client_id'] = (int)$first['id'];
            return (int)$first['id'];
        }
        return null;
    }
    return $_SESSION['user']['client_id'] ?? null;
}

function login(string $username, string $password): bool|string {
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) return false;

    if (($user['auth_source'] ?? 'local') === 'ldap') {
        require_once __DIR__ . '/ldap.php';
        if (!ldap_authenticate($username, $password)) return false;
    } else {
        if (!password_verify($password, $user['password'])) return false;
    }

    $policy   = app_setting('totp_policy', 'disabled');
    $has_totp = !empty($user['totp_secret']) && !empty($user['totp_enabled']);

    $need_totp = match($policy) {
        'required_all' => $has_totp,
        'optional'     => $has_totp,
        default        => false,
    };

    if ($need_totp) {
        $_SESSION['totp_pending'] = [
            'user_id' => (int)$user['id'],
            'expires' => time() + 300,
        ];
        return 'totp_required';
    }

    complete_login($user);
    return true;
}

function complete_login(array $user): void {
    $pdo = db();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user']    = $user;
    unset($_SESSION['totp_pending']);

    if (($user['role'] ?? '') === 'superadmin') {
        $first = $pdo->query('SELECT id FROM clients WHERE active=1 ORDER BY id LIMIT 1')->fetch();
        if ($first) $_SESSION['selected_client_id'] = $first['id'];
    }

    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
}

function logout(): void {
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

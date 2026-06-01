<?php
function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_auth(): void {
    if (!is_logged_in()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'error'=>'Non authentifie']);
            exit;
        }
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
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
        // Auto-select first available client (created after login)
        $first = db()->query('SELECT id FROM clients WHERE active=1 ORDER BY id LIMIT 1')->fetch();
        if ($first) {
            $_SESSION['selected_client_id'] = (int)$first['id'];
            return (int)$first['id'];
        }
        return null;
    }
    return $_SESSION['user']['client_id'] ?? null;
}

function login(string $username, string $password): bool {
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = $user;

    if (is_superadmin()) {
        $first = db()->query('SELECT id FROM clients WHERE active=1 ORDER BY id LIMIT 1')->fetch();
        if ($first) $_SESSION['selected_client_id'] = $first['id'];
    }

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    return true;
}

function logout(): void {
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

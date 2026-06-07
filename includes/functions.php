<?php
function json_response(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $code = 400): void {
    json_response(['success' => false, 'error' => $message], $code);
}

function json_success(mixed $data = [], string $message = 'OK'): void {
    json_response(['success' => true, 'message' => $message, 'data' => $data]);
}

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_clients(): array {
    return db()->query('SELECT * FROM clients WHERE active=1 ORDER BY name')->fetchAll();
}

function get_departments(int $client_id): array {
    $stmt = db()->prepare('SELECT * FROM departments WHERE client_id = ? ORDER BY name');
    $stmt->execute([$client_id]);
    return $stmt->fetchAll();
}

function get_employees(int $client_id): array {
    $stmt = db()->prepare('SELECT e.*, d.name as dept_name FROM employees e LEFT JOIN departments d ON e.department_id = d.id WHERE e.client_id = ? AND e.active = 1 ORDER BY e.last_name, e.first_name');
    $stmt->execute([$client_id]);
    return $stmt->fetchAll();
}

/**
 * Build a hostname from a template + replacements (no DB write, no seq increment).
 */
function build_hostname(string $template, string $client_code, string $os_type, string $dept_code, int $seq): string {
    $name = $template;
    $name = str_replace(
        ['{CLIENT}', '{OS}', '{DEPT}', '{YEAR}', '{MONTH}'],
        [strtoupper($client_code), strtoupper($os_type), strtoupper($dept_code), date('Y'), date('m')],
        $name
    );
    $name = preg_replace_callback('/\{SEQ:(\d+)\}/', fn($m) => str_pad((string)$seq, (int)$m[1], '0', STR_PAD_LEFT), $name);
    $name = preg_replace('/\{SEQ\}/', (string)$seq, $name);
    return strtoupper($name);
}

/**
 * Suggest N available hostnames based on templates.
 * Checks against existing assets so no duplicates are proposed.
 */
function suggest_hostnames(int $client_id, string $os_type, int $dept_id = 0, int $count = 5): array {
    $pdo = db();

    $client_stmt = $pdo->prepare('SELECT code FROM clients WHERE id = ?');
    $client_stmt->execute([$client_id]);
    $client = $client_stmt->fetch();
    $client_code = $client['code'] ?? 'CLI';

    $dept_code = '';
    if ($dept_id > 0) {
        $d = $pdo->prepare('SELECT code FROM departments WHERE id = ?');
        $d->execute([$dept_id]);
        $dept = $d->fetch();
        $dept_code = $dept['code'] ?? '';
    }

    // Get all templates for this OS (specific first, then ALL)
    $tpl_stmt = $pdo->prepare('SELECT template FROM naming_conventions WHERE client_id = ? AND (os_type = ? OR os_type = "ALL") ORDER BY FIELD(os_type, ?, "ALL")');
    $tpl_stmt->execute([$client_id, $os_type, $os_type]);
    $templates = array_column($tpl_stmt->fetchAll(), 'template');
    if (empty($templates)) $templates = ['{CLIENT}-{OS}-{SEQ:3}'];

    // Get existing hostnames for this client (to avoid collisions)
    $existing_stmt = $pdo->prepare('SELECT UPPER(hostname) as h FROM assets WHERE client_id = ?');
    $existing_stmt->execute([$client_id]);
    $existing = array_flip(array_column($existing_stmt->fetchAll(), 'h'));

    $suggestions = [];

    foreach ($templates as $template) {
        // Find starting seq: highest existing seq for this template pattern + 1
        $seq = find_next_seq($template, $client_code, $os_type, $dept_code, $existing);
        $found = 0;

        // Scan up to 200 seq values to find $count available names
        while ($found < $count && $seq < 9999) {
            $name = build_hostname($template, $client_code, $os_type, $dept_code ?: 'GEN', $seq);
            if (!isset($existing[$name]) && !in_array($name, $suggestions)) {
                $suggestions[] = $name;
                $found++;
            }
            $seq++;
        }

        if (count($suggestions) >= $count) break;
    }

    return array_slice($suggestions, 0, $count);
}

/**
 * Find the next sequence number by scanning existing hostnames that match the template pattern.
 */
function find_next_seq(string $template, string $client_code, string $os_type, string $dept_code, array $existing): int {
    // Build a regex from the template to extract sequence numbers from existing names
    $pattern = preg_quote(build_hostname($template, $client_code, $os_type, $dept_code ?: 'GEN', 0), '/');
    $pattern = preg_replace('/0+/', '(\d+)', $pattern, 1); // replace seq placeholder with capture group

    $max_seq = 0;
    foreach (array_keys($existing) as $name) {
        if (preg_match('/^' . $pattern . '$/', $name, $m)) {
            $max_seq = max($max_seq, (int)($m[1] ?? 0));
        }
    }
    return $max_seq + 1;
}

/** Keep for backward compat with agent register (uses machine hostname anyway now) */
function generate_hostname(int $client_id, string $os_type, int $dept_id = 0): string {
    $suggestions = suggest_hostnames($client_id, $os_type, $dept_id, 1);
    return $suggestions[0] ?? 'NONAME-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Create (or return existing) the auto deploy key for a client.
 * The plain key is stored in DB so it can be retrieved for the deploy modal.
 */
function ensure_deploy_key(int $client_id): string {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT plain_key FROM api_keys WHERE client_id = ? AND name = "deploy" AND active = 1 LIMIT 1');
    $stmt->execute([$client_id]);
    $row = $stmt->fetch();

    if ($row && $row['plain_key']) {
        return $row['plain_key'];
    }

    // Generate a new deploy key
    $plain = 'if_' . bin2hex(random_bytes(20));
    $hash  = hash('sha256', $plain);

    // Deactivate any old deploy key
    $pdo->prepare('UPDATE api_keys SET active = 0 WHERE client_id = ? AND name = "deploy"')->execute([$client_id]);

    $pdo->prepare('INSERT INTO api_keys (client_id, name, key_hash, plain_key) VALUES (?, "deploy", ?, ?)')
        ->execute([$client_id, $hash, $plain]);

    return $plain;
}


/**
 * Assigne automatiquement la supervision basique à un client
 * si aucun abonnement monitoring n'existe encore.
 * Crée le service s'il n'existe pas dans le catalogue.
 */
function auto_assign_monitoring(int $client_id): void {
    $pdo = db();

    // Déjà un abonnement monitoring actif pour ce client ?
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM billing_subscriptions bs
         JOIN billing_services s ON bs.service_id = s.id
         WHERE bs.client_id = ? AND s.category = "monitoring" AND bs.active = 1'
    );
    $stmt->execute([$client_id]);
    if ((int)$stmt->fetchColumn() > 0) return;

    // Chercher le service monitoring le moins cher du catalogue
    $svc = $pdo->prepare(
        'SELECT id FROM billing_services
         WHERE category = "monitoring" AND active = 1
         ORDER BY price ASC LIMIT 1'
    );
    $svc->execute();
    $service = $svc->fetch();

    if (!$service) {
        // Créer le service "Supervision basique" si la catégorie n'existe pas
        $pdo->prepare(
            'INSERT INTO billing_services
             (name, description, category, price, unit, billing_period, color)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            'Supervision basique',
            'Monitoring uptime, métriques système, alertes',
            'monitoring',
            3.00,
            'per_device',
            'monthly',
            '#38d9f5',
        ]);
        $service_id = (int)$pdo->lastInsertId();
    } else {
        $service_id = (int)$service['id'];
    }

    // Assigner automatiquement — quantité auto (NULL = compte les postes actifs)
    $pdo->prepare(
        'INSERT IGNORE INTO billing_subscriptions
         (client_id, service_id, qty_override, discount_pct, active, notes)
         VALUES (?, ?, NULL, 0, 1, "Auto-assigné au premier poste")'
    )->execute([$client_id, $service_id]);
}

function log_asset_action(int $asset_id, string $action, ?string $from = null, ?string $to = null, ?string $notes = null): void {
    $user_id = $_SESSION['user_id'] ?? null;
    $stmt = db()->prepare('INSERT INTO asset_history (asset_id, action, from_value, to_value, notes, performed_by) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$asset_id, $action, $from, $to, $notes, $user_id]);
}

function os_badge(string $os): string {
    $classes = ['MAC' => 'badge-mac', 'WIN' => 'badge-win', 'LIN' => 'badge-lin'];
    $icons = ['MAC' => 'apple', 'WIN' => 'windows', 'LIN' => 'linux'];
    $class = $classes[$os] ?? 'badge-default';
    $icon = $icons[$os] ?? 'monitor';
    return "<span class=\"badge {$class}\"><svg class=\"icon-xs\"><use href=\"#icon-{$icon}\"/></svg>{$os}</span>";
}

function status_badge(string $status): string {
    $map = [
        'active' => ['class' => 'badge-active', 'label' => 'Actif'],
        'stock'  => ['class' => 'badge-stock',  'label' => 'En stock'],
        'repair' => ['class' => 'badge-repair', 'label' => 'En réparation'],
        'retired'=> ['class' => 'badge-retired','label' => 'Retraité'],
    ];
    $s = $map[$status] ?? ['class' => 'badge-default', 'label' => $status];
    return "<span class=\"badge {$s['class']}\">{$s['label']}</span>";
}

/**
 * Journalise une action sensible dans audit_logs. Ne bloque jamais l'action
 * métier en cas d'erreur (table absente, etc.).
 */
function audit_log(string $action, ?string $entity = null, ?int $entity_id = null, array $details = []): void {
    try {
        $u = $_SESSION['user'] ?? [];
        db()->prepare('INSERT INTO audit_logs (user_id, username, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?,?)')
           ->execute([
               $u['id'] ?? null, $u['username'] ?? null, $action, $entity, $entity_id,
               $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
               $_SERVER['REMOTE_ADDR'] ?? null,
           ]);
    } catch (Throwable $e) { /* audit best-effort */ }
}

require_once __DIR__ . '/custom_fields.php';
require_once __DIR__ . '/i18n.php';

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'À l\'instant';
    if ($diff < 3600) return floor($diff/60) . ' min';
    if ($diff < 86400) return floor($diff/3600) . 'h';
    if ($diff < 2592000) return floor($diff/86400) . 'j';
    return date('d/m/Y', strtotime($datetime));
}

/**
 * Dernière release GitHub (version + notes + url), en cache 24h dans app_settings.
 * Lecture seule, non bloquant : tout échec réseau renvoie le dernier cache connu.
 * $force = true : ignore le cache (bouton « Rechercher les mises à jour »).
 *
 * @return array{version:?string,notes:string,url:string,published_at:string}
 */
function github_latest_release(bool $force = false): array {
    $out = ['version' => null, 'notes' => '', 'url' => 'https://github.com/The-DCTX/Inventorflow/releases/latest', 'published_at' => ''];
    try {
        $pdo = db();
        $get = fn(string $k) => $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = " . $pdo->quote($k))->fetchColumn();
        $checked = (int)$get('update_checked_at');

        if (!$force && $checked && (time() - $checked) < 86400) {
            $out['version']      = $get('update_latest') ?: null;
            $out['notes']        = (string)($get('update_notes') ?: '');
            $out['url']          = (string)($get('update_url') ?: $out['url']);
            $out['published_at'] = (string)($get('update_published') ?: '');
            return $out;
        }

        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'timeout' => 4,
            'header'  => "User-Agent: InventorFlow-UpdateCheck\r\nAccept: application/vnd.github+json\r\n",
        ]]);
        $json = @file_get_contents('https://api.github.com/repos/The-DCTX/Inventorflow/releases/latest', false, $ctx);
        if ($json) {
            $d = json_decode($json, true);
            if (!empty($d['tag_name'])) {
                $out['version']      = ltrim((string)$d['tag_name'], 'vV');
                $out['notes']        = (string)($d['body'] ?? '');
                $out['url']          = (string)($d['html_url'] ?? $out['url']);
                $out['published_at'] = (string)($d['published_at'] ?? '');
            }
        }

        $set = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $set->execute(['update_checked_at', (string)time()]);   // anti-martèlement même en cas d'échec
        if ($out['version']) {
            $set->execute(['update_latest', $out['version']]);
            $set->execute(['update_notes', $out['notes']]);
            $set->execute(['update_url', $out['url']]);
            $set->execute(['update_published', $out['published_at']]);
        } else {
            // échec réseau : on retombe sur le dernier cache connu
            $out['version']      = $get('update_latest') ?: null;
            $out['notes']        = (string)($get('update_notes') ?: '');
            $out['url']          = (string)($get('update_url') ?: $out['url']);
            $out['published_at'] = (string)($get('update_published') ?: '');
        }
        return $out;
    } catch (\Throwable $e) {
        return $out;
    }
}

/** Dernière version disponible sur GitHub (string) ou null. Voir github_latest_release(). */
function latest_available_version(): ?string {
    return github_latest_release(false)['version'];
}

/**
 * Mini-rendu Markdown → HTML (sous-ensemble suffisant pour des notes de release).
 * Échappe tout le HTML d'abord (sûr), puis applique titres, listes, gras, code, liens.
 */
function md_to_html(string $md): string {
    $esc    = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $inline = function (string $t) use ($esc): string {
        $t = $esc($t);
        $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
        $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t);
        $t = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $t);
        return $t;
    };
    $html = '';
    $in_list = false;
    $in_code = false;
    foreach (preg_split('/\r\n|\r|\n/', $md) as $ln) {
        if (preg_match('/^```/', $ln)) {
            if ($in_code) { $html .= '</code></pre>'; $in_code = false; }
            else { if ($in_list) { $html .= '</ul>'; $in_list = false; } $html .= '<pre><code>'; $in_code = true; }
            continue;
        }
        if ($in_code) { $html .= $esc($ln) . "\n"; continue; }
        if (preg_match('/^(#{1,6})\s+(.*)/', $ln, $m)) {
            if ($in_list) { $html .= '</ul>'; $in_list = false; }
            $lvl = min(strlen($m[1]) + 1, 6);
            $html .= "<h{$lvl}>" . $inline($m[2]) . "</h{$lvl}>";
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.*)/', $ln, $m)) {
            if (!$in_list) { $html .= '<ul>'; $in_list = true; }
            $html .= '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^---+\s*$/', $ln)) {
            if ($in_list) { $html .= '</ul>'; $in_list = false; }
            $html .= '<hr>';
            continue;
        }
        if (trim($ln) === '') { if ($in_list) { $html .= '</ul>'; $in_list = false; } continue; }
        if ($in_list) { $html .= '</ul>'; $in_list = false; }
        $html .= '<p>' . $inline($ln) . '</p>';
    }
    if ($in_list) { $html .= '</ul>'; }
    if ($in_code) { $html .= '</code></pre>'; }
    return $html;
}

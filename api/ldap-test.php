<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if (!is_superadmin()) json_error('Accès réservé au superadmin', 403);

require_once __DIR__ . '/../includes/ldap.php';
$result = ldap_test_connection();

if ($result['ok']) {
    json_success(['message' => $result['message']], $result['message']);
} else {
    json_error($result['error']);
}

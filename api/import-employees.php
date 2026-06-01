<?php
/**
 * InventorFlow — Import CSV utilisateurs
 * POST multipart/form-data : file=csv, source=ad|generic, client_id=N
 */
require_once __DIR__ . '/../config/app.php';
if (!is_logged_in()) json_error('Non authentifié', 401);

$pdo       = db();
$client_id = (int)current_client_id();
if (!$client_id) json_error('Aucun client sélectionné');

$source = $_POST['source'] ?? 'generic'; // 'ad' | 'generic'

if (empty($_FILES['file']['tmp_name'])) json_error('Fichier CSV manquant');

$tmp  = $_FILES['file']['tmp_name'];
$fp   = fopen($tmp, 'r');
if (!$fp) json_error('Impossible de lire le fichier');

// Detect delimiter (comma or semicolon)
$first = fgets($fp);
rewind($fp);
$delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';

// Read header row
$header = fgetcsv($fp, 0, $delim);
if (!$header) json_error('CSV vide ou invalide');
$header = array_map(fn($h) => strtolower(trim(preg_replace('/[\x{FEFF}\x{200B}]/u', '', $h))), $header);

// ── FIELD MAP ────────────────────────────────────────────────
// Maps source column names → InventorFlow fields
$maps = [
    'ad' => [
        'givenname'         => 'first_name',
        'prénom'            => 'first_name',
        'prenom'            => 'first_name',
        'firstname'         => 'first_name',
        'first name'        => 'first_name',
        'surname'           => 'last_name',
        'nom'               => 'last_name',
        'lastname'          => 'last_name',
        'last name'         => 'last_name',
        'sn'                => 'last_name',
        'displayname'       => 'display_name',
        'display name'      => 'display_name',
        'name'              => 'display_name',
        'emailaddress'      => 'email',
        'mail'              => 'email',
        'email'             => 'email',
        'userprincipalname' => 'email',
        'telephonenumber'   => 'phone',
        'officephone'       => 'phone',
        'mobile'            => 'phone',
        'phone'             => 'phone',
        'téléphone'         => 'phone',
        'telephone'         => 'phone',
        'department'        => 'department',
        'département'       => 'department',
        'title'             => 'position',
        'poste'             => 'position',
        'description'       => 'position',
        'enabled'           => 'active',
        'actif'             => 'active',
    ],
    'generic' => [
        'prénom'    => 'first_name',
        'prenom'    => 'first_name',
        'first_name'=> 'first_name',
        'firstname' => 'first_name',
        'nom'       => 'last_name',
        'last_name' => 'last_name',
        'lastname'  => 'last_name',
        'email'     => 'email',
        'mail'      => 'email',
        'téléphone' => 'phone',
        'telephone' => 'phone',
        'phone'     => 'phone',
        'département'  => 'department',
        'departement'  => 'department',
        'department'   => 'department',
        'poste'        => 'position',
        'position'     => 'position',
        'title'        => 'position',
        'actif'        => 'active',
        'active'       => 'active',
    ],
];
$map = $maps[$source] ?? $maps['generic'];

// Build column index → IF field
$col_map = [];
foreach ($header as $i => $col) {
    $clean = strtolower(trim($col));
    if (isset($map[$clean])) $col_map[$i] = $map[$clean];
}

// Preload departments for this client
$dept_rows = $pdo->prepare('SELECT id, name FROM departments WHERE client_id=? OR client_id IS NULL');
$dept_rows->execute([$client_id]);
$depts = [];
foreach ($dept_rows->fetchAll() as $d) $depts[strtolower(trim($d['name']))] = (int)$d['id'];

// Preload existing emails to detect duplicates
$exist_stmt = $pdo->prepare('SELECT LOWER(email) FROM employees WHERE client_id=? AND email IS NOT NULL');
$exist_stmt->execute([$client_id]);
$existing_emails = array_flip($exist_stmt->fetchAll(PDO::FETCH_COLUMN));

$imported = 0; $skipped = 0; $errors = [];
$preview  = ($_POST['preview'] ?? '0') === '1';
$rows_out = [];

$insert = $pdo->prepare(
    'INSERT INTO employees (client_id, first_name, last_name, email, phone, department_id, position, active)
     VALUES (?,?,?,?,?,?,?,?)'
);

while (($row = fgetcsv($fp, 0, $delim)) !== false) {
    if (count(array_filter($row)) === 0) continue; // skip blank rows

    $data = ['first_name'=>'','last_name'=>'','display_name'=>'','email'=>null,'phone'=>null,
             'department'=>null,'position'=>null,'active'=>1];

    foreach ($col_map as $i => $field) {
        $val = trim($row[$i] ?? '');
        if ($val === '') continue;

        if ($field === 'active') {
            $data['active'] = in_array(strtolower($val), ['true','1','yes','oui','enabled','actif']) ? 1 : 0;
        } else {
            $data[$field] = $val;
        }
    }

    // Fallback prénom 1 : extraire depuis DisplayName en soustrayant le nom de famille
    // DisplayName peut être "Marie DUPONT" ou "DUPONT Marie"
    if (empty($data['first_name']) && !empty($data['display_name']) && !empty($data['last_name'])) {
        $dn      = trim($data['display_name']);
        $last_uc = strtoupper(trim($data['last_name']));
        // Supprimer le nom de famille (insensible à la casse) pour isoler le prénom
        $candidate = trim(preg_replace('/\b' . preg_quote($last_uc, '/') . '\b/i', '', $dn));
        if ($candidate !== '') {
            $data['first_name'] = ucwords(strtolower($candidate));
        }
    }

    // Fallback prénom 2 : DisplayName seul sans Surname (ex: "Marie Dupont" sans colonne Surname séparée)
    if (empty($data['first_name']) && empty($data['last_name']) && !empty($data['display_name'])) {
        $parts = explode(' ', trim($data['display_name']), 2);
        if (count($parts) === 2) {
            // Heuristique : si la première partie est en MAJUSCULES c'est le nom, sinon c'est le prénom
            if ($parts[0] === strtoupper($parts[0])) {
                $data['last_name']  = $parts[0];
                $data['first_name'] = ucwords(strtolower($parts[1]));
            } else {
                $data['first_name'] = ucwords(strtolower($parts[0]));
                $data['last_name']  = $parts[1];
            }
        }
    }

    // Require at least a name
    if (empty($data['first_name']) && empty($data['last_name'])) {
        $skipped++;
        if ($preview) $rows_out[] = ['status'=>'skip','reason'=>'Nom manquant','data'=>$data];
        continue;
    }

    // Duplicate check by email
    $email_lc = $data['email'] ? strtolower($data['email']) : null;
    if ($email_lc && isset($existing_emails[$email_lc])) {
        $skipped++;
        if ($preview) $rows_out[] = ['status'=>'duplicate','reason'=>'Email déjà existant','data'=>$data];
        continue;
    }

    // Resolve department
    $dept_id = null;
    if ($data['department']) {
        $dk = strtolower(trim($data['department']));
        if (isset($depts[$dk])) {
            $dept_id = $depts[$dk];
        } elseif (!$preview) {
            // Create department on the fly — génère un code depuis le nom
            $dept_name = ucfirst(trim($data['department']));
            $words = preg_split('/[\s\-_\/]+/', strtoupper($dept_name));
            $code = count($words) >= 2
                ? implode('', array_map(fn($w) => substr($w, 0, 1), $words))
                : substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($dept_name)), 0, 6);
            $code = $code ?: 'DEPT';
            // Unicité du code pour ce client
            $base = $code; $n = 2;
            $ck = $pdo->prepare('SELECT id FROM departments WHERE client_id=? AND code=?');
            while ($ck->execute([$client_id, $code]) && $ck->fetch()) { $code = $base . $n++; }
            try {
                $pdo->prepare('INSERT INTO departments (client_id, name, code) VALUES (?,?,?)')->execute([$client_id, $dept_name, $code]);
                $dept_id = (int)$pdo->lastInsertId();
                $depts[$dk] = $dept_id;
            } catch (PDOException $e) {
                $dept_id = null;
            }
        } else {
            $dept_id = null; // in preview, just note it'll be created
        }
    }

    if ($preview) {
        $rows_out[] = [
            'status'  => 'ok',
            'first_name'  => $data['first_name'],
            'last_name'   => $data['last_name'],
            'email'       => $data['email'],
            'phone'       => $data['phone'],
            'department'  => $data['department'],
            'position'    => $data['position'],
            'active'      => $data['active'],
            'dept_new'    => ($data['department'] && !isset($depts[strtolower(trim($data['department']))])),
        ];
        $imported++;
        continue;
    }

    try {
        $insert->execute([
            $client_id,
            $data['first_name'],
            $data['last_name'],
            $data['email'] ?: null,
            $data['phone'] ?: null,
            $dept_id,
            $data['position'] ?: null,
            $data['active'],
        ]);
        if ($email_lc) $existing_emails[$email_lc] = 1;
        $imported++;
    } catch (PDOException $e) {
        $errors[] = ($data['first_name'].' '.$data['last_name']).': '.$e->getMessage();
        $skipped++;
    }
}
fclose($fp);

json_success([
    'imported' => $imported,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'rows'     => $rows_out,
]);

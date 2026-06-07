<?php
// custom_fields.php — champs personnalisés génériques par entité.
// Entités supportées : asset, employee, client, license.

function cf_entities(): array {
    return [
        'asset'    => 'Postes',
        'employee' => 'Employés',
        'client'   => 'Clients',
        'license'  => 'Licences',
    ];
}

function cf_types(): array {
    return [
        'text'     => 'Texte',
        'number'   => 'Nombre',
        'date'     => 'Date',
        'select'   => 'Liste déroulante',
        'checkbox' => 'Case à cocher',
        'textarea' => 'Zone de texte',
    ];
}

function cf_valid_entity(string $e): bool { return array_key_exists($e, cf_entities()); }

/** Définitions de champs d'une entité. */
function cf_defs(string $entity, bool $only_active = true): array {
    $sql = 'SELECT * FROM custom_field_defs WHERE entity_type = ?'
         . ($only_active ? ' AND active = 1' : '')
         . ' ORDER BY sort_order, id';
    try {
        $st = db()->prepare($sql);
        $st->execute([$entity]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Valeurs {field_key => value} pour une entité donnée. */
function cf_values(string $entity, int $entity_id): array {
    if ($entity_id <= 0) return [];
    try {
        $st = db()->prepare(
            'SELECT d.field_key, v.value
               FROM custom_field_values v
               JOIN custom_field_defs d ON v.field_id = d.id
              WHERE d.entity_type = ? AND v.entity_id = ?');
        $st->execute([$entity, $entity_id]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[$r['field_key']] = $r['value'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Sauvegarde les valeurs depuis un tableau d'entrée (clés `cf_<field_key>`). */
function cf_save(string $entity, int $entity_id, array $input): void {
    if ($entity_id <= 0) return;
    $pdo = db();
    foreach (cf_defs($entity) as $d) {
        $key = 'cf_' . $d['field_key'];
        if (!array_key_exists($key, $input)) {
            if ($d['field_type'] !== 'checkbox') continue;   // case décochée = absente
            $val = '0';
        } else {
            $val = $input[$key];
            $val = is_array($val) ? implode(',', $val) : (string)$val;
            if ($d['field_type'] === 'checkbox') $val = $val !== '' && $val !== '0' ? '1' : '0';
        }
        $pdo->prepare('INSERT INTO custom_field_values (field_id, entity_id, value) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([$d['id'], $entity_id, $val]);
    }
}

/** HTML des inputs pour un formulaire (noms `cf_<field_key>`). */
function cf_render_inputs(string $entity, array $values = []): string {
    $defs = cf_defs($entity);
    if (!$defs) return '';
    $out = '<div class="form-group" style="grid-column:1/-1;border-top:1px solid var(--border-subtle);margin-top:8px;padding-top:14px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">Champs personnalisés</div></div>';
    foreach ($defs as $d) {
        $name = 'cf_' . $d['field_key'];
        $v    = $values[$d['field_key']] ?? '';
        $req  = $d['required'] ? 'required' : '';
        $star = $d['required'] ? ' <span class="required">*</span>' : '';
        $out .= '<div class="form-group"><label class="form-label">' . h($d['label']) . $star . '</label>';
        switch ($d['field_type']) {
            case 'textarea':
                $out .= '<textarea name="' . $name . '" class="form-control" rows="2" ' . $req . '>' . h($v) . '</textarea>';
                break;
            case 'select':
                $opts = json_decode($d['options'] ?: '[]', true) ?: [];
                $out .= '<select name="' . $name . '" class="form-control" ' . $req . '><option value="">—</option>';
                foreach ($opts as $o) $out .= '<option ' . ((string)$v === (string)$o ? 'selected' : '') . '>' . h($o) . '</option>';
                $out .= '</select>';
                break;
            case 'checkbox':
                $out .= '<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="' . $name . '" value="1" ' . ($v ? 'checked' : '') . ' style="accent-color:var(--accent);width:16px;height:16px"> Oui</label>';
                break;
            case 'number':
                $out .= '<input type="number" name="' . $name . '" class="form-control" value="' . h($v) . '" ' . $req . '>';
                break;
            case 'date':
                $out .= '<input type="date" name="' . $name . '" class="form-control" value="' . h($v) . '" ' . $req . '>';
                break;
            default:
                $out .= '<input type="text" name="' . $name . '" class="form-control" value="' . h($v) . '" ' . $req . '>';
        }
        $out .= '</div>';
    }
    return $out;
}

/** Affichage en lecture (fiche). */
function cf_render_display(string $entity, int $entity_id): string {
    $defs = cf_defs($entity);
    if (!$defs) return '';
    $vals = cf_values($entity, $entity_id);
    $rows = '';
    foreach ($defs as $d) {
        $v = $vals[$d['field_key']] ?? '';
        if ($d['field_type'] === 'checkbox') $v = $v ? 'Oui' : 'Non';
        if ($v === '' || $v === null) continue;
        $rows .= '<div style="display:flex;justify-content:space-between;gap:12px;padding:4px 0;font-size:13px"><span style="color:var(--text-muted)">' . h($d['label']) . '</span><span>' . h($v) . '</span></div>';
    }
    return $rows;
}

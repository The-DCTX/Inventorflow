<?php
// i18n.php — internationalisation. Le français est la clé : t('Texte FR')
// renvoie la traduction si elle existe pour la langue active, sinon le FR.

function i18n_langs(): array { return ['fr' => 'Français', 'en' => 'English', 'es' => 'Español']; }

function current_lang(): string {
    $l = $_SESSION['user']['lang'] ?? ($_SESSION['_lang'] ?? 'fr');
    return array_key_exists($l, i18n_langs()) ? $l : 'fr';
}

function t(string $fr, array $args = []): string {
    static $cache = [];
    $lang = current_lang();
    if ($lang !== 'fr') {
        if (!isset($cache[$lang])) {
            $f = __DIR__ . '/../lang/' . $lang . '.php';
            $cache[$lang] = is_file($f) ? (require $f) : [];
        }
        $fr = $cache[$lang][$fr] ?? $fr;
    }
    return $args ? strtr($fr, $args) : $fr;
}

/** Échappe et traduit (raccourci pour le HTML). */
function th(string $fr, array $args = []): string { return h(t($fr, $args)); }

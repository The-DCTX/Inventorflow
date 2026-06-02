<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();
if (!is_superadmin()) { header('Location: ' . APP_URL . '/'); exit; }

render_head('Mises à jour');
render_icons();
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php render_sidebar('updates'); ?>
<div class="main-wrapper">
<?php render_topbar('Mises à jour', 'Vérifier et installer les nouvelles versions'); ?>
<main class="main-content">

<div class="card" style="max-width:760px;padding:24px">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted)">Version installée</div>
            <div style="font-size:26px;font-weight:800;color:var(--text-primary)"><?= h(APP_VERSION) ?></div>
        </div>
        <div style="width:1px;height:42px;background:var(--border)"></div>
        <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted)">Dernière version</div>
            <div id="latest-version" style="font-size:26px;font-weight:800;color:var(--text-muted)">…</div>
        </div>
        <button class="btn btn-ghost btn-sm" id="btn-search" onclick="ifCheckUpdates(true)" style="margin-left:auto">
            Rechercher les mises à jour
        </button>
    </div>

    <div id="update-status" style="margin-top:20px;font-size:14px;color:var(--text-muted)">
        Recherche en cours…
    </div>

    <div id="update-action" style="margin-top:18px;display:none">
        <button class="btn btn-primary" id="btn-open-notes" onclick="ifOpenNotes()">
            Voir les nouveautés et mettre à jour
        </button>
    </div>
</div>

</main>
</div>

<!-- Modale : notes de version + bouton d'installation -->
<div class="modal-backdrop" id="modal-update" style="display:none">
<div class="modal" style="max-width:640px">
    <div class="modal-header">
        <h2 class="modal-title" id="modal-update-title">Mise à jour</h2>
        <button class="btn btn-ghost btn-icon" data-modal-close aria-label="Fermer">&times;</button>
    </div>
    <div class="modal-body">
        <div id="modal-update-notes" class="release-notes" style="max-height:46vh;overflow:auto;padding-right:6px;font-size:14px;line-height:1.6"></div>
        <pre id="update-output" style="display:none;margin-top:14px;max-height:30vh;overflow:auto;background:var(--bg-elev,#161b22);border:1px solid var(--border);border-radius:8px;padding:12px;font-size:12px;white-space:pre-wrap"></pre>
    </div>
    <div class="modal-footer" style="display:flex;gap:10px;justify-content:flex-end;padding:16px 20px;border-top:1px solid var(--border)">
        <a id="modal-update-link" href="#" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Ouvrir sur GitHub</a>
        <button class="btn btn-primary" id="btn-run-update" onclick="ifRunUpdate()">Mettre à jour maintenant</button>
    </div>
</div>
</div>

<style>
.release-notes h2,.release-notes h3{margin:14px 0 6px;color:var(--text-primary)}
.release-notes ul{margin:6px 0 6px 18px}
.release-notes li{margin:3px 0}
.release-notes code{background:var(--bg-elev,#161b22);border:1px solid var(--border);border-radius:5px;padding:1px 5px;font-size:.88em}
.release-notes pre{background:var(--bg-elev,#161b22);border:1px solid var(--border);border-radius:8px;padding:12px;overflow:auto}
.release-notes pre code{border:0;padding:0;background:none}
.release-notes hr{border:0;border-top:1px solid var(--border);margin:14px 0}
.release-notes a{color:var(--accent,#3fb950)}
</style>

<script>
let ifLatest = null;

async function ifCheckUpdates(force) {
    const btn = document.getElementById("btn-search");
    const status = document.getElementById("update-status");
    const action = document.getElementById("update-action");
    btn.disabled = true;
    status.textContent = force ? "Recherche sur GitHub…" : "Recherche en cours…";
    try {
        const url = "<?= APP_URL ?>/api/update-check.php" + (force ? "?refresh=1" : "");
        const res = await api(url);
        const d = res.data;
        ifLatest = d;
        document.getElementById("latest-version").textContent = d.latest || "—";
        if (d.has_update) {
            status.textContent = "Une nouvelle version est disponible : " + d.latest;   // textContent : pas d'injection HTML
            status.style.color = "var(--accent,#3fb950)";
            status.style.fontWeight = "600";
            action.style.display = "block";
        } else {
            status.textContent = "✓ Vous êtes à jour.";
            status.style.color = "var(--success,#3fb950)";
            status.style.fontWeight = "600";
            action.style.display = "none";
        }
    } catch (e) {
        status.textContent = "Impossible de vérifier (réseau / GitHub).";
    } finally {
        btn.disabled = false;
    }
}

function ifOpenNotes() {
    if (!ifLatest) return;
    document.getElementById("modal-update-title").textContent = "InventorFlow " + ifLatest.latest;
    // notes_html est généré par md_to_html() côté serveur (HTML échappé puis sous-ensemble
    // contrôlé de balises ; liens restreints à https?://) → rendu sûr.
    document.getElementById("modal-update-notes").innerHTML = ifLatest.notes_html || "<p>Pas de notes de version.</p>";
    document.getElementById("modal-update-link").href = ifLatest.url;
    document.getElementById("update-output").style.display = "none";
    document.getElementById("btn-run-update").disabled = false;
    Modal.open("modal-update");
}

async function ifRunUpdate() {
    const btn = document.getElementById("btn-run-update");
    const out = document.getElementById("update-output");
    if (!confirm("Lancer la mise à jour maintenant ? Une sauvegarde est faite automatiquement avant.")) return;
    btn.disabled = true;
    btn.textContent = "Mise à jour en cours…";
    out.style.display = "block";
    out.textContent = "Lancement… (sauvegarde + téléchargement + migrations)";
    try {
        const res = await api("<?= APP_URL ?>/api/update-run.php", { method: "POST", body: {} });
        out.textContent = res.data.output || "Terminé.";
        toast("Mise à jour installée (" + res.data.version_after + ")", "success");
        setTimeout(() => location.reload(), 1800);
    } catch (e) {
        out.textContent = e.message || "Échec de la mise à jour.";
        btn.disabled = false;
        btn.textContent = "Réessayer";
    }
}

document.addEventListener("DOMContentLoaded", () => ifCheckUpdates(false));
</script>
<?php render_footer(); ?>

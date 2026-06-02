<div align="center">

# 🗂️ Inventor-Flow

### Gestion de parc informatique multi-client — inventaire, supervision, licences & facturation

*Une seule application, auto-hébergée, pour piloter tout votre parc IT et celui de vos clients.*

<br>

![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10%2B-003545?style=for-the-badge&logo=mariadb&logoColor=white)
![Apache](https://img.shields.io/badge/Apache-2.4-D22128?style=for-the-badge&logo=apache&logoColor=white)
![JavaScript](https://img.shields.io/badge/Vanilla_JS-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)
![License](https://img.shields.io/badge/licence-AGPL--3.0-blue?style=for-the-badge)

<br>

[Fonctionnalités](#-fonctionnalités) •
[Architecture](#%EF%B8%8F-architecture) •
[Installation](#-installation) •
[Agent](#-déploiement-de-lagent) •
[Stack](#-stack-technique)

</div>

---

## 📋 Présentation

**InventorFlow** est une plateforme web auto-hébergée de **gestion de parc informatique** pensée pour les prestataires IT et MSP. Elle centralise l'**inventaire matériel**, la **supervision temps réel**, la **gestion des licences logicielles** et la **facturation récurrente** — le tout en **multi-client**, avec un agent de monitoring léger déployable en une seule commande.

> Dark mode soigné (OLED), interface en français, zéro framework lourd — rapide et auto-hébergeable sur un simple serveur Debian/Ubuntu.

<div align="center">
  <img src="docs/dashboard.png" alt="Tableau de bord InventorFlow" width="92%">
  <br><sub><em>Tableau de bord — vue d'ensemble du parc</em></sub>
</div>

---

## 📸 Captures d'écran

<div align="center">

**Parc informatique** — inventaire multi-OS, sélection multiple & attribution de licences
<img src="docs/parc.png" alt="Parc informatique" width="92%">

<br>

**Supervision temps réel** — CPU, RAM, disque, température, alertes de sécurité
<img src="docs/supervision.png" alt="Supervision" width="92%">

<br>

**Gestion des licences** — conformité, sièges, renouvellements, marge
<img src="docs/licences.png" alt="Gestion des licences" width="92%">

</div>

---

## ✨ Fonctionnalités

### 🖥️ Inventaire du parc
- CRUD complet des postes **macOS / Windows / Linux** (hostname, n° série, CPU, RAM, stockage, IP, MAC)
- Attribution **poste ↔ utilisateur** avec historique des assignations
- Statuts : actif, stock, réparation, retiré
- Export **CSV** et badges OS colorés
- Système de **nommage normalisé** par templates (`{CLIENT}-{OS}-{DEPT}-{SEQ}`) avec suggestions

### 👥 Multi-client
- Sélecteur de client dans la barre latérale
- **Clé de déploiement** auto-générée par client
- Isolation des données par `client_id` sur toutes les vues

### 📡 Supervision temps réel
- Agent **bash universel** (macOS + Linux) qui remonte chaque heure : CPU, RAM, disque, load, uptime, processus
- **Température CPU** multi-plateforme (Apple Silicon, Intel SMC, Linux `sensors`)
- Détection de **brute-force SSH** (auth.log / journalctl) avec whitelist LAN
- Dashboard à barres colorées, statuts *heartbeat* et alertes de sécurité

### 🔑 Licences logicielles
- Catalogue **HT / TTC** (TVA 20 %) avec prix d'achat **et** prix de revente / marge
- Attribution par **poste** ou par **utilisateur**, panneau latéral dédié
- Barre de **conformité** (sièges utilisés / total) et alertes de renouvellement (J-90, J-30, expiré)

### 💶 Facturation
- Catalogue de services (par poste, par utilisateur, forfait, horaire)
- Abonnements client ↔ service avec calcul **automatique** selon les ressources actives
- **MRR / ARR** et marge nette calculés en continu

### 👤 Employés & départements
- Profil employé complet (machines, licences actives, coût mensuel estimé)
- **Assistant d'offboarding** : retour au stock, révocation/transfert des licences en cascade
- Vue **département** consolidée (utilisateurs / postes / licences)

### 📊 Pilotage
- **Tableau de bord unifié** avec widgets personnalisables (ordre + visibilité sauvegardés)
- **Rapports PDF** double version : *interne* (marges visibles) et *client* (marges masquées)
- Gestion des **comptes** : rôles superadmin / admin / viewer

---

## 🏗️ Architecture

```mermaid
flowchart LR
    subgraph Postes["🖥️ Parc supervisé"]
        M["macOS"]
        W["Windows"]
        L["Linux"]
    end

    subgraph Serveur["🌐 Serveur InventorFlow"]
        A["Apache2 / nginx"]
        P["PHP 8.4 — FPM"]
        DB[("MariaDB")]
        A --> P --> DB
    end

    Postes -- "agent bash (HTTPS, horaire)" --> A
    U["👤 Admin / Client"] -- "Dashboard web" --> A
    A -- "deploy.php (clé client)" --> Postes
```

**Principe :** un serveur central (PHP-FPM + MariaDB) expose le dashboard et l'API. Chaque poste reçoit, via une commande unique générée par `deploy.php`, un **agent bash** qui s'auto-installe et envoie ses métriques toutes les heures.

---

## 🚀 Installation

Déploiement automatisé sur **Debian 11+/Ubuntu 22.04+** (Apache2 + PHP-FPM + MariaDB), via le pack de release :

```bash
unzip inventorflow-deploy.zip -d if-install
cd if-install
sudo bash install.sh
```

L'installeur, **interactif et idempotent**, gère :
- la détection de l'OS et la meilleure version de PHP disponible ;
- l'installation des dépendances manquantes uniquement ;
- la configuration **PHP-FPM + proxy_fcgi**, la base de données, le vhost et les permissions ;
- l'import du schéma et la création du compte administrateur (hash bcrypt).

```
➜  App   : http://<IP_DU_SERVEUR>:8080
➜  Login : admin
```

> Port, identifiants DB et mot de passe admin se règlent en tête de `install.sh`.

---

## 📦 Déploiement de l'agent

Depuis la fiche d'un client, le bouton **« Déployer l'agent »** fournit une commande prête à coller sur le poste :

```bash
# macOS
sudo bash -c "$(curl -fsSL 'https://votre-serveur/deploy.php?key=CLE_CLIENT')"

# Linux
curl -fsSL 'http://votre-serveur:8083/deploy.php?key=CLE_CLIENT' | bash
```

L'agent s'enrôle automatiquement, détecte l'OS et commence à remonter ses métriques. Aucune dépendance lourde — du bash portable testé sur macOS 12+, Debian, RHEL et XCP-ng.

---

## 🧰 Stack technique

| Couche | Technologie |
|---|---|
| **Backend** | PHP 8.4 (PDO, sans framework) |
| **Base de données** | MariaDB / MySQL (utf8mb4) |
| **Frontend** | HTML + CSS (dark mode OLED) + Vanilla JS |
| **Serveur web** | Apache2 ou nginx + PHP-FPM |
| **Agent** | Bash universel (macOS / Linux) |
| **Rapports** | Génération PDF interne/client |

---

## 📁 Structure du projet

```
inventor-flow/
├── config/        # Connexion DB + configuration applicative
├── includes/      # Layout, authentification, fonctions partagées
├── api/           # Endpoints REST (assets, licences, monitoring, facturation…)
├── pages/         # Vues (parc, supervision, licences, facturation, rapports…)
├── agent/         # Agent de supervision bash
├── deploy.php     # Générateur d'installeur d'agent (clé par client)
└── assets/        # CSS (dark mode) + JS (toast, modales, helpers API)
```

---

## 🔒 Sécurité

- Authentification par session, mots de passe en **bcrypt**
- En-têtes `X-Content-Type-Options`, `X-Frame-Options`
- API d'enrôlement protégée par **clé d'API** par client
- Détection et journal des tentatives de **brute-force SSH**

---

## ⚠️ Avertissement

L'installeur (`install.sh`) **s'exécute en `root`** et effectue des opérations système : installation de paquets, configuration d'Apache/PHP/MariaDB, écriture de fichiers de configuration. Une option permet, sur confirmation explicite, de **réinitialiser une base de données existante**.

- **Sauvegardez vos données avant toute installation** sur un serveur déjà en service.
- Privilégiez un **serveur dédié ou une VM** pour le premier déploiement.
- L'option de réinitialisation de base est **désactivée par défaut** et exige de taper un mot de confirmation ; une sauvegarde `mysqldump` est tentée automatiquement avant toute purge — mais **vérifiez-la**.

Ce logiciel est fourni **« tel quel », sans aucune garantie** (voir la licence). L'auteur **ne saurait être tenu responsable** d'une perte de données ou de tout dommage résultant de son utilisation. **Vous l'utilisez à vos propres risques.**

---

## 📄 Licence

Distribué sous licence **GNU AGPL-3.0**. Voir le fichier [`LICENSE`](LICENSE) pour le texte complet.

En résumé : vous êtes libre d'utiliser, modifier et redistribuer ce projet, **à condition de publier vos modifications sous la même licence** — y compris si vous le proposez comme service en réseau (SaaS). La mention de crédit affichée dans l'application doit être conservée.

> 💼 **Usage commercial fermé / sans les obligations de l'AGPL ?** Contactez l'auteur (**DCTX**) pour une licence dédiée.

© 2026 **DCTX** — InventorFlow

---

<div align="center">

**InventorFlow** — conçu pour les prestataires IT qui veulent tout voir, d'un seul écran.

</div>

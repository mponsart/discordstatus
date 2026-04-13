# Discord Guard

Surveillance automatique d'un compte Discord via Bot API — page de statut publique, dashboard admin sécurisé par Passkey/WebAuthn, alertes webhook, détection d'anomalies, tâche cron cPanel.

---

## Ce que fait Discord Guard

| Fonctionnalité | Description |
|---|---|
| **Surveillance automatique** | Un bot Discord interroge l'API toutes les 5 min (cron) et détecte les changements de profil |
| **Détection d'anomalies** | Changement de nom d'utilisateur, d'avatar, de nom d'affichage, départ du serveur |
| **Escalade automatique** | Le statut passe de `secure → warning → compromised` sans intervention manuelle |
| **Alertes webhook** | Chaque anomalie déclenche un embed Discord dans le salon de votre choix |
| **Page publique** | Affiche en temps réel le statut, le profil Discord, les métriques et la timeline des événements |
| **Dashboard admin** | Protégé par 3 couches d'auth : token secret → Passkey WebAuthn → session PHP |

---

## Prérequis

| Élément | Version / Détail |
|---|---|
| PHP | ≥ 8.1 |
| Extension `pdo_sqlite` | Obligatoire |
| Extension `openssl` | Obligatoire |
| Extension `curl` | Obligatoire pour le bot Discord |
| Apache + `mod_rewrite` + `AllowOverride All` | Obligatoire |
| **HTTPS (SSL)** | **Obligatoire** — WebAuthn refuse de fonctionner en HTTP |
| Tâche cron cPanel | Pour la surveillance automatique |
| Bot Discord | Compte développeur + bot avec accès serveur |

> **cPanel** : *PHP Selector → Extensions* → vérifiez `pdo_sqlite`, `openssl`, `curl`.

---

## Installation

### 1. Déposer les fichiers

```
public_html/
├── .htaccess
├── config.php
├── functions.php
├── index.php
├── cron/
│   ├── .htaccess
│   └── monitor.php
├── admin/
│   ├── .htaccess
│   ├── gate.php
│   ├── install.php
│   ├── login.php
│   ├── verify.php
│   ├── dashboard.php
│   ├── actions.php
│   └── logout.php
└── data/
    └── .htaccess
```

### 2. Configurer config.php

#### Domaine WebAuthn

```php
define('WEBAUTHN_RP_ID',  'votredomaine.com');         // Domaine nu, sans https://
define('WEBAUTHN_ORIGIN', 'https://votredomaine.com'); // URL complète
```

#### Token d'accès admin

```bash
php -r "echo bin2hex(random_bytes(32));"
```

```php
define('ADMIN_SECRET_TOKEN', 'le_token_64_chars_généré');
```

#### Bot Discord (surveillance automatique)

Trois étapes sur [discord.com/developers/applications](https://discord.com/developers/applications) :

1. **Créer une application** → onglet **Bot** → `Reset Token` → copiez le token
2. Dans **Privileged Gateway Intents**, activez **Server Members Intent**
3. **OAuth2 → URL Generator** : cochez `bot`, aucune permission requise → invitez le bot sur un serveur dont vous êtes membre

Activez le **Mode développeur** dans Discord (Paramètres → Avancé), puis :
- Clic droit sur **votre profil** → Copier l'identifiant → `DISCORD_TARGET_USER_ID`
- Clic droit sur **le serveur commun** → Copier l'identifiant → `DISCORD_GUILD_ID`

```php
define('DISCORD_BOT_TOKEN',      'votre_token_bot');
define('DISCORD_TARGET_USER_ID', 'votre_id_discord');
define('DISCORD_GUILD_ID',       'id_du_serveur_commun');
```

> Le bot lit uniquement les informations de membre via l'API REST. Il ne se connecte pas à la gateway temps réel et n'a besoin d'aucune permission particulière.

#### (Recommandé) Base SQLite hors web

```php
// Remplacez DB_FILE dans config.php :
define('DB_FILE', dirname(__DIR__, 2) . '/discordguard.sqlite');
// → Place le fichier dans ~/ (hors public_html)
```

### 3. Tâche cron cPanel

Dans **cPanel → Cron Jobs**, ajoutez une tâche toutes les 5 minutes :

```
*/5 * * * * /usr/bin/php /home/VOTRE_USER/public_html/cron/monitor.php >> /dev/null 2>&1
```

Pour journaliser les résultats (recommandé au début) :

```
*/5 * * * * /usr/bin/php /home/VOTRE_USER/public_html/cron/monitor.php >> /home/VOTRE_USER/logs/discord_guard.log 2>&1
```

### 4. Permissions

```bash
chmod 750 data/
chmod 640 config.php
chmod 750 cron/
```

### 5. Premier accès — enregistrer la Passkey

1. `https://votredomaine.com/admin/gate.php` → saisir le token secret
2. Vous êtes redirigé vers `/admin/install.php`
3. Cliquez **Enregistrer ma Passkey** → suivez les instructions du navigateur
4. `install.php` est automatiquement désactivé après le premier enregistrement

### 6. Se connecter

1. `https://votredomaine.com/admin/gate.php` → saisir le token
2. `/admin/login.php` → **Se connecter avec une Passkey**
3. Authentifiez-vous via empreinte, Face ID ou clé de sécurité

---

## Surveillance automatique — Comment ça marche

```
Toutes les 5 min (cron)
        │
        ▼
cron/monitor.php
        │
        ▼
GET /guilds/{guild_id}/members/{user_id}
  ← API Discord (Bot Token)
        │
        ├─ 404 → L'utilisateur a quitté le serveur
        │         → Alerte critique + statut compromised
        │
        └─ 200 → Comparer avec le snapshot SQLite
                  │
                  ├─ username changé  (+3)  → warning
                  ├─ global_name changé (+2) → warning
                  ├─ avatar changé    (+1)  → warning
                  └─ discriminant changé (+2) → warning
                          │
                          └─ score ≥ 3 → compromised
                             score ≥ 1 → warning
                          (jamais de descente automatique)
```

### Ce qui est détecté automatiquement

| Signal | Score | Escalade |
|---|---|---|
| Départ du serveur de surveillance | — | `compromised` immédiat |
| Changement de nom d'utilisateur | +3 | `warning` (ou `compromised` si score ≥ 3) |
| Changement de nom d'affichage | +2 | `warning` |
| Changement d'avatar | +1 | `warning` |
| Changement de discriminant | +2 | `warning` |

> Le statut ne descend **jamais** automatiquement. Seul l'administrateur peut réinitialiser à `secure` depuis l'onglet **Profil** du dashboard.

---

## Architecture de sécurité

### 3 couches d'authentification admin

```
Internet
    │
    ▼
┌────────────────────────────────────────┐
│  1. Token secret (ADMIN_SECRET_TOKEN)  │  256 bits, cookie httponly/secure
│     Vérifié PHP à chaque page admin    │  hash_equals() — résistant au timing
│     Sans lui : impossible d'atteindre  │
│     login.php, install.php, etc.       │
└─────────────────┬──────────────────────┘
                  │ ✓ Cookie DGRD_ACCESS valide
                  ▼
┌────────────────────────────────────────┐
│  2. Passkey WebAuthn (ES256 / RS256)   │  Cryptographie asymétrique
│     CBOR decoder maison (sans lib)     │  COSE → PEM + openssl_verify
│     Challenge unique (anti-rejeu)      │  signCount anti-clonage de clé
└─────────────────┬──────────────────────┘
                  │ ✓ Signature valide
                  ▼
┌────────────────────────────────────────┐
│  3. Session PHP sécurisée              │  httponly, secure, SameSite=Strict
│     Rotation ID toutes les 5 min       │  Timeout 1h d'inactivité
└─────────────────┬──────────────────────┘
                  │ ✓ Session active
                  ▼
             Dashboard admin
```

### Tableau des protections

| Mécanisme | Détail |
|---|---|
| **Token secret admin** | 256 bits, cookie httponly/secure/SameSite=Strict, `hash_equals` |
| **WebAuthn sans bibliothèque** | CBOR decoder, COSE→PEM, `openssl_verify` SHA-256 |
| **Anti-brute force SQLite** | 5 tentatives / 15 min par IP (gate + login) |
| **CSRF** | Token session sur tous les formulaires, `hash_equals` |
| **Restriction IP admin** | `ALLOWED_ADMIN_IPS` dans config.php (optionnel) |
| **En-têtes HTTP** | CSP, X-Frame-Options DENY, X-Content-Type-Options, COOP, Referrer-Policy |
| **signCount WebAuthn** | Détection de clonage de Passkey |
| **SQLite WAL** | Lectures/écritures concurrentes sans corruption |
| **.htaccess multi-couche** | Blocage `.sqlite`, `.db`, `.json`, `.log`, `.env`, `.pem`... |
| **cron CLI uniquement** | `monitor.php` bloqué en accès web (.htaccess + vérification `PHP_SAPI`) |
| **Bot en lecture seule** | Aucune permission bot requise — GET uniquement sur l'API Discord |

---

## Schéma de la base SQLite

```sql
credentials     -- Passkeys enregistrées (id, public_key, sign_count, name, created_at)
logs            -- Journal de tous les accès et événements admin
alerts          -- Anomalies : IP inconnue, changement profil Discord, départ serveur
settings        -- Tous les paramètres : statut, profil Discord, métriques monitoring…
brute_force     -- Compteur de tentatives par IP (nettoyage automatique)
status_history  -- Timeline des changements de statut (manuel + auto 🤖)
```

Clés `settings` importantes :

| Clé | Source | Description |
|---|---|---|
| `status` | admin / auto | `secure`, `warning`, `compromised` |
| `discord_username` | **auto (bot)** | Nom Discord actuel |
| `discord_global_name` | **auto (bot)** | Nom d'affichage Discord |
| `discord_avatar_url` | **auto (bot)** | URL CDN de l'avatar |
| `discord_guild_joined_at` | **auto (bot)** | Date de rejoint-serveur |
| `discord_bio` | manuel | Bio (non accessible via l'API bot) |
| `discord_joined` | manuel | Date de création du compte |
| `discord_last_check` | **auto (bot)** | Horodatage de la dernière vérification |
| `discord_check_count` | **auto (bot)** | Nombre total de vérifications |
| `discord_anomaly_count` | **auto (bot)** | Score cumulé d'anomalies |
| `discord_monitor_status` | **auto (bot)** | `ok`, `error_404`, `error_401`… |

---

## Utilisation quotidienne

### Accéder au dashboard

1. `https://votredomaine.com/admin/gate.php` → token secret
2. `/admin/login.php` → Passkey
3. `/admin/dashboard.php` → tableau de bord (4 onglets)

### Onglets du dashboard

| Onglet | Contenu |
|---|---|
| **Logs** | Journal de tous les accès admin, changements de statut, résultats du monitoring |
| **Alertes** | Anomalies détectées (changements Discord, IP inconnues…) |
| **Paramètres** | Webhook Discord, message public d'alerte |
| **Profil Discord** | Statut monitoring, snapshot bot, boutons d'action, champs manuels |

### Boutons d'action (onglet Profil)

| Bouton | Effet |
|---|---|
| **Vérifier maintenant** | Déclenche immédiatement un cycle de monitoring sans attendre le cron |
| **Marquer comme sécurisé** | Réinitialise manuellement le statut à `secure` après résolution d'une alerte |

### Page publique

`https://votredomaine.com/` affiche :
- Le profil Discord (avatar, nom, bio) mis à jour automatiquement par le bot
- Le statut actuel avec indicateur visuel coloré
- Les métriques de surveillance (nb vérifications, anomalies, dernière MAJ)
- L'état du bot et la date de la dernière/prochaine vérification
- La timeline des événements (activable/désactivable dans le dashboard)

---

## Résolution de problèmes

### Le bot ne détecte rien / erreur API

| Erreur | Cause probable |
|---|---|
| `error_401` | `DISCORD_BOT_TOKEN` invalide ou révoqué → regénérez-le |
| `error_403` | **Server Members Intent** non activé dans les paramètres du bot |
| `error_404` | L'utilisateur cible n'est pas membre du serveur `DISCORD_GUILD_ID` |
| `error_429` | Rate limit Discord — réduisez la fréquence du cron (toutes les 10 min) |
| `curl_unavailable` | Extension `curl` non activée — activez-la dans PHP Selector cPanel |

### La Passkey ne fonctionne pas

- Vérifiez que vous êtes en **HTTPS** avec un certificat SSL valide
- `WEBAUTHN_RP_ID` doit être le domaine nu (ex: `mondomaine.com`), sans `https://`
- `WEBAUTHN_ORIGIN` doit inclure `https://` (ex: `https://mondomaine.com`)
- Vérifiez `pdo_sqlite` dans PHP Selector cPanel

### Bloqué par le brute-force (erreur 429)

Attendez 15 minutes ou déverrouillez en SSH :

```bash
php -r "require 'config.php'; require 'functions.php'; get_db()->exec('DELETE FROM brute_force'); echo 'OK';"
```

### Réinitialiser complètement

1. Supprimez `data/discordguard.sqlite` (ou le chemin `DB_FILE` configuré)
2. La base est recréée automatiquement au 1er accès
3. Retournez sur `/admin/install.php` pour enregistrer une nouvelle Passkey

### Tester le cron manuellement

```bash
php /home/VOTRE_USER/public_html/cron/monitor.php
```

La sortie indique le résultat de la vérification (changements détectés, erreurs API…).

---

## Rotation du token secret admin

```bash
php -r "echo bin2hex(random_bytes(32));"
```

1. Remplacez `ADMIN_SECRET_TOKEN` dans `config.php`
2. Tout cookie `DGRD_ACCESS` existant devient immédiatement invalide
3. Reconnectez-vous via `gate.php`

---

## Restriction IP admin (optionnel)

```php
// config.php
define('ALLOWED_ADMIN_IPS', ['1.2.3.4', '5.6.7.8']);
// Tableau vide [] = restriction désactivée
```

---

## Licence

Usage personnel. Pas de redistribution sans autorisation.


## Prérequis

| Élément | Requis |
|---|---|
| PHP | ≥ 8.1 |
| Extension PDO SQLite (`pdo_sqlite`) | Obligatoire |
| Extension OpenSSL | Obligatoire |
| Apache + mod_rewrite + AllowOverride All | Obligatoire |
| **HTTPS (SSL)** | **Obligatoire** — WebAuthn refuse de fonctionner en HTTP |
| Navigateur moderne | Chrome 67+, Firefox 60+, Safari 14+, Edge 18+ |

> **cPanel** : vérifiez que `pdo_sqlite` est activé dans *PHP Selector → Extensions*.

---

## Installation en 6 étapes

### Étape 1 — Déposer les fichiers

Uploadez **tous les fichiers** dans votre répertoire public (`public_html/` ou sous-dossier).

```
public_html/
├── .htaccess
├── config.php
├── functions.php
├── index.php
├── admin/
│   ├── .htaccess
│   ├── gate.php
│   ├── install.php
│   ├── login.php
│   ├── verify.php
│   ├── dashboard.php
│   ├── actions.php
│   └── logout.php
└── data/
    └── .htaccess
```

### Étape 2 — Configurer config.php

Ouvrez `config.php` et renseignez les 3 valeurs obligatoires :

#### 1. Domaine WebAuthn

```php
define('WEBAUTHN_RP_ID',  'votredomaine.com');         // Domaine nu, sans https://
define('WEBAUTHN_ORIGIN', 'https://votredomaine.com'); // URL complète avec https://
```

#### 2. Token d'accès admin (clé secrète)

Générez un token de 64 caractères hexadécimaux (256 bits d'entropie) :

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Copiez le résultat dans `config.php` :

```php
define('ADMIN_SECRET_TOKEN', 'votre_token_64_chars_ici');
```

> ⚠️ **Ne partagez jamais ce token.** Il est l'équivalent d'un mot de passe maître.
> Sans lui, aucune page `/admin/` n'est accessible, même en connaissant les URLs.

#### 3. (Recommandé) Déplacer la base SQLite hors du web

Sur cPanel, le dossier `public_html/` est exposé. Pour une sécurité maximale, mettez la base en dehors :

```php
// Remplacer la ligne DB_FILE dans config.php :
define('DB_FILE', dirname(__DIR__, 2) . '/discordguard.sqlite');
// → Crée le fichier dans ~/discordguard.sqlite (hors public_html)
```

### Étape 3 — Permissions

```bash
chmod 750 data/
chmod 640 config.php
```

Le serveur web doit avoir les droits d'écriture sur `data/` pour créer la base SQLite.
Sur cPanel, les fichiers appartiennent déjà à votre utilisateur, les permissions sont correctes par défaut.

### Étape 4 — Accéder à la Gate et s'installer

1. Visitez `https://votredomaine.com/admin/gate.php`
2. Saisissez votre **ADMIN_SECRET_TOKEN** → un cookie sécurisé est posé
3. Vous êtes redirigé vers `/admin/install.php`
4. Cliquez sur **Enregistrer ma Passkey** et suivez les instructions du navigateur
5. Une fois la Passkey enregistrée, `install.php` est **automatiquement désactivé**

### Étape 5 — Se connecter

1. Visitez `https://votredomaine.com/admin/gate.php`
2. Saisissez votre token → vous accédez à `/admin/login.php`
3. Cliquez sur **Se connecter avec une Passkey**
4. Authentifiez-vous via votre empreinte, Face ID ou clé de sécurité

### Étape 6 — (Optionnel) Configurer le webhook Discord

Dans le Dashboard → onglet **Paramètres** :
- Collez l'URL de votre webhook Discord
- Personnalisez le message public affiché en cas d'alerte

---

## Architecture de sécurité

### Double authentification en couches

```
Internet
    │
    ▼
┌─────────────────────────────────────────┐
│  1. Clé d'accès (ADMIN_SECRET_TOKEN)    │  ← Cookie httponly, 256 bits
│     Vérifiée à chaque requête admin      │
│     Sans elle → impossible d'accéder    │
│     à login.php, install.php, etc.      │
└──────────────┬──────────────────────────┘
               │ ✓ Cookie DGRD_ACCESS valide
               ▼
┌─────────────────────────────────────────┐
│  2. Passkey WebAuthn                    │  ← Cryptographie asymétrique
│     Vérification de signature OpenSSL   │
│     Challenge unique (anti-rejeu)       │
│     signCount anti-clonage              │
└──────────────┬──────────────────────────┘
               │ ✓ Signature valide
               ▼
┌─────────────────────────────────────────┐
│  3. Session PHP sécurisée               │  ← httponly, secure, SameSite=Strict
│     Rotation toutes les 5 minutes       │
│     Timeout 1 heure d'inactivité        │
└─────────────────────────────────────────┘
               │ ✓ Session active
               ▼
          Dashboard admin
```

### Protections implémentées

| Mécanisme | Détail |
|---|---|
| **Token secret admin** | 256 bits d'entropie, cookie httponly/secure/SameSite=Strict, vérifié avec `hash_equals` (timing-safe) |
| **WebAuthn ES256 / RS256** | Vérification COSE → PEM + `openssl_verify`, sans bibliothèque externe |
| **Anti-brute force** | 5 tentatives / 15 min par IP (gate + login), stocké en SQLite |
| **CSRF** | Token session sur tous les formulaires POST, vérifié avec `hash_equals` |
| **Sessions** | `httponly`, `secure`, `SameSite=Strict`, rotation ID toutes les 5 min |
| **signCount WebAuthn** | Détection de clonage de Passkey |
| **Restriction IP admin** | Tableau `ALLOWED_ADMIN_IPS` dans config.php (optionnel) |
| **En-têtes HTTP** | CSP, X-Frame-Options DENY, X-Content-Type-Options, Referrer-Policy, COOP |
| **SQLite WAL** | Transactions atomiques, pas de fichiers .tmp exposés |
| **Base de données hors web** | Recommandé : déplacer `DB_FILE` hors de `public_html/` |
| **.htaccess multi-couche** | Blocage `.sqlite`, `.db`, `.json`, `.log`, `.env`, `.pem`... |
| **Détection d'anomalie** | IP inconnue + User-Agent nouveau → alerte + webhook + statut warning |

### Pourquoi le token ne peut pas être contourné

Le cookie `DGRD_ACCESS` est vérifié **en PHP** (pas seulement par `.htaccess`) dans chaque fichier admin via `require_secret_token()`. La vérification utilise `hash_equals()` pour être résistante aux attaques temporelles.

Sans le cookie :
- `login.php` → redirige vers `gate.php`
- `install.php` → redirige vers `gate.php`
- `verify.php` → redirige vers `gate.php`
- `dashboard.php` → redirige vers `gate.php`
- `actions.php` → redirige vers `gate.php`
- `logout.php` → redirige vers `gate.php`

Il est **impossible** d'atteindre la page de connexion Passkey sans connaître d'abord le token secret.

---

## Structure des fichiers

```
/
├── config.php             ← Configuration (⚠️ à modifier avant déploiement)
├── functions.php          ← Toutes les fonctions (WebAuthn, SQLite, sécurité…)
├── index.php              ← Page publique (🟢/🟠/🔴)
├── .htaccess              ← Sécurité Apache, en-têtes HTTP, blocage fichiers
│
├── /data/
│   ├── .htaccess          ← Accès web totalement interdit
│   └── discordguard.sqlite← Base de données (créée automatiquement)
│
└── /admin/
    ├── .htaccess          ← Anti-cache, blocage extensions sensibles
    ├── gate.php           ← Porte d'entrée (saisie du token secret)
    ├── install.php        ← Enregistrement Passkey (désactivé après setup)
    ├── login.php          ← Connexion via Passkey
    ├── verify.php         ← API WebAuthn (challenges + vérification)
    ├── dashboard.php      ← Tableau de bord
    ├── actions.php        ← Traitements POST (CSRF protégé)
    └── logout.php         ← Déconnexion propre
```

### Schéma de la base SQLite

```sql
credentials   -- Passkeys enregistrées (id, public_key, sign_count, name…)
logs          -- Journal de tous les accès admin
alerts        -- Anomalies détectées (IP inconnue, User-Agent différent…)
settings      -- Paramètres (statut, webhook, message, IPs connues…)
brute_force   -- Compteur de tentatives par IP
```

---

## Utilisation quotidienne

### Accéder au dashboard

1. `https://votredomaine.com/admin/gate.php` → saisir le token
2. `/admin/login.php` → s'authentifier avec la Passkey
3. `/admin/dashboard.php` → tableau de bord

### Changer le statut

| Bouton | Effet | Webhook envoyé |
|---|---|---|
| 🟢 Tout est normal | Statut → **secure** | Oui |
| 🟠 Activité suspecte | Statut → **warning** | Oui |
| 🔴 Compte compromis | Statut → **compromised** | Oui |

Le statut est immédiatement visible sur la page publique `/`.

### Page publique

`https://votredomaine.com/` affiche en temps réel :
- 🟢 **Sécurisé** — aucun problème
- 🟠 **Activité suspecte** — surveillance renforcée + message d'avertissement
- 🔴 **Compte compromis** — alerte maximale + message personnalisé

---

## Restriction IP admin (optionnel)

Pour n'autoriser que certaines IP à accéder à `/admin/` :

```php
// Dans config.php
define('ALLOWED_ADMIN_IPS', ['1.2.3.4', '5.6.7.8']);
```

Avec un tableau vide `[]`, la restriction est désactivée.

---

## Résolution de problèmes

### La Passkey ne fonctionne pas

- Vérifiez que vous êtes bien en **HTTPS** (certificat SSL valide)
- Vérifiez que `WEBAUTHN_RP_ID` correspond exactement au domaine (sans `https://`)
- Vérifiez que `WEBAUTHN_ORIGIN` commence bien par `https://`
- Vérifiez que `pdo_sqlite` est activé : `<?php phpinfo(); ?>` et cherchez "PDO"

### Erreur "pdo_sqlite non disponible"

Sur cPanel : *Logiciels → PHP Selector → Extensions* → cocher `pdo_sqlite` → Enregistrer.

### Bloqué par le brute-force (429)

Attendez 15 minutes ou supprimez la table en SSH :

```bash
php -r "
require 'config.php';
require 'functions.php';
get_db()->exec('DELETE FROM brute_force');
echo 'OK';
"
```

### Réinitialiser l'application complètement

1. Supprimez `data/discordguard.sqlite`
2. La base sera recréée automatiquement au prochain accès
3. Retournez sur `/admin/install.php` pour enregistrer une nouvelle Passkey

---

## Mise à jour du token secret

1. Générez un nouveau token : `php -r "echo bin2hex(random_bytes(32));"`
2. Remplacez `ADMIN_SECRET_TOKEN` dans `config.php`
3. Déconnectez-vous et reconnectez-vous via `gate.php`

> Le changement est immédiat. Tout cookie `DGRD_ACCESS` existant devient invalide.

---

## Licence

Usage personnel. Pas de redistribution sans autorisation.

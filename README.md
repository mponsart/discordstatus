# Discord Guard

Système de surveillance de la sécurité d'un compte Discord.
Authentification sans mot de passe (Passkey / WebAuthn), dashboard admin, page de statut public et alertes Discord.

---

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

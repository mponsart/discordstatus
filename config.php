<?php
/**
 * Discord Guard — Configuration centrale
 *
 * ⚠️  HTTPS OBLIGATOIRE — WebAuthn / Passkeys refusent de fonctionner en HTTP.
 * ⚠️  Modifiez TOUTES les valeurs marquées « À CONFIGURER » avant le déploiement.
 */

// =============================================================================
// DOMAINE & WEBAUTHN
// =============================================================================

/**
 * RP ID : domaine nu, sans protocole ni slash (ex: mondomaine.com).
 * Doit correspondre EXACTEMENT au domaine servi en HTTPS.
 * À CONFIGURER ↓
 */
define('WEBAUTHN_RP_ID',   'mondomaine.com');

/**
 * Origine complète attendue lors de la vérification WebAuthn.
 * À CONFIGURER ↓
 */
define('WEBAUTHN_ORIGIN',  'https://mondomaine.com');

/** Nom affiché dans le dialogue Passkey du navigateur. */
define('WEBAUTHN_RP_NAME', 'Discord Guard');

// =============================================================================
// CLÉ D'ACCÈS ADMIN (non contournable)
// =============================================================================

/**
 * Token secret de 64 caractères hexadécimaux (256 bits d'entropie).
 * Générez-en un avec : php -r "echo bin2hex(random_bytes(32));"
 *
 * ⚠️  OBLIGATOIRE — À CONFIGURER avant toute utilisation.
 *     Sans ce token valide dans le cookie DGRD_ACCESS, toutes les pages
 *     /admin/ (sauf gate.php) sont inaccessibles.
 */
define('ADMIN_SECRET_TOKEN', 'CHANGEZ_MOI_AVEC_php_-r_echo_bin2hex(random_bytes(32))');

// =============================================================================
// BASE DE DONNÉES SQLITE
// =============================================================================

define('DATA_DIR', __DIR__ . '/data/');

/**
 * Chemin vers la base de données SQLite.
 *
 * RECOMMANDÉ : placez ce fichier EN DEHORS du répertoire public (public_html/).
 * Sur cPanel, remplacez par :
 *   define('DB_FILE', dirname(__DIR__, 2) . '/discordguard.sqlite');
 *
 * Par défaut (dans data/, protégé par .htaccess) :
 */
define('DB_FILE', DATA_DIR . 'discordguard.sqlite');

// =============================================================================
// SÉCURITÉ
// =============================================================================

/** Tentatives avant verrouillage de l'IP (brute-force). */
define('MAX_LOGIN_ATTEMPTS', 5);

/** Durée du verrouillage en secondes (15 min). */
define('LOCKOUT_TIME', 900);

/** Durée de vie d'une session admin en secondes (1 heure). */
define('SESSION_LIFETIME', 3600);

// =============================================================================
// LIMITES DE STOCKAGE
// =============================================================================

define('MAX_LOGS',   500);
define('MAX_ALERTS', 200);

// =============================================================================
// RESTRICTION IP ADMIN (optionnel — double protection)
// =============================================================================

/**
 * Tableau vide = restriction désactivée.
 * Exemple multi-IP : ['1.2.3.4', '5.6.7.8']
 */
define('ALLOWED_ADMIN_IPS', []);

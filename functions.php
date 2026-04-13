<?php
/**
 * Discord Guard — Fonctions utilitaires
 *
 * Contient :
 *  - Gestion des sessions sécurisées
 *  - Protection CSRF & token d'accès admin
 *  - Base de données SQLite (PDO)
 *  - Logs & alertes
 *  - Géolocalisation IP
 *  - Détection d'anomalie
 *  - Envoi webhook Discord
 *  - Protection brute-force
 *  - Décodeur CBOR minimal (WebAuthn)
 *  - Utilitaires WebAuthn (COSE → PEM, parse authData, verify signature)
 *  - Accès aux credentials WebAuthn
 */

require_once __DIR__ . '/config.php';

// =============================================================================
// BASE64URL
// =============================================================================

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $base64 = strtr($data, '-_', '+/');
    $padded = str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '=');
    return base64_decode($padded);
}

// =============================================================================
// SESSIONS SÉCURISÉES
// =============================================================================

function init_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,           // Expiration à la fermeture du navigateur
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,        // HTTPS uniquement
        'httponly' => true,        // Non accessible en JavaScript
        'samesite' => 'Strict',
    ]);
    session_name('DGRD_SESS');
    session_start();

    // Rotation automatique si la session est nouvelle ou ancienne
    if (empty($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 300) {
        // Regénérer l'ID toutes les 5 min pour limiter la fixation de session
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }
}

function is_admin_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in'])
        && $_SESSION['admin_logged_in'] === true;
}

function require_admin(): void
{
    if (!is_admin_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }

    // Timeout d'inactivité
    if (!empty($_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME
    ) {
        session_unset();
        session_destroy();
        header('Location: /admin/login.php?reason=timeout');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

// =============================================================================
// RESTRICTION IP ADMIN
// =============================================================================

function check_admin_ip(): void
{
    $allowed = ALLOWED_ADMIN_IPS;
    if (empty($allowed)) {
        return;
    }

    $ip = get_client_ip();
    if (!in_array($ip, $allowed, true)) {
        http_response_code(403);
        exit('Accès interdit depuis cette adresse IP.');
    }
}

// =============================================================================
// CSRF
// =============================================================================

function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8')
        . '">';
}

// =============================================================================
// ADRESSE IP CLIENT
// =============================================================================

function get_client_ip(): string
{
    // On ne fait confiance qu'à REMOTE_ADDR (non falsifiable côté réseau)
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// =============================================================================
// BASE DE DONNÉES SQLITE
// =============================================================================

/**
 * Retourne une instance PDO SQLite (singleton).
 * Crée la base et les tables au premier appel.
 */
function get_db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $dbFile = DB_FILE;
    $dbDir  = dirname($dbFile);

    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0750, true);
    }

    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // WAL : lectures/écritures concurrentes sans blocage
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous  = NORMAL');
    $db->exec('PRAGMA foreign_keys = ON');

    _init_schema($db);

    // Chmod restrictif sur le fichier
    @chmod($dbFile, 0640);

    return $db;
}

function _init_schema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS credentials (
            id          TEXT PRIMARY KEY,
            public_key  TEXT NOT NULL,
            sign_count  INTEGER NOT NULL DEFAULT 0,
            name        TEXT NOT NULL DEFAULT 'Passkey',
            created_at  TEXT NOT NULL,
            last_used   TEXT
        );

        CREATE TABLE IF NOT EXISTS logs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            date        TEXT NOT NULL,
            ip          TEXT NOT NULL,
            user_agent  TEXT,
            action      TEXT NOT NULL,
            extra       TEXT
        );

        CREATE TABLE IF NOT EXISTS alerts (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            date        TEXT NOT NULL,
            type        TEXT NOT NULL,
            message     TEXT NOT NULL,
            ip          TEXT NOT NULL,
            data        TEXT
        );

        CREATE TABLE IF NOT EXISTS settings (
            key         TEXT PRIMARY KEY,
            value       TEXT
        );

        CREATE TABLE IF NOT EXISTS brute_force (
            ip_hash     TEXT PRIMARY KEY,
            attempts    INTEGER NOT NULL DEFAULT 1,
            first_seen  INTEGER NOT NULL
        );
    ");

    // Paramètres par défaut (INSERT OR IGNORE)
    $defaults = [
        ['status',             'secure'],
        ['last_updated',       null],
        ['custom_message',     'Si vous recevez un lien Discord de ma part, ne cliquez pas.'],
        ['discord_webhook',    ''],
        ['webauthn_user_id',   null],
        ['known_ips',          '[]'],
        ['known_user_agents',  '[]'],
    ];
    $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
    foreach ($defaults as [$k, $v]) {
        $stmt->execute([$k, $v]);
    }
}

/** Lit un paramètre depuis la table settings. */
function get_setting(string $key, mixed $default = null): mixed
{
    $stmt = get_db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return ($row !== false) ? $row['value'] : $default;
}

/** Écrit un paramètre dans la table settings. */
function set_setting(string $key, mixed $value): void
{
    $stmt = get_db()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $stmt->execute([$key, $value]);
}

// =============================================================================
// LOGS
// =============================================================================

function log_action(string $action, array $extra = []): void
{
    $db   = get_db();
    $stmt = $db->prepare(
        'INSERT INTO logs (date, ip, user_agent, action, extra)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        date('c'),
        get_client_ip(),
        substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 512),
        $action,
        $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
    ]);

    // Écrêter : garder les MAX_LOGS plus récents
    $db->exec(
        'DELETE FROM logs WHERE id NOT IN
         (SELECT id FROM logs ORDER BY id DESC LIMIT ' . MAX_LOGS . ')'
    );
}

/** Vide la table logs. */
function clear_logs(): void
{
    get_db()->exec('DELETE FROM logs');
    log_action('logs_cleared');
}

// =============================================================================
// ALERTES
// =============================================================================

function add_alert(string $type, string $message, array $data = []): void
{
    $db   = get_db();
    $stmt = $db->prepare(
        'INSERT INTO alerts (date, type, message, ip, data)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        date('c'),
        $type,
        $message,
        get_client_ip(),
        $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
    ]);

    // Écrêter
    $db->exec(
        'DELETE FROM alerts WHERE id NOT IN
         (SELECT id FROM alerts ORDER BY id DESC LIMIT ' . MAX_ALERTS . ')'
    );
}

/** Vide la table alerts. */
function clear_alerts(): void
{
    get_db()->exec('DELETE FROM alerts');
}

/** Retourne les logs sous forme de tableau, du plus récent au plus ancien. */
function get_logs(int $limit = 50): array
{
    $stmt = get_db()->prepare(
        'SELECT date, ip, user_agent, action, extra
         FROM logs ORDER BY id DESC LIMIT ?'
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['extra'] = $r['extra'] ? json_decode($r['extra'], true) : [];
    }
    return $rows;
}

/** Retourne les alertes sous forme de tableau, de la plus récente à la plus ancienne. */
function get_alerts(int $limit = 30): array
{
    $stmt = get_db()->prepare(
        'SELECT date, type, message, ip, data
         FROM alerts ORDER BY id DESC LIMIT ?'
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['data'] = $r['data'] ? json_decode($r['data'], true) : [];
    }
    return $rows;
}

/** Compte les lignes d'une table. */
function count_table(string $table): int
{
    $allowed = ['logs', 'alerts', 'credentials'];
    if (!in_array($table, $allowed, true)) {
        return 0;
    }
    return (int) get_db()->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}

// =============================================================================
// STATUT
// =============================================================================

function get_status(): array
{
    return [
        'status'        => get_setting('status',         'secure'),
        'lastUpdated'   => get_setting('last_updated'),
        'customMessage' => get_setting('custom_message', 'Si vous recevez un lien Discord de ma part, ne cliquez pas.'),
    ];
}

function set_status(string $status): void
{
    $allowed = ['secure', 'warning', 'compromised'];
    if (!in_array($status, $allowed, true)) {
        return;
    }
    set_setting('status',       $status);
    set_setting('last_updated', date('c'));
}

// =============================================================================
// GÉOLOCALISATION IP (ip-api.com — gratuit, sans clé)
// =============================================================================

function get_ip_info(string $ip): array
{
    $default = ['country' => 'N/A', 'city' => 'N/A', 'isp' => 'N/A'];

    if (!filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
    ) {
        return array_merge($default, ['country' => 'Local/Privé']);
    }

    $url = 'http://ip-api.com/json/' . rawurlencode($ip)
         . '?fields=status,country,city,isp';

    $ctx      = stream_context_create(['http' => ['timeout' => 3, 'method' => 'GET']]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        return $default;
    }
    $info = json_decode($response, true);
    if (!is_array($info) || ($info['status'] ?? '') !== 'success') {
        return $default;
    }
    return [
        'country' => $info['country'] ?? 'N/A',
        'city'    => $info['city']    ?? 'N/A',
        'isp'     => $info['isp']     ?? 'N/A',
    ];
}

// =============================================================================
// DÉTECTION D'ANOMALIE
// =============================================================================

function check_for_anomaly(): void
{
    $ip = get_client_ip();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

    $knownIPs = json_decode(get_setting('known_ips', '[]') ?? '[]', true) ?? [];
    $knownUAs = json_decode(get_setting('known_user_agents', '[]') ?? '[]', true) ?? [];
    $anomalies = [];

    if (!empty($knownIPs) && !in_array($ip, $knownIPs, true)) {
        $anomalies[] = 'ip_inconnue';
    }
    if (!empty($knownUAs) && !in_array($ua, $knownUAs, true)) {
        $anomalies[] = 'user_agent_different';
    }

    if (!empty($anomalies)) {
        $ipInfo = get_ip_info($ip);
        add_alert('anomaly', 'Connexion anormale détectée', [
            'anomalies'  => $anomalies,
            'ip'         => $ip,
            'country'    => $ipInfo['country'],
            'city'       => $ipInfo['city'],
            'user_agent' => $ua,
        ]);

        if (get_setting('status', 'secure') === 'secure') {
            set_status('warning');
        }

        send_discord_alert($ip, $ipInfo, $ua, $anomalies);
    }

    // Mémoriser l'IP / l'UA s'ils sont nouveaux
    $changed = false;
    if (!in_array($ip, $knownIPs, true)) {
        $knownIPs[] = $ip;
        $changed    = true;
    }
    if (!in_array($ua, $knownUAs, true)) {
        $knownUAs[] = $ua;
        $changed    = true;
    }
    if ($changed) {
        set_setting('known_ips',          json_encode($knownIPs));
        set_setting('known_user_agents',  json_encode($knownUAs));
    }
}

// =============================================================================
// WEBHOOK DISCORD
// =============================================================================

function send_discord_alert(
    string $ip,
    array  $ipInfo,
    string $ua,
    array  $anomalies
): void {
    $webhookUrl = get_setting('discord_webhook', '');
    if (empty($webhookUrl)) {
        return;
    }

    $payload = [
        'embeds' => [[
            'title'       => '⚠️ Discord Guard — Alerte de sécurité',
            'color'       => 0xFFA500,
            'description' => 'Une connexion anormale a été détectée sur le dashboard.',
            'fields'      => [
                ['name' => '🌐 IP',         'value' => $ip,                           'inline' => true],
                ['name' => '🗺️ Pays',       'value' => $ipInfo['country'] ?? 'N/A',  'inline' => true],
                ['name' => '🏙️ Ville',      'value' => $ipInfo['city']    ?? 'N/A',  'inline' => true],
                ['name' => '🔍 FAI',         'value' => $ipInfo['isp']     ?? 'N/A',  'inline' => true],
                ['name' => '⚡ Anomalies',  'value' => implode(', ', $anomalies),     'inline' => false],
                ['name' => '🖥️ User-Agent', 'value' => substr($ua, 0, 500),          'inline' => false],
            ],
            'timestamp' => date('c'),
            'footer'    => ['text' => 'Discord Guard'],
        ]],
    ];

    _discord_post($webhookUrl, $payload);
}

function send_discord_status_change(string $newStatus): void
{
    $webhookUrl = get_setting('discord_webhook', '');
    if (empty($webhookUrl)) {
        return;
    }

    $colors = ['secure' => 0x57F287, 'warning' => 0xFEE75C, 'compromised' => 0xED4245];
    $labels = ['secure' => '🟢 Sécurisé', 'warning' => '🟠 Activité suspecte', 'compromised' => '🔴 Compromis'];

    $payload = [
        'embeds' => [[
            'title'       => 'Discord Guard — Changement de statut',
            'color'       => $colors[$newStatus] ?? 0x95A5A6,
            'description' => 'Le statut du compte a été mis à jour manuellement.',
            'fields'      => [
                ['name' => '📊 Nouveau statut', 'value' => $labels[$newStatus] ?? $newStatus, 'inline' => false],
                ['name' => '🕒 Date',            'value' => date('d/m/Y H:i:s'),               'inline' => false],
            ],
            'timestamp' => date('c'),
            'footer'    => ['text' => 'Discord Guard'],
        ]],
    ];

    _discord_post($webhookUrl, $payload);
}

/** Envoi HTTP POST vers un webhook Discord. */
function _discord_post(string $url, array $payload): void
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ctx  = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n"
                             . 'Content-Length: ' . strlen($body) . "\r\n",
            'content'       => $body,
            'timeout'       => 5,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($url, false, $ctx);
}

// =============================================================================
// PROTECTION BRUTE-FORCE (SQLite)
// =============================================================================

function check_brute_force(): void
{
    $db   = get_db();
    $hash = md5(get_client_ip());

    // Nettoyer les entrées expirées
    $db->prepare('DELETE FROM brute_force WHERE first_seen < ?')
       ->execute([time() - LOCKOUT_TIME]);

    $stmt = $db->prepare('SELECT attempts, first_seen FROM brute_force WHERE ip_hash = ?');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) {
        return;
    }

    if ((int) $row['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $remaining = LOCKOUT_TIME - (time() - (int) $row['first_seen']);
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'Trop de tentatives. Réessayez dans '
                        . ceil($remaining / 60) . ' minute(s).',
        ]);
        exit;
    }
}

function record_failed_attempt(): void
{
    $db   = get_db();
    $hash = md5(get_client_ip());

    $db->prepare(
        'INSERT INTO brute_force (ip_hash, attempts, first_seen)
         VALUES (?, 1, ?)
         ON CONFLICT(ip_hash) DO UPDATE SET attempts = attempts + 1'
    )->execute([$hash, time()]);
}

function clear_failed_attempts(): void
{
    $db = get_db();
    $db->prepare('DELETE FROM brute_force WHERE ip_hash = ?')
       ->execute([md5(get_client_ip())]);
}

// =============================================================================
// DÉCODEUR CBOR MINIMAL (nécessaire pour WebAuthn)
// Supporte : unsigned int, negative int, byte string, text string, array, map,
//            bool, null.  Longueurs indéfinies non supportées (WebAuthn n'en
//            utilise pas).
// =============================================================================

function cbor_decode(string $data): mixed
{
    $offset = 0;
    return _cbor_item($data, $offset);
}

function _cbor_item(string $data, int &$o): mixed
{
    if ($o >= strlen($data)) {
        throw new RuntimeException('CBOR : fin de flux inattendue');
    }
    $byte  = ord($data[$o++]);
    $major = ($byte >> 5) & 0x07;
    $info  = $byte & 0x1f;
    $val   = _cbor_length($data, $o, $info);

    switch ($major) {
        case 0: return $val;                          // Entier non signé
        case 1: return -1 - $val;                     // Entier négatif
        case 2:                                       // Bytes
            $s = substr($data, $o, $val);
            $o += $val;
            return $s;
        case 3:                                       // Texte
            $s = substr($data, $o, $val);
            $o += $val;
            return $s;
        case 4:                                       // Tableau
            $arr = [];
            for ($i = 0; $i < $val; $i++) {
                $arr[] = _cbor_item($data, $o);
            }
            return $arr;
        case 5:                                       // Map
            $map = [];
            for ($i = 0; $i < $val; $i++) {
                $k      = _cbor_item($data, $o);
                $map[$k] = _cbor_item($data, $o);
            }
            return $map;
        case 7:                                       // Simple / float
            if ($info === 20) return false;
            if ($info === 21) return true;
            if ($info === 22) return null;
            return null;
    }
    throw new RuntimeException("CBOR : type majeur non géré : $major");
}

function _cbor_length(string $data, int &$o, int $info): int
{
    if ($info <= 23) {
        return $info;
    }
    if ($info === 24) {
        return ord($data[$o++]);
    }
    if ($info === 25) {
        $v  = unpack('n', substr($data, $o, 2))[1];
        $o += 2;
        return $v;
    }
    if ($info === 26) {
        $v  = unpack('N', substr($data, $o, 4))[1];
        $o += 4;
        return $v;
    }
    if ($info === 27) {
        $hi  = unpack('N', substr($data, $o,     4))[1];
        $lo  = unpack('N', substr($data, $o + 4, 4))[1];
        $o  += 8;
        return ($hi * 4294967296) + $lo;
    }
    return 0;
}

// =============================================================================
// WEBAUTHN — CONVERSION COSE → PEM
// =============================================================================

/**
 * Convertit une clé publique COSE (EC P-256 / RS256) en PEM SubjectPublicKeyInfo.
 *
 * @param  array $coseKey  Résultat du décodage CBOR de la clé
 * @return string PEM
 * @throws RuntimeException si le type n'est pas supporté
 */
function cose_key_to_pem(array $coseKey): string
{
    $kty = $coseKey[1] ?? null;

    // kty = 2 → EC
    if ($kty === 2) {
        $x = $coseKey[-2] ?? '';
        $y = $coseKey[-3] ?? '';

        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Clé EC P-256 invalide (coordonnées)');
        }

        // ASN.1 DER SubjectPublicKeyInfo pour EC P-256
        // SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BITSTRING { 04 || x || y } }
        $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004');
        $der    = $prefix . $x . $y;

        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    // kty = 3 → RSA
    if ($kty === 3) {
        $n = $coseKey[-1] ?? '';
        $e = $coseKey[-2] ?? '';
        if (empty($n) || empty($e)) {
            throw new RuntimeException('Clé RSA invalide (n ou e manquant)');
        }
        return _rsa_components_to_pem($n, $e);
    }

    throw new RuntimeException("Type de clé COSE non supporté : kty = $kty");
}

/**
 * Construit un PEM RSA public key à partir du modulus (n) et de l'exposant (e).
 */
function _rsa_components_to_pem(string $n, string $e): string
{
    $encLen = static function (int $len): string {
        if ($len < 128) {
            return chr($len);
        }
        if ($len < 256) {
            return "\x81" . chr($len);
        }
        return "\x82" . pack('n', $len);
    };

    $encInt = static function (string $bytes) use ($encLen): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) >= 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $encLen(strlen($bytes)) . $bytes;
    };

    $nDer  = $encInt($n);
    $eDer  = $encInt($e);
    $seq   = $nDer . $eDer;
    $rsaSeq = "\x30" . $encLen(strlen($seq)) . $seq;

    // BIT STRING = 0x00 (pas de bits inutilisés) + rsaSeq
    $bs = "\x03" . $encLen(strlen($rsaSeq) + 1) . "\x00" . $rsaSeq;

    // OID 1.2.840.113549.1.1.1 + NULL
    $oid   = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $algId = "\x30" . $encLen(strlen($oid)) . $oid;

    $spki = "\x30" . $encLen(strlen($algId) + strlen($bs)) . $algId . $bs;

    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

// =============================================================================
// WEBAUTHN — PARSE AUTHENTICATOR DATA
// =============================================================================

/**
 * Décode les authData WebAuthn.
 *
 * Structure :
 *  [0-31]  rpIdHash     (32 octets)
 *  [32]    flags        (1 octet, bits : UP=0, UV=2, AT=6)
 *  [33-36] signCount    (uint32 big-endian)
 *  [37+]   attested credential data (si AT=1, lors de l'enregistrement)
 */
function parse_auth_data(string $authData): array
{
    if (strlen($authData) < 37) {
        throw new RuntimeException('authData trop court (' . strlen($authData) . ' octets)');
    }

    $result = [
        'rpIdHash'  => substr($authData, 0, 32),
        'flags'     => ord($authData[32]),
        'signCount' => unpack('N', substr($authData, 33, 4))[1],
    ];

    // Bit 6 (0x40) = AT : données de credential attestées présentes
    if (($result['flags'] & 0x40) && strlen($authData) > 37) {
        $off = 37;
        $result['aaguid']       = substr($authData, $off, 16);
        $off += 16;
        $cidLen                 = unpack('n', substr($authData, $off, 2))[1];
        $off += 2;
        $result['credentialId'] = substr($authData, $off, $cidLen);
        $off += $cidLen;
        // Le reste est la clé publique en COSE
        $result['credentialPublicKeyBytes'] = substr($authData, $off);
    }

    return $result;
}

// =============================================================================
// WEBAUTHN — VÉRIFICATION DE SIGNATURE
// =============================================================================

/**
 * Vérifie une signature WebAuthn (ES256 ou RS256) avec openssl_verify.
 *
 * @param  string $pem        Clé publique PEM
 * @param  string $data       Données signées (authData || sha256(clientDataJSON))
 * @param  string $signature  Signature brute (DER pour ES256)
 * @return bool
 */
function verify_webauthn_signature(string $pem, string $data, string $signature): bool
{
    $pubKey = openssl_pkey_get_public($pem);
    if ($pubKey === false) {
        return false;
    }
    $result = openssl_verify($data, $signature, $pubKey, OPENSSL_ALGO_SHA256);
    return $result === 1;
}

// =============================================================================
// CREDENTIALS WEBAUTHN (SQLite)
// =============================================================================

function get_credentials(): array
{
    return get_db()
        ->query('SELECT * FROM credentials ORDER BY created_at ASC')
        ->fetchAll();
}

function save_credential(array $credential): void
{
    $stmt = get_db()->prepare(
        'INSERT INTO credentials (id, public_key, sign_count, name, created_at, last_used)
         VALUES (:id, :public_key, :sign_count, :name, :created_at, :last_used)'
    );
    $stmt->execute([
        ':id'         => $credential['id'],
        ':public_key' => $credential['publicKey'],
        ':sign_count' => (int) ($credential['signCount'] ?? 0),
        ':name'       => $credential['name'] ?? 'Passkey',
        ':created_at' => $credential['createdAt'] ?? date('c'),
        ':last_used'  => $credential['lastUsed']  ?? null,
    ]);
}

function find_credential(string $id): ?array
{
    $stmt = get_db()->prepare('SELECT * FROM credentials WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    // Uniformiser les noms de clés pour ne pas casser verify.php
    $row['publicKey']  = $row['public_key'];
    $row['signCount']  = (int) $row['sign_count'];
    $row['createdAt']  = $row['created_at'];
    $row['lastUsed']   = $row['last_used'];
    return $row;
}

function update_sign_count(string $id, int $newCount): void
{
    $stmt = get_db()->prepare(
        'UPDATE credentials SET sign_count = ?, last_used = ? WHERE id = ?'
    );
    $stmt->execute([$newCount, date('c'), $id]);
}

function is_installed(): bool
{
    return count_table('credentials') > 0;
}

// =============================================================================
// CLÉ D'ACCÈS ADMIN (TOKEN SECRET — NON CONTOURNABLE)
// =============================================================================

/**
 * Vérifie que le cookie DGRD_ACCESS contient le token secret défini dans config.php.
 *
 * Si le token est absent ou incorrect :
 *   → redirection vers /admin/gate.php (page de saisie du token)
 *
 * Le token fait 64 caractères hex (256 bits d'entropie). Même en connaissant
 * l'URL de gate.php, une attaque par force brute est irréalisable.
 * Cette vérification est effectuée AVANT toute autre logique admin,
 * y compris avant la vérification de la Passkey.
 */
function require_secret_token(): void
{
    // Valider que le token configuré n'est pas encore le placeholder
    if (str_starts_with(ADMIN_SECRET_TOKEN, 'CHANGEZ_MOI')) {
        http_response_code(503);
        exit('⚠️ Discord Guard non configuré : modifiez ADMIN_SECRET_TOKEN dans config.php.');
    }

    $provided = $_COOKIE['DGRD_ACCESS'] ?? '';

    if (!hash_equals(ADMIN_SECRET_TOKEN, $provided)) {
        // Détruire le cookie invalide s'il existe
        if ($provided !== '') {
            setcookie('DGRD_ACCESS', '', time() - 3600, '/admin/', '', true, true);
        }
        header('Location: /admin/gate.php');
        exit;
    }
}

// =============================================================================
// HELPERS DIVERS
// =============================================================================

/** Retourne true si la requête arrive via HTTPS. */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Formate une date ISO en français lisible. */
function format_date(?string $iso): string
{
    if (!$iso) return 'jamais';
    $ts = strtotime($iso);
    if ($ts === false) return $iso;
    return date('d/m/Y à H:i:s', $ts);
}

/** Retourne la classe CSS Tailwind selon le statut. */
function status_class(string $status): string
{
    return match ($status) {
        'secure'      => 'text-green-400',
        'warning'     => 'text-yellow-400',
        'compromised' => 'text-red-400',
        default       => 'text-gray-400',
    };
}

/** Retourne le libellé français du statut. */
function status_label(string $status): string
{
    return match ($status) {
        'secure'      => '🟢 Sécurisé',
        'warning'     => '🟠 Activité suspecte',
        'compromised' => '🔴 Compte compromis',
        default       => '❓ Inconnu',
    };
}

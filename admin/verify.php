<?php
/**
 * Discord Guard — Point d'entrée API WebAuthn (JSON)
 *
 * Actions supportées :
 *   GET  ?action=challenge_register  → options pour navigator.credentials.create()
 *   POST ?action=register            → vérifie l'attestation et sauvegarde la Passkey
 *   GET  ?action=challenge_login     → options pour navigator.credentials.get()
 *   POST ?action=authenticate        → vérifie l'assertion et ouvre la session
 */

require_once __DIR__ . '/../functions.php';
init_secure_session();
check_admin_ip();

// Réponses exclusivement en JSON
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Anti-cache
header('Cache-Control: no-store, no-cache');

$action = $_GET['action'] ?? '';

// =============================================================================
// Helpers
// =============================================================================

function json_error(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function json_success(array $extra = []): never
{
    echo json_encode(array_merge(['success' => true], $extra));
    exit;
}

/** Récupère et valide le corps JSON de la requête POST. */
function get_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        json_error('Corps de requête vide.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('JSON invalide.');
    }
    return $data;
}

// =============================================================================
// GET — Challenge d'enregistrement
// =============================================================================
if ($action === 'challenge_register' && $_SERVER['REQUEST_METHOD'] === 'GET') {

    if (is_installed()) {
        json_error('Installation déjà effectuée.', 403);
    }

    $challenge = random_bytes(32);
    $_SESSION['webauthn_challenge'] = base64url_encode($challenge);
    $_SESSION['webauthn_action']    = 'register';

    // Identifiant utilisateur stable (aléatoire, non lié à un compte)
    $userId = get_setting('webauthn_user_id');
    if (empty($userId)) {
        $userId = base64url_encode(random_bytes(16));
        set_setting('webauthn_user_id', $userId);
    }

    echo json_encode([
        'challenge' => base64url_encode($challenge),
        'rp'        => [
            'id'   => WEBAUTHN_RP_ID,
            'name' => WEBAUTHN_RP_NAME,
        ],
        'user' => [
            'id'          => $userId,
            'name'        => 'admin',
            'displayName' => 'Administrateur',
        ],
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],    // ES256
            ['type' => 'public-key', 'alg' => -257],   // RS256
        ],
        'authenticatorSelection' => [
            'userVerification' => 'required',
            'residentKey'      => 'preferred',
        ],
        'timeout'     => 60000,
        'attestation' => 'none',
    ]);
    exit;
}

// =============================================================================
// POST — Vérification de l'enregistrement
// =============================================================================
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (is_installed()) {
        json_error('Installation déjà effectuée.', 403);
    }

    check_brute_force();

    $body = get_json_body();

    // --- Validation des champs obligatoires ---
    $credId            = $body['id']                              ?? '';
    $clientDataJSON_b64 = $body['response']['clientDataJSON']    ?? '';
    $attestationObj_b64 = $body['response']['attestationObject'] ?? '';

    if (!$credId || !$clientDataJSON_b64 || !$attestationObj_b64) {
        record_failed_attempt();
        json_error('Données manquantes.');
    }

    // --- Vérification du challenge stocké en session ---
    $storedChallenge = $_SESSION['webauthn_challenge'] ?? '';
    if (!$storedChallenge || ($_SESSION['webauthn_action'] ?? '') !== 'register') {
        json_error('Session invalide ou expirée.', 403);
    }
    // Supprimer immédiatement le challenge (usage unique)
    unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_action']);

    try {
        // --- Décodage clientDataJSON ---
        $clientDataRaw = base64url_decode($clientDataJSON_b64);
        $clientData    = json_decode($clientDataRaw, true);
        if (!is_array($clientData)) {
            throw new RuntimeException('clientDataJSON invalide.');
        }

        // Vérification du type
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new RuntimeException('Type WebAuthn incorrect.');
        }

        // Vérification du challenge (comparaison temps constant)
        $receivedChallenge = $clientData['challenge'] ?? '';
        if (!hash_equals($storedChallenge, $receivedChallenge)) {
            throw new RuntimeException('Challenge incorrect.');
        }

        // Vérification de l'origine
        $origin = rtrim($clientData['origin'] ?? '', '/');
        if ($origin !== rtrim(WEBAUTHN_ORIGIN, '/')) {
            throw new RuntimeException("Origine incorrecte : $origin");
        }

        // --- Décodage attestationObject (CBOR) ---
        $attestationRaw = base64url_decode($attestationObj_b64);
        $attObj         = cbor_decode($attestationRaw);
        if (!is_array($attObj) || !isset($attObj['authData'])) {
            throw new RuntimeException('attestationObject CBOR invalide.');
        }

        $authDataRaw = $attObj['authData'];

        // --- Parse authData ---
        $authData = parse_auth_data($authDataRaw);

        // Vérification du rpIdHash
        $expectedRpIdHash = hash('sha256', WEBAUTHN_RP_ID, true);
        if (!hash_equals($expectedRpIdHash, $authData['rpIdHash'])) {
            throw new RuntimeException('rpIdHash incorrect.');
        }

        // Vérification du flag UP (User Present, bit 0)
        if (($authData['flags'] & 0x01) === 0) {
            throw new RuntimeException('Flag UP (User Present) absent.');
        }

        // Vérification du flag UV (User Verified, bit 2) — requis car userVerification=required
        if (($authData['flags'] & 0x04) === 0) {
            throw new RuntimeException('Flag UV (User Verified) absent.');
        }

        // Vérification de la présence de la clé attestée (flag AT, bit 6)
        if (empty($authData['credentialId']) || empty($authData['credentialPublicKeyBytes'])) {
            throw new RuntimeException('Données de credential absentes dans authData.');
        }

        // --- Décodage de la clé COSE & conversion PEM ---
        $coseKey = cbor_decode($authData['credentialPublicKeyBytes']);
        if (!is_array($coseKey)) {
            throw new RuntimeException('Clé COSE invalide.');
        }
        $publicKeyPem = cose_key_to_pem($coseKey);

        // --- Sauvegarde du credential ---
        $newCredential = [
            'id'         => base64url_encode($authData['credentialId']),
            'publicKey'  => $publicKeyPem,
            'signCount'  => (int) $authData['signCount'],
            'name'       => 'Passkey principale',
            'createdAt'  => date('c'),
            'lastUsed'   => null,
        ];
        save_credential($newCredential);
        clear_failed_attempts();

        log_action('passkey_registered', ['credential_id' => substr($newCredential['id'], 0, 16) . '…']);

    } catch (RuntimeException $e) {
        record_failed_attempt();
        json_error('Erreur d\'enregistrement : ' . $e->getMessage());
    }

    json_success(['message' => 'Passkey enregistrée avec succès.']);
}

// =============================================================================
// GET — Challenge pour AJOUT d'une passkey supplémentaire (admin connecté)
// =============================================================================
if ($action === 'challenge_add_passkey' && $_SERVER['REQUEST_METHOD'] === 'GET') {

    if (!is_admin_logged_in()) {
        json_error('Non authentifié.', 401);
    }

    $challenge = random_bytes(32);
    $_SESSION['webauthn_challenge'] = base64url_encode($challenge);
    $_SESSION['webauthn_action']    = 'add_passkey';

    $userId = get_setting('webauthn_user_id');
    if (empty($userId)) {
        $userId = base64url_encode(random_bytes(16));
        set_setting('webauthn_user_id', $userId);
    }

    echo json_encode([
        'challenge' => base64url_encode($challenge),
        'rp'        => [
            'id'   => WEBAUTHN_RP_ID,
            'name' => WEBAUTHN_RP_NAME,
        ],
        'user' => [
            'id'          => $userId,
            'name'        => 'admin',
            'displayName' => 'Administrateur',
        ],
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],
            ['type' => 'public-key', 'alg' => -257],
        ],
        'authenticatorSelection' => [
            'userVerification' => 'required',
            'residentKey'      => 'preferred',
        ],
        'timeout'     => 60000,
        'attestation' => 'none',
    ]);
    exit;
}

// =============================================================================
// POST — Enregistrement d'une passkey supplémentaire (admin connecté)
// =============================================================================
if ($action === 'add_passkey' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!is_admin_logged_in()) {
        json_error('Non authentifié.', 401);
    }

    $body = get_json_body();

    $credId             = $body['id']                              ?? '';
    $clientDataJSON_b64 = $body['response']['clientDataJSON']     ?? '';
    $attestationObj_b64 = $body['response']['attestationObject']  ?? '';

    if (!$credId || !$clientDataJSON_b64 || !$attestationObj_b64) {
        json_error('Données manquantes.');
    }

    $storedChallenge = $_SESSION['webauthn_challenge'] ?? '';
    if (!$storedChallenge || ($_SESSION['webauthn_action'] ?? '') !== 'add_passkey') {
        json_error('Session invalide ou expirée.', 403);
    }
    unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_action']);

    try {
        $clientDataJSON    = base64url_decode($clientDataJSON_b64);
        $attestationObject = base64url_decode($attestationObj_b64);

        $clientData = json_decode($clientDataJSON, true);
        if (!$clientData) {
            throw new RuntimeException('clientDataJSON invalide.');
        }
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new RuntimeException('Type WebAuthn incorrect.');
        }

        $receivedChallenge = base64url_encode(base64url_decode($clientData['challenge'] ?? ''));
        if (!hash_equals($storedChallenge, $receivedChallenge)) {
            throw new RuntimeException('Challenge incorrect.');
        }

        $expectedOrigin = WEBAUTHN_ORIGIN;
        if (($clientData['origin'] ?? '') !== $expectedOrigin) {
            throw new RuntimeException('Origine incorrecte.');
        }

        $cbor     = cbor_decode($attestationObject);
        $authData = parse_authenticator_data($cbor['authData'] ?? '');

        if (!$authData['userPresent']) {
            throw new RuntimeException('Présence utilisateur non confirmée.');
        }
        if (empty($authData['credentialId'])) {
            throw new RuntimeException('Credential ID manquant.');
        }

        $rpIdHash = hash('sha256', WEBAUTHN_RP_ID, true);
        if (!hash_equals($rpIdHash, $authData['rpIdHash'])) {
            throw new RuntimeException('RP ID incorrect.');
        }

        $coseKey      = cbor_decode($authData['credentialPublicKey']);
        $publicKeyPem = cose_key_to_pem($coseKey);

        $newCredential = [
            'id'         => base64url_encode($authData['credentialId']),
            'publicKey'  => $publicKeyPem,
            'signCount'  => (int) $authData['signCount'],
            'name'       => 'Passkey #' . (count(get_credentials()) + 1),
            'createdAt'  => date('c'),
            'lastUsed'   => null,
        ];
        save_credential($newCredential);

        log_action('passkey_added', ['credential_id' => substr($newCredential['id'], 0, 16) . '…']);

    } catch (RuntimeException $e) {
        json_error('Erreur d\'enregistrement : ' . $e->getMessage());
    }

    json_success(['message' => 'Nouvelle Passkey ajoutée avec succès.']);
}

// =============================================================================
// GET — Challenge d'authentification
// =============================================================================
if ($action === 'challenge_login' && $_SERVER['REQUEST_METHOD'] === 'GET') {

    if (!is_installed()) {
        json_error('Application non installée.', 403);
    }

    check_brute_force();

    $challenge = random_bytes(32);
    $_SESSION['webauthn_challenge'] = base64url_encode($challenge);
    $_SESSION['webauthn_action']    = 'authenticate';

    // Construire la liste allowCredentials à partir des credentials enregistrés
    $allowCredentials = array_map(
        fn($c) => ['type' => 'public-key', 'id' => $c['id']],
        get_credentials()
    );

    echo json_encode([
        'challenge'        => base64url_encode($challenge),
        'allowCredentials' => $allowCredentials,
        'userVerification' => 'required',
        'rpId'             => WEBAUTHN_RP_ID,
        'timeout'          => 60000,
    ]);
    exit;
}

// =============================================================================
// POST — Vérification de l'assertion (authentification)
// =============================================================================
if ($action === 'authenticate' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!is_installed()) {
        json_error('Application non installée.', 403);
    }

    check_brute_force();

    $body = get_json_body();

    $credId             = $body['id']                              ?? '';
    $clientDataJSON_b64 = $body['response']['clientDataJSON']     ?? '';
    $authData_b64       = $body['response']['authenticatorData']  ?? '';
    $signature_b64      = $body['response']['signature']          ?? '';

    if (!$credId || !$clientDataJSON_b64 || !$authData_b64 || !$signature_b64) {
        record_failed_attempt();
        json_error('Données manquantes.');
    }

    // Récupérer le challenge de session
    $storedChallenge = $_SESSION['webauthn_challenge'] ?? '';
    if (!$storedChallenge || ($_SESSION['webauthn_action'] ?? '') !== 'authenticate') {
        json_error('Session invalide ou expirée.', 403);
    }
    unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_action']);

    try {
        // --- Trouver le credential ---
        $credential = find_credential($credId);
        if ($credential === null) {
            throw new RuntimeException('Credential introuvable.');
        }

        // --- Décodage clientDataJSON ---
        $clientDataRaw = base64url_decode($clientDataJSON_b64);
        $clientData    = json_decode($clientDataRaw, true);
        if (!is_array($clientData)) {
            throw new RuntimeException('clientDataJSON invalide.');
        }

        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new RuntimeException('Type WebAuthn incorrect.');
        }

        $receivedChallenge = $clientData['challenge'] ?? '';
        if (!hash_equals($storedChallenge, $receivedChallenge)) {
            throw new RuntimeException('Challenge incorrect.');
        }

        $origin = rtrim($clientData['origin'] ?? '', '/');
        if ($origin !== rtrim(WEBAUTHN_ORIGIN, '/')) {
            throw new RuntimeException("Origine incorrecte : $origin");
        }

        // --- Décode authData ---
        $authDataRaw = base64url_decode($authData_b64);
        $authData    = parse_auth_data($authDataRaw);

        // Vérification rpIdHash
        $expectedRpIdHash = hash('sha256', WEBAUTHN_RP_ID, true);
        if (!hash_equals($expectedRpIdHash, $authData['rpIdHash'])) {
            throw new RuntimeException('rpIdHash incorrect.');
        }

        // Vérification flags UP + UV
        if (($authData['flags'] & 0x01) === 0) {
            throw new RuntimeException('Flag UP absent.');
        }
        if (($authData['flags'] & 0x04) === 0) {
            throw new RuntimeException('Flag UV absent.');
        }

        // --- Vérification du signCount (anti-rejeu) ---
        $storedCount   = (int) ($credential['signCount'] ?? 0);
        $receivedCount = (int) $authData['signCount'];

        // Si les deux sont non nuls, le nouveau doit être strictement supérieur
        if ($storedCount !== 0 || $receivedCount !== 0) {
            if ($receivedCount <= $storedCount) {
                throw new RuntimeException(
                    "signCount suspect : reçu $receivedCount ≤ stocké $storedCount (possible clonage)."
                );
            }
        }

        // --- Vérification de la signature ---
        // Données signées = authData || SHA-256(clientDataJSON)
        $clientDataHash = hash('sha256', $clientDataRaw, true);
        $signedData     = $authDataRaw . $clientDataHash;
        $signature      = base64url_decode($signature_b64);

        if (!verify_webauthn_signature($credential['publicKey'], $signedData, $signature)) {
            throw new RuntimeException('Signature invalide.');
        }

        // --- Tout est valide : mettre à jour le compteur ---
        update_sign_count($credId, $receivedCount);

        // --- Ouvrir la session admin sécurisée ---
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['last_activity']   = time();
        $_SESSION['_created']        = time();

        clear_failed_attempts();
        log_action('admin_login_success');

        // Vérification d'anomalie après connexion (non bloquante)
        check_for_anomaly();

    } catch (RuntimeException $e) {
        record_failed_attempt();
        log_action('admin_login_failure', ['reason' => $e->getMessage()]);
        json_error('Échec d\'authentification : ' . $e->getMessage(), 401);
    }

    json_success(['message' => 'Authentification réussie.']);
}

// =============================================================================
// Action inconnue
// =============================================================================
json_error('Action inconnue.', 404);

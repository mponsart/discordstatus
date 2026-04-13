<?php
/**
 * Discord Guard — Connexion via Passkey
 *
 * ⚠️  HTTPS obligatoire pour les Passkeys (WebAuthn).
 */

require_once __DIR__ . '/../functions.php';
init_secure_session();
check_admin_ip();
require_secret_token();

// Déjà connecté → dashboard
if (is_admin_logged_in()) {
    header('Location: /admin/dashboard.php');
    exit;
}

// Pas encore installé → installation
if (!is_installed()) {
    header('Location: /admin/install.php');
    exit;
}

$reason = $_GET['reason'] ?? '';
$httpsWarning = !is_https();
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Guard — Connexion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen flex items-center justify-center p-6 font-sans">

<div class="w-full max-w-md">

    <!-- En-tête -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center gap-2 bg-gray-800/60 rounded-full px-4 py-1.5 ring-1 ring-gray-700 text-sm text-gray-400 mb-6">
            <span class="w-2 h-2 rounded-full bg-indigo-400 inline-block"></span>
            Discord Guard
        </div>
        <h1 class="text-3xl font-bold text-white">Connexion</h1>
        <p class="text-gray-500 mt-2 text-sm">Authentifiez-vous avec votre Passkey</p>
    </div>

    <?php if ($httpsWarning): ?>
    <div class="bg-red-900/40 ring-1 ring-red-500/40 rounded-xl p-4 mb-6">
        <p class="text-red-400 text-sm font-medium">
            ⚠️ <strong>HTTPS requis</strong> — Les Passkeys ne fonctionnent qu'en HTTPS.
        </p>
    </div>
    <?php endif; ?>

    <?php if ($reason === 'timeout'): ?>
    <div class="bg-yellow-900/30 ring-1 ring-yellow-500/30 rounded-xl p-4 mb-6">
        <p class="text-yellow-400 text-sm">⏱️ Votre session a expiré. Veuillez vous reconnecter.</p>
    </div>
    <?php endif; ?>

    <!-- Carte -->
    <div class="bg-gray-900 rounded-2xl p-8 ring-1 ring-gray-800">

        <!-- Icône verrou -->
        <div class="flex justify-center mb-6">
            <div class="w-16 h-16 bg-indigo-600/20 ring-1 ring-indigo-500/40 rounded-2xl flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
        </div>

        <p class="text-gray-400 text-sm text-center mb-8 leading-relaxed">
            Aucun mot de passe requis. Utilisez votre empreinte digitale, Face ID
            ou clé de sécurité physique.
        </p>

        <!-- Message d'état -->
        <div id="status-box" class="hidden rounded-xl p-4 ring-1 mb-6 text-sm"></div>

        <!-- Bouton principal -->
        <button
            id="btn-login"
            onclick="startAuthentication()"
            class="w-full bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-semibold py-3 px-4 rounded-xl transition-colors duration-150 flex items-center justify-center gap-2"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            Se connecter avec une Passkey
        </button>

        <p id="compat-warning" class="hidden text-red-400 text-xs text-center mt-3">
            Votre navigateur ne prend pas en charge les Passkeys.
        </p>
    </div>

    <p class="text-center text-gray-700 text-xs mt-6">
        <a href="/" class="hover:text-gray-500 transition-colors">← Retour au statut public</a>
    </p>
</div>

<script>
// =============================================================================
// Utilitaires base64url
// =============================================================================
function b64url(buf) {
    return btoa(String.fromCharCode(...new Uint8Array(buf)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}
function fromB64url(str) {
    const b64 = str.replace(/-/g, '+').replace(/_/g, '/');
    const padded = b64.padEnd(b64.length + (4 - b64.length % 4) % 4, '=');
    const bin = atob(padded);
    const buf = new ArrayBuffer(bin.length);
    const v   = new Uint8Array(buf);
    for (let i = 0; i < bin.length; i++) v[i] = bin.charCodeAt(i);
    return buf;
}

// =============================================================================
// Vérification compatibilité
// =============================================================================
(function() {
    if (!window.PublicKeyCredential) {
        document.getElementById('compat-warning').classList.remove('hidden');
        const btn = document.getElementById('btn-login');
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
    }
})();

// =============================================================================
// Authentification par Passkey
// =============================================================================
async function startAuthentication() {
    const btn = document.getElementById('btn-login');
    btn.disabled = true;
    btn.textContent = 'Authentification en cours…';
    showStatus('info', '⏳ Récupération du challenge serveur…');

    try {
        // 1. Récupérer les options d'authentification
        const resp = await fetch('/admin/verify.php?action=challenge_login');
        if (!resp.ok) throw new Error('Impossible de contacter le serveur.');
        const options = await resp.json();
        if (options.error) throw new Error(options.error);

        // 2. Décoder les buffers
        options.challenge = fromB64url(options.challenge);
        if (options.allowCredentials) {
            options.allowCredentials = options.allowCredentials.map(c => ({
                ...c,
                id: fromB64url(c.id),
            }));
        }

        showStatus('info', '🔑 En attente de votre authentificateur…');

        // 3. Obtenir l'assertion
        const assertion = await navigator.credentials.get({ publicKey: options });

        showStatus('info', '📤 Vérification en cours…');

        // 4. Préparer le payload
        const payload = {
            id:   assertion.id,
            type: assertion.type,
            response: {
                clientDataJSON:    b64url(assertion.response.clientDataJSON),
                authenticatorData: b64url(assertion.response.authenticatorData),
                signature:         b64url(assertion.response.signature),
                userHandle: assertion.response.userHandle
                    ? b64url(assertion.response.userHandle)
                    : null,
            },
        };

        // 5. Envoyer pour vérification
        const verifyResp = await fetch('/admin/verify.php?action=authenticate', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const result = await verifyResp.json();

        if (result.success) {
            showStatus('success', '✅ Connexion réussie ! Redirection…');
            setTimeout(() => window.location.href = '/admin/dashboard.php', 800);
        } else {
            throw new Error(result.error || 'Échec de la vérification.');
        }

    } catch (err) {
        showStatus('error', '❌ ' + (err.message || 'Erreur inconnue.'));
        btn.disabled    = false;
        btn.textContent = 'Se connecter avec une Passkey';
    }
}

function showStatus(type, msg) {
    const box = document.getElementById('status-box');
    box.className = 'rounded-xl p-4 ring-1 mb-6 text-sm';
    const cls = {
        info:    'bg-blue-900/40 ring-blue-500/40 text-blue-300',
        success: 'bg-green-900/40 ring-green-500/40 text-green-300',
        error:   'bg-red-900/40 ring-red-500/40 text-red-300',
    };
    box.classList.add(...(cls[type] || cls.info).split(' '));
    box.textContent = msg;
    box.classList.remove('hidden');
}
</script>

</body>
</html>

<?php
/**
 * Discord Guard — Installation (enregistrement de la première Passkey)
 *
 * ⚠️  Cette page est automatiquement désactivée après le premier enregistrement.
 * ⚠️  HTTPS obligatoire — WebAuthn refuse de fonctionner en HTTP.
 */

require_once __DIR__ . '/../functions.php';
init_secure_session();
check_admin_ip();
require_secret_token();

// Si déjà installé, rediriger vers la connexion
if (is_installed()) {
    header('Location: /admin/login.php');
    exit;
}

// Forcer HTTPS en production
$httpsWarning = !is_https();
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Guard — Installation</title>
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
        <h1 class="text-3xl font-bold text-white">Installation</h1>
        <p class="text-gray-500 mt-2 text-sm">Configurez votre Passkey d'administration</p>
    </div>

    <?php if ($httpsWarning): ?>
    <!-- Alerte HTTPS -->
    <div class="bg-red-900/40 ring-1 ring-red-500/40 rounded-xl p-4 mb-6">
        <p class="text-red-400 text-sm font-medium">
            ⚠️ <strong>HTTPS requis</strong> — Les Passkeys (WebAuthn) ne fonctionnent
            qu'en HTTPS. Cette page peut ne pas fonctionner correctement sur HTTP.
        </p>
    </div>
    <?php endif; ?>

    <!-- Carte principale -->
    <div class="bg-gray-900 rounded-2xl p-8 ring-1 ring-gray-800">

        <!-- Étapes -->
        <div class="space-y-4 mb-8">
            <div class="flex items-start gap-3">
                <div class="w-6 h-6 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">1</div>
                <p class="text-gray-300 text-sm">Cliquez sur <strong class="text-white">Enregistrer ma Passkey</strong>.</p>
            </div>
            <div class="flex items-start gap-3">
                <div class="w-6 h-6 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">2</div>
                <p class="text-gray-300 text-sm">Suivez les instructions de votre navigateur (empreinte digitale, Face ID, clé de sécurité…).</p>
            </div>
            <div class="flex items-start gap-3">
                <div class="w-6 h-6 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5">3</div>
                <p class="text-gray-300 text-sm">Cette page sera <strong class="text-white">désactivée définitivement</strong> après l'enregistrement.</p>
            </div>
        </div>

        <!-- Message d'état -->
        <div id="status-box" class="hidden rounded-xl p-4 ring-1 mb-6 text-sm"></div>

        <!-- Bouton -->
        <button
            id="btn-register"
            onclick="startRegistration()"
            class="w-full bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-semibold py-3 px-4 rounded-xl transition-colors duration-150 flex items-center justify-center gap-2"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            Enregistrer ma Passkey
        </button>

        <!-- Vérification support navigateur -->
        <p id="compat-warning" class="hidden text-red-400 text-xs text-center mt-3">
            Votre navigateur ne supporte pas les Passkeys. Utilisez un navigateur moderne (Chrome, Firefox, Safari, Edge).
        </p>
    </div>

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
    const view = new Uint8Array(buf);
    for (let i = 0; i < bin.length; i++) view[i] = bin.charCodeAt(i);
    return buf;
}

// =============================================================================
// Vérification compatibilité
// =============================================================================
(function checkCompat() {
    if (!window.PublicKeyCredential) {
        document.getElementById('compat-warning').classList.remove('hidden');
        document.getElementById('btn-register').disabled = true;
        document.getElementById('btn-register').classList.add('opacity-50', 'cursor-not-allowed');
    }
})();

// =============================================================================
// Enregistrement de la Passkey
// =============================================================================
async function startRegistration() {
    const btn    = document.getElementById('btn-register');
    const box    = document.getElementById('status-box');

    btn.disabled = true;
    btn.textContent = 'Authentification en cours…';
    showStatus('info', '⏳ Récupération du challenge serveur…');

    try {
        // 1. Récupérer les options d'enregistrement
        const resp = await fetch('/admin/verify.php?action=challenge_register');
        if (!resp.ok) throw new Error('Impossible de contacter le serveur.');
        const options = await resp.json();
        if (options.error) throw new Error(options.error);

        // 2. Décoder les buffers
        options.challenge = fromB64url(options.challenge);
        options.user.id   = fromB64url(options.user.id);

        showStatus('info', '🔑 En attente de votre authentificateur…');

        // 3. Créer le credential
        const credential = await navigator.credentials.create({ publicKey: options });

        showStatus('info', '📤 Envoi au serveur pour vérification…');

        // 4. Préparer le payload
        const payload = {
            id:   credential.id,
            type: credential.type,
            response: {
                clientDataJSON:    b64url(credential.response.clientDataJSON),
                attestationObject: b64url(credential.response.attestationObject),
            },
        };

        // 5. Envoyer au serveur
        const verifyResp = await fetch('/admin/verify.php?action=register', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const result = await verifyResp.json();

        if (result.success) {
            showStatus('success', '✅ Passkey enregistrée avec succès ! Redirection vers la connexion…');
            setTimeout(() => window.location.href = '/admin/login.php', 1800);
        } else {
            throw new Error(result.error || 'Erreur lors de l\'enregistrement.');
        }

    } catch (err) {
        showStatus('error', '❌ ' + (err.message || 'Erreur inconnue.'));
        btn.disabled    = false;
        btn.textContent = 'Enregistrer ma Passkey';
    }
}

function showStatus(type, msg) {
    const box = document.getElementById('status-box');
    box.classList.remove('hidden', 'bg-blue-900/40', 'ring-blue-500/40', 'text-blue-300',
                                   'bg-green-900/40', 'ring-green-500/40', 'text-green-300',
                                   'bg-red-900/40',   'ring-red-500/40',   'text-red-300');
    const classes = {
        info:    ['bg-blue-900/40',  'ring-blue-500/40',  'text-blue-300'],
        success: ['bg-green-900/40', 'ring-green-500/40', 'text-green-300'],
        error:   ['bg-red-900/40',   'ring-red-500/40',   'text-red-300'],
    };
    box.classList.add(...(classes[type] || classes.info));
    box.textContent = msg;
    box.classList.remove('hidden');
}
</script>

</body>
</html>

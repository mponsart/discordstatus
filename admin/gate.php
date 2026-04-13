<?php
/**
 * Discord Guard — Porte d'entrée sécurisée (Gate)
 *
 * Cette page est le SEUL point d'entrée vers le panneau admin.
 * Elle vérifie le token secret et pose un cookie httponly sécurisé.
 * Sans ce cookie valide, toutes les autres pages /admin/ sont inaccessibles.
 *
 * ⚠️  Accessible sans token (c'est le formulaire de saisie).
 *     Mais 5 tentatives erronées ⇒ blocage IP 15 min.
 */

require_once __DIR__ . '/../functions.php';
init_secure_session();
check_admin_ip();

// Si le token secret n'a pas encore été configuré
if (str_starts_with(ADMIN_SECRET_TOKEN, 'CHANGEZ_MOI')) {
    http_response_code(503);
    exit('⚠️ Discord Guard non configuré : modifiez ADMIN_SECRET_TOKEN dans config.php.');
}

// Si le cookie est déjà valide → aller directement au login
if (hash_equals(ADMIN_SECRET_TOKEN, $_COOKIE['DGRD_ACCESS'] ?? '')) {
    header('Location: /admin/login.php');
    exit;
}

$error   = '';
$success = false;

// =============================================================================
// Traitement POST
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Anti-brute force sur la saisie du token
    check_brute_force();

    $submitted = trim($_POST['access_token'] ?? '');

    if (!hash_equals(ADMIN_SECRET_TOKEN, $submitted)) {
        record_failed_attempt();
        log_action('gate_failure');
        // Message volontairement vague pour ne pas aider un attaquant
        $error = 'Token incorrect.';
    } else {
        // Token valide : poser le cookie sécurisé
        clear_failed_attempts();
        log_action('gate_success');

        setcookie(
            'DGRD_ACCESS',
            ADMIN_SECRET_TOKEN,
            [
                'expires'  => 0,          // Cookie de session (expire à la fermeture du navigateur)
                'path'     => '/admin/',
                'domain'   => '',
                'secure'   => true,       // HTTPS uniquement
                'httponly' => true,       // Non lisible en JavaScript
                'samesite' => 'Strict',
            ]
        );
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Guard — Accès sécurisé</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen flex items-center justify-center p-6 font-sans">

<div class="w-full max-w-sm">

    <!-- En-tête -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center gap-2 bg-gray-800/60 rounded-full px-4 py-1.5 ring-1 ring-gray-700 text-sm text-gray-400 mb-6">
            <span class="w-2 h-2 rounded-full bg-indigo-400 inline-block"></span>
            Discord Guard
        </div>
        <h1 class="text-2xl font-bold text-white">Accès sécurisé</h1>
        <p class="text-gray-500 mt-2 text-sm">Saisissez votre clé d'accès admin</p>
    </div>

    <?php if ($success): ?>
    <!-- Succès : redirection automatique -->
    <div class="bg-green-900/40 ring-1 ring-green-500/40 rounded-2xl p-6 text-center">
        <p class="text-green-300 text-sm font-medium mb-3">✅ Clé valide. Redirection…</p>
        <div class="w-full bg-gray-800 rounded-full h-1.5 overflow-hidden">
            <div class="bg-green-400 h-1.5 rounded-full animate-pulse w-full"></div>
        </div>
    </div>
    <script>
        setTimeout(() => window.location.href = '/admin/login.php', 600);
    </script>

    <?php else: ?>
    <!-- Formulaire -->
    <div class="bg-gray-900 rounded-2xl p-8 ring-1 ring-gray-800">

        <!-- Icône -->
        <div class="flex justify-center mb-6">
            <div class="w-14 h-14 bg-gray-800 ring-1 ring-gray-700 rounded-xl flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-900/40 ring-1 ring-red-500/40 rounded-xl p-3 mb-5">
            <p class="text-red-400 text-sm text-center">❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <?php endif; ?>

        <form method="POST" action="/admin/gate.php" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-5">
                <label for="access_token" class="block text-sm font-medium text-gray-400 mb-2">
                    Clé d'accès
                </label>
                <input
                    type="password"
                    id="access_token"
                    name="access_token"
                    placeholder="••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••••"
                    autocomplete="off"
                    autofocus
                    required
                    minlength="16"
                    class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-3 text-sm text-gray-100 placeholder-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent font-mono"
                >
                <p class="text-gray-600 text-xs mt-1.5">
                    Token défini dans <code class="text-gray-500">config.php</code> (ADMIN_SECRET_TOKEN).
                </p>
            </div>

            <button
                type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-semibold py-3 px-4 rounded-xl transition-colors duration-150"
            >
                Accéder au panneau admin
            </button>
        </form>

        <p class="text-gray-700 text-xs text-center mt-5">
            5 tentatives incorrectes entraînent un blocage temporaire de 15 min.
        </p>
    </div>

    <p class="text-center text-gray-700 text-xs mt-6">
        <a href="/" class="hover:text-gray-500 transition-colors">← Retour au statut public</a>
    </p>
    <?php endif; ?>

</div>

</body>
</html>

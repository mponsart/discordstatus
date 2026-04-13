<?php
/**
 * Discord Guard — Dashboard administrateur
 *
 * Accessible uniquement après authentification via Passkey.
 */

require_once __DIR__ . '/../functions.php';
init_secure_session();
check_admin_ip();
require_admin();

// Données
$statusData  = get_status();
$status      = $statusData['status'];
$logs        = get_logs(50);
$alerts      = get_alerts(30);
$settings    = ['discord_webhook' => get_setting('discord_webhook', ''), 'customMessage' => get_setting('custom_message', '')];
$credentials = get_credentials();
$profile     = get_discord_profile();
$totalLogs   = count_table('logs');
$totalAlerts = count_table('alerts');

// Données CV
$cv = [
    'title'       => get_setting('cv_title',       ''),
    'tagline'     => get_setting('cv_tagline',      ''),
    'email'       => get_setting('cv_email',        ''),
    'github'      => get_setting('cv_github',       ''),
    'linkedin'    => get_setting('cv_linkedin',     ''),
    'skills'      => json_decode(get_setting('cv_skills',      '[]'), true) ?: [],
    'experiences' => json_decode(get_setting('cv_experiences', '[]'), true) ?: [],
    'education'   => json_decode(get_setting('cv_education',   '[]'), true) ?: [],
    'projects'    => json_decode(get_setting('cv_projects',    '[]'), true) ?: [],
];

// CSRF token
$csrf = generate_csrf_token();

// Log de cette visite
log_action('dashboard_view');

// Flash messages
$flash = ''; $flashType = 'success';
$s = $_GET['success'] ?? '';
if ($s !== '') {
    $flashMap = [
        'status_updated' => 'Statut mis à jour.',
        'settings_saved' => 'Paramètres enregistrés.',
        'profile_saved'  => 'Profil enregistré.',
        'logs_cleared'   => 'Logs supprimés.',
        'alerts_cleared' => 'Alertes supprimées.',
        'status_reset'   => 'Statut réinitialisé à « Sécurisé ».',
        'monitor_ok'     => 'Vérification OK — aucun changement détecté.',
        'cv_saved'       => 'CV enregistré.',
        'cv_error'       => 'Erreur lors de la sauvegarde du CV.',
        'invalid_url'    => 'URL invalide.',
        'invalid_email'  => 'Adresse e-mail invalide.',
    ];
    $flash = $flashMap[$s] ?? '';
    if ($flash === '' && preg_match('/^monitor_changes_(\d+)$/', $s, $m)) {
        $flash = $m[1] . ' changement(s) détecté(s) — voir les alertes.';
        $flashType = 'warning';
    }
}

// Config visuelle
$statusCfg = [
    'secure'      => ['label' => 'Compte sécurisé',   'icon' => '🛡️', 'class' => 'text-emerald-400', 'bg' => 'bg-emerald-900/30', 'ring' => 'ring-emerald-500/40'],
    'warning'     => ['label' => 'Activité suspecte',  'icon' => '⚠️', 'class' => 'text-yellow-400',  'bg' => 'bg-yellow-900/30',  'ring' => 'ring-yellow-500/40'],
    'compromised' => ['label' => 'Compte compromis',   'icon' => '🚨', 'class' => 'text-red-400',     'bg' => 'bg-red-900/30',     'ring' => 'ring-red-500/40'],
];
$sc = $statusCfg[$status] ?? $statusCfg['secure'];
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Guard — Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <style>
        .tab-btn.active { border-color: rgb(99,102,241); color: white; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen font-sans">

<!-- ======================================================
     NAVBAR
     ====================================================== -->
<nav class="bg-gray-900 border-b border-gray-800 sticky top-0 z-50">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-7 h-7 bg-indigo-600 rounded-lg flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <span class="font-semibold text-white">Discord Guard</span>
            <span class="text-gray-600 text-sm hidden sm:inline">— Dashboard</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="/" target="_blank" class="text-gray-500 hover:text-gray-300 text-xs transition-colors hidden sm:block">
                Page publique ↗
            </a>
            <a href="/admin/logout.php"
               onclick="return confirm('Se déconnecter ?')"
               class="bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm px-3 py-1.5 rounded-lg ring-1 ring-gray-700 transition-colors">
                Déconnexion
            </a>
        </div>
    </div>
</nav>

<!-- ======================================================
     CORPS
     ====================================================== -->
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-8 space-y-8">

    <?php if ($flash): ?>
    <div class="rounded-xl px-4 py-3 text-sm font-semibold ring-1 flex items-center gap-2
        <?= $flashType === 'warning' ? 'bg-yellow-950/50 ring-yellow-500/30 text-yellow-300' : 'bg-emerald-950/50 ring-emerald-500/30 text-emerald-300' ?>">
        <?= $flashType === 'warning' ? '⚠️' : '✅' ?> <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- ── Statut courant ── -->
    <section>
        <h2 class="text-xs font-semibold uppercase tracking-widest text-gray-500 mb-4">Statut courant</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

            <!-- Carte statut -->
            <div class="sm:col-span-2 bg-gray-900 ring-1 ring-gray-800 rounded-2xl p-6 flex items-center gap-5">
                <div class="w-14 h-14 rounded-xl <?= $sc['bg'] ?> ring-1 <?= $sc['ring'] ?> flex items-center justify-center text-2xl flex-shrink-0">
                    <?= $sc['icon'] ?>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Statut actuel</p>
                    <p class="text-xl font-bold <?= $sc['class'] ?> mt-0.5">
                        <?= htmlspecialchars($sc['label'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <p class="text-xs text-gray-600 mt-1">
                        Mis à jour : <?= htmlspecialchars(format_date($statusData['lastUpdated']), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>

            <!-- Stats rapides -->
            <div class="bg-gray-900 ring-1 ring-gray-800 rounded-2xl p-6 flex flex-col justify-between">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Logs totaux</span>
                    <span class="text-white font-semibold"><?= $totalLogs ?></span>
                </div>
                <div class="flex justify-between text-sm mt-3">
                    <span class="text-gray-500">Alertes</span>
                    <span class="text-yellow-400 font-semibold"><?= $totalAlerts ?></span>
                </div>
                <div class="flex justify-between text-sm mt-3">
                    <span class="text-gray-500">Passkeys</span>
                    <span class="text-indigo-400 font-semibold"><?= count($credentials) ?></span>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Actions de statut ── -->
    <section>
        <h2 class="text-xs font-semibold uppercase tracking-widest text-gray-500 mb-4">Modifier le statut</h2>
        <div class="bg-gray-900 ring-1 ring-gray-800 rounded-2xl p-6">
            <p class="text-gray-400 text-sm mb-5">
                Sélectionnez le statut à afficher sur la page publique.
                Un webhook Discord sera envoyé si configuré.
            </p>
            <form method="POST" action="/admin/actions.php" class="flex flex-wrap gap-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="set_status">

                <button type="submit" name="status" value="secure"
                    class="flex items-center gap-2 <?= $status === 'secure' ? 'bg-green-700 ring-green-500' : 'bg-gray-800 ring-gray-700 hover:bg-green-900/50 hover:ring-green-600' ?> ring-1 text-white font-medium px-4 py-2.5 rounded-xl transition-colors text-sm">
                    🟢 Tout est normal
                </button>
                <button type="submit" name="status" value="warning"
                    class="flex items-center gap-2 <?= $status === 'warning' ? 'bg-yellow-700 ring-yellow-500' : 'bg-gray-800 ring-gray-700 hover:bg-yellow-900/50 hover:ring-yellow-600' ?> ring-1 text-white font-medium px-4 py-2.5 rounded-xl transition-colors text-sm">
                    🟠 Activité suspecte
                </button>
                <button type="submit" name="status" value="compromised"
                    class="flex items-center gap-2 <?= $status === 'compromised' ? 'bg-red-700 ring-red-500' : 'bg-gray-800 ring-gray-700 hover:bg-red-900/50 hover:ring-red-600' ?> ring-1 text-white font-medium px-4 py-2.5 rounded-xl transition-colors text-sm">
                    🔴 Compte compromis
                </button>
            </form>
        </div>
    </section>

    <!-- ── Onglets : Logs / Alertes / Paramètres ── -->
    <section>
        <!-- Onglets -->
        <div class="flex gap-1 border-b border-gray-800 mb-6">
            <button onclick="showTab('logs')"    id="tab-logs"    class="tab-btn active px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 transition-colors">
                Logs <span class="ml-1.5 bg-gray-800 text-gray-400 rounded-full px-1.5 py-0.5 text-xs"><?= $totalLogs ?></span>
            </button>
            <button onclick="showTab('alerts')"  id="tab-alerts"  class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 transition-colors">
                Alertes <?php if ($alerts): ?><span class="ml-1.5 bg-yellow-900 text-yellow-400 rounded-full px-1.5 py-0.5 text-xs"><?= $totalAlerts ?></span><?php endif; ?>
            </button>
            <button onclick="showTab('settings')" id="tab-settings" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 transition-colors">
                Paramètres
            </button>
            <button onclick="showTab('profile')" id="tab-profile" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 transition-colors">
                Profil Discord
            </button>
            <button onclick="showTab('cv')" id="tab-cv" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 transition-colors">
                CV public
            </button>
        </div>

        <!-- ── Onglet Logs ── -->
        <div id="panel-logs" class="tab-panel active">
            <?php if (empty($logs)): ?>
                <p class="text-gray-600 text-sm text-center py-12">Aucun log enregistré.</p>
            <?php else: ?>
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-800/60">
                        <tr>
                            <th class="text-left px-4 py-3 text-gray-400 font-medium">Date</th>
                            <th class="text-left px-4 py-3 text-gray-400 font-medium">IP</th>
                            <th class="text-left px-4 py-3 text-gray-400 font-medium">Action</th>
                            <th class="text-left px-4 py-3 text-gray-400 font-medium hidden md:table-cell">User-Agent</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php foreach (array_slice($logs, 0, 50) as $log): ?>
                        <tr class="hover:bg-gray-800/40 transition-colors">
                            <td class="px-4 py-3 text-gray-400 whitespace-nowrap text-xs">
                                <?= htmlspecialchars(format_date($log['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-indigo-300">
                                <?= htmlspecialchars($log['ip'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php
                                $actionLabel = match ($log['action'] ?? '') {
                                    'admin_login_success'    => '<span class="text-emerald-400">✅ Connexion</span>',
                                    'admin_login_failure'    => '<span class="text-red-400">❌ Échec connexion</span>',
                                    'admin_logout'           => '<span class="text-gray-400">🚪 Déconnexion</span>',
                                    'dashboard_view'         => '<span class="text-gray-600">👁 Dashboard</span>',
                                    'status_changed'         => '<span class="text-yellow-400">⚙️ Statut modifié</span>',
                                    'status_reset_to_secure' => '<span class="text-emerald-400">🛡️ Statut réinitialisé</span>',
                                    'passkey_registered'     => '<span class="text-indigo-400">🔑 Passkey enregistrée</span>',
                                    'passkey_added'          => '<span class="text-indigo-300">🔑 Passkey ajoutée</span>',
                                    'profile_updated'        => '<span class="text-blue-400">👤 Profil MAJ</span>',
                                    'settings_updated'       => '<span class="text-blue-300">⚙️ Paramètres MAJ</span>',
                                    'logs_cleared'           => '<span class="text-orange-400">🗑 Logs purgés</span>',
                                    'alerts_cleared'         => '<span class="text-orange-400">🗑 Alertes purgées</span>',
                                    'monitor_manual_run'     => '<span class="text-violet-400">🤖 Vérification manuelle</span>',
                                    default                  => '<span class="text-gray-500">' . htmlspecialchars($log['action'] ?? '', ENT_QUOTES, 'UTF-8') . '</span>',
                                };
                                echo $actionLabel;
                                ?>
                            </td>
                            <td class="px-4 py-3 text-gray-500 text-xs hidden md:table-cell max-w-xs truncate">
                                <?= htmlspecialchars(substr($log['user_agent'] ?? '', 0, 80), ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($logs) > 50): ?>
            <p class="text-gray-600 text-xs text-center mt-3">
                Affichage des 50 derniers logs sur <?= $totalLogs ?> enregistrés.
            </p>
            <?php endif; ?>

            <!-- Bouton purge logs -->
            <form method="POST" action="/admin/actions.php" class="mt-4" onsubmit="return confirm('Supprimer tous les logs ?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="clear_logs">
                <button type="submit" class="text-xs text-gray-500 hover:text-red-400 transition-colors">
                    🗑 Vider les logs
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- ── Onglet Alertes ── -->
        <div id="panel-alerts" class="tab-panel">
            <?php if (empty($alerts)): ?>
                <p class="text-gray-600 text-sm text-center py-12">Aucune alerte enregistrée.</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach (array_slice($alerts, 0, 30) as $alert): ?>
                <div class="bg-yellow-900/20 ring-1 ring-yellow-500/20 rounded-xl p-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2">
                        <span class="text-yellow-400 font-medium text-sm">
                            ⚠️ <?= htmlspecialchars($alert['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span class="text-gray-500 text-xs">
                            <?= htmlspecialchars(format_date($alert['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                        <div>
                            <span class="text-gray-500">IP : </span>
                            <span class="text-indigo-300 font-mono"><?= htmlspecialchars($alert['ip'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if (!empty($alert['data']['country'])): ?>
                        <div>
                            <span class="text-gray-500">Pays : </span>
                            <span class="text-gray-300"><?= htmlspecialchars($alert['data']['country'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($alert['data']['anomalies'])): ?>
                        <div class="sm:col-span-2">
                            <span class="text-gray-500">Anomalies : </span>
                            <span class="text-red-400"><?= htmlspecialchars(implode(', ', $alert['data']['anomalies']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Bouton purge alertes -->
            <form method="POST" action="/admin/actions.php" class="mt-4" onsubmit="return confirm('Supprimer toutes les alertes ?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="clear_alerts">
                <button type="submit" class="text-xs text-gray-500 hover:text-red-400 transition-colors">
                    🗑 Vider les alertes
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- ── Onglet Paramètres ── -->
        <div id="panel-settings" class="tab-panel">
            <form method="POST" action="/admin/actions.php" class="space-y-6 max-w-xl">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action"     value="update_settings">

                <!-- Webhook Discord -->
                <div>
                    <label for="discord_webhook" class="block text-sm font-medium text-gray-300 mb-1.5">
                        Webhook Discord
                    </label>
                    <input
                        type="url"
                        id="discord_webhook"
                        name="discord_webhook"
                        placeholder="https://discord.com/api/webhooks/…"
                        value="<?= htmlspecialchars($settings['discord_webhook'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-2.5 text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                    >
                    <p class="text-gray-600 text-xs mt-1">URL du webhook pour les notifications d'alerte et de changement de statut.</p>
                </div>

                <!-- Message personnalisé -->
                <div>
                    <label for="custom_message" class="block text-sm font-medium text-gray-300 mb-1.5">
                        Message public (affiché en cas d'alerte)
                    </label>
                    <textarea
                        id="custom_message"
                        name="custom_message"
                        rows="2"
                        maxlength="500"
                        class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-2.5 text-sm text-gray-100 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none"
                    ><?= htmlspecialchars($settings['customMessage'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <p class="text-gray-600 text-xs mt-1">Affiché sur la page publique quand le statut est « warning » ou « compromis ».</p>
                </div>

                <button type="submit"
                    class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-5 py-2.5 rounded-xl text-sm transition-colors">
                    Enregistrer les paramètres
                </button>
            </form>

            <!-- Passkeys enregistrées -->
            <div class="mt-8 pt-6 border-t border-gray-800">
                <h3 class="text-sm font-medium text-gray-300 mb-4">Passkeys enregistrées</h3>
                <?php if (empty($credentials)): ?>
                    <p class="text-gray-600 text-sm">Aucune Passkey.</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($credentials as $cred): ?>
                    <div class="flex items-center justify-between bg-gray-800/60 ring-1 ring-gray-700 rounded-xl px-4 py-3">
                        <div>
                            <p class="text-sm text-white font-medium">
                                🔑 <?= htmlspecialchars($cred['name'] ?? 'Passkey', ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                Enregistrée le <?= htmlspecialchars(format_date($cred['createdAt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                — Dernière utilisation : <?= htmlspecialchars(format_date($cred['lastUsed'] ?? null), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                        <span class="text-xs font-mono text-gray-600" title="ID de credential">
                            <?= htmlspecialchars(substr($cred['id'] ?? '', 0, 12), ENT_QUOTES, 'UTF-8') ?>…
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Ajouter une Passkey -->
            <div class="mt-4">
                <button id="btn-add-passkey"
                    onclick="addPasskey()"
                    class="bg-indigo-700 hover:bg-indigo-600 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors">
                    + Ajouter une Passkey
                </button>
                <div id="add-passkey-status" class="hidden mt-3 ring-1 rounded-xl px-4 py-2.5 text-sm"></div>
            </div>
        </div>

        <!-- ── Onglet Profil Discord ── -->
        <div id="panel-profile" class="tab-panel">

            <?php
            // Données monitoring pour cet onglet
            $botToken   = defined('DISCORD_BOT_TOKEN')      ? DISCORD_BOT_TOKEN      : '';
            $botUserId  = defined('DISCORD_TARGET_USER_ID') ? DISCORD_TARGET_USER_ID : '';
            $botGuildId = defined('DISCORD_GUILD_ID')       ? DISCORD_GUILD_ID       : '';
            $botCfg     = $botToken !== '' && $botUserId !== '' && $botGuildId !== '';
            $monStatus  = get_setting('discord_monitor_status', 'not_configured');
            $lastChk    = get_setting('discord_last_check');
            $chkCount   = (int) get_setting('discord_check_count',   '0');
            $anomCount  = (int) get_setting('discord_anomaly_count', '0');
            $snapUser   = get_setting('discord_username',    '');
            $snapGlobal = get_setting('discord_global_name', '');
            $snapAvatar = get_setting('discord_avatar_url',  '');
            $currentStatus = get_setting('status', 'secure');
            ?>

            <!-- ── Bloc configuration bot ── -->
            <?php if (!$botCfg): ?>
            <div class="mb-6 bg-amber-950/40 ring-1 ring-amber-700/40 rounded-xl px-4 py-4">
                <p class="text-sm font-semibold text-amber-300 mb-2">⚙️ Bot Discord non configuré</p>
                <p class="text-xs text-amber-500/80 leading-relaxed mb-3">
                    Pour activer la surveillance automatique, renseignez ces trois constantes dans
                    <code class="bg-white/[.06] rounded px-1">config.php</code> :
                </p>
                <ul class="space-y-1.5 text-xs text-amber-600/80 font-mono">
                    <li><span class="text-amber-400">DISCORD_BOT_TOKEN</span> — token du bot (discord.com/developers/applications)</li>
                    <li><span class="text-amber-400">DISCORD_TARGET_USER_ID</span> — votre ID Discord (clic droit → Copier l'identifiant)</li>
                    <li><span class="text-amber-400">DISCORD_GUILD_ID</span> — ID d'un serveur commun entre le bot et vous</li>
                </ul>
                <p class="text-xs text-amber-700/70 mt-3 leading-relaxed">
                    URL cron HTTP : <code class="text-amber-500 bg-black/20 rounded px-1">https://maximeponsart.fr/monitor.php?token=CRON_SECRET</code>
                </p>
            </div>
            <?php else: ?>
            <!-- ── Statut bot + snapshot ── -->
            <div class="mb-6 grid grid-cols-1 sm:grid-cols-3 gap-3">

                <!-- Statut API -->
                <div class="bg-gray-800/60 ring-1 ring-gray-700 rounded-xl px-4 py-3">
                    <p class="text-xs text-gray-500 mb-1 uppercase tracking-wider font-medium">Statut API</p>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full flex-shrink-0
                                     <?= $monStatus === 'ok' ? 'bg-green-400' : 'bg-red-400' ?>"></span>
                        <span class="text-sm font-semibold <?= $monStatus === 'ok' ? 'text-green-300' : 'text-red-300' ?>">
                            <?= $monStatus === 'ok' ? 'Connecté' : htmlspecialchars($monStatus, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <p class="text-xs text-gray-600 mt-1">
                        <?= $chkCount ?> vérif. · <?= $anomCount ?> anomalie(s)
                    </p>
                </div>

                <!-- Dernière vérification -->
                <div class="bg-gray-800/60 ring-1 ring-gray-700 rounded-xl px-4 py-3">
                    <p class="text-xs text-gray-500 mb-1 uppercase tracking-wider font-medium">Dernière vérif.</p>
                    <p class="text-sm font-semibold text-white">
                        <?= $lastChk ? htmlspecialchars(format_date($lastChk), ENT_QUOTES, 'UTF-8') : 'Aucune' ?>
                    </p>
                    <p class="text-xs text-gray-600 mt-1">fréquence cron ≈ 5 min</p>
                </div>

                <!-- Snapshot actuel -->
                <div class="bg-gray-800/60 ring-1 ring-gray-700 rounded-xl px-4 py-3">
                    <p class="text-xs text-gray-500 mb-2 uppercase tracking-wider font-medium">Snapshot Discord</p>
                    <?php if ($snapUser): ?>
                    <div class="flex items-center gap-2">
                        <?php if ($snapAvatar): ?>
                        <img src="<?= htmlspecialchars($snapAvatar, ENT_QUOTES, 'UTF-8') ?>"
                             alt="" class="w-7 h-7 rounded-full bg-gray-700"
                             onerror="this.style.display='none'">
                        <?php endif; ?>
                        <div class="min-w-0">
                            <p class="text-sm text-white truncate font-medium">
                                <?= htmlspecialchars($snapGlobal ?: $snapUser, ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <?php if ($snapGlobal && $snapGlobal !== $snapUser): ?>
                            <p class="text-xs text-gray-600 font-mono">@<?= htmlspecialchars($snapUser, ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="text-xs text-gray-600">En attente du 1er cycle cron</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Actions monitoring ── -->
            <div class="flex flex-wrap gap-3 mb-6">
                <!-- Vérifier maintenant -->
                <form method="POST" action="/admin/actions.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="run_monitor">
                    <button type="submit" <?= !$botCfg ? 'disabled title="Configurez d\'abord le bot dans config.php"' : '' ?>
                            class="flex items-center gap-2 bg-indigo-700 hover:bg-indigo-600 disabled:opacity-40
                                   disabled:cursor-not-allowed text-white font-semibold px-4 py-2 rounded-xl text-sm
                                   transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/>
                        </svg>
                        Vérifier maintenant
                    </button>
                </form>

                <!-- Marquer comme sécurisé -->
                <?php if ($currentStatus !== 'secure'): ?>
                <form method="POST" action="/admin/actions.php"
                      onsubmit="return confirm('Confirmer : réinitialiser le statut à « Sécurisé » ?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="reset_to_secure">
                    <button type="submit"
                            class="flex items-center gap-2 bg-emerald-700 hover:bg-emerald-600 text-white
                                   font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        Marquer comme sécurisé
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- ── Champs manuels (non auto-remplis par le bot) ── -->
            <div class="border-t border-gray-800 pt-6">
                <h4 class="text-sm font-semibold text-gray-300 mb-1">Informations manuelles</h4>
                <p class="text-xs text-gray-600 mb-4">
                    Le bot remplit automatiquement le nom d'utilisateur et l'avatar.
                    Seuls la bio et la date d'inscription compte nécessitent une saisie manuelle.
                </p>

                <form method="POST" action="/admin/actions.php" class="space-y-4 max-w-xl">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action"     value="update_profile">

                    <!-- Bio (non disponible via API Discord pour les bots) -->
                    <div>
                        <label for="discord_bio" class="block text-sm font-medium text-gray-300 mb-1.5">
                            Bio <span class="text-gray-600 font-normal">(non accessible via API — saisie manuelle)</span>
                        </label>
                        <textarea id="discord_bio" name="discord_bio" rows="2" maxlength="300"
                                  placeholder="Votre description publique…"
                                  class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-2.5 text-sm
                                         text-gray-100 placeholder-gray-600 focus:outline-none focus:ring-2
                                         focus:ring-indigo-500 focus:border-transparent resize-none"
                        ><?= htmlspecialchars($profile['bio'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <!-- Date de création du compte -->
                    <div>
                        <label for="discord_joined" class="block text-sm font-medium text-gray-300 mb-1.5">
                            Compte Discord créé en
                        </label>
                        <input type="text" id="discord_joined" name="discord_joined" maxlength="30"
                               value="<?= htmlspecialchars($profile['joined'], ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="janvier 2020"
                               class="w-full bg-gray-800 border border-gray-700 rounded-xl px-4 py-2.5 text-sm
                                      text-gray-100 placeholder-gray-600 focus:outline-none focus:ring-2
                                      focus:ring-indigo-500 focus:border-transparent">
                        <p class="text-gray-600 text-xs mt-1">Affiché tel quel. La date de rejoint-serveur est auto-détectée par le bot.</p>
                    </div>

                    <!-- Afficher l'historique public -->
                    <div class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" id="show_public_history" name="show_public_history" value="1"
                               <?= $profile['show_history'] ? 'checked' : '' ?>
                               class="w-4 h-4 rounded bg-gray-700 border-gray-600 text-indigo-500 focus:ring-indigo-500">
                        <label for="show_public_history" class="text-sm text-gray-300 cursor-pointer">
                            Afficher l'historique des événements sur la page publique
                        </label>
                    </div>

                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold
                                   px-5 py-2.5 rounded-xl text-sm transition-colors">
                        Enregistrer
                    </button>
                </form>
            </div>
        </div>

        <!-- ── Onglet CV public ── -->
        <div id="panel-cv" class="tab-panel">
            <form method="POST" action="/admin/actions.php" id="form-cv">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_cv">
                <input type="hidden" name="cv_skills"      id="input-cv-skills"      value="<?= htmlspecialchars(json_encode($cv['skills'],      JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="cv_experiences" id="input-cv-experiences" value="<?= htmlspecialchars(json_encode($cv['experiences'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="cv_education"   id="input-cv-education"   value="<?= htmlspecialchars(json_encode($cv['education'],   JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="cv_projects"    id="input-cv-projects"    value="<?= htmlspecialchars(json_encode($cv['projects'],    JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">

                <div class="space-y-8">

                    <!-- Infos générales -->
                    <div class="bg-gray-900 rounded-2xl p-6 ring-1 ring-gray-800">
                        <h3 class="text-sm font-semibold text-white mb-4">Informations générales</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Titre / poste</label>
                                <input type="text" name="cv_title" maxlength="100"
                                       value="<?= htmlspecialchars($cv['title'], ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="ex : Développeur web full stack"
                                       class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">E-mail public</label>
                                <input type="email" name="cv_email" maxlength="200"
                                       value="<?= htmlspecialchars($cv['email'], ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="contact@exemple.fr"
                                       class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">GitHub (URL)</label>
                                <input type="url" name="cv_github" maxlength="200"
                                       value="<?= htmlspecialchars($cv['github'], ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="https://github.com/…"
                                       class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">LinkedIn (URL)</label>
                                <input type="url" name="cv_linkedin" maxlength="200"
                                       value="<?= htmlspecialchars($cv['linkedin'], ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="https://linkedin.com/in/…"
                                       class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs text-gray-400 mb-1">Accroche (tagline)</label>
                                <textarea name="cv_tagline" maxlength="300" rows="2"
                                          placeholder="Passionné de développement web, spécialisé en PHP et JavaScript…"
                                          class="w-full bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-sm text-white placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 resize-none"><?= htmlspecialchars($cv['tagline'], ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Compétences -->
                    <div class="bg-gray-900 rounded-2xl p-6 ring-1 ring-gray-800">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-white">Compétences</h3>
                            <button type="button" onclick="cvAddSkill()"
                                    class="text-xs bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-400 ring-1 ring-indigo-500/30 rounded-lg px-3 py-1.5 transition-colors">
                                + Ajouter
                            </button>
                        </div>
                        <div id="cv-skills" class="flex flex-wrap gap-2">
                            <?php foreach ($cv['skills'] as $skill): ?>
                            <div class="cv-skill-tag flex items-center gap-1.5 bg-gray-800 ring-1 ring-gray-700 rounded-lg px-2 py-1">
                                <input type="text" maxlength="40" value="<?= htmlspecialchars($skill, ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="Compétence"
                                       class="bg-transparent text-sm text-white w-24 focus:outline-none focus:w-32 transition-all">
                                <button type="button" onclick="this.closest('.cv-skill-tag').remove()"
                                        class="text-gray-600 hover:text-red-400 text-xs leading-none transition-colors">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Expériences -->
                    <div class="bg-gray-900 rounded-2xl p-6 ring-1 ring-gray-800">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-white">Expériences professionnelles</h3>
                            <button type="button" onclick="cvAddExp()"
                                    class="text-xs bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-400 ring-1 ring-indigo-500/30 rounded-lg px-3 py-1.5 transition-colors">
                                + Ajouter
                            </button>
                        </div>
                        <div id="cv-experiences" class="space-y-4">
                            <?php foreach ($cv['experiences'] as $exp): ?>
                            <div class="cv-item bg-gray-800 rounded-xl p-4 ring-1 ring-gray-700">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <input type="text" data-field="title" maxlength="100" value="<?= htmlspecialchars($exp['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Intitulé du poste"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <input type="text" data-field="company" maxlength="100" value="<?= htmlspecialchars($exp['company'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Entreprise"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <input type="text" data-field="dates" maxlength="60" value="<?= htmlspecialchars($exp['dates'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="ex : Janv. 2023 – Présent"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <button type="button" onclick="this.closest('.cv-item').remove()"
                                            class="text-red-500/70 hover:text-red-400 text-xs ring-1 ring-red-500/20 rounded-lg px-3 py-1.5 transition-colors text-left sm:text-center">
                                        Supprimer
                                    </button>
                                </div>
                                <textarea data-field="description" rows="2" maxlength="400" placeholder="Description (optionnel)"
                                          class="w-full bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 resize-none"><?= htmlspecialchars($exp['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Formation -->
                    <div class="bg-gray-900 rounded-2xl p-6 ring-1 ring-gray-800">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-white">Formation</h3>
                            <button type="button" onclick="cvAddEdu()"
                                    class="text-xs bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-400 ring-1 ring-indigo-500/30 rounded-lg px-3 py-1.5 transition-colors">
                                + Ajouter
                            </button>
                        </div>
                        <div id="cv-education" class="space-y-4">
                            <?php foreach ($cv['education'] as $edu): ?>
                            <div class="cv-item bg-gray-800 rounded-xl p-4 ring-1 ring-gray-700">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <input type="text" data-field="degree" maxlength="100" value="<?= htmlspecialchars($edu['degree'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Diplôme / filière"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <input type="text" data-field="school" maxlength="100" value="<?= htmlspecialchars($edu['school'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="École / université"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <input type="text" data-field="dates" maxlength="60" value="<?= htmlspecialchars($edu['dates'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="ex : 2020 – 2023"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <button type="button" onclick="this.closest('.cv-item').remove()"
                                            class="text-red-500/70 hover:text-red-400 text-xs ring-1 ring-red-500/20 rounded-lg px-3 py-1.5 transition-colors text-left sm:text-center">
                                        Supprimer
                                    </button>
                                </div>
                                <textarea data-field="description" rows="2" maxlength="300" placeholder="Description (optionnel)"
                                          class="w-full bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 resize-none"><?= htmlspecialchars($edu['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Projets -->
                    <div class="bg-gray-900 rounded-2xl p-6 ring-1 ring-gray-800">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-white">Projets</h3>
                            <button type="button" onclick="cvAddProject()"
                                    class="text-xs bg-indigo-600/20 hover:bg-indigo-600/40 text-indigo-400 ring-1 ring-indigo-500/30 rounded-lg px-3 py-1.5 transition-colors">
                                + Ajouter
                            </button>
                        </div>
                        <div id="cv-projects" class="space-y-4">
                            <?php foreach ($cv['projects'] as $proj): ?>
                            <div class="cv-item bg-gray-800 rounded-xl p-4 ring-1 ring-gray-700">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <input type="text" data-field="title" maxlength="100" value="<?= htmlspecialchars($proj['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Nom du projet"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <input type="url" data-field="url" maxlength="200" value="<?= htmlspecialchars($proj['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="URL (optionnel)"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <input type="text" data-field="tech" maxlength="150" value="<?= htmlspecialchars($proj['tech'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Stack (PHP, JS, …)"
                                           class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <button type="button" onclick="this.closest('.cv-item').remove()"
                                            class="text-red-500/70 hover:text-red-400 text-xs ring-1 ring-red-500/20 rounded-lg px-3 py-1.5 transition-colors text-left sm:text-center">
                                        Supprimer
                                    </button>
                                </div>
                                <textarea data-field="description" rows="2" maxlength="400" placeholder="Description du projet"
                                          class="w-full bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 resize-none"><?= htmlspecialchars($proj['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" id="btn-save-cv"
                            class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-xl px-4 py-2.5 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        Enregistrer le CV
                    </button>

                </div>
            </form>
        </div>

    </section>

</main>

<!-- ======================================================
     Pied de page
     ====================================================== -->
<footer class="border-t border-gray-900 mt-12 py-6 text-center text-gray-700 text-xs">
    Discord Guard &nbsp;·&nbsp; Session sécurisée via Passkey (WebAuthn)
</footer>

<script>
const TABS = ['logs', 'alerts', 'settings', 'profile', 'cv'];

function showTab(name) {
    TABS.forEach(t => {
        document.getElementById('tab-'   + t).classList.remove('active');
        document.getElementById('panel-' + t).classList.remove('active');
    });
    document.getElementById('tab-'   + name).classList.add('active');
    document.getElementById('panel-' + name).classList.add('active');
}

// Ouverture automatique de l'onglet depuis le paramètre URL ?tab=xxx
(function () {
    const p = new URLSearchParams(location.search).get('tab');
    if (p && TABS.includes(p)) showTab(p);
})();

// ── Ajout d'une Passkey depuis le dashboard ──────────────────────────────────
function b64url(buf) {
    return btoa(String.fromCharCode(...new Uint8Array(buf)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function fromB64url(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(s), arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return arr.buffer;
}

async function addPasskey() {
    const btn = document.getElementById('btn-add-passkey');
    const box = document.getElementById('add-passkey-status');

    btn.disabled = true;
    btn.textContent = 'Enregistrement…';
    showPasskeyStatus('info', '⏳ Récupération du challenge…');

    try {
        const resp = await fetch('/admin/verify.php?action=challenge_add_passkey');
        if (!resp.ok) throw new Error('Serveur inaccessible.');
        const options = await resp.json();
        if (options.error) throw new Error(options.error);

        options.challenge = fromB64url(options.challenge);
        options.user.id   = fromB64url(options.user.id);

        showPasskeyStatus('info', '🔑 En attente de votre authentificateur…');

        const credential = await navigator.credentials.create({ publicKey: options });

        showPasskeyStatus('info', '📤 Envoi au serveur…');

        const payload = {
            id:   credential.id,
            type: credential.type,
            response: {
                clientDataJSON:    b64url(credential.response.clientDataJSON),
                attestationObject: b64url(credential.response.attestationObject),
            },
        };

        const verifyResp = await fetch('/admin/verify.php?action=add_passkey', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const result = await verifyResp.json();

        if (result.success) {
            showPasskeyStatus('success', '✅ Passkey ajoutée ! Rechargement…');
            setTimeout(() => location.reload(), 1500);
        } else {
            throw new Error(result.error || 'Échec de l\'enregistrement.');
        }
    } catch (err) {
        showPasskeyStatus('error', '❌ ' + (err.message || 'Erreur inconnue.'));
        btn.disabled    = false;
        btn.textContent = '+ Ajouter une Passkey';
    }
}

function showPasskeyStatus(type, msg) {
    const box = document.getElementById('add-passkey-status');
    box.className = 'mt-3 ring-1 rounded-xl px-4 py-2.5 text-sm';
    const classes = {
        info:    ['bg-blue-900/40',  'ring-blue-500/40',  'text-blue-300'],
        success: ['bg-green-900/40', 'ring-green-500/40', 'text-green-300'],
        error:   ['bg-red-900/40',   'ring-red-500/40',   'text-red-300'],
    };
    box.classList.add(...(classes[type] || classes.info));
    box.textContent = msg;
}

// ── Gestion CV ───────────────────────────────────────────────────────────────
function cvSkillHtml(val = '') {
    const d = document.createElement('div');
    d.className = 'cv-skill-tag flex items-center gap-1.5 bg-gray-800 ring-1 ring-gray-700 rounded-lg px-2 py-1';
    d.innerHTML = `<input type="text" maxlength="40" value="${val.replace(/"/g,'&quot;')}" placeholder="Compétence"
        class="bg-transparent text-sm text-white w-24 focus:outline-none focus:w-32 transition-all">
        <button type="button" onclick="this.closest('.cv-skill-tag').remove()" class="text-gray-600 hover:text-red-400 text-xs leading-none transition-colors">✕</button>`;
    return d;
}
function cvAddSkill() { document.getElementById('cv-skills').appendChild(cvSkillHtml()); }

function cvExpHtml(d={}) {
    const el = document.createElement('div');
    el.className = 'cv-item bg-gray-800 rounded-xl p-4 ring-1 ring-gray-700';
    el.innerHTML = `<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
        <input type="text" data-field="title" maxlength="100" value="${(d.title||'').replace(/"/g,'&quot;')}" placeholder="Intitulé du poste" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <input type="text" data-field="company" maxlength="100" value="${(d.company||'').replace(/"/g,'&quot;')}" placeholder="Entreprise" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <input type="text" data-field="dates" maxlength="60" value="${(d.dates||'').replace(/"/g,'&quot;')}" placeholder="ex : Janv. 2023 – Présent" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <button type="button" onclick="this.closest('.cv-item').remove()" class="text-red-500/70 hover:text-red-400 text-xs ring-1 ring-red-500/20 rounded-lg px-3 py-1.5 transition-colors text-left sm:text-center">Supprimer</button>
    </div>
    <textarea data-field="description" rows="2" maxlength="400" placeholder="Description (optionnel)" class="w-full bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 resize-none">${(d.description||'').replace(/</g,'&lt;')}</textarea>`;
    return el;
}
function cvAddExp() { document.getElementById('cv-experiences').appendChild(cvExpHtml()); }

function cvEduHtml(d={}) {
    const el = document.createElement('div');
    el.className = 'cv-item bg-gray-800 rounded-xl p-4 ring-1 ring-gray-700';
    el.innerHTML = `<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
        <input type="text" data-field="degree" maxlength="100" value="${(d.degree||'').replace(/"/g,'&quot;')}" placeholder="Diplôme / filière" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <input type="text" data-field="school" maxlength="100" value="${(d.school||'').replace(/"/g,'&quot;')}" placeholder="École / université" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <input type="text" data-field="dates" maxlength="60" value="${(d.dates||'').replace(/"/g,'&quot;')}" placeholder="ex : 2020 – 2023" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <button type="button" onclick="this.closest('.cv-item').remove()" class="text-red-500/70 hover:text-red-400 text-xs ring-1 ring-red-500/20 rounded-lg px-3 py-1.5 transition-colors text-left sm:text-center">Supprimer</button>
    </div>
    <textarea data-field="description" rows="2" maxlength="300" placeholder="Description (optionnel)" class="w-full bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 resize-none">${(d.description||'').replace(/</g,'&lt;')}</textarea>`;
    return el;
}
function cvAddEdu() { document.getElementById('cv-education').appendChild(cvEduHtml()); }

function cvProjectHtml(d={}) {
    const el = document.createElement('div');
    el.className = 'cv-item bg-gray-800 rounded-xl p-4 ring-1 ring-gray-700';
    el.innerHTML = `<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
        <input type="text" data-field="title" maxlength="100" value="${(d.title||'').replace(/"/g,'&quot;')}" placeholder="Nom du projet" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <input type="url" data-field="url" maxlength="200" value="${(d.url||'').replace(/"/g,'&quot;')}" placeholder="URL (optionnel)" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <input type="text" data-field="tech" maxlength="150" value="${(d.tech||'').replace(/"/g,'&quot;')}" placeholder="Stack (PHP, JS, …)" class="bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <button type="button" onclick="this.closest('.cv-item').remove()" class="text-red-500/70 hover:text-red-400 text-xs ring-1 ring-red-500/20 rounded-lg px-3 py-1.5 transition-colors text-left sm:text-center">Supprimer</button>
    </div>
    <textarea data-field="description" rows="2" maxlength="400" placeholder="Description du projet" class="w-full bg-gray-700/60 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 resize-none">${(d.description||'').replace(/</g,'&lt;')}</textarea>`;
    return el;
}
function cvAddProject() { document.getElementById('cv-projects').appendChild(cvProjectHtml()); }

// Sérialisation CV avant soumission
document.getElementById('form-cv').addEventListener('submit', function () {
    // Skills
    const skills = [...document.querySelectorAll('#cv-skills .cv-skill-tag input')]
        .map(i => i.value.trim()).filter(Boolean);
    document.getElementById('input-cv-skills').value = JSON.stringify(skills);

    // Helper pour les blocs avec data-field
    function serializeItems(containerId, fields) {
        return [...document.querySelectorAll('#' + containerId + ' .cv-item')].map(item => {
            const obj = {};
            fields.forEach(f => {
                const el = item.querySelector('[data-field="' + f + '"]');
                obj[f] = el ? el.value.trim() : '';
            });
            return obj;
        }).filter(obj => Object.values(obj).some(Boolean));
    }

    document.getElementById('input-cv-experiences').value = JSON.stringify(
        serializeItems('cv-experiences', ['title', 'company', 'dates', 'description'])
    );
    document.getElementById('input-cv-education').value = JSON.stringify(
        serializeItems('cv-education', ['degree', 'school', 'dates', 'description'])
    );
    document.getElementById('input-cv-projects').value = JSON.stringify(
        serializeItems('cv-projects', ['title', 'url', 'tech', 'description'])
    );
});
</script>

</body>
</html>

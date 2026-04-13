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
$statusData = get_status();
$status     = $statusData['status'];
$logs       = read_json(LOGS_FILE,   []);
$alerts     = read_json(ALERTS_FILE, []);
$settings   = read_json(SETTINGS_FILE, []);
$credentials = get_credentials();

// CSRF token
$csrf = generate_csrf_token();

// Log de cette visite
log_action('dashboard_view');

// Config visuelle
$statusCfg = [
    'secure'      => ['label' => '🟢 Sécurisé',          'class' => 'text-green-400',  'bg' => 'bg-green-900/30',  'ring' => 'ring-green-500/40'],
    'warning'     => ['label' => '🟠 Activité suspecte',  'class' => 'text-yellow-400', 'bg' => 'bg-yellow-900/30', 'ring' => 'ring-yellow-500/40'],
    'compromised' => ['label' => '🔴 Compte compromis',   'class' => 'text-red-400',    'bg' => 'bg-red-900/30',    'ring' => 'ring-red-500/40'],
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

    <!-- ── Statut courant ── -->
    <section>
        <h2 class="text-xs font-semibold uppercase tracking-widest text-gray-500 mb-4">Statut courant</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

            <!-- Carte statut -->
            <div class="sm:col-span-2 bg-gray-900 ring-1 ring-gray-800 rounded-2xl p-6 flex items-center gap-5">
                <div class="w-14 h-14 rounded-xl <?= $sc['bg'] ?> ring-1 <?= $sc['ring'] ?> flex items-center justify-center text-2xl flex-shrink-0">
                    <?= $status === 'secure' ? '🛡️' : ($status === 'warning' ? '⚠️' : '🚨') ?>
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
                    <span class="text-white font-semibold"><?= count($logs) ?></span>
                </div>
                <div class="flex justify-between text-sm mt-3">
                    <span class="text-gray-500">Alertes</span>
                    <span class="text-yellow-400 font-semibold"><?= count($alerts) ?></span>
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
                Logs <span class="ml-1.5 bg-gray-800 text-gray-400 rounded-full px-1.5 py-0.5 text-xs"><?= count($logs) ?></span>
            </button>
            <button onclick="showTab('alerts')"  id="tab-alerts"  class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 transition-colors">
                Alertes <?php if ($alerts): ?><span class="ml-1.5 bg-yellow-900 text-yellow-400 rounded-full px-1.5 py-0.5 text-xs"><?= count($alerts) ?></span><?php endif; ?>
            </button>
            <button onclick="showTab('settings')" id="tab-settings" class="tab-btn px-4 py-2 text-sm font-medium border-b-2 border-transparent text-gray-400 transition-colors">
                Paramètres
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
                                    'admin_login_success' => '<span class="text-green-400">✅ Connexion</span>',
                                    'admin_login_failure' => '<span class="text-red-400">❌ Échec connexion</span>',
                                    'dashboard_view'      => '<span class="text-gray-300">👁 Dashboard</span>',
                                    'status_changed'      => '<span class="text-yellow-400">⚙️ Statut modifié</span>',
                                    'passkey_registered'  => '<span class="text-indigo-400">🔑 Passkey enregistrée</span>',
                                    default               => '<span class="text-gray-400">' . htmlspecialchars($log['action'] ?? '', ENT_QUOTES, 'UTF-8') . '</span>',
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
                Affichage des 50 derniers logs sur <?= count($logs) ?> enregistrés.
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
function showTab(name) {
    ['logs', 'alerts', 'settings'].forEach(t => {
        document.getElementById('tab-'   + t).classList.remove('active');
        document.getElementById('panel-' + t).classList.remove('active');
    });
    document.getElementById('tab-'   + name).classList.add('active');
    document.getElementById('panel-' + name).classList.add('active');
}
</script>

</body>
</html>

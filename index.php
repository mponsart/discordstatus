<?php
/**
 * Discord Guard — Page publique de statut
 * Surveillance automatique du compte Discord via Bot API + tâche cron.
 */

require_once __DIR__ . '/functions.php';

// ─── Données ───────────────────────────────────────────────────────────────────
$statusData    = get_status();
$status        = $statusData['status'];
$customMessage = $statusData['customMessage'];
$lastUpdated   = $statusData['lastUpdated'];

// Profil (auto via bot + champs manuels)
$username    = get_setting('discord_username',        '');
$globalName  = get_setting('discord_global_name',     '');
$discrim     = get_setting('discord_discriminator',   '');
$avatarUrl   = get_setting('discord_avatar_url',      '');
$bio         = get_setting('discord_bio',             '');
$joinedAcc   = get_setting('discord_joined',          '');    // date compte (manuelle)
$joinedGuild = get_setting('discord_guild_joined_at', '');    // rejointe serveur (auto)
$displayName = $globalName ?: $username;

// Monitoring
$lastCheck      = get_setting('discord_last_check');
$monitorStatus  = get_setting('discord_monitor_status', 'not_configured');
$checkCount     = (int) get_setting('discord_check_count',   '0');
$anomalyCount   = (int) get_setting('discord_anomaly_count', '0');
$botConfigured  = defined('DISCORD_BOT_TOKEN')      && DISCORD_BOT_TOKEN      !== ''
               && defined('DISCORD_TARGET_USER_ID') && DISCORD_TARGET_USER_ID !== ''
               && defined('DISCORD_GUILD_ID')       && DISCORD_GUILD_ID       !== '';
$botActive      = $botConfigured && $monitorStatus === 'ok';
$showHistory    = get_setting('show_public_history', '1') === '1';
$history        = $showHistory ? get_status_history(20) : [];

// ─── Config visuelle selon le statut ──────────────────────────────────────────
$S = match ($status) {
    'warning'     => [
        'label'  => 'Activité suspecte',
        'sublabel' => 'Des changements inhabituels ont été détectés.',
        'dot'    => 'bg-yellow-400',
        'ring'   => 'ring-yellow-400/30',
        'badge'  => 'bg-yellow-500/10 text-yellow-300 ring-yellow-400/25',
        'bar'    => 'bg-yellow-400',
        'glow'   => 'bg-yellow-400/8',
        'border' => 'border-yellow-400/20',
        'hex'    => '#facc15',
        'icon'   => '⚠️',
    ],
    'compromised' => [
        'label'    => 'Compte compromis',
        'sublabel' => 'Ce compte est potentiellement sous contrôle d\'un tiers.',
        'dot'    => 'bg-red-500',
        'ring'   => 'ring-red-500/30',
        'badge'  => 'bg-red-500/10 text-red-300 ring-red-400/25',
        'bar'    => 'bg-red-500',
        'glow'   => 'bg-red-500/8',
        'border' => 'border-red-500/20',
        'hex'    => '#ef4444',
        'icon'   => '🚨',
    ],
    default       => [
        'label'    => 'Compte sécurisé',
        'sublabel' => 'Aucune anomalie détectée. Le compte est sous surveillance active.',
        'dot'    => 'bg-emerald-400',
        'ring'   => 'ring-emerald-400/30',
        'badge'  => 'bg-emerald-500/10 text-emerald-300 ring-emerald-400/25',
        'bar'    => 'bg-emerald-400',
        'glow'   => 'bg-emerald-400/8',
        'border' => 'border-emerald-400/20',
        'hex'    => '#34d399',
        'icon'   => '🛡️',
    ],
};

function hcfg(string $s): array {
    return match ($s) {
        'warning'     => ['dot' => 'bg-yellow-400', 'text' => 'text-yellow-300',  'label' => 'Activité suspecte'],
        'compromised' => ['dot' => 'bg-red-500',    'text' => 'text-red-300',     'label' => 'Compte compromis'],
        default       => ['dot' => 'bg-emerald-400','text' => 'text-emerald-300', 'label' => 'Compte sécurisé'],
    };
}

// Ago helper (PHP fallback, JS prend le relais côté client)
function ago_php(?string $iso): string {
    if (!$iso) return 'jamais';
    $d = time() - strtotime($iso);
    if ($d < 60)    return $d . 's';
    if ($d < 3600)  return floor($d/60) . ' min';
    if ($d < 86400) return floor($d/3600) . ' h';
    return floor($d/86400) . ' j';
}
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Guard<?= $displayName ? ' — ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') : '' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <style>
        :root { --accent: <?= $S['hex'] ?>; }

        body { background: #060810; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

        /* Fond animé */
        .blob { border-radius: 50%; filter: blur(80px); position: absolute; pointer-events: none; }

        /* Cartes */
        .card { background: rgba(255,255,255,.035); border: 1px solid rgba(255,255,255,.07); border-radius: 16px; }
        .card-sm { background: rgba(255,255,255,.025); border: 1px solid rgba(255,255,255,.055); border-radius: 12px; }

        /* Animations */
        @keyframes pulse-ring {
            0%   { transform: scale(1); opacity: .6; }
            100% { transform: scale(2.2); opacity: 0; }
        }
        @keyframes breathe {
            0%, 100% { opacity: 1; }
            50%       { opacity: .55; }
        }
        @keyframes slide-up {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-pulse-ring  { animation: pulse-ring 2s ease-out infinite; }
        .animate-breathe     { animation: breathe 3s ease-in-out infinite; }
        .anim-card           { animation: slide-up .4s ease both; }
        .anim-card:nth-child(1) { animation-delay: .05s; }
        .anim-card:nth-child(2) { animation-delay: .10s; }
        .anim-card:nth-child(3) { animation-delay: .15s; }
        .anim-card:nth-child(4) { animation-delay: .20s; }

        /* Timeline */
        .tl { border-left: 1px solid rgba(255,255,255,.06); }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 3px; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 4px; }
    </style>
</head>
<body class="text-gray-100 min-h-screen antialiased overflow-x-hidden">

<!-- ── Fond décoratif ─────────────────────────────────────────────────────── -->
<div class="fixed inset-0 overflow-hidden" aria-hidden="true">
    <div class="blob w-[500px] h-[500px] -top-40 -left-32 opacity-[.04]"
         style="background:<?= $S['hex'] ?>"></div>
    <div class="blob w-96 h-96 top-1/3 -right-28 opacity-[.03] bg-indigo-500"></div>
    <div class="blob w-[600px] h-64 bottom-0 left-1/2 -translate-x-1/2 opacity-[.035]"
         style="background:<?= $S['hex'] ?>"></div>
</div>

<div class="relative z-10 max-w-3xl mx-auto px-4 py-10 space-y-5">

    <!-- ══════════════════════════════════════════════════════════════════════
         TOPBAR
         ════════════════════════════════════════════════════════════════════ -->
    <div class="flex items-center justify-between">
        <!-- Logo -->
        <div class="flex items-center gap-2.5">
            <div class="w-7 h-7 rounded-lg bg-[#5865F2]/20 ring-1 ring-[#5865F2]/30
                        flex items-center justify-center text-sm">🔰</div>
            <span class="font-semibold text-white text-sm">Discord Guard</span>
        </div>

        <!-- Bot status pill + admin -->
        <div class="flex items-center gap-3">
            <?php if ($botConfigured): ?>
            <div class="flex items-center gap-1.5 bg-white/[.04] rounded-full px-3 py-1
                        ring-1 ring-white/[.07] text-xs <?= $botActive ? 'text-emerald-400' : 'text-red-400' ?>">
                <span class="w-1.5 h-1.5 rounded-full <?= $botActive ? 'bg-emerald-400 animate-breathe' : 'bg-red-400' ?>"></span>
                <?= $botActive ? 'Bot actif' : 'Bot — erreur API' ?>
            </div>
            <?php else: ?>
            <div class="flex items-center gap-1.5 bg-white/[.03] rounded-full px-3 py-1
                        ring-1 ring-white/[.05] text-xs text-gray-600">
                <span class="w-1.5 h-1.5 rounded-full bg-gray-700"></span>
                Surveillance manuelle
            </div>
            <?php endif; ?>
            <a href="/admin/gate.php" class="text-xs text-gray-700 hover:text-gray-400 transition-colors">
                Admin →
            </a>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         HERO : PROFIL + STATUT
         ════════════════════════════════════════════════════════════════════ -->
    <div class="card overflow-hidden">

        <!-- Bannière dégradée -->
        <div class="h-24 relative overflow-hidden"
             style="background: linear-gradient(135deg, #0e1021 0%, #1a1040 50%, #0e0e1a 100%);">
            <!-- Particules déco -->
            <div class="absolute inset-0 opacity-20"
                 style="background-image: radial-gradient(circle at 20% 50%, <?= $S['hex'] ?>33 0%, transparent 50%),
                                          radial-gradient(circle at 80% 30%, #5865F233 0%, transparent 40%)"></div>
        </div>

        <div class="px-6 pb-6">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 -mt-12 mb-5">

                <!-- Avatar + indicateur statut -->
                <div class="relative flex-shrink-0 self-start">
                    <?php if (!empty($avatarUrl)): ?>
                    <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="Avatar Discord"
                         width="96" height="96"
                         class="w-24 h-24 rounded-2xl ring-4 ring-[#060810] object-cover bg-gray-800"
                         onerror="this.style.display='none';document.getElementById('av-fb').style.display='flex'">
                    <div id="av-fb"
                         class="w-24 h-24 rounded-2xl ring-4 ring-[#060810] bg-indigo-950
                                items-center justify-center text-4xl font-black text-indigo-400 hidden">
                        <?= mb_strtoupper(mb_substr($displayName ?: '?', 0, 1)) ?>
                    </div>
                    <?php else: ?>
                    <div class="w-24 h-24 rounded-2xl ring-4 ring-[#060810] bg-indigo-950
                                flex items-center justify-center text-4xl font-black text-indigo-400">
                        <?= mb_strtoupper(mb_substr($displayName ?: '?', 0, 1)) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Dot statut sur l'avatar -->
                    <div class="absolute -bottom-1 -right-1 w-6 h-6 rounded-full
                                ring-2 ring-[#060810] <?= $S['dot'] ?>
                                flex items-center justify-center">
                        <span class="absolute w-full h-full rounded-full <?= $S['dot'] ?>
                                     opacity-60 animate-pulse-ring"></span>
                    </div>
                </div>

                <!-- Badge statut -->
                <div class="sm:mb-1">
                    <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-sm
                                 font-semibold ring-1 <?= $S['badge'] ?>">
                        <span class="text-base"><?= $S['icon'] ?></span>
                        <?= htmlspecialchars($S['label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </div>

            <!-- Nom + discriminant -->
            <?php if ($displayName): ?>
            <div class="mb-3">
                <div class="flex items-baseline gap-2 flex-wrap">
                    <h1 class="text-2xl font-black text-white tracking-tight">
                        <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
                    </h1>
                    <?php if ($username && $globalName && $username !== $globalName): ?>
                    <span class="text-gray-500 text-sm font-mono">@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <?php if ($discrim && $discrim !== '0'): ?>
                    <span class="text-gray-600 text-sm">#<?= htmlspecialchars($discrim, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-gray-500 mt-0.5"><?= htmlspecialchars($S['sublabel'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <?php endif; ?>

            <!-- Bio -->
            <?php if (!empty($bio)): ?>
            <p class="text-sm text-gray-400 leading-relaxed mb-4 card-sm px-4 py-3">
                <?= nl2br(htmlspecialchars($bio, ENT_QUOTES, 'UTF-8')) ?>
            </p>
            <?php endif; ?>

            <!-- Méta Discord -->
            <div class="flex flex-wrap gap-x-5 gap-y-2 text-xs text-gray-600">
                <?php if (!empty($joinedAcc)): ?>
                <span class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Compte Discord depuis <?= htmlspecialchars($joinedAcc, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($joinedGuild)): ?>
                <span class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Serveur rejoint le <?= htmlspecialchars(
                        date('d/m/Y', strtotime($joinedGuild) ?: time()),
                        ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
                <?php if ($lastCheck): ?>
                <span class="flex items-center gap-1.5" id="last-check-meta"
                      data-ts="<?= htmlspecialchars($lastCheck, ENT_QUOTES, 'UTF-8') ?>">
                    <svg class="w-3.5 h-3.5 <?= $botActive ? 'text-emerald-600' : 'text-gray-600' ?>"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Dernière vérification : <span class="text-gray-500" id="last-check-ago">
                        <?= htmlspecialchars(ago_php($lastCheck), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         ALERTE — message personnalisé si statut ≠ secure
         ════════════════════════════════════════════════════════════════════ -->
    <?php if ($status !== 'secure' && !empty($customMessage)): ?>
    <div class="card px-6 py-4 <?= $S['border'] ?>"
         style="border-color: <?= $S['hex'] ?>30">
        <div class="flex gap-3">
            <span class="text-2xl flex-shrink-0 mt-0.5"><?= $S['icon'] ?></span>
            <div>
                <p class="text-sm font-semibold mb-1"
                   style="color: <?= $S['hex'] ?>">Message de l'administrateur</p>
                <p class="text-sm text-gray-300 leading-relaxed">
                    <?= nl2br(htmlspecialchars($customMessage, ENT_QUOTES, 'UTF-8')) ?>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════
         MÉTRIQUES (4 cartes)
         ════════════════════════════════════════════════════════════════════ -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <!-- Statut -->
        <div class="card-sm anim-card p-4 text-center">
            <div class="text-2xl mb-1"><?= $S['icon'] ?></div>
            <p class="text-xs text-gray-600 uppercase tracking-wider font-medium">Statut</p>
            <p class="text-sm font-bold mt-0.5"
               style="color: <?= $S['hex'] ?>"><?= htmlspecialchars($S['label'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <!-- Vérifications -->
        <div class="card-sm anim-card p-4 text-center">
            <p class="text-2xl font-black text-white"><?= number_format($checkCount) ?></p>
            <p class="text-xs text-gray-600 uppercase tracking-wider font-medium mt-1">Vérifications</p>
            <p class="text-xs text-gray-700 mt-0.5">
                <?= $botConfigured ? 'via bot Discord' : 'non configuré' ?>
            </p>
        </div>

        <!-- Anomalies -->
        <div class="card-sm anim-card p-4 text-center">
            <p class="text-2xl font-black <?= $anomalyCount > 0 ? 'text-yellow-400' : 'text-white' ?>">
                <?= $anomalyCount ?>
            </p>
            <p class="text-xs text-gray-600 uppercase tracking-wider font-medium mt-1">Anomalies</p>
            <p class="text-xs text-gray-700 mt-0.5">score cumulé</p>
        </div>

        <!-- Dernière MAJ -->
        <div class="card-sm anim-card p-4 text-center">
            <p class="text-2xl font-black text-white" id="last-updated-ago"
               data-ts="<?= htmlspecialchars($lastUpdated ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <?= ago_php($lastUpdated) ?>
            </p>
            <p class="text-xs text-gray-600 uppercase tracking-wider font-medium mt-1">Dernière MAJ</p>
            <p class="text-xs text-gray-700 mt-0.5">du statut</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         MONITORING BOT
         ════════════════════════════════════════════════════════════════════ -->
    <div class="card px-5 py-4">
        <div class="flex items-center gap-2 mb-4">
            <svg class="w-4 h-4 text-[#5865F2]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.032.055a19.9 19.9 0 0 0 5.993 3.03.079.079 0 0 0 .086-.027 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/>
            </svg>
            <h3 class="text-sm font-semibold text-white">Surveillance Discord</h3>
        </div>

        <?php if (!$botConfigured): ?>
        <!-- Bot non configuré -->
        <div class="rounded-xl bg-white/[.025] ring-1 ring-white/[.05] px-4 py-3.5 flex gap-3">
            <span class="text-gray-600 text-lg flex-shrink-0">⚫</span>
            <div>
                <p class="text-sm font-medium text-gray-400">Surveillance automatique non configurée</p>
                <p class="text-xs text-gray-600 mt-1 leading-relaxed">
                    Le bot Discord n'est pas encore configuré. Renseignez
                    <code class="bg-white/[.05] rounded px-1">DISCORD_BOT_TOKEN</code>,
                    <code class="bg-white/[.05] rounded px-1">DISCORD_TARGET_USER_ID</code> et
                    <code class="bg-white/[.05] rounded px-1">DISCORD_GUILD_ID</code> dans
                    <code class="bg-white/[.05] rounded px-1">config.php</code>, puis configurez une tâche cron.
                </p>
            </div>
        </div>

        <?php else: ?>
        <!-- Bot configuré -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">

            <!-- Statut connexion -->
            <div class="card-sm px-4 py-3">
                <div class="flex items-center gap-2 mb-1">
                    <span class="w-2 h-2 rounded-full flex-shrink-0
                                 <?= $botActive ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                    <span class="text-xs font-medium text-gray-300">
                        <?= $botActive ? 'Connecté' : 'Erreur API' ?>
                    </span>
                </div>
                <p class="text-xs text-gray-600">
                    <?php
                    $mLabel = match(true) {
                        $monitorStatus === 'ok'             => 'API Discord OK',
                        $monitorStatus === 'not_configured' => 'Non configuré',
                        $monitorStatus === 'error_401'      => 'Token invalide',
                        $monitorStatus === 'error_403'      => 'Permissions insuffisantes',
                        $monitorStatus === 'error_404'      => 'Compte non trouvé',
                        $monitorStatus === 'error_429'      => 'Rate limit Discord',
                        default                             => 'Erreur : ' . htmlspecialchars($monitorStatus, ENT_QUOTES, 'UTF-8'),
                    };
                    echo $mLabel;
                    ?>
                </p>
            </div>

            <!-- Dernière vérification -->
            <div class="card-sm px-4 py-3">
                <p class="text-xs font-medium text-gray-500 mb-1">Dernière vérif.</p>
                <p class="text-sm font-bold text-white" id="bot-last-check"
                   data-ts="<?= htmlspecialchars($lastCheck ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?= $lastCheck ? ago_php($lastCheck) : '—' ?>
                </p>
                <p class="text-xs text-gray-700 mt-0.5">
                    <?= $lastCheck ? htmlspecialchars(format_date($lastCheck), ENT_QUOTES, 'UTF-8') : 'aucune vérification' ?>
                </p>
            </div>

            <!-- Prochaine vérification (estimation ≈ +5 min) -->
            <div class="card-sm px-4 py-3">
                <p class="text-xs font-medium text-gray-500 mb-1">Prochaine vérif.</p>
                <?php if ($lastCheck): ?>
                <p class="text-sm font-bold text-white" id="bot-next-check"
                   data-ts="<?= htmlspecialchars(date('c', strtotime($lastCheck) + 300), ENT_QUOTES, 'UTF-8') ?>">
                    dans <?= ago_php(date('c', strtotime($lastCheck) + 300)) ?>
                </p>
                <p class="text-xs text-gray-700 mt-0.5">fréquence cron ≈ 5 min</p>
                <?php else: ?>
                <p class="text-sm font-bold text-gray-600">—</p>
                <p class="text-xs text-gray-700 mt-0.5">en attente du 1er cycle</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════
         TIMELINE DES ÉVÉNEMENTS
         ════════════════════════════════════════════════════════════════════ -->
    <?php if ($showHistory && !empty($history)): ?>
    <div class="card overflow-hidden">

        <div class="flex items-center justify-between px-5 py-4 border-b border-white/[.05]">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <h3 class="text-sm font-semibold text-white">Historique des événements</h3>
            </div>
            <span class="text-xs text-gray-700"><?= count($history) ?> entrées</span>
        </div>

        <div class="px-5 py-4">
            <div class="tl pl-5 space-y-4 ml-2">
                <?php foreach ($history as $i => $ev):
                    $h = hcfg($ev['status']);
                    $isAuto = str_starts_with($ev['note'] ?? '', '[AUTO]');
                    $noteText = $isAuto
                        ? trim(substr($ev['note'], 6))
                        : ($ev['note'] ?? '');
                ?>
                <div class="relative">
                    <!-- Dot timeline -->
                    <span class="absolute -left-[1.6rem] top-1 w-3 h-3 rounded-full
                                 ring-2 ring-[#060810] <?= $h['dot'] ?> flex-shrink-0
                                 <?= $i === 0 ? 'shadow-lg' : '' ?>"></span>

                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-0.5">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium <?= $h['text'] ?>">
                                    <?= htmlspecialchars($h['label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($i === 0): ?>
                                <span class="text-[10px] bg-white/[.07] text-gray-400
                                             rounded-full px-1.5 py-0.5 font-medium">actuel</span>
                                <?php endif; ?>
                                <?php if ($isAuto): ?>
                                <span class="text-[10px] bg-[#5865F2]/15 text-[#8891f2]
                                             rounded-full px-1.5 py-0.5 font-medium">🤖 auto</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($noteText)): ?>
                            <p class="text-xs text-gray-600 mt-0.5">
                                <?= htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-700 flex-shrink-0 ml-2 mt-0.5 whitespace-nowrap"
                           data-ts="<?= htmlspecialchars($ev['date'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(ago_php($ev['date']), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php elseif ($showHistory): ?>
    <div class="card-sm px-5 py-4 text-center">
        <p class="text-sm text-gray-700">Aucun événement enregistré pour le moment.</p>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════
         NOTE D'INFORMATION
         ════════════════════════════════════════════════════════════════════ -->
    <div class="card-sm px-5 py-4">
        <p class="text-xs text-gray-700 leading-relaxed">
            Cette page est mise à jour automatiquement par un bot Discord qui surveille
            les modifications de profil (nom d'utilisateur, avatar, départ du serveur) toutes les 5 minutes.
            En cas de statut <span class="text-yellow-500">activité suspecte</span> ou
            <span class="text-red-500">compromis</span>, méfiez-vous de tout message ou lien
            envoyé depuis ce compte Discord.
        </p>
    </div>

    <!-- ── Pied de page ──────────────────────────────────────────────────── -->
    <p class="text-center text-gray-800 text-xs pb-2">
        Protégé par <strong class="text-gray-700">Discord Guard</strong>
        &nbsp;·&nbsp;
        <a href="/admin/gate.php" class="hover:text-gray-600 transition-colors">Administration</a>
    </p>

</div><!-- /max-w-3xl -->

<!-- ── JavaScript : compteurs relatifs ───────────────────────────────────── -->
<script>
(function () {
    function timeAgo(iso) {
        if (!iso) return '—';
        const d = Math.floor((Date.now() - new Date(iso)) / 1000);
        if (d < 0)    return 'à venir';
        if (d < 60)   return d + 's';
        if (d < 3600) return Math.floor(d / 60) + ' min';
        if (d < 86400) return Math.floor(d / 3600) + ' h';
        return Math.floor(d / 86400) + ' j';
    }

    // Tous les éléments avec data-ts (horodatages relatifs à actualiser)
    function refresh() {
        document.querySelectorAll('[data-ts]').forEach(function (el) {
            const ts = el.getAttribute('data-ts');
            if (!ts) return;
            // Ne pas écraser les éléments qui ont un contenu fixe (date absolue)
            if (el.id === 'last-check-meta') return;
            el.textContent = timeAgo(ts);
        });
        // Compteur spécifique "il y a Xmin"
        const lcAgo = document.getElementById('last-check-ago');
        if (lcAgo) {
            const meta = document.getElementById('last-check-meta');
            if (meta) lcAgo.textContent = timeAgo(meta.getAttribute('data-ts'));
        }
    }

    refresh();
    setInterval(refresh, 30000); // rafraîchir toutes les 30 s
})();
</script>

</body>
</html>

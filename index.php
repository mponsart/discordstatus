<?php
/**
 * Discord Guard — Page publique de statut
 * Accessible à tous, sans authentification.
 *
 * ℹ️  CONNEXION DISCORD : Cette application ne se connecte PAS à l'API Discord.
 *     Le profil est saisi manuellement dans le dashboard admin (onglet Profil).
 *     Le statut est mis à jour manuellement par l'administrateur.
 *     Un webhook Discord permet l'envoi de notifications vers un salon Discord.
 */

require_once __DIR__ . '/functions.php';

$statusData    = get_status();
$status        = $statusData['status'];
$customMessage = $statusData['customMessage'];
$lastUpdated   = $statusData['lastUpdated'];
$profile       = get_discord_profile();
$history       = $profile['show_history'] ? get_status_history(12) : [];

// ─── Configuration visuelle ───────────────────────────────────────────────────
$cfg = match ($status) {
    'warning'     => [
        'label'      => 'Activité suspecte',
        'badge'      => 'bg-yellow-500/15 text-yellow-300 ring-yellow-400/30',
        'dot'        => 'bg-yellow-400',
        'dot_shadow' => 'shadow-yellow-400/50',
        'ring'       => 'ring-yellow-400/40',
        'glow'       => 'shadow-yellow-500/10',
        'icon'       => '⚠️',
        'bar'        => 'from-yellow-400 to-orange-400',
    ],
    'compromised' => [
        'label'      => 'Compte compromis',
        'badge'      => 'bg-red-500/15 text-red-300 ring-red-400/30',
        'dot'        => 'bg-red-500',
        'dot_shadow' => 'shadow-red-400/50',
        'ring'       => 'ring-red-500/40',
        'glow'       => 'shadow-red-500/10',
        'icon'       => '🚨',
        'bar'        => 'from-red-500 to-rose-500',
    ],
    default       => [
        'label'      => 'Compte sécurisé',
        'badge'      => 'bg-green-500/15 text-green-300 ring-green-400/30',
        'dot'        => 'bg-green-400',
        'dot_shadow' => 'shadow-green-400/50',
        'ring'       => 'ring-green-400/40',
        'glow'       => 'shadow-green-500/10',
        'icon'       => '🛡️',
        'bar'        => 'from-green-400 to-emerald-400',
    ],
};

// Historique : libellés & couleurs
function history_cfg(string $s): array {
    return match ($s) {
        'warning'     => ['label' => 'Activité suspecte',  'dot' => 'bg-yellow-400', 'text' => 'text-yellow-300'],
        'compromised' => ['label' => 'Compte compromis',   'dot' => 'bg-red-500',    'text' => 'text-red-300'],
        default       => ['label' => 'Compte sécurisé',    'dot' => 'bg-green-400',  'text' => 'text-green-300'],
    };
}
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Guard<?= $profile['username'] ? ' — ' . htmlspecialchars($profile['username'], ENT_QUOTES, 'UTF-8') : '' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <style>
        @keyframes spin-slow { to { transform: rotate(360deg); } }
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1);   opacity: 1; }
            50%       { transform: scale(1.15); opacity: .8; }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0);   }
            50%       { transform: translateY(-6px); }
        }
        .animate-spin-slow { animation: spin-slow 8s linear infinite; }
        .animate-pulse-dot  { animation: pulse-dot 2s ease-in-out infinite; }
        .animate-float      { animation: float 4s ease-in-out infinite; }

        /* Barre dégradée derrière la carte statut */
        .status-glow {
            position: absolute;
            inset: -1px;
            border-radius: 1.25rem;
            opacity: .18;
            filter: blur(18px);
            z-index: 0;
        }

        /* Timeline verticale */
        .tl-line { border-left: 2px solid rgba(255,255,255,.07); }

        /* Scrollbar fine */
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }
    </style>
</head>
<body class="bg-[#0b0d14] text-gray-100 min-h-screen font-sans antialiased">

<!-- ══════════════════════════════════════════════════════════════════
     FOND ANIMÉ (cercles décoratifs)
     ══════════════════════════════════════════════════════════════════ -->
<div class="fixed inset-0 overflow-hidden pointer-events-none select-none" aria-hidden="true">
    <div class="absolute -top-40 -left-40 w-96 h-96 rounded-full bg-indigo-600/5 blur-3xl"></div>
    <div class="absolute top-1/3 -right-32 w-80 h-80 rounded-full bg-violet-600/5 blur-3xl"></div>
    <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[600px] h-64 rounded-full
                <?= $status === 'secure' ? 'bg-green-500/4' : ($status === 'warning' ? 'bg-yellow-500/4' : 'bg-red-500/4') ?>
                blur-3xl"></div>
</div>

<div class="relative z-10 max-w-2xl mx-auto px-4 py-12 space-y-6">

    <!-- ── PILL HEADER ────────────────────────────────────────────── -->
    <div class="flex items-center justify-between">
        <div class="inline-flex items-center gap-2 bg-white/5 rounded-full px-3.5 py-1.5
                    ring-1 ring-white/10 text-xs text-gray-400 backdrop-blur">
            <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-pulse-dot"></span>
            Discord Guard
        </div>
        <a href="/admin/gate.php"
           class="text-xs text-gray-600 hover:text-gray-400 transition-colors">
            Admin →
        </a>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         PROFIL DISCORD
         ══════════════════════════════════════════════════════════════ -->
    <?php if (!empty($profile['username'])): ?>
    <div class="bg-white/[.04] backdrop-blur rounded-2xl ring-1 ring-white/[.07] overflow-hidden">

        <!-- Bannière dégradée -->
        <div class="h-20 bg-gradient-to-br from-indigo-900/60 via-purple-900/40 to-slate-900/60
                    relative overflow-hidden">
            <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\' fill=\'%23ffffff\' fill-opacity=\'0.03\' fill-rule=\'evenodd\'/%3E%3C/svg%3E')]"></div>
        </div>

        <div class="px-6 pb-6">
            <!-- Avatar + nom -->
            <div class="flex items-end gap-4 -mt-10 mb-4">
                <div class="relative flex-shrink-0">
                    <?php if (!empty($profile['avatar_url'])): ?>
                    <img src="<?= htmlspecialchars($profile['avatar_url'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="Avatar"
                         class="w-20 h-20 rounded-full ring-4 ring-[#0b0d14] object-cover bg-gray-800"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="w-20 h-20 rounded-full ring-4 ring-[#0b0d14] bg-indigo-900
                                items-center justify-center text-3xl font-bold text-indigo-300"
                         style="display:none">
                        <?= mb_strtoupper(mb_substr($profile['username'], 0, 1)) ?>
                    </div>
                    <?php else: ?>
                    <div class="w-20 h-20 rounded-full ring-4 ring-[#0b0d14] bg-indigo-900
                                flex items-center justify-center text-3xl font-bold text-indigo-300">
                        <?= mb_strtoupper(mb_substr($profile['username'], 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <!-- Indicateur de statut -->
                    <span class="absolute bottom-1 right-1 w-5 h-5 rounded-full
                                 <?= $cfg['dot'] ?> ring-2 ring-[#0b0d14]
                                 shadow-lg <?= $cfg['dot_shadow'] ?>
                                 animate-pulse-dot"></span>
                </div>

                <div class="mb-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-xl font-bold text-white truncate">
                            <?= htmlspecialchars($profile['username'], ENT_QUOTES, 'UTF-8') ?>
                        </h1>
                        <?php if (!empty($profile['discriminator'])): ?>
                        <span class="text-gray-500 text-sm">
                            #<?= htmlspecialchars($profile['discriminator'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <!-- Badge statut inline -->
                    <span class="inline-flex items-center gap-1.5 mt-1 px-2.5 py-0.5 rounded-full
                                 text-xs font-medium ring-1 <?= $cfg['badge'] ?>">
                        <span class="w-1.5 h-1.5 rounded-full <?= $cfg['dot'] ?>"></span>
                        <?= htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </div>

            <!-- Bio -->
            <?php if (!empty($profile['bio'])): ?>
            <p class="text-gray-400 text-sm leading-relaxed mb-4 bg-white/[.03] rounded-xl px-4 py-3
                       ring-1 ring-white/[.05]">
                <?= nl2br(htmlspecialchars($profile['bio'], ENT_QUOTES, 'UTF-8')) ?>
            </p>
            <?php endif; ?>

            <!-- Méta infos -->
            <div class="flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-gray-500">
                <?php if (!empty($profile['joined'])): ?>
                <span class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Membre depuis <?= htmlspecialchars($profile['joined'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
                <span class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    Page de surveillance publique
                </span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════
         CARTE STATUT PRINCIPAL
         ══════════════════════════════════════════════════════════════ -->
    <div class="relative">
        <div class="status-glow bg-gradient-to-br <?= $cfg['bar'] ?>"></div>
        <div class="relative bg-white/[.04] backdrop-blur rounded-2xl ring-1 <?= $cfg['ring'] ?>
                    p-6 shadow-2xl <?= $cfg['glow'] ?> overflow-hidden">

            <!-- Décoration fond -->
            <div class="absolute top-0 right-0 w-48 h-48 opacity-5 pointer-events-none">
                <svg viewBox="0 0 200 200" class="w-full h-full animate-spin-slow" fill="none">
                    <path d="M100 10 L190 55 L190 145 L100 190 L10 145 L10 55 Z"
                          stroke="white" stroke-width="1" fill="none"/>
                    <path d="M100 30 L170 67.5 L170 132.5 L100 170 L30 132.5 L30 67.5 Z"
                          stroke="white" stroke-width="1" fill="none"/>
                </svg>
            </div>

            <div class="flex items-start gap-5">
                <!-- Icône animée -->
                <div class="flex-shrink-0 animate-float text-4xl leading-none mt-0.5">
                    <?= $cfg['icon'] ?>
                </div>

                <div class="flex-1 min-w-0">
                    <p class="text-xs font-medium uppercase tracking-widest text-gray-500 mb-1">
                        Statut de sécurité
                    </p>
                    <h2 class="text-2xl font-bold text-white mb-2">
                        <?= htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') ?>
                    </h2>

                    <?php if ($lastUpdated): ?>
                    <p class="text-xs text-gray-500 mb-4">
                        Dernière mise à jour : <?= htmlspecialchars(format_date($lastUpdated), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <?php else: ?>
                    <p class="text-xs text-gray-600 mb-4">Aucune mise à jour enregistrée</p>
                    <?php endif; ?>

                    <!-- Message d'alerte -->
                    <?php if ($status !== 'secure' && !empty($customMessage)): ?>
                    <div class="bg-white/[.05] rounded-xl px-4 py-3 ring-1 ring-white/[.08]">
                        <p class="text-sm leading-relaxed
                                  <?= $status === 'compromised' ? 'text-red-300' : 'text-yellow-300' ?>">
                            ⚠️ <?= nl2br(htmlspecialchars($customMessage, ENT_QUOTES, 'UTF-8')) ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="bg-white/[.03] rounded-xl px-4 py-3 ring-1 ring-white/[.06]">
                        <p class="text-sm text-gray-400 leading-relaxed">
                            Aucune activité suspecte n'a été détectée. Ce compte Discord est
                            activement surveillé et sécurisé.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         INDICATEURS RAPIDES
         ══════════════════════════════════════════════════════════════ -->
    <div class="grid grid-cols-3 gap-3">
        <?php
        $totalEvents = count(get_status_history(999));
        $lastChange  = $history[0] ?? null;
        $isStable    = $lastChange && $lastChange['status'] === 'secure';
        ?>
        <div class="bg-white/[.03] rounded-xl ring-1 ring-white/[.06] p-4 text-center">
            <p class="text-2xl font-bold text-white"><?= $totalEvents ?></p>
            <p class="text-xs text-gray-500 mt-1">Événements</p>
        </div>
        <div class="bg-white/[.03] rounded-xl ring-1 ring-white/[.06] p-4 text-center">
            <p class="text-2xl font-bold <?= $status === 'secure' ? 'text-green-300' : ($status === 'warning' ? 'text-yellow-300' : 'text-red-300') ?>">
                <?= match($status) { 'secure' => '✓', 'warning' => '⚠', default => '✕' } ?>
            </p>
            <p class="text-xs text-gray-500 mt-1">Statut actuel</p>
        </div>
        <div class="bg-white/[.03] rounded-xl ring-1 ring-white/[.06] p-4 text-center">
            <p class="text-2xl font-bold <?= $isStable ? 'text-green-300' : 'text-gray-400' ?>">
                <?= $isStable ? '🟢' : '⬜' ?>
            </p>
            <p class="text-xs text-gray-500 mt-1">Dernier état</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         HISTORIQUE DES ÉVÉNEMENTS
         ══════════════════════════════════════════════════════════════ -->
    <?php if ($profile['show_history'] && !empty($history)): ?>
    <div class="bg-white/[.03] backdrop-blur rounded-2xl ring-1 ring-white/[.06] overflow-hidden">

        <div class="px-5 py-4 border-b border-white/[.05] flex items-center justify-between">
            <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                <svg class="w-4 h-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                Historique des événements
            </h3>
            <span class="text-xs text-gray-600"><?= count($history) ?> entrées</span>
        </div>

        <div class="px-5 py-4">
            <div class="relative tl-line pl-6 space-y-5">
                <?php foreach ($history as $i => $event):
                    $hCfg = history_cfg($event['status']);
                ?>
                <div class="relative">
                    <!-- Dot sur la ligne -->
                    <span class="absolute -left-[1.65rem] top-1 w-3 h-3 rounded-full
                                 <?= $hCfg['dot'] ?> ring-2 ring-[#0b0d14] flex-shrink-0
                                 <?= $i === 0 ? 'shadow-md shadow-' . explode('-', $hCfg['dot'])[1] . '-400/40' : '' ?>"></span>

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-0.5">
                        <div>
                            <p class="text-sm font-medium <?= $hCfg['text'] ?>">
                                <?= htmlspecialchars($hCfg['label'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($i === 0): ?>
                                <span class="ml-1.5 text-xs bg-white/[.08] text-gray-400
                                             rounded-full px-1.5 py-0.5">actuel</span>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($event['note'])): ?>
                            <p class="text-xs text-gray-500 mt-0.5">
                                <?= htmlspecialchars($event['note'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-600 whitespace-nowrap ml-2 flex-shrink-0">
                            <?= htmlspecialchars(format_date($event['date']), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════
         NOTE D'INFORMATION (qu'est-ce que ce site ?)
         ══════════════════════════════════════════════════════════════ -->
    <div class="bg-white/[.02] rounded-xl ring-1 ring-white/[.05] px-5 py-4">
        <h4 class="text-xs font-semibold text-gray-400 mb-2 uppercase tracking-widest">
            À propos de cette page
        </h4>
        <p class="text-xs text-gray-600 leading-relaxed">
            Cette page affiche en temps réel le statut de sécurité de ce compte Discord.
            Elle est mise à jour manuellement par le propriétaire du compte.
            Si le statut passe en mode <span class="text-yellow-500">activité suspecte</span>
            ou <span class="text-red-500">compromis</span>, méfiez-vous de tout message
            ou lien envoyé depuis ce compte.
        </p>
    </div>

    <!-- ── Pied de page ───────────────────────────────────────────── -->
    <p class="text-center text-gray-700 text-xs pb-4">
        Protégé par <strong class="text-gray-600">Discord Guard</strong>
        &nbsp;·&nbsp;
        <a href="/admin/gate.php" class="hover:text-gray-500 transition-colors">Admin</a>
    </p>

</div>

</body>
</html>

// Configuration visuelle selon le statut
$config = match ($status) {
    'warning'     => [
        'label'       => 'Activité suspecte',
        'emoji'       => '🟠',
        'ring'        => 'ring-yellow-400',
        'dot'         => 'bg-yellow-400',
        'title_color' => 'text-yellow-300',
        'badge_bg'    => 'bg-yellow-900/40',
        'badge_text'  => 'text-yellow-300',
        'badge_ring'  => 'ring-yellow-400/30',
        'glow'        => 'shadow-yellow-400/20',
    ],
    'compromised' => [
        'label'       => 'Compte compromis',
        'emoji'       => '🔴',
        'ring'        => 'ring-red-500',
        'dot'         => 'bg-red-500',
        'title_color' => 'text-red-400',
        'badge_bg'    => 'bg-red-900/40',
        'badge_text'  => 'text-red-300',
        'badge_ring'  => 'ring-red-400/30',
        'glow'        => 'shadow-red-400/20',
    ],
    default       => [
        'label'       => 'Compte sécurisé',
        'emoji'       => '🟢',
        'ring'        => 'ring-green-400',
        'dot'         => 'bg-green-400',
        'title_color' => 'text-green-300',
        'badge_bg'    => 'bg-green-900/40',
        'badge_text'  => 'text-green-300',
        'badge_ring'  => 'ring-green-400/30',
        'glow'        => 'shadow-green-400/20',
    ],
};
?>
<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Guard — Statut</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <style>
        @keyframes pulse-ring {
            0%   { transform: scale(0.95); opacity: 0.7; }
            70%  { transform: scale(1.1);  opacity: 0; }
            100% { transform: scale(0.95); opacity: 0; }
        }
        .pulse-ring::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 9999px;
            animation: pulse-ring 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        <?php if ($status === 'secure'): ?>
        .pulse-ring::before { background: rgba(74, 222, 128, 0.4); }
        <?php elseif ($status === 'warning'): ?>
        .pulse-ring::before { background: rgba(250, 204, 21, 0.4); }
        <?php else: ?>
        .pulse-ring::before { background: rgba(239, 68, 68, 0.4); }
        <?php endif; ?>
    </style>
</head>
<body class="bg-gray-950 text-gray-100 min-h-screen flex flex-col items-center justify-center p-6 font-sans">

    <!-- Carte principale -->
    <div class="w-full max-w-md">

        <!-- Logo / titre -->
        <div class="text-center mb-10">
            <div class="inline-flex items-center gap-2 bg-gray-800/60 rounded-full px-4 py-1.5 ring-1 ring-gray-700 text-sm text-gray-400 mb-6">
                <span class="w-2 h-2 rounded-full bg-indigo-400 inline-block"></span>
                Discord Guard
            </div>
            <h1 class="text-3xl font-bold text-white tracking-tight">Statut du compte</h1>
            <p class="text-gray-500 mt-2 text-sm">Surveillance de la sécurité en temps réel</p>
        </div>

        <!-- Badge de statut -->
        <div class="relative bg-gray-900 rounded-2xl p-8 ring-1 ring-gray-800 shadow-2xl <?= $config['glow'] ?> shadow-2xl text-center">

            <!-- Indicateur animé -->
            <div class="flex justify-center mb-6">
                <div class="relative flex items-center justify-center">
                    <div class="pulse-ring relative w-16 h-16 rounded-full <?= $config['dot'] ?> flex items-center justify-center shadow-lg">
                        <span class="text-2xl"><?= $config['emoji'] ?></span>
                    </div>
                </div>
            </div>

            <!-- Libellé -->
            <h2 class="text-2xl font-bold <?= $config['title_color'] ?> mb-2">
                <?= htmlspecialchars($config['label'], ENT_QUOTES, 'UTF-8') ?>
            </h2>

            <?php if ($lastUpdated): ?>
            <p class="text-gray-500 text-xs mb-6">
                Mis à jour le <?= htmlspecialchars(format_date($lastUpdated), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php else: ?>
            <p class="text-gray-600 text-xs mb-6">Aucune mise à jour enregistrée</p>
            <?php endif; ?>

            <!-- Message de sécurité -->
            <?php if ($status !== 'secure' && !empty($customMessage)): ?>
            <div class="<?= $config['badge_bg'] ?> rounded-xl p-4 ring-1 <?= $config['badge_ring'] ?>">
                <p class="<?= $config['badge_text'] ?> text-sm font-medium leading-relaxed">
                    ⚠️ <?= htmlspecialchars($customMessage, ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
            <?php else: ?>
            <div class="bg-gray-800/50 rounded-xl p-4 ring-1 ring-gray-700/50">
                <p class="text-gray-400 text-sm leading-relaxed">
                    Aucune activité suspecte détectée. Ce compte Discord est surveillé et sécurisé.
                </p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pied de page -->
        <p class="text-center text-gray-600 text-xs mt-8">
            Propulsé par <strong class="text-gray-500">Discord Guard</strong>
            &nbsp;·&nbsp;
            <a href="/admin/login.php" class="hover:text-gray-400 transition-colors">Admin</a>
        </p>
    </div>

</body>
</html>

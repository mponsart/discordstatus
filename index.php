<?php
/**
 * Page publique — CV / Portfolio + statut Discord
 */

require_once __DIR__ . '/functions.php';

// ─── Statut Discord ────────────────────────────────────────────────────────
$statusData    = get_status();
$status        = $statusData['status'];
$customMessage = $statusData['customMessage'];
$lastUpdated   = $statusData['lastUpdated'];

$username    = get_setting('discord_username',        '');
$globalName  = get_setting('discord_global_name',     '');
$avatarUrl   = get_setting('discord_avatar_url',      '');
$displayName = $globalName ?: $username;

$lastCheck     = get_setting('discord_last_check');
$monitorStatus = get_setting('discord_monitor_status', 'not_configured');
$botConfigured = defined('DISCORD_BOT_TOKEN')      && DISCORD_BOT_TOKEN      !== ''
              && defined('DISCORD_TARGET_USER_ID') && DISCORD_TARGET_USER_ID !== ''
              && defined('DISCORD_GUILD_ID')       && DISCORD_GUILD_ID       !== '';
$botActive     = $botConfigured && $monitorStatus === 'ok';

$showHistory = get_setting('show_public_history', '1') === '1';
$history     = $showHistory ? get_status_history(10) : [];

// ─── Données CV ────────────────────────────────────────────────────────────
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

$name = $displayName ?: 'Maxime Ponsart';

// ─── Config visuelle statut ────────────────────────────────────────────────
$S = match ($status) {
    'warning'     => ['label' => 'Activité suspecte',  'dot' => 'bg-yellow-400', 'hex' => '#facc15', 'icon' => '⚠️',  'badge' => 'bg-yellow-500/10 text-yellow-300 ring-yellow-400/20'],
    'compromised' => ['label' => 'Compte compromis',   'dot' => 'bg-red-500',    'hex' => '#ef4444', 'icon' => '🚨', 'badge' => 'bg-red-500/10 text-red-300 ring-red-400/20'],
    default       => ['label' => 'Compte sécurisé',    'dot' => 'bg-emerald-400','hex' => '#34d399', 'icon' => '🛡️', 'badge' => 'bg-emerald-500/10 text-emerald-300 ring-emerald-400/20'],
};

function hcfg(string $s): array {
    return match ($s) {
        'warning'     => ['dot' => 'bg-yellow-400', 'text' => 'text-yellow-300', 'label' => 'Activité suspecte'],
        'compromised' => ['dot' => 'bg-red-500',    'text' => 'text-red-300',    'label' => 'Compte compromis'],
        default       => ['dot' => 'bg-emerald-400','text' => 'text-emerald-300','label' => 'Compte sécurisé'],
    };
}

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
    <title><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?><?= $cv['title'] ? ' — ' . htmlspecialchars($cv['title'], ENT_QUOTES, 'UTF-8') : '' ?></title>
    <meta name="description" content="<?= htmlspecialchars($cv['tagline'] ?: $name, ENT_QUOTES, 'UTF-8') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
    <style>
        body { background: #070913; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .blob { border-radius: 50%; filter: blur(100px); position: absolute; pointer-events: none; }
        .card { background: rgba(255,255,255,.035); border: 1px solid rgba(255,255,255,.07); border-radius: 20px; }
        .card-sm { background: rgba(255,255,255,.025); border: 1px solid rgba(255,255,255,.055); border-radius: 14px; }
        .tag { display:inline-flex;align-items:center;padding:4px 12px;border-radius:99px;font-size:.75rem;font-weight:600;background:rgba(99,102,241,.12);color:#a5b4fc;border:1px solid rgba(99,102,241,.2); }
        .tl  { border-left: 1px solid rgba(255,255,255,.07); }
        @keyframes pulse-ring { 0%{transform:scale(1);opacity:.6} 100%{transform:scale(2.2);opacity:0} }
        @keyframes breathe    { 0%,100%{opacity:1} 50%{opacity:.55} }
        @keyframes slide-up   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
        .anim   { animation: slide-up .5s ease both; }
        .anim-1 { animation-delay:.05s }
        .anim-2 { animation-delay:.12s }
        .anim-3 { animation-delay:.20s }
        .anim-4 { animation-delay:.28s }
        .anim-5 { animation-delay:.36s }
        .anim-6 { animation-delay:.44s }
        .animate-pulse-ring { animation: pulse-ring 2s ease-out infinite; }
        .animate-breathe    { animation: breathe 3s ease-in-out infinite; }
        ::-webkit-scrollbar { width: 3px; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 4px; }
    </style>
</head>
<body class="text-gray-100 min-h-screen antialiased overflow-x-hidden">

<div class="fixed inset-0 overflow-hidden pointer-events-none" aria-hidden="true">
    <div class="blob w-[600px] h-[600px] -top-60 -left-48 opacity-[.035] bg-indigo-600"></div>
    <div class="blob w-96 h-96 top-1/2 -right-32 opacity-[.025] bg-violet-500"></div>
    <div class="blob w-[500px] h-64 bottom-0 left-1/2 -translate-x-1/2 opacity-[.03]"
         style="background:<?= $S['hex'] ?>"></div>
</div>

<div class="relative z-10 max-w-3xl mx-auto px-4 py-10 space-y-6">

    <!-- ██ HEADER ██ -->
    <div class="card overflow-hidden anim anim-1">
        <div class="h-28 relative overflow-hidden"
             style="background:linear-gradient(135deg,#0b0f22 0%,#12103a 50%,#0c0c1c 100%)">
            <div class="absolute inset-0" style="background:
                radial-gradient(circle at 25% 55%,#6366f133 0%,transparent 55%),
                radial-gradient(circle at 75% 35%,#8b5cf633 0%,transparent 45%)"></div>
        </div>

        <div class="px-6 pb-6">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 -mt-14 mb-5">

                <!-- Avatar -->
                <div class="relative flex-shrink-0">
                    <?php if (!empty($avatarUrl)): ?>
                    <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="Avatar" width="88" height="88"
                         class="w-[88px] h-[88px] rounded-2xl ring-4 ring-[#070913] object-cover bg-gray-800"
                         onerror="this.style.display='none';document.getElementById('av-fb').style.display='flex'">
                    <div id="av-fb" style="display:none"
                         class="w-[88px] h-[88px] rounded-2xl ring-4 ring-[#070913] bg-indigo-950
                                items-center justify-center text-4xl font-black text-indigo-400">
                        <?= mb_strtoupper(mb_substr($name, 0, 1)) ?>
                    </div>
                    <?php else: ?>
                    <div class="w-[88px] h-[88px] rounded-2xl ring-4 ring-[#070913] bg-indigo-950
                                flex items-center justify-center text-4xl font-black text-indigo-400">
                        <?= mb_strtoupper(mb_substr($name, 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <div class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full ring-2 ring-[#070913] <?= $S['dot'] ?>">
                        <span class="absolute w-full h-full rounded-full <?= $S['dot'] ?> opacity-60 animate-pulse-ring"></span>
                    </div>
                </div>

                <!-- Liens sociaux -->
                <div class="flex items-center gap-2 sm:mb-1 flex-wrap">
                    <?php if (!empty($cv['github'])): ?>
                    <a href="<?= htmlspecialchars($cv['github'], ENT_QUOTES, 'UTF-8') ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="flex items-center gap-1.5 bg-white/[.05] hover:bg-white/[.09] ring-1 ring-white/[.08] rounded-full px-3 py-1.5 text-xs text-gray-300 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844a9.59 9.59 0 012.504.337c1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/></svg>
                        GitHub
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($cv['linkedin'])): ?>
                    <a href="<?= htmlspecialchars($cv['linkedin'], ENT_QUOTES, 'UTF-8') ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="flex items-center gap-1.5 bg-white/[.05] hover:bg-white/[.09] ring-1 ring-white/[.08] rounded-full px-3 py-1.5 text-xs text-gray-300 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                        LinkedIn
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($cv['email'])): ?>
                    <a href="mailto:<?= htmlspecialchars($cv['email'], ENT_QUOTES, 'UTF-8') ?>"
                       class="flex items-center gap-1.5 bg-white/[.05] hover:bg-white/[.09] ring-1 ring-white/[.08] rounded-full px-3 py-1.5 text-xs text-gray-300 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        Contact
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <h1 class="text-3xl font-black text-white tracking-tight mb-1">
                <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
            </h1>
            <?php if (!empty($cv['title'])): ?>
            <p class="text-indigo-400 font-semibold text-sm mb-2">
                <?= htmlspecialchars($cv['title'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php endif; ?>
            <?php if (!empty($cv['tagline'])): ?>
            <p class="text-sm text-gray-400 leading-relaxed max-w-xl">
                <?= nl2br(htmlspecialchars($cv['tagline'], ENT_QUOTES, 'UTF-8')) ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ██ COMPÉTENCES ██ -->
    <?php if (!empty($cv['skills'])): ?>
    <div class="card px-6 py-5 anim anim-2">
        <p class="text-[10px] font-bold tracking-widest text-gray-600 uppercase mb-3">Compétences</p>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($cv['skills'] as $skill): ?>
            <span class="tag"><?= htmlspecialchars($skill, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ██ EXPÉRIENCES ██ -->
    <?php if (!empty($cv['experiences'])): ?>
    <div class="card px-6 py-5 anim anim-3">
        <p class="text-[10px] font-bold tracking-widest text-gray-600 uppercase mb-5">Expériences</p>
        <div class="tl pl-5 ml-2 space-y-6">
            <?php foreach ($cv['experiences'] as $exp): ?>
            <div class="relative">
                <span class="absolute -left-[1.55rem] top-1.5 w-2.5 h-2.5 rounded-full bg-indigo-500 ring-2 ring-[#070913]"></span>
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-0.5 mb-1">
                    <div>
                        <p class="font-semibold text-white text-sm"><?= htmlspecialchars($exp['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if (!empty($exp['company'])): ?>
                        <p class="text-indigo-400/80 text-xs font-medium"><?= htmlspecialchars($exp['company'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($exp['dates'])): ?>
                    <span class="text-xs text-gray-600 flex-shrink-0 mt-0.5"><?= htmlspecialchars($exp['dates'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($exp['description'])): ?>
                <p class="text-xs text-gray-500 leading-relaxed"><?= nl2br(htmlspecialchars($exp['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ██ FORMATION ██ -->
    <?php if (!empty($cv['education'])): ?>
    <div class="card px-6 py-5 anim anim-4">
        <p class="text-[10px] font-bold tracking-widest text-gray-600 uppercase mb-5">Formation</p>
        <div class="tl pl-5 ml-2 space-y-5">
            <?php foreach ($cv['education'] as $edu): ?>
            <div class="relative">
                <span class="absolute -left-[1.55rem] top-1.5 w-2.5 h-2.5 rounded-full bg-violet-500 ring-2 ring-[#070913]"></span>
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-0.5 mb-1">
                    <div>
                        <p class="font-semibold text-white text-sm"><?= htmlspecialchars($edu['degree'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if (!empty($edu['school'])): ?>
                        <p class="text-violet-400/80 text-xs font-medium"><?= htmlspecialchars($edu['school'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($edu['dates'])): ?>
                    <span class="text-xs text-gray-600 flex-shrink-0 mt-0.5"><?= htmlspecialchars($edu['dates'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($edu['description'])): ?>
                <p class="text-xs text-gray-500 leading-relaxed"><?= nl2br(htmlspecialchars($edu['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ██ PROJETS ██ -->
    <?php if (!empty($cv['projects'])): ?>
    <div class="anim anim-5">
        <p class="text-[10px] font-bold tracking-widest text-gray-600 uppercase mb-3 px-1">Projets</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <?php foreach ($cv['projects'] as $proj): ?>
            <div class="card-sm p-5 flex flex-col gap-2 hover:ring-1 hover:ring-indigo-500/20 transition-all">
                <div class="flex items-start justify-between gap-2">
                    <p class="font-semibold text-white text-sm"><?= htmlspecialchars($proj['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if (!empty($proj['url'])): ?>
                    <a href="<?= htmlspecialchars($proj['url'], ENT_QUOTES, 'UTF-8') ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="text-indigo-400 hover:text-indigo-300 transition-colors flex-shrink-0" aria-label="Lien">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    </a>
                    <?php endif; ?>
                </div>
                <?php if (!empty($proj['description'])): ?>
                <p class="text-xs text-gray-500 leading-relaxed flex-1"><?= nl2br(htmlspecialchars($proj['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
                <?php if (!empty($proj['tech'])): ?>
                <div class="flex flex-wrap gap-1 mt-auto pt-1">
                    <?php foreach (array_filter(array_map('trim', explode(',', $proj['tech']))) as $t): ?>
                    <span class="inline-block text-[10px] font-medium bg-indigo-900/30 text-indigo-400 ring-1 ring-indigo-500/20 rounded-md px-1.5 py-0.5">
                        <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ██ WIDGET DISCORD STATUS ██ -->
    <div class="anim anim-6">
        <p class="text-[10px] font-bold tracking-widest text-gray-600 uppercase mb-3 px-1">Statut Discord</p>
        <div class="card px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
                <div class="flex items-center gap-3">
                    <div class="relative w-10 h-10 flex-shrink-0">
                        <?php if (!empty($avatarUrl)): ?>
                        <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""
                             width="40" height="40"
                             class="w-10 h-10 rounded-xl ring-2 ring-white/[.05] object-cover bg-gray-800">
                        <?php else: ?>
                        <div class="w-10 h-10 rounded-xl bg-indigo-950 flex items-center justify-center text-sm font-bold text-indigo-400">
                            <?= mb_strtoupper(mb_substr($name, 0, 1)) ?>
                        </div>
                        <?php endif; ?>
                        <span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full <?= $S['dot'] ?> ring-2 ring-[#070913]">
                            <span class="absolute w-full h-full rounded-full <?= $S['dot'] ?> opacity-60 animate-pulse-ring"></span>
                        </span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-white"><?= htmlspecialchars($displayName ?: $name, ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-xs text-gray-600">Compte Discord</p>
                    </div>
                </div>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full ring-1 self-start sm:self-auto <?= $S['badge'] ?>">
                    <?= $S['icon'] ?> <?= htmlspecialchars($S['label'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <?php if ($status !== 'secure' && !empty($customMessage)): ?>
            <div class="mb-3 bg-white/[.025] rounded-xl px-4 py-3 text-xs text-gray-400 leading-relaxed ring-1 ring-white/[.05]">
                <?= nl2br(htmlspecialchars($customMessage, ENT_QUOTES, 'UTF-8')) ?>
            </div>
            <?php endif; ?>

            <div class="flex flex-wrap gap-4 text-xs text-gray-600">
                <?php if ($lastCheck): ?>
                <span class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 <?= $botActive ? 'text-emerald-700' : 'text-gray-700' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Vérifié <span data-ts="<?= htmlspecialchars($lastCheck, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ago_php($lastCheck), ENT_QUOTES, 'UTF-8') ?></span>
                </span>
                <?php endif; ?>
                <span class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full <?= $botConfigured ? ($botActive ? 'bg-emerald-500' : 'bg-red-500') : 'bg-gray-700' ?>"></span>
                    <?= $botConfigured ? ($botActive ? 'Bot OK' : 'Erreur API') : 'Manuel' ?>
                </span>
            </div>

            <?php if ($showHistory && !empty($history)): ?>
            <div class="mt-4 border-t border-white/[.05] pt-4">
                <p class="text-[10px] font-bold tracking-widest text-gray-700 uppercase mb-3">Historique</p>
                <div class="tl pl-4 ml-1.5 space-y-3">
                    <?php foreach ($history as $i => $ev):
                        $h = hcfg($ev['status']);
                        $isAuto = str_starts_with($ev['note'] ?? '', '[AUTO]');
                        $noteText = $isAuto ? trim(substr($ev['note'], 6)) : ($ev['note'] ?? '');
                    ?>
                    <div class="relative text-xs">
                        <span class="absolute -left-[1.3rem] top-1 w-2 h-2 rounded-full <?= $h['dot'] ?> ring-2 ring-[#070913]"></span>
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="<?= $h['text'] ?> font-medium"><?= htmlspecialchars($h['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($i === 0): ?><span class="text-[10px] bg-white/[.06] text-gray-500 rounded-full px-1.5 py-0.5">actuel</span><?php endif; ?>
                                <?php if ($isAuto): ?><span class="text-[10px] bg-indigo-900/40 text-indigo-500 rounded-full px-1.5 py-0.5">🤖</span><?php endif; ?>
                            </div>
                            <span class="text-gray-700 flex-shrink-0" data-ts="<?= htmlspecialchars($ev['date'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ago_php($ev['date']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if (!empty($noteText)): ?>
                        <p class="text-gray-700 mt-0.5"><?= htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <p class="text-center text-gray-800 text-xs pb-2">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
        &nbsp;&middot;&nbsp;
        <a href="/admin/login.php" class="hover:text-gray-600 transition-colors">Admin</a>
    </p>

</div>

<script>
(function () {
    function timeAgo(iso) {
        if (!iso) return '&mdash;';
        const d = Math.floor((Date.now() - new Date(iso)) / 1000);
        if (d < 0)     return 'à venir';
        if (d < 60)    return d + 's';
        if (d < 3600)  return Math.floor(d / 60) + ' min';
        if (d < 86400) return Math.floor(d / 3600) + ' h';
        return Math.floor(d / 86400) + ' j';
    }
    function refresh() {
        document.querySelectorAll('[data-ts]').forEach(function (el) {
            var ts = el.getAttribute('data-ts');
            if (ts) el.textContent = timeAgo(ts);
        });
    }
    refresh();
    setInterval(refresh, 30000);
})();
</script>

</body>
</html>

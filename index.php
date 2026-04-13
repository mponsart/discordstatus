<?php
/**
 * Discord Guard — Page publique de statut
 * Accessible à tous, sans authentification.
 */

require_once __DIR__ . '/functions.php';

$statusData    = get_status();
$status        = $statusData['status'];
$customMessage = $statusData['customMessage'];
$lastUpdated   = $statusData['lastUpdated'];

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

<?php
/**
 * Discord Guard — Script de surveillance automatique
 *
 * Ce script est conçu pour être exécuté via une tâche cron cPanel.
 * Il interroge l'API Discord pour détecter les anomalies sur le compte surveillé.
 *
 * ─── Configuration cPanel Cron Jobs ──────────────────────────────────────────
 *  Fréquence recommandée : toutes les 5 minutes
 *  Commande :
 *    /usr/bin/php /home/VOTRE_USER/public_html/cron/monitor.php >> /dev/null 2>&1
 *
 *  Pour journaliser les résultats :
 *    /usr/bin/php /home/VOTRE_USER/public_html/cron/monitor.php >> /home/VOTRE_USER/logs/discord_guard.log 2>&1
 *
 * ─── Accès HTTP (optionnel) ───────────────────────────────────────────────────
 *  L'accès web est bloqué par défaut via .htaccess.
 *  Pour permettre un déclenchement HTTP depuis le dashboard (bouton "Vérifier maintenant"),
 *  le script est également appelé via admin/actions.php en CLI simulé.
 *
 * ─── Sécurité ─────────────────────────────────────────────────────────────────
 *  - Ce fichier NE doit PAS être accessible depuis le web (protégé par .htaccess)
 *  - Il s'exécute uniquement en CLI ou via actions.php (admin authentifié)
 */

// Bloc d'accès web direct (double protection au cas où .htaccess échouerait)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit. Ce script s\'exécute uniquement en tâche cron CLI.');
}

// Résoudre le chemin depuis n'importe quel répertoire de travail
$root = dirname(__DIR__);
require_once $root . '/functions.php';

$startTime = microtime(true);

echo '[' . date('Y-m-d H:i:s') . '] Discord Guard Monitor — démarrage' . PHP_EOL;

$result = run_discord_monitor();
$elapsed = round((microtime(true) - $startTime) * 1000);

switch ($result['status'] ?? 'unknown') {
    case 'not_configured':
        echo "[{$elapsed}ms] ⚫ Non configuré : DISCORD_BOT_TOKEN, DISCORD_TARGET_USER_ID ou DISCORD_GUILD_ID manquant." . PHP_EOL;
        break;

    case 'user_not_in_guild':
        echo "[{$elapsed}ms] 🚨 ALERTE : Le compte surveillé n'est plus dans le serveur de surveillance (404)." . PHP_EOL;
        break;

    case 'api_error':
        $http  = $result['http']  ?? '?';
        $error = $result['error'] ?? 'inconnue';
        echo "[{$elapsed}ms] ❌ Erreur API Discord (HTTP {$http}) : {$error}" . PHP_EOL;
        break;

    case 'ok':
        $user      = $result['user']     ?? [];
        $changes   = $result['changes']  ?? [];
        $score     = $result['score']    ?? 0;
        $firstRun  = $result['first_run'] ?? false;
        $username  = $user['username']   ?? '?';

        if ($firstRun) {
            echo "[{$elapsed}ms] ✅ Première exécution — snapshot initialisé pour @{$username}." . PHP_EOL;
        } elseif (empty($changes)) {
            echo "[{$elapsed}ms] ✅ OK — Aucun changement détecté pour @{$username}." . PHP_EOL;
        } else {
            $nbChanges = count($changes);
            echo "[{$elapsed}ms] ⚠️  {$nbChanges} changement(s) détecté(s) pour @{$username} (score anomalie : {$score})." . PHP_EOL;
            foreach ($changes as $c) {
                $type = $c['type'] ?? '?';
                $from = isset($c['from']) ? " ({$c['from']} → {$c['to']})" : '';
                echo "    → {$type}{$from}" . PHP_EOL;
            }
        }
        break;

    default:
        echo "[{$elapsed}ms] ❓ Statut inconnu : " . json_encode($result) . PHP_EOL;
}

echo '[' . date('Y-m-d H:i:s') . '] Discord Guard Monitor — terminé' . PHP_EOL;

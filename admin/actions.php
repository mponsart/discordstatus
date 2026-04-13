<?php
/**
 * Discord Guard — Actions admin (POST uniquement)
 *
 * Actions supportées :
 *   set_status     → change le statut du compte
 *   update_settings → sauvegarde webhook + message
 *   clear_logs      → vide logs.json
 *   clear_alerts    → vide alerts.json
 */

require_once __DIR__ . '/../functions.php';
init_secure_session();
check_admin_ip();
require_secret_token();
require_admin();

// Uniquement POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/dashboard.php');
    exit;
}

// Vérification CSRF
$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    // Token CSRF invalide : redirection sans message d'erreur explicite pour éviter
    // toute fuite d'information, mais on logge l'événement.
    log_action('csrf_failure', ['attempted_action' => $_POST['action'] ?? 'unknown']);
    header('Location: /admin/dashboard.php');
    exit;
}

$action = $_POST['action'] ?? '';

// =============================================================================
// Changement de statut
// =============================================================================
if ($action === 'set_status') {
    $newStatus = $_POST['status'] ?? '';
    $allowed   = ['secure', 'warning', 'compromised'];

    if (!in_array($newStatus, $allowed, true)) {
        header('Location: /admin/dashboard.php?error=invalid_status');
        exit;
    }

    set_status($newStatus);
    log_action('status_changed', ['new_status' => $newStatus]);
    send_discord_status_change($newStatus);

    header('Location: /admin/dashboard.php?success=status_updated');
    exit;
}

// =============================================================================
// Mise à jour des paramètres
// =============================================================================
if ($action === 'update_settings') {
    $webhookUrl    = trim($_POST['discord_webhook'] ?? '');
    $customMessage = trim($_POST['custom_message']  ?? '');

    // Validation de l'URL du webhook
    if (!empty($webhookUrl)) {
        // On autorise uniquement les URLs HTTPS de Discord
        if (
            !filter_var($webhookUrl, FILTER_VALIDATE_URL)
            || parse_url($webhookUrl, PHP_URL_SCHEME) !== 'https'
            || !str_contains(parse_url($webhookUrl, PHP_URL_HOST) ?? '', 'discord')
        ) {
            header('Location: /admin/dashboard.php?error=invalid_webhook');
            exit;
        }
    }

    // Limiter la longueur du message
    $customMessage = substr($customMessage, 0, 500);

    set_setting('discord_webhook', $webhookUrl);
    set_setting('custom_message',  $customMessage);

    log_action('settings_updated');
    header('Location: /admin/dashboard.php?success=settings_saved&tab=settings');
    exit;
}

// =============================================================================
// Purge des logs
// =============================================================================
if ($action === 'clear_logs') {
    clear_logs();
    header('Location: /admin/dashboard.php?success=logs_cleared');
    exit;
}

// =============================================================================
// Purge des alertes
// =============================================================================
if ($action === 'clear_alerts') {
    clear_alerts();
    log_action('alerts_cleared');
    header('Location: /admin/dashboard.php?success=alerts_cleared&tab=alerts');
    exit;
}

// =============================================================================
// Mise à jour du profil Discord public (champs manuels uniquement)
// =============================================================================
if ($action === 'update_profile') {
    $bio         = mb_substr(trim($_POST['discord_bio']    ?? ''), 0, 300);
    $joined      = mb_substr(trim($_POST['discord_joined'] ?? ''), 0, 30);
    $showHistory = isset($_POST['show_public_history']) ? '1' : '0';

    set_setting('discord_bio',          $bio);
    set_setting('discord_joined',       $joined);
    set_setting('show_public_history',  $showHistory);

    log_action('profile_updated');
    header('Location: /admin/dashboard.php?success=profile_saved&tab=profile');
    exit;
}

// =============================================================================
// Lancer une vérification Discord immédiate (sans attendre le cron)
// =============================================================================
if ($action === 'run_monitor') {
    $result = run_discord_monitor();
    $n      = count($result['changes'] ?? []);
    $param  = $n > 0 ? 'monitor_changes_' . $n : 'monitor_ok';
    log_action('monitor_manual_run', ['result' => $result['status'] ?? 'unknown', 'changes' => $n]);
    header('Location: /admin/dashboard.php?success=' . $param . '&tab=profile');
    exit;
}

// =============================================================================
// Réinitialiser le statut à « Sécurisé » (reset manuel après anomalie)
// =============================================================================
if ($action === 'reset_to_secure') {
    $current = get_setting('status', 'secure');
    if ($current !== 'secure') {
        set_setting('status',        'secure');
        set_setting('last_updated',  date('c'));
        set_setting('status_source', 'manual');
        $stmt = get_db()->prepare(
            'INSERT INTO status_history (date, status, note) VALUES (?, ?, ?)'
        );
        $stmt->execute([date('c'), 'secure', 'Statut réinitialisé manuellement par l\'administrateur']);
        log_action('status_reset_to_secure');
        send_discord_status_change('secure');
    }
    header('Location: /admin/dashboard.php?success=status_reset&tab=profile');
    exit;
}

// Action inconnue
header('Location: /admin/dashboard.php');
exit;

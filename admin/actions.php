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

    $settings                   = read_json(SETTINGS_FILE, []);
    $settings['discord_webhook'] = $webhookUrl;
    $settings['customMessage']   = $customMessage;
    write_json(SETTINGS_FILE, $settings);

    log_action('settings_updated');
    header('Location: /admin/dashboard.php?success=settings_saved&tab=settings');
    exit;
}

// =============================================================================
// Purge des logs
// =============================================================================
if ($action === 'clear_logs') {
    write_json(LOGS_FILE, []);
    log_action('logs_cleared');
    header('Location: /admin/dashboard.php?success=logs_cleared');
    exit;
}

// =============================================================================
// Purge des alertes
// =============================================================================
if ($action === 'clear_alerts') {
    write_json(ALERTS_FILE, []);
    log_action('alerts_cleared');
    header('Location: /admin/dashboard.php?success=alerts_cleared&tab=alerts');
    exit;
}

// Action inconnue
header('Location: /admin/dashboard.php');
exit;

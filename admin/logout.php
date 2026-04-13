<?php
/**
 * Discord Guard — Déconnexion admin
 */

require_once __DIR__ . '/../functions.php';
init_secure_session();
require_secret_token();

if (is_admin_logged_in()) {
    log_action('admin_logout');
}

// Destruction propre de la session
session_unset();
session_destroy();

// Supprimer le cookie de session
$params = session_get_cookie_params();
setcookie(
    session_name(),
    '',
    time() - 3600,
    $params['path'],
    $params['domain'],
    $params['secure'],
    $params['httponly']
);

header('Location: /admin/login.php');
exit;

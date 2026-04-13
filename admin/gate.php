<?php
/**
 * Discord Guard — Ancienne porte d'entrée (désactivée)
 *
 * L'authentification est désormais gérée uniquement par Passkey (WebAuthn).
 * Cette page redirige vers la page de connexion.
 */
header('Location: /admin/login.php');
exit;

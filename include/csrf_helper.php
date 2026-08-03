<?php

/**
 * SATHEE CRM - CSRF Token Protection
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate or get CSRF token
 * @return string CSRF token
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST request
 * @param string $token The token to verify
 * @return bool True if token is valid
 */
function verifyCsrfToken(string $token): bool
{
    return !empty($_SESSION['csrf_token']) &&
           hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output CSRF token as hidden input for forms
 * @return string HTML hidden input
 */
function csrfTokenField(): string
{
    return sprintf(
        '<input type="hidden" name="csrf_token" value="%s">',
        htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8')
    );
}

?>

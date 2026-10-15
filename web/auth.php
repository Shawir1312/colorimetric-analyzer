<?php
/**
 * Colorimetric Analyzer - Authentication Manager (auth.php)
 * Mengatur sesi login untuk antarmuka web pengguna.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

// Default kredensial administrator jika belum didefinisikan di config.php
if (!defined('ADMIN_USERNAME')) {
    define('ADMIN_USERNAME', 'admin');
}
if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', 'admin123');
}

/**
 * Cek apakah user sedang login
 */
function isLoggedIn(): bool {
    return isset($_SESSION['ca_logged_in']) && $_SESSION['ca_logged_in'] === true;
}

/**
 * Wajibkan login untuk mengakses halaman web (hanya untuk halaman HTML, bukan API)
 */
function requireAuth(): void {
    if (!isLoggedIn()) {
        $currentUrl = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?redirect=' . urlencode($currentUrl));
        exit;
    }
}

/**
 * Dapatkan username admin yang sedang aktif
 */
function getLoggedInUser(): string {
    return $_SESSION['ca_user'] ?? 'Administrator';
}

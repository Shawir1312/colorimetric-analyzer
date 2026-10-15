<?php
/**
 * Colorimetric Analyzer - Configuration File
 * Generated automatically by install.php on 2026-10-15 09:45:14
 */

// Zona Waktu Server
define('APP_TIMEZONE_ID', 'Asia/Jayapura');
define('APP_TIMEZONE', 'WIT (UTC+9)');
date_default_timezone_set(APP_TIMEZONE_ID);

// Driver Database ('sqlite' atau 'mysql')
define('DB_TYPE', 'sqlite');

// Jalur Database SQLite
define('SQLITE_PATH', __DIR__ . '/data/colorimeter.db');

// Pengaturan MySQL
define('MYSQL_HOST', '127.0.0.1');
define('MYSQL_PORT', '3306');
define('MYSQL_NAME', 'colorimeter_db');
define('MYSQL_USER', 'root');
define('MYSQL_PASS', '');

// Kunci Keamanan API (Wajib dikirim ESP32 pada header X-API-KEY)
define('API_SECRET_KEY', 'color2026');

// Akun Administrator Web
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'admin123');

// Informasi Aplikasi
define('APP_NAME', 'Colorimetric Analyzer Web');
define('APP_VERSION', '1.2.0');

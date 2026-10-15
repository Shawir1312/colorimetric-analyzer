<?php
/**
 * Colorimetric Analyzer - 1-Click Server Installation Wizard (install.php)
 * Memudahkan instalasi database SQLite / MySQL hanya dalam 1 kali klik.
 */

// Coba perbaiki izin tulis secara otomatis saat file dibuka
@chmod(__DIR__, 0777);
@chmod(__DIR__ . '/config.php', 0666);
@chmod(__DIR__ . '/data', 0777);

// Tangkap zona waktu awal
$currentTz = 'Asia/Jayapura';
if (file_exists(__DIR__ . '/config.php')) {
    @include_once __DIR__ . '/config.php';
    if (defined('APP_TIMEZONE_ID')) {
        $currentTz = APP_TIMEZONE_ID;
    }
}
@date_default_timezone_set($currentTz);

$lockFile = __DIR__ . '/data/installed.lock';
$isInstalled = file_exists($lockFile);

// Endpoint untuk unduh langsung config.php jika server membatasi penulisan file
if (isset($_GET['download_config'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="config.php"');
    if (file_exists(__DIR__ . '/config.php')) {
        readfile(__DIR__ . '/config.php');
    } else {
        echo "<?php\n// File config.php belum digenerate.\n";
    }
    exit;
}

// Periksa Kesiapan Server (Pre-flight checks)
$configFile = __DIR__ . '/config.php';
$isConfigWritable = file_exists($configFile) ? is_writable($configFile) : is_writable(__DIR__);
$isDataWritable = file_exists(__DIR__ . '/data') ? is_writable(__DIR__ . '/data') : is_writable(__DIR__);

$checks = [
    'php_version' => [
        'name' => 'Versi PHP >= 7.4',
        'passed' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'detail' => 'Versi PHP saat ini: ' . PHP_VERSION,
        'required' => true
    ],
    'pdo' => [
        'name' => 'Ekstensi PHP PDO',
        'passed' => extension_loaded('pdo'),
        'detail' => extension_loaded('pdo') ? 'Tersedia' : 'Wajib diaktifkan di php.ini',
        'required' => true
    ],
    'pdo_sqlite' => [
        'name' => 'Ekstensi PDO SQLite (Mode 1-Klik)',
        'passed' => extension_loaded('pdo_sqlite'),
        'detail' => extension_loaded('pdo_sqlite') ? 'Tersedia & Siap Digunakan' : 'Diperlukan jika memilih SQLite',
        'required' => false
    ],
    'pdo_mysql' => [
        'name' => 'Ekstensi PDO MySQL (Mode cPanel / Dedicated)',
        'passed' => extension_loaded('pdo_mysql'),
        'detail' => extension_loaded('pdo_mysql') ? 'Tersedia' : 'Diperlukan jika memilih MySQL/MariaDB',
        'required' => false
    ],
    'config_writable' => [
        'name' => 'Izin Tulis config.php',
        'passed' => $isConfigWritable,
        'detail' => $isConfigWritable ? 'Dapat ditulis (Writable)' : 'Perlu izin tulis: jalankan `chmod 666 web/config.php`',
        'required' => false
    ],
    'dir_writable' => [
        'name' => 'Izin Tulis Folder web/data',
        'passed' => $isDataWritable,
        'detail' => $isDataWritable ? 'Folder data siap digunakan' : 'Jalankan `chmod 777 web/data`',
        'required' => true
    ],
    'json' => [
        'name' => 'Ekstensi PHP JSON',
        'passed' => extension_loaded('json'),
        'detail' => extension_loaded('json') ? 'Tersedia' : 'Wajib untuk komunikasi API',
        'required' => true
    ]
];

$allRequiredPassed = true;
foreach ($checks as $c) {
    if ($c['required'] && !$c['passed']) {
        $allRequiredPassed = false;
        break;
    }
}

// Proses AJAX / POST Instalasi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'do_install') {
    header('Content-Type: application/json; charset=utf-8');
    
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    if ($isInstalled && !$force) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Aplikasi sudah terinstal sebelumnya. Centang "Instal Ulang / Reset" jika ingin mengganti database.'
        ]);
        exit;
    }

    $dbType = $_POST['db_type'] ?? 'sqlite';
    $tz = $_POST['timezone'] ?? 'Asia/Jayapura';
    $apiKey = trim($_POST['api_key'] ?? 'colan_secret_2026');
    $seedData = isset($_POST['seed_data']) && $_POST['seed_data'] === '1';

    if (empty($apiKey)) {
        $apiKey = 'colan_' . bin2hex(random_bytes(6));
    }

    $mysqlHost = trim($_POST['mysql_host'] ?? '127.0.0.1');
    $mysqlPort = trim($_POST['mysql_port'] ?? '3306');
    $mysqlName = trim($_POST['mysql_name'] ?? 'colorimeter_db');
    $mysqlUser = trim($_POST['mysql_user'] ?? 'root');
    $mysqlPass = $_POST['mysql_pass'] ?? '';

    // Buat folder data jika belum ada
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0777, true);
    }
    @chmod($dataDir, 0777);

    try {
        if ($dbType === 'sqlite') {
            if (!$checks['pdo_sqlite']['passed']) {
                throw new Exception('Ekstensi pdo_sqlite tidak aktif di server ini.');
            }

            $sqlitePath = $dataDir . '/colorimeter.db';
            
            // Jika reset, hapus file lama
            if ($force && file_exists($sqlitePath)) {
                @unlink($sqlitePath);
            }

            $pdo = new PDO('sqlite:' . $sqlitePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Buat skema tabel SQLite
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS measurements (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sample_name TEXT,
                    hex TEXT NOT NULL,
                    r INTEGER NOT NULL,
                    g INTEGER NOT NULL,
                    b INTEGER NOT NULL,
                    cie_x REAL DEFAULT 0,
                    cie_y REAL DEFAULT 0,
                    cie_z REAL DEFAULT 0,
                    lab_l REAL DEFAULT 0,
                    lab_a REAL DEFAULT 0,
                    lab_b REAL DEFAULT 0,
                    de REAL DEFAULT 0,
                    is_relative INTEGER DEFAULT 0,
                    f1_415 REAL DEFAULT 0,
                    f2_445 REAL DEFAULT 0,
                    f3_480 REAL DEFAULT 0,
                    f4_515 REAL DEFAULT 0,
                    f5_555 REAL DEFAULT 0,
                    f6_590 REAL DEFAULT 0,
                    f7_630 REAL DEFAULT 0,
                    f8_680 REAL DEFAULT 0,
                    nir REAL DEFAULT 0,
                    clear REAL DEFAULT 0,
                    led_ma INTEGER DEFAULT 12,
                    gain TEXT DEFAULT '64X',
                    samples INTEGER DEFAULT 5,
                    device_id TEXT DEFAULT 'ESP32S2_COLORIMETER',
                    device_bat_v REAL DEFAULT 4.0,
                    device_bat_pct INTEGER DEFAULT 100,
                    charging INTEGER DEFAULT 0,
                    rssi INTEGER DEFAULT 0,
                    notes TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE IF NOT EXISTS device_status (
                    device_id TEXT PRIMARY KEY,
                    ip_address TEXT,
                    firmware_version TEXT,
                    bat_voltage REAL,
                    bat_percent INTEGER,
                    charging INTEGER,
                    rssi INTEGER,
                    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_measurement_id INTEGER
                );
            ");

            if ($seedData) {
                insertDemoData($pdo, 'sqlite', $tz);
            }

        } else {
            // Mode MySQL
            if (!$checks['pdo_mysql']['passed']) {
                throw new Exception('Ekstensi pdo_mysql tidak aktif di server ini.');
            }

            // Pertama, coba sambung ke server MySQL tanpa database (untuk buat DB jika belum ada)
            try {
                $dsnServer = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $mysqlHost, $mysqlPort);
                $pdoServer = new PDO($dsnServer, $mysqlUser, $mysqlPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `$mysqlName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Exception $e) {
                // Abaikan jika tidak punya hak CREATE DATABASE
            }

            // Sambung langsung ke database target
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $mysqlHost, $mysqlPort, $mysqlName);
            $pdo = new PDO($dsn, $mysqlUser, $mysqlPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            if ($force) {
                $pdo->exec("DROP TABLE IF EXISTS measurements");
                $pdo->exec("DROP TABLE IF EXISTS device_status");
            }

            // Buat skema tabel MySQL
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS measurements (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sample_name VARCHAR(191),
                    hex VARCHAR(16) NOT NULL,
                    r INT NOT NULL,
                    g INT NOT NULL,
                    b INT NOT NULL,
                    cie_x DOUBLE DEFAULT 0,
                    cie_y DOUBLE DEFAULT 0,
                    cie_z DOUBLE DEFAULT 0,
                    lab_l DOUBLE DEFAULT 0,
                    lab_a DOUBLE DEFAULT 0,
                    lab_b DOUBLE DEFAULT 0,
                    de DOUBLE DEFAULT 0,
                    is_relative TINYINT DEFAULT 0,
                    f1_415 DOUBLE DEFAULT 0,
                    f2_445 DOUBLE DEFAULT 0,
                    f3_480 DOUBLE DEFAULT 0,
                    f4_515 DOUBLE DEFAULT 0,
                    f5_555 DOUBLE DEFAULT 0,
                    f6_590 DOUBLE DEFAULT 0,
                    f7_630 DOUBLE DEFAULT 0,
                    f8_680 DOUBLE DEFAULT 0,
                    nir DOUBLE DEFAULT 0,
                    clear DOUBLE DEFAULT 0,
                    led_ma INT DEFAULT 12,
                    gain VARCHAR(32) DEFAULT '64X',
                    samples INT DEFAULT 5,
                    device_id VARCHAR(64) DEFAULT 'ESP32S2_COLORIMETER',
                    device_bat_v DOUBLE DEFAULT 4.0,
                    device_bat_pct INT DEFAULT 100,
                    charging TINYINT DEFAULT 0,
                    rssi INT DEFAULT 0,
                    notes TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS device_status (
                    device_id VARCHAR(64) PRIMARY KEY,
                    ip_address VARCHAR(45),
                    firmware_version VARCHAR(32),
                    bat_voltage DOUBLE,
                    bat_percent INT,
                    charging TINYINT,
                    rssi INT,
                    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    last_measurement_id INT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            if ($seedData) {
                insertDemoData($pdo, 'mysql', $tz);
            }
        }

        // Susun Kode File config.php
        $tzLabel = 'WIT (UTC+9)';
        if ($tz === 'Asia/Makassar') $tzLabel = 'WITA (UTC+8)';
        elseif ($tz === 'Asia/Jakarta') $tzLabel = 'WIB (UTC+7)';

        $configContent = "<?php\n";
        $configContent .= "/**\n * Colorimetric Analyzer - Configuration File\n * Generated automatically by install.php on " . date('Y-m-d H:i:s') . "\n */\n\n";
        $configContent .= "// Zona Waktu Server\n";
        $configContent .= "define('APP_TIMEZONE_ID', '" . addslashes($tz) . "');\n";
        $configContent .= "define('APP_TIMEZONE', '" . addslashes($tzLabel) . "');\n";
        $configContent .= "date_default_timezone_set(APP_TIMEZONE_ID);\n\n";
        $configContent .= "// Driver Database ('sqlite' atau 'mysql')\n";
        $configContent .= "define('DB_TYPE', '" . addslashes($dbType) . "');\n\n";
        $configContent .= "// Jalur Database SQLite\n";
        $configContent .= "define('SQLITE_PATH', __DIR__ . '/data/colorimeter.db');\n\n";
        $configContent .= "// Pengaturan MySQL\n";
        $configContent .= "define('MYSQL_HOST', '" . addslashes($mysqlHost) . "');\n";
        $configContent .= "define('MYSQL_PORT', '" . addslashes($mysqlPort) . "');\n";
        $configContent .= "define('MYSQL_NAME', '" . addslashes($mysqlName) . "');\n";
        $configContent .= "define('MYSQL_USER', '" . addslashes($mysqlUser) . "');\n";
        $configContent .= "define('MYSQL_PASS', '" . addslashes($mysqlPass) . "');\n\n";
        $configContent .= "// Kunci Keamanan API (Wajib dikirim ESP32 pada header X-API-KEY)\n";
        $configContent .= "define('API_SECRET_KEY', '" . addslashes($apiKey) . "');\n\n";
        $configContent .= "// Informasi Aplikasi\n";
        $configContent .= "define('APP_NAME', 'Colorimetric Analyzer Web');\n";
        $configContent .= "define('APP_VERSION', '1.2.0');\n";

        // TULIS FILE CONFIG DENGAN MULTI-STRATEGI
        $written = false;
        $targetConfig = __DIR__ . '/config.php';

        // 1. Coba chmod target
        @chmod($targetConfig, 0666);
        @chmod(__DIR__, 0777);

        // 2. Tulis langsung
        if (@file_put_contents($targetConfig, $configContent) !== false) {
            $written = true;
        }

        // 3. Jika gagal, coba hapus (unlink) lalu tulis baru
        if (!$written && file_exists($targetConfig)) {
            if (@unlink($targetConfig)) {
                if (@file_put_contents($targetConfig, $configContent) !== false) {
                    $written = true;
                }
            }
        }

        // 4. Jika masih gagal, coba via fopen
        if (!$written) {
            $fp = @fopen($targetConfig, 'wb');
            if ($fp) {
                if (@fwrite($fp, $configContent) !== false) {
                    $written = true;
                }
                @fclose($fp);
            }
        }

        // Tulis file lock instalasi
        $lockData = json_encode([
            'installed_at' => date('Y-m-d H:i:s'),
            'db_type' => $dbType,
            'timezone' => $tz,
            'api_key' => $apiKey,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ], JSON_PRETTY_PRINT);
        @file_put_contents($lockFile, $lockData);

        // Cari tahu URL absolut untuk panduan ESP32
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $apiUrl = $protocol . '://' . $host . $scriptDir . '/api.php';

        // JIKA PENULISAN CONFIG GAGAL KARENA PERMISSION RESTRICTED
        if (!$written) {
            echo json_encode([
                'status' => 'manual_config',
                'message' => 'Database & tabel berhasil dipasang! Namun web server Anda mengunci penulisan file config.php.',
                'db_type' => $dbType,
                'api_url' => $apiUrl,
                'api_key' => $apiKey,
                'timezone' => $tzLabel,
                'config_code' => $configContent,
                'chmod_cmd' => 'chmod 666 ' . $targetConfig . ' && chmod 777 ' . __DIR__ . '/data'
            ]);
            exit;
        }

        // SUKSES PENUH OTOMATIS
        echo json_encode([
            'status' => 'success',
            'message' => 'Instalasi server berhasil diselesaikan dalam 1 langkah!',
            'db_type' => $dbType,
            'api_url' => $apiUrl,
            'api_key' => $apiKey,
            'timezone' => $tzLabel
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal menginstal database: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Fungsi bantu isi data demo
function insertDemoData(PDO $pdo, string $driver, string $tz): void {
    $nowExp = ($driver === 'sqlite') ? "datetime('now', '+9 hours')" : "NOW()";
    
    $stmt = $pdo->prepare("
        INSERT INTO measurements (
            sample_name, hex, r, g, b, cie_x, cie_y, cie_z, lab_l, lab_a, lab_b, de, is_relative,
            f1_415, f2_445, f3_480, f4_515, f5_555, f6_590, f7_630, f8_680, nir, clear,
            led_ma, gain, samples, device_id, device_bat_v, device_bat_pct, charging, notes, created_at
        ) VALUES (
            :name, :hex, :r, :g, :b, :cx, :cy, :cz, :ll, :la, :lb, :de, :rel,
            :f1, :f2, :f3, :f4, :f5, :f6, :f7, :f8, :nir, :clr,
            :led, :gain, :smp, :dev, :bv, :bp, :chg, :notes, $nowExp
        )
    ");

    $demoData = [
        ['Sample Kalibrasi Putih (D65)', '#FAFAFA', 250, 250, 250, 0.3127, 0.3290, 0.3583, 98.2, 0.0, 0.0, 0.0, 0, 95.2, 98.4, 100.0, 99.2, 98.5, 96.4, 94.8, 92.1, 88.0, 2450.0, 12, '64X', 5, 'ESP32S2_COLORIMETER', 4.05, 95, 0, 'Referensi kalibrasi standar ubin putih'],
        ['Daun Herbal Hijau Segar', '#2ECC71', 46, 204, 113, 0.2854, 0.5420, 0.1726, 73.5, -58.2, 38.6, 1.45, 0, 12.4, 28.5, 42.0, 88.6, 94.2, 65.4, 38.2, 22.0, 98.5, 1850.0, 12, '64X', 5, 'ESP32S2_COLORIMETER', 4.02, 88, 0, 'Analisis pigmen klorofil daun herbal'],
        ['Larutan Uji Methyl Orange', '#E67E22', 230, 126, 34, 0.5120, 0.4125, 0.0755, 62.8, 35.4, 62.1, 2.18, 0, 5.2, 10.4, 18.2, 45.0, 78.5, 95.6, 92.4, 78.0, 45.0, 1620.0, 12, '64X', 5, 'ESP32S2_COLORIMETER', 3.98, 82, 0, 'Titrasi asam-basa spektrofotometri'],
        ['Reagen Biru Methylene', '#3498DB', 52, 152, 219, 0.1985, 0.2150, 0.5865, 59.8, -12.4, -45.2, 0.82, 0, 68.5, 92.4, 96.0, 72.5, 45.0, 22.4, 15.2, 12.0, 15.0, 1420.0, 12, '64X', 5, 'ESP32S2_COLORIMETER', 3.95, 78, 1, 'Uji kadar zat pewarna biru sintesis']
    ];

    foreach ($demoData as $d) {
        $stmt->execute([
            ':name' => $d[0], ':hex' => $d[1], ':r' => $d[2], ':g' => $d[3], ':b' => $d[4],
            ':cx' => $d[5], ':cy' => $d[6], ':cz' => $d[7], ':ll' => $d[8], ':la' => $d[9], ':lb' => $d[10],
            ':de' => $d[11], ':rel' => $d[12], ':f1' => $d[13], ':f2' => $d[14], ':f3' => $d[15],
            ':f4' => $d[16], ':f5' => $d[17], ':f6' => $d[18], ':f7' => $d[19], ':f8' => $d[20],
            ':nir' => $d[21], ':clr' => $d[22], ':led' => $d[23], ':gain' => $d[24], ':smp' => $d[25],
            ':dev' => $d[26], ':bv' => $d[27], ':bp' => $d[28], ':chg' => $d[29], ':notes' => $d[30]
        ]);
    }

    if ($driver === 'sqlite') {
        $pdo->exec("
            INSERT OR REPLACE INTO device_status (device_id, ip_address, firmware_version, bat_voltage, bat_percent, charging, rssi, last_seen, last_measurement_id)
            VALUES ('ESP32S2_COLORIMETER', '192.168.1.50', 'v1.2.0', 4.02, 88, 0, -58, datetime('now', '+9 hours'), 4)
        ");
    } else {
        $pdo->exec("
            INSERT INTO device_status (device_id, ip_address, firmware_version, bat_voltage, bat_percent, charging, rssi, last_seen, last_measurement_id)
            VALUES ('ESP32S2_COLORIMETER', '192.168.1.50', 'v1.2.0', 4.02, 88, 0, -58, NOW(), 4)
            ON DUPLICATE KEY UPDATE 
                ip_address=VALUES(ip_address), firmware_version=VALUES(firmware_version),
                bat_voltage=VALUES(bat_voltage), bat_percent=VALUES(bat_percent), charging=VALUES(charging),
                rssi=VALUES(rssi), last_seen=NOW(), last_measurement_id=VALUES(last_measurement_id)
        ");
    }
}

// Baca info jika sudah terinstal
$installedInfo = null;
$totalExistingRecords = 0;
if ($isInstalled && file_exists($lockFile)) {
    $installedInfo = @json_decode(file_get_contents($lockFile), true);
    try {
        require_once __DIR__ . '/db.php';
        $db = getDb();
        $totalExistingRecords = (int)$db->query("SELECT COUNT(*) FROM measurements")->fetchColumn();
    } catch (Exception $e) {
        $totalExistingRecords = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Instalasi Server 1-Klik - Colorimetric Analyzer</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚙️</text></svg>">
  <style>
    .install-container {
      max-width: 820px;
      margin: 2rem auto;
      padding: 0 1rem;
    }
    .check-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
      gap: 0.75rem;
      margin-bottom: 1.5rem;
    }
    .check-item {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.75rem 1rem;
      border-radius: var(--radius-md);
      background: rgba(17, 24, 39, 0.6);
      border: 1px solid var(--border-subtle);
      font-size: 0.88rem;
    }
    .check-item.pass {
      border-left: 4px solid var(--accent-green);
    }
    .check-item.fail {
      border-left: 4px solid var(--accent-red);
    }
    .check-item.warn {
      border-left: 4px solid var(--accent-amber);
    }
    .check-icon {
      font-size: 1.25rem;
      line-height: 1;
    }
    .db-tab-btn {
      flex: 1;
      padding: 1rem;
      text-align: center;
      border: 1px solid var(--border-subtle);
      background: rgba(17, 24, 39, 0.4);
      color: var(--text-secondary);
      border-radius: var(--radius-md);
      cursor: pointer;
      transition: all 0.25s ease;
      font-weight: 600;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.35rem;
    }
    .db-tab-btn:hover {
      background: rgba(59, 130, 246, 0.1);
      color: var(--text-primary);
    }
    .db-tab-btn.active {
      background: rgba(59, 130, 246, 0.15);
      border-color: var(--accent-blue);
      color: var(--text-primary);
      box-shadow: 0 0 15px rgba(59, 130, 246, 0.2);
    }
    .tab-content {
      display: none;
      animation: fadeIn 0.3s ease;
    }
    .tab-content.active {
      display: block;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .code-box {
      background: #0d1117;
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 0.85rem 1.1rem;
      font-family: var(--font-mono);
      font-size: 0.84rem;
      color: var(--accent-cyan);
      overflow-x: auto;
      word-break: break-all;
    }
    .step-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: var(--accent-blue);
      color: white;
      font-size: 0.75rem;
      font-weight: bold;
      margin-right: 0.5rem;
    }
  </style>
</head>
<body>

  <!-- Navbar -->
  <nav class="navbar">
    <a href="index.php" class="nav-brand">
      <div class="brand-logo" style="background: linear-gradient(135deg, #10B981, #06B6D4);">⚙️</div>
      <div class="brand-text">
        <h1>Instalasi Server 1-Klik</h1>
        <span>Colorimetric Analyzer Web v1.2</span>
      </div>
    </a>
    <div class="nav-links">
      <a href="index.php" class="nav-link">📊 Buka Dashboard</a>
      <a href="device.php" class="nav-link">📡 Info Perangkat</a>
    </div>
  </nav>

  <main class="install-container">

    <!-- JIKA SUDAH TERINSTAL -->
    <?php if ($isInstalled && !isset($_GET['reinstall'])): ?>
      <div class="card" style="border-top: 4px solid var(--accent-green); margin-bottom: 2rem;">
        <div class="card-body" style="padding: 2rem;">
          <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <div style="font-size: 2.8rem; line-height: 1;">🎉</div>
            <div>
              <h2 style="font-size: 1.45rem; font-weight: 700; color: var(--accent-green);">
                Aplikasi Sudah Terpasang & Siap Digunakan!
              </h2>
              <p style="color: var(--text-secondary); font-size: 0.9rem;">
                Database dan konfigurasi server telah aktif dan berfungsi normal.
              </p>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div style="background: rgba(17, 24, 39, 0.6); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
              <div style="font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase;">Tipe Database</div>
              <div style="font-size: 1.15rem; font-weight: 700; color: var(--text-primary); margin-top: 0.2rem;">
                <?= strtoupper($installedInfo['db_type'] ?? (defined('DB_TYPE') ? DB_TYPE : 'sqlite')) ?>
              </div>
            </div>
            <div style="background: rgba(17, 24, 39, 0.6); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
              <div style="font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase;">Data Tersimpan</div>
              <div style="font-size: 1.15rem; font-weight: 700; color: var(--accent-cyan); margin-top: 0.2rem;">
                <?= number_format($totalExistingRecords) ?> Pengukuran
              </div>
            </div>
            <div style="background: rgba(17, 24, 39, 0.6); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
              <div style="font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase;">Zona Waktu</div>
              <div style="font-size: 1.15rem; font-weight: 700; color: var(--accent-amber); margin-top: 0.2rem;">
                <?= defined('APP_TIMEZONE') ? APP_TIMEZONE : ($installedInfo['timezone'] ?? 'WIT') ?>
              </div>
            </div>
          </div>

          <div style="margin-bottom: 1.5rem;">
            <label style="font-size: 0.82rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 0.35rem; display: block;">
              URL Web API untuk Mikrokontroler ESP32:
            </label>
            <?php
              $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
              $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
              $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
              $curApiUrl = $protocol . '://' . $host . $dir . '/api.php';
            ?>
            <div class="code-box" style="display: flex; justify-content: space-between; align-items: center;">
              <span id="txt-api-url"><?= htmlspecialchars($curApiUrl) ?></span>
              <button onclick="navigator.clipboard.writeText('<?= addslashes($curApiUrl) ?>'); alert('URL API berhasil disalin!');" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.75rem;">📋 Salin</button>
            </div>
          </div>

          <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="index.php" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-size: 1rem;">
              🚀 Buka Dashboard Web
            </a>
            <a href="device.php" class="btn btn-secondary">
              📡 Cek Integrasi Alat
            </a>
            <a href="install.php?reinstall=1" class="btn btn-danger" style="margin-left: auto;" onclick="return confirm('Apakah Anda yakin ingin mengatur ulang konfigurasi database? Data lama mungkin akan direset!');">
              🔄 Ubah Konfigurasi / Format Ulang
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- WIZARD PEMASANGAN -->
    <?php if (!$isInstalled || isset($_GET['reinstall'])): ?>

      <div style="margin-bottom: 1.5rem; text-align: center;">
        <h2 style="font-size: 1.8rem; font-weight: 800; background: linear-gradient(135deg, #F9FAFB, #9CA3AF); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
          Instalasi Server Kolorimeter
        </h2>
        <p style="color: var(--text-secondary); font-size: 0.92rem; margin-top: 0.35rem;">
          Setup database otomatis dalam 1 kali klik tanpa perlu command line atau konfigurasi manual yang rumit.
        </p>
      </div>

      <!-- Kesiapan Server -->
      <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header">
          <div class="card-title">
            <span class="step-badge">1</span> Pemeriksaan Kesiapan Server
          </div>
          <?php if ($allRequiredPassed): ?>
            <span class="badge badge-success">✓ Server Memenuhi Syarat</span>
          <?php else: ?>
            <span class="badge badge-danger">⚠️ Ada Syarat Belum Terpenuhi</span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <div class="check-grid">
            <?php foreach ($checks as $k => $c): ?>
              <div class="check-item <?= $c['passed'] ? 'pass' : ($c['required'] ? 'fail' : 'warn') ?>">
                <span class="check-icon"><?= $c['passed'] ? '✅' : ($c['required'] ? '❌' : '⚠️') ?></span>
                <div style="flex: 1;">
                  <div style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($c['name']) ?></div>
                  <div style="font-size: 0.78rem; color: var(--text-muted);"><?= htmlspecialchars($c['detail']) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if (!$isConfigWritable): ?>
            <div class="alert alert-warning" style="margin-bottom: 0; font-size: 0.86rem; line-height: 1.5;">
              ℹ️ <strong>Tips Izin File config.php:</strong> Server web Anda saat ini membatasi penulisan file `config.php`.<br>
              Jika nanti installer tidak dapat menulis otomatis, cukup jalankan perintah di terminal: 
              <code style="background: rgba(0,0,0,0.3); padding: 0.2rem 0.4rem; border-radius: 4px; font-family: monospace;">chmod 666 web/config.php web/data</code> 
              atau installer akan menyediakan tombol <strong>Unduh File config.php</strong> secara instan.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Form Instalasi -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">
            <span class="step-badge">2</span> Pilih Metode Database & Jalankan Instalasi
          </div>
        </div>
        <div class="card-body">

          <!-- Tab Selector -->
          <div style="display: flex; gap: 0.75rem; margin-bottom: 1.5rem;">
            <div class="db-tab-btn active" id="tab-btn-sqlite" onclick="switchTab('sqlite')">
              <span style="font-size: 1.4rem;">⚡</span>
              <div>SQLite (1-Klik / Zero-Config)</div>
              <span style="font-size: 0.75rem; font-weight: normal; color: var(--accent-green);">Paling Mudah & Direkomendasikan</span>
            </div>
            <div class="db-tab-btn" id="tab-btn-mysql" onclick="switchTab('mysql')">
              <span style="font-size: 1.4rem;">🐬</span>
              <div>MySQL / MariaDB</div>
              <span style="font-size: 0.75rem; font-weight: normal; color: var(--text-muted);">Untuk cPanel / VPS / Hosting Terpisah</span>
            </div>
          </div>

          <form id="install-form" onsubmit="handleInstall(event)">
            <input type="hidden" name="action" value="do_install">
            <input type="hidden" name="db_type" id="input_db_type" value="sqlite">
            <?php if ($isInstalled): ?>
              <input type="hidden" name="force" value="1">
            <?php endif; ?>

            <!-- TAB 1: SQLITE -->
            <div class="tab-content active" id="tab-sqlite">
              <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: var(--radius-md); padding: 1rem; margin-bottom: 1.25rem;">
                <div style="font-weight: 600; color: var(--accent-green); display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem;">
                  <span>✓</span> Tidak Memerlukan Setup Database Tambahan
                </div>
                <div style="font-size: 0.85rem; color: var(--text-secondary); line-height: 1.4;">
                  Database SQLite akan dibuat secara otomatis di: <code style="color: var(--accent-cyan); font-family: var(--font-mono);">web/data/colorimeter.db</code>. 
                  Sangat cepat, ringan, bebas ribet, dan langsung siap dipakai seketika!
                </div>
              </div>
            </div>

            <!-- TAB 2: MYSQL -->
            <div class="tab-content" id="tab-mysql">
              <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                <div class="form-group">
                  <label class="form-label">Host MySQL</label>
                  <input type="text" name="mysql_host" class="form-control" value="127.0.0.1" placeholder="127.0.0.1 atau localhost">
                </div>
                <div class="form-group">
                  <label class="form-label">Port</label>
                  <input type="number" name="mysql_port" class="form-control" value="3306">
                </div>
              </div>

              <div class="form-group" style="margin-bottom: 0.75rem;">
                <label class="form-label">Nama Database</label>
                <input type="text" name="mysql_name" class="form-control" value="colorimeter_db" placeholder="colorimeter_db">
                <small style="color: var(--text-muted); font-size: 0.76rem;">Jika belum ada, installer akan mencoba membuatnya otomatis.</small>
              </div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.25rem;">
                <div class="form-group">
                  <label class="form-label">Username Database</label>
                  <input type="text" name="mysql_user" class="form-control" value="root" placeholder="root">
                </div>
                <div class="form-group">
                  <label class="form-label">Password Database</label>
                  <input type="password" name="mysql_pass" class="form-control" value="" placeholder="Kosongkan jika tidak ada">
                </div>
              </div>
            </div>

            <!-- PENGATURAN UMUM -->
            <div style="border-top: 1px solid var(--border-subtle); padding-top: 1.25rem; margin-top: 1rem;">
              <div style="font-size: 0.95rem; font-weight: 700; margin-bottom: 1rem;">Pengaturan Sistem</div>

              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div class="form-group">
                  <label class="form-label">Zona Waktu</label>
                  <select name="timezone" class="form-control">
                    <option value="Asia/Jayapura" selected>WIT (UTC+9 - Jayapura / Papua / Maluku)</option>
                    <option value="Asia/Makassar">WITA (UTC+8 - Makassar / Bali / Sulawesi)</option>
                    <option value="Asia/Jakarta">WIB (UTC+7 - Jakarta / Jawa / Sumatera)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">API Secret Key (Untuk ESP32)</label>
                  <input type="text" name="api_key" class="form-control" value="colan_secret_2026">
                </div>
              </div>

              <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer; color: var(--text-primary); font-size: 0.88rem;">
                  <input type="checkbox" name="seed_data" value="1" checked style="width: 18px; height: 18px; accent-color: var(--accent-blue);">
                  <span>Masukkan Data Kalibrasi Awal & Sampel Uji Coba (Rekomendasi agar grafik langsung tampil)</span>
                </label>
              </div>

              <?php if ($isInstalled): ?>
                <div class="alert alert-warning" style="margin-bottom: 1.25rem; font-size: 0.85rem;">
                  ⚠️ <strong>Peringatan Format Ulang:</strong> Tindakan ini akan menimpa konfigurasi sebelumnya dan membuat tabel baru.
                </div>
              <?php endif; ?>

              <!-- Status Feedback -->
              <div id="install-result" style="display: none; margin-bottom: 1.25rem;"></div>

              <!-- Submit Button -->
              <button type="submit" id="btn-submit-install" class="btn btn-primary" style="width: 100%; padding: 0.9rem; font-size: 1.05rem; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 0.5rem;" <?= !$allRequiredPassed ? 'disabled' : '' ?>>
                <span id="btn-text">⚡ Pasang Sekarang (1-Klik Selesai)</span>
                <span id="btn-spinner" style="display: none;">⏳ Memproses Instalasi...</span>
              </button>
            </div>
          </form>

        </div>
      </div>

    <?php endif; ?>

  </main>

  <script>
    let generatedConfigCode = '';

    function switchTab(type) {
      document.getElementById('input_db_type').value = type;
      
      const tabSqlite = document.getElementById('tab-sqlite');
      const tabMysql = document.getElementById('tab-mysql');
      const btnSqlite = document.getElementById('tab-btn-sqlite');
      const btnMysql = document.getElementById('tab-btn-mysql');
      const btnText = document.getElementById('btn-text');

      if (type === 'sqlite') {
        tabSqlite.classList.add('active');
        tabMysql.classList.remove('active');
        btnSqlite.classList.add('active');
        btnMysql.classList.remove('active');
        if (btnText) btnText.textContent = '⚡ Pasang Sekarang (1-Klik SQLite)';
      } else {
        tabMysql.classList.add('active');
        tabSqlite.classList.remove('active');
        btnMysql.classList.add('active');
        btnSqlite.classList.remove('active');
        if (btnText) btnText.textContent = '🔌 Uji Koneksi & Pasang (MySQL)';
      }
    }

    function downloadConfigBlob() {
      if (!generatedConfigCode) return;
      const blob = new Blob([generatedConfigCode], { type: 'application/x-httpd-php' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'config.php';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    }

    async function handleInstall(e) {
      e.preventDefault();
      
      const form = document.getElementById('install-form');
      const formData = new FormData(form);
      const resBox = document.getElementById('install-result');
      const btn = document.getElementById('btn-submit-install');
      const btnText = document.getElementById('btn-text');
      const btnSpinner = document.getElementById('btn-spinner');

      btn.disabled = true;
      btnText.style.display = 'none';
      btnSpinner.style.display = 'inline';
      resBox.style.display = 'none';

      try {
        const response = await fetch('install.php', {
          method: 'POST',
          body: formData
        });

        const data = await response.json();
        resBox.style.display = 'block';

        if (data.status === 'success') {
          resBox.className = 'alert alert-success';
          resBox.innerHTML = `
            <div style="font-weight: 700; font-size: 1.05rem; margin-bottom: 0.35rem;">🎉 ${data.message}</div>
            <div style="font-size: 0.85rem; line-height: 1.5;">
              Tipe Database: <strong>${data.db_type.toUpperCase()}</strong> | Zona Waktu: <strong>${data.timezone}</strong><br>
              URL API ESP32: <code style="color: var(--accent-cyan); font-family: monospace;">${data.api_url}</code><br>
              API Key: <code style="color: var(--accent-amber); font-family: monospace;">${data.api_key}</code>
            </div>
            <div style="margin-top: 1rem;">
              <a href="index.php" class="btn btn-primary" style="font-size: 0.95rem; padding: 0.6rem 1.25rem;">🚀 Langsung Buka Dashboard Web</a>
            </div>
          `;
          form.querySelector('button[type="submit"]').style.display = 'none';

        } else if (data.status === 'manual_config') {
          // DATABASE BERHASIL, TETAPI SERVER MEMBATASI PENULISAN CONFIG.PHP
          generatedConfigCode = data.config_code;
          resBox.className = 'alert alert-warning';
          resBox.innerHTML = `
            <div style="font-weight: 700; font-size: 1.05rem; color: #F59E0B; margin-bottom: 0.4rem;">
              ✅ Database & Skema Tabel Berhasil Dibuat!
            </div>
            <div style="font-size: 0.86rem; line-height: 1.5; color: var(--text-primary); margin-bottom: 0.75rem;">
              Namun web server Anda memiliki izin terbatas (Read-Only) sehingga tidak dapat menimpa file <code style="color: var(--accent-cyan); font-family: monospace;">web/config.php</code> secara otomatis.
            </div>
            
            <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); padding: 0.85rem; margin-bottom: 1rem;">
              <div style="font-weight: 600; font-size: 0.82rem; margin-bottom: 0.5rem; color: var(--accent-amber);">PILIH SALAH SATU CARA MUDAH BERIKUT:</div>
              <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.75rem;">
                <button type="button" onclick="downloadConfigBlob()" class="btn btn-primary btn-sm">
                  📥 Unduh File config.php
                </button>
                <button type="button" onclick="navigator.clipboard.writeText(generatedConfigCode); alert('Kode konfigurasi disalin ke clipboard!');" class="btn btn-secondary btn-sm">
                  📋 Salin Kode Konfigurasi
                </button>
              </div>
              <div style="font-size: 0.8rem; color: var(--text-secondary); line-height: 1.4;">
                <strong>Opsi A:</strong> Klik "Unduh File config.php", lalu upload/simpan ke dalam folder <code style="color: var(--accent-cyan); font-family: monospace;">web/</code>.<br>
                <strong>Opsi B (Terminal/SSH):</strong> Jalankan perintah: <code style="color: var(--accent-green); font-family: monospace;">${data.chmod_cmd}</code>
              </div>
            </div>

            <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
              <a href="index.php" class="btn btn-success" style="padding: 0.6rem 1.25rem;">
                🚀 Buka Dashboard Web Sekarang
              </a>
              <button type="button" onclick="handleInstall(event)" class="btn btn-secondary btn-sm">
                🔄 Coba Simpan Ulang
              </button>
            </div>
          `;
          form.querySelector('button[type="submit"]').style.display = 'none';

        } else {
          resBox.className = 'alert alert-danger';
          resBox.innerHTML = `<strong>Gagal:</strong> ${data.message}`;
          btn.disabled = false;
          btnText.style.display = 'inline';
          btnSpinner.style.display = 'none';
        }
      } catch (err) {
        resBox.style.display = 'block';
        resBox.className = 'alert alert-danger';
        resBox.innerHTML = `<strong>Terjadi Kesalahan:</strong> ${err.message}`;
        btn.disabled = false;
        btnText.style.display = 'inline';
        btnSpinner.style.display = 'none';
      }
    }
  </script>

</body>
</html>

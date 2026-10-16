<?php
/**
 * Colorimetric Analyzer - Database Connection & Initialization
 */

require_once __DIR__ . '/config.php';

function isAppInstalled(): bool {
    if (file_exists(__DIR__ . '/data/installed.lock')) {
        return true;
    }
    if (defined('DB_TYPE') && DB_TYPE === 'sqlite' && file_exists(SQLITE_PATH) && filesize(SQLITE_PATH) > 0) {
        return true;
    }
    return false;
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (DB_TYPE === 'sqlite') {
        $dbDir = dirname(SQLITE_PATH);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0777, true);
        }
        $isNew = !file_exists(SQLITE_PATH);
        $pdo = new PDO('sqlite:' . SQLITE_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if ($isNew || filesize(SQLITE_PATH) === 0) {
            initDatabase($pdo, 'sqlite');
        }
    } else {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', MYSQL_HOST, MYSQL_PORT, MYSQL_NAME);
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Periksa apakah tabel sudah ada di MySQL
        try {
            $pdo->query("SELECT 1 FROM measurements LIMIT 1");
        } catch (Exception $e) {
            initDatabase($pdo, 'mysql');
        }
    }

    checkAndMigrateMetalColumns($pdo, DB_TYPE);
    return $pdo;
}


function checkAndMigrateMetalColumns(PDO $pdo, string $driver = 'sqlite'): void {
    $cols = [
        'metal_type' => ($driver === 'sqlite') ? 'TEXT DEFAULT NULL' : 'VARCHAR(64) DEFAULT NULL',
        'metal_conc' => ($driver === 'sqlite') ? 'REAL DEFAULT NULL' : 'DOUBLE DEFAULT NULL',
        'metal_unit' => ($driver === 'sqlite') ? "TEXT DEFAULT 'mg/L'" : "VARCHAR(16) DEFAULT 'mg/L'",
        'peak_wl' => ($driver === 'sqlite') ? 'INTEGER DEFAULT NULL' : 'INT DEFAULT NULL',
        'water_status' => ($driver === 'sqlite') ? 'TEXT DEFAULT NULL' : 'VARCHAR(64) DEFAULT NULL',
        'indicator' => ($driver === 'sqlite') ? 'TEXT DEFAULT NULL' : 'VARCHAR(128) DEFAULT NULL'
    ];
    foreach ($cols as $col => $type) {
        try {
            $pdo->exec("ALTER TABLE measurements ADD COLUMN $col $type");
        } catch (Exception $e) {
            // Kolom sudah ada
        }
    }
}

function initDatabase(PDO $pdo, string $driver = 'sqlite'): void {
    if ($driver === 'sqlite') {
        $sqlMeasurements = "
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
            metal_type TEXT DEFAULT NULL,
            metal_conc REAL DEFAULT NULL,
            metal_unit TEXT DEFAULT 'mg/L',
            peak_wl INTEGER DEFAULT NULL,
            water_status TEXT DEFAULT NULL,
            indicator TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );";

        $sqlDevice = "
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
        );";

        $pdo->exec($sqlMeasurements);
        $pdo->exec($sqlDevice);

    } else {
        // MySQL skema
        $sqlMeasurements = "
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
            metal_type TEXT DEFAULT NULL,
            metal_conc REAL DEFAULT NULL,
            metal_unit TEXT DEFAULT 'mg/L',
            peak_wl INTEGER DEFAULT NULL,
            water_status TEXT DEFAULT NULL,
            indicator TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $sqlDevice = "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        $pdo->exec($sqlMeasurements);
        $pdo->exec($sqlDevice);
    }

    // Insert sample initial data if empty so the user immediately sees a functioning dashboard
    $count = $pdo->query("SELECT COUNT(*) FROM measurements")->fetchColumn();
    if ($count == 0) {
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
            ['Sample Kalibrasi Putih (D65)', '#FAFAFA', 250, 250, 250, 0.3127, 0.3290, 0.3583, 98.2, 0.0, 0.0, 0.0, 0, 95.2, 98.4, 100.0, 99.2, 98.5, 96.4, 94.8, 92.1, 88.0, 2450.0, 12, '64X', 5, 'ESP32S2_COLORIMETER', 4.05, 92, 0, 'Referensi kalibrasi standar ubin putih'],
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
                VALUES ('ESP32S2_COLORIMETER', '192.168.1.50', 'v1.2.0', 3.98, 82, 0, -62, datetime('now', '+9 hours'), 4)
            ");
        } else {
            $pdo->exec("
                INSERT INTO device_status (device_id, ip_address, firmware_version, bat_voltage, bat_percent, charging, rssi, last_seen, last_measurement_id)
                VALUES ('ESP32S2_COLORIMETER', '192.168.1.50', 'v1.2.0', 3.98, 82, 0, -62, NOW(), 4)
                ON DUPLICATE KEY UPDATE ip_address=VALUES(ip_address), last_seen=NOW()
            ");
        }
    }
}

<?php
/**
 * Colorimetric Analyzer - RESTful JSON API
 * Mendukung SQLite dan MySQL/MariaDB
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? ($_POST['action'] ?? 'latest');

function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper validasi API Key
function isValidApiKey(?string $key): bool {
    if (!defined('API_SECRET_KEY') || empty(API_SECRET_KEY)) {
        return true;
    }
    if (empty($key)) {
        return false;
    }
    // Terima API key dari config, atau default umum color2026 / colan_secret_2026
    return ($key === API_SECRET_KEY || $key === 'color2026' || $key === 'colan_secret_2026');
}

$db = getDb();

// ---------------------------------------------------------------------
// 1. UPLOAD PENGUKURAN DARI ESP32 ATAU FORM WEB
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'upload' || !isset($_GET['action']))) {
    // Baca payload JSON
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data && !empty($_POST)) {
        $data = $_POST;
    }

    if (!$data) {
        jsonResponse(['status' => 'error', 'message' => 'Payload JSON tidak valid'], 400);
    }

    // Jika payload adalah ping/heartbeat
    if (isset($data['action']) && $data['action'] === 'ping') {
        $action = 'ping';
    } else {
        // Verifikasi API Key
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($data['api_key'] ?? ($_GET['api_key'] ?? ''));
        if (!empty(API_SECRET_KEY) && !isValidApiKey($apiKey)) {
            jsonResponse(['status' => 'error', 'message' => 'API Key tidak valid'], 403);
        }

        // Parse data warna & spektrum
        $nowStr = date('Y-m-d H:i:s');
        $sampleName = !empty($data['sample_name']) ? trim($data['sample_name']) : ('Sampel #' . date('d-m-Y H:i:s'));
        $hex = !empty($data['hex']) ? strtoupper(trim($data['hex'])) : '#888888';
        if ($hex[0] !== '#') $hex = '#' . $hex;

        $r = isset($data['r']) ? (int)$data['r'] : hexdec(substr($hex, 1, 2));
        $g = isset($data['g']) ? (int)$data['g'] : hexdec(substr($hex, 3, 2));
        $b = isset($data['b']) ? (int)$data['b'] : hexdec(substr($hex, 5, 2));

        $cx = (float)($data['cie_x'] ?? 0.3127);
        $cy = (float)($data['cie_y'] ?? 0.3290);
        $cz = (float)($data['cie_z'] ?? (1.0 - $cx - $cy));

        $ll = (float)($data['lab_l'] ?? 50.0);
        $la = (float)($data['lab_a'] ?? 0.0);
        $lb = (float)($data['lab_b'] ?? 0.0);
        $de = (float)($data['de'] ?? 0.0);
        $isRel = !empty($data['is_relative']) ? 1 : 0;

        // 8 band AS7341
        $bands = $data['spectrum'] ?? ($data['bands'] ?? []);
        $f1 = (float)($bands[0] ?? ($data['f1_415'] ?? 0));
        $f2 = (float)($bands[1] ?? ($data['f2_445'] ?? 0));
        $f3 = (float)($bands[2] ?? ($data['f3_480'] ?? 0));
        $f4 = (float)($bands[3] ?? ($data['f4_515'] ?? 0));
        $f5 = (float)($bands[4] ?? ($data['f5_555'] ?? 0));
        $f6 = (float)($bands[5] ?? ($data['f6_590'] ?? 0));
        $f7 = (float)($bands[6] ?? ($data['f7_630'] ?? 0));
        $f8 = (float)($bands[7] ?? ($data['f8_680'] ?? 0));

        $nir = (float)($data['nir'] ?? 0);
        $clr = (float)($data['clear'] ?? 0);

        $ledMa = (int)($data['led_ma'] ?? 12);
        $gain = (string)($data['gain'] ?? '64X');
        $samples = (int)($data['samples'] ?? 5);

        $devId = !empty($data['device_id']) ? trim($data['device_id']) : 'ESP32S2_COLORIMETER';
        $batV = (float)($data['bat_v'] ?? ($data['bat_voltage'] ?? 4.0));
        $batPct = (int)($data['bat_pct'] ?? ($data['bat_percent'] ?? 100));
        $charging = !empty($data['charging']) ? 1 : 0;
        $rssi = (int)($data['rssi'] ?? 0);
        $notes = (string)($data['notes'] ?? '');
        $fw = (string)($data['fw_version'] ?? 'v1.2.0');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $stmt = $db->prepare("
            INSERT INTO measurements (
                sample_name, hex, r, g, b, cie_x, cie_y, cie_z, lab_l, lab_a, lab_b, de, is_relative,
                f1_415, f2_445, f3_480, f4_515, f5_555, f6_590, f7_630, f8_680, nir, clear,
                led_ma, gain, samples, device_id, device_bat_v, device_bat_pct, charging, rssi, notes, created_at
            ) VALUES (
                :name, :hex, :r, :g, :b, :cx, :cy, :cz, :ll, :la, :lb, :de, :rel,
                :f1, :f2, :f3, :f4, :f5, :f6, :f7, :f8, :nir, :clr,
                :led, :gain, :smp, :dev, :bv, :bp, :chg, :rssi, :notes, :created_at
            )
        ");

        $stmt->execute([
            ':name' => $sampleName, ':hex' => $hex, ':r' => $r, ':g' => $g, ':b' => $b,
            ':cx' => $cx, ':cy' => $cy, ':cz' => $cz, ':ll' => $ll, ':la' => $la, ':lb' => $lb,
            ':de' => $de, ':rel' => $isRel, ':f1' => $f1, ':f2' => $f2, ':f3' => $f3,
            ':f4' => $f4, ':f5' => $f5, ':f6' => $f6, ':f7' => $f7, ':f8' => $f8,
            ':nir' => $nir, ':clr' => $clr, ':led' => $ledMa, ':gain' => $gain, ':smp' => $samples,
            ':dev' => $devId, ':bv' => $batV, ':bp' => $batPct, ':chg' => $charging, ':rssi' => $rssi,
            ':notes' => $notes, ':created_at' => $nowStr
        ]);

        $insertId = (int)$db->lastInsertId();

        // Perbarui status perangkat (menggunakan REPLACE INTO standar SQLite & MariaDB)
        $stmtDev = $db->prepare("
            REPLACE INTO device_status (
                device_id, ip_address, firmware_version, bat_voltage, bat_percent, charging, rssi, last_seen, last_measurement_id
            ) VALUES (
                :dev, :ip, :fw, :bv, :bp, :chg, :rssi, :ls, :mid
            )
        ");
        $stmtDev->execute([
            ':dev' => $devId, ':ip' => $ip, ':fw' => $fw, ':bv' => $batV,
            ':bp' => $batPct, ':chg' => $charging, ':rssi' => $rssi, ':ls' => $nowStr, ':mid' => $insertId
        ]);

        jsonResponse([
            'status' => 'success',
            'message' => 'Data pengukuran berhasil disimpan',
            'id' => $insertId,
            'hex' => $hex,
            'created_at' => $nowStr
        ], 201);
    }
}

// ---------------------------------------------------------------------
// 2. STATUS TERKINI & POLLING LIVE
// ---------------------------------------------------------------------
if ($action === 'latest') {
    $latest = $db->query("SELECT * FROM measurements ORDER BY id DESC LIMIT 1")->fetch();
    $device = $db->query("SELECT * FROM device_status ORDER BY last_seen DESC LIMIT 1")->fetch();
    $totalCount = (int)$db->query("SELECT COUNT(*) FROM measurements")->fetchColumn();

    $isOnline = false;
    $timeDiff = 999999;
    if ($device && !empty($device['last_seen'])) {
        $lastSeenEpoch = strtotime($device['last_seen']);
        $timeDiff = time() - $lastSeenEpoch;
        $isOnline = ($timeDiff >= 0 && $timeDiff < 120);
    }

    jsonResponse([
        'status' => 'success',
        'total_measurements' => $totalCount,
        'latest_measurement' => $latest ?: null,
        'device' => $device ? array_merge($device, [
            'is_online' => $isOnline,
            'last_seen_epoch' => strtotime($device['last_seen']),
            'time_diff_sec' => $timeDiff
        ]) : null,
        'server_time' => date('Y-m-d H:i:s'),
        'server_epoch' => time(),
        'timezone' => APP_TIMEZONE
    ]);
}

// ---------------------------------------------------------------------
// 3. DAFTAR PENGUKURAN (LIST DENGAN PENCARIAN & PAGINATION)
// ---------------------------------------------------------------------
if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(5, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(sample_name LIKE :s1 OR hex LIKE :s2 OR notes LIKE :s3)";
        $params[':s1'] = "%$search%";
        $params[':s2'] = "%$search%";
        $params[':s3'] = "%$search%";
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM measurements $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM measurements $whereSql ORDER BY id DESC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    jsonResponse([
        'status' => 'success',
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => ceil($total / $limit),
        'data' => $rows
    ]);
}

// ---------------------------------------------------------------------
// 4. DETAIL PENGUKURAN TUNGGAL
// ---------------------------------------------------------------------
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM measurements WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch();

    if (!$item) {
        jsonResponse(['status' => 'error', 'message' => 'Data tidak ditemukan'], 404);
    }

    jsonResponse(['status' => 'success', 'data' => $item]);
}

// ---------------------------------------------------------------------
// 5. UPDATE CATATAN (NOTES)
// ---------------------------------------------------------------------
if ($action === 'update_notes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $id = (int)($data['id'] ?? 0);
    $notes = trim($data['notes'] ?? '');

    $stmt = $db->prepare("UPDATE measurements SET notes = :notes WHERE id = :id");
    $stmt->execute([':notes' => $notes, ':id' => $id]);

    jsonResponse(['status' => 'success', 'message' => 'Catatan berhasil diperbarui']);
}

// ---------------------------------------------------------------------
// 6. HAPUS PENGUKURAN (TUNGGAL ATAU MASSAL)
// ---------------------------------------------------------------------
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $id = (int)($data['id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM measurements WHERE id = :id");
    $stmt->execute([':id' => $id]);

    jsonResponse(['status' => 'success', 'message' => 'Data berhasil dihapus']);
}

if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        jsonResponse(['status' => 'error', 'message' => 'Daftar ID tidak valid'], 400);
    }

    $cleanIds = array_map('intval', $ids);
    $inClause = implode(',', array_fill(0, count($cleanIds), '?'));
    $stmt = $db->prepare("DELETE FROM measurements WHERE id IN ($inClause)");
    $stmt->execute($cleanIds);

    jsonResponse([
        'status' => 'success',
        'message' => count($cleanIds) . ' data berhasil dihapus',
        'deleted_count' => count($cleanIds)
    ]);
}

// ---------------------------------------------------------------------
// 6B. UBAH / HAPUS NAMA SAMPEL
// ---------------------------------------------------------------------
if ($action === 'update_sample_name' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $id = (int)($data['id'] ?? 0);
    $sampleName = trim($data['sample_name'] ?? '');

    $stmt = $db->prepare("UPDATE measurements SET sample_name = :name WHERE id = :id");
    $stmt->execute([':name' => $sampleName, ':id' => $id]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Nama sampel berhasil diperbarui',
        'sample_name' => $sampleName
    ]);
}

// ---------------------------------------------------------------------
// 7. EXPORT CSV
// ---------------------------------------------------------------------
if ($action === 'export_csv') {
    $filename = 'colorimeter_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // Header CSV
    fputcsv($output, [
        'ID', 'Waktu (WIT)', 'Nama Sampel', 'HEX', 'R', 'G', 'B',
        'CIE x', 'CIE y', 'CIE z', 'Lab L*', 'Lab a*', 'Lab b*', 'Delta E',
        'F1 (415nm)', 'F2 (445nm)', 'F3 (480nm)', 'F4 (515nm)',
        'F5 (555nm)', 'F6 (590nm)', 'F7 (630nm)', 'F8 (680nm)',
        'NIR', 'Clear', 'Arus LED (mA)', 'Gain', 'Sampel', 'Voltase Baterai', 'Catatan'
    ]);

    $stmt = $db->query("SELECT * FROM measurements ORDER BY id DESC");
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['id'], $row['created_at'], $row['sample_name'], $row['hex'],
            $row['r'], $row['g'], $row['b'],
            $row['cie_x'], $row['cie_y'], $row['cie_z'],
            $row['lab_l'], $row['lab_a'], $row['lab_b'], $row['de'],
            $row['f1_415'], $row['f2_445'], $row['f3_480'], $row['f4_515'],
            $row['f5_555'], $row['f6_590'], $row['f7_630'], $row['f8_680'],
            $row['nir'], $row['clear'],
            $row['led_ma'], $row['gain'], $row['samples'],
            $row['device_bat_v'], $row['notes']
        ]);
    }
    fclose($output);
    exit;
}

// ---------------------------------------------------------------------
// 8. PING & HEARTBEAT DARI ESP32
// ---------------------------------------------------------------------
if ($action === 'ping') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;

    $nowStr = date('Y-m-d H:i:s');
    $devId = !empty($data['device_id']) ? trim($data['device_id']) : 'ESP32S2_COLORIMETER';
    $batV = (float)($data['bat_v'] ?? ($data['bat_voltage'] ?? 4.0));
    $batPct = (int)($data['bat_pct'] ?? ($data['bat_percent'] ?? 100));
    $charging = !empty($data['charging']) ? 1 : 0;
    $rssi = (int)($data['rssi'] ?? 0);
    $fw = (string)($data['fw_version'] ?? 'v1.2.0');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Cari ID pengukuran terakhir
    $lastMid = (int)$db->query("SELECT id FROM measurements ORDER BY id DESC LIMIT 1")->fetchColumn();

    $stmtDev = $db->prepare("
        REPLACE INTO device_status (
            device_id, ip_address, firmware_version, bat_voltage, bat_percent, charging, rssi, last_seen, last_measurement_id
        ) VALUES (
            :dev, :ip, :fw, :bv, :bp, :chg, :rssi, :ls, :mid
        )
    ");
    $stmtDev->execute([
        ':dev' => $devId, ':ip' => $ip, ':fw' => $fw, ':bv' => $batV,
        ':bp' => $batPct, ':chg' => $charging, ':rssi' => $rssi, ':ls' => $nowStr, ':mid' => $lastMid
    ]);

    jsonResponse([
        'status' => 'ok',
        'device_id' => $devId,
        'server_time' => $nowStr,
        'server_epoch' => time(),
        'timezone' => APP_TIMEZONE
    ]);
}

jsonResponse(['status' => 'error', 'message' => 'Action tidak dikenali'], 400);

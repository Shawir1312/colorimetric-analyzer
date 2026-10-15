<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Pastikan sudah terinstal, jika belum arahkan ke install.php
if (!isAppInstalled()) {
    header('Location: install.php');
    exit;
}

// Wajibkan login
requireAuth();

$db = getDb();

// Ambil data terbaru
$latest = $db->query("SELECT * FROM measurements ORDER BY id DESC LIMIT 1")->fetch();
$device = $db->query("SELECT * FROM device_status ORDER BY last_seen DESC LIMIT 1")->fetch();
$totalCount = (int)$db->query("SELECT COUNT(*) FROM measurements")->fetchColumn();

// Ambil riwayat pengukuran (terbaru 15 data)
$search = trim($_GET['search'] ?? '');
$whereSql = "";
$params = [];
if ($search !== '') {
    $whereSql = "WHERE sample_name LIKE :s1 OR hex LIKE :s2 OR notes LIKE :s3";
    $params[':s1'] = "%$search%";
    $params[':s2'] = "%$search%";
    $params[':s3'] = "%$search%";
}

$stmt = $db->prepare("SELECT * FROM measurements $whereSql ORDER BY id DESC LIMIT 25");
$stmt->execute($params);
$measurements = $stmt->fetchAll();

// Hitung rata-rata Delta E
$avgDe = (float)$db->query("SELECT AVG(de) FROM measurements WHERE de > 0")->fetchColumn();

// Siapkan data untuk grafik
$spectrumVals = [0, 0, 0, 0, 0, 0, 0, 0];
$ciePoints = [];
if ($latest) {
    $spectrumVals = [
        (float)$latest['f1_415'], (float)$latest['f2_445'], (float)$latest['f3_480'], (float)$latest['f4_515'],
        (float)$latest['f5_555'], (float)$latest['f6_590'], (float)$latest['f7_630'], (float)$latest['f8_680']
    ];
}

foreach ($measurements as $m) {
    $ciePoints[] = [
        'x' => (float)$m['cie_x'],
        'y' => (float)$m['cie_y'],
        'hex' => $m['hex'],
        'label' => $m['sample_name']
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= APP_NAME ?> - Dashboard Spektrofotometri</title>
  <link rel="stylesheet" href="assets/css/style.css?v=20261015_light_v4">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🌈</text></svg>">
</head>
<body>

  <!-- Navbar -->
  <nav class="navbar">
    <a href="index.php" class="nav-brand">
      <div class="brand-logo">CA</div>
      <div class="brand-text">
        <h1>Colorimetric Analyzer</h1>
        <span>Spektrofotometri AS7341 & CIE 1931</span>
      </div>
    </a>

    <div class="nav-links">
      <a href="index.php" class="nav-link active">📊 Dashboard</a>
      <a href="compare.php" class="nav-link">⚖️ Bandingkan & ΔE</a>
      <a href="device.php" class="nav-link">📡 Perangkat & API</a>
    </div>

    <!-- Device Telemetry Badge & User Menu -->
    <div style="display: flex; align-items: center; gap: 0.75rem;">
      <div class="device-telemetry-badge">
        <span class="status-dot <?= ($device && (time() - strtotime($device['last_seen'])) < 120) ? '' : 'offline' ?>" id="device-status-dot"></span>
        <span id="device-status-text" style="font-weight: 600;"><?= ($device && (time() - strtotime($device['last_seen'])) < 120) ? 'ESP32 Online' : 'ESP32 Standby' ?></span>
        <span style="color: #64748B;">|</span>
        <span class="bat-pill" style="font-family: var(--font-mono); font-size: 0.8rem; color: #334155;">
          🔋 <span id="device-bat-text"><?= $device ? htmlspecialchars($device['bat_percent'] . '% (' . number_format($device['bat_voltage'], 2) . 'V)') : '100% (4.0V)' ?></span>
          <span class="lightning-icon" id="device-chg-icon" style="<?= ($device && $device['charging']) ? '' : 'display:none;' ?>; color: #D97706; font-weight: bold;">⚡</span>
        </span>
      </div>

      <div class="user-menu">
        <span class="user-badge">👤 <?= htmlspecialchars(getLoggedInUser()) ?></span>
        <a href="logout.php" class="btn btn-secondary btn-sm" title="Keluar dari akun">
          🚪 Keluar
        </a>
      </div>
    </div>
  </nav>

  <!-- Main Container -->
  <main class="container">

    <!-- Top Metrics Row -->
    <section class="stats-grid">
      <div class="stat-card">
        <div class="stat-title">Total Sampel Diuji</div>
        <div class="stat-value" style="color: var(--accent-blue);"><?= number_format($totalCount) ?></div>
        <div class="stat-sub">Data tersimpan di database</div>
      </div>

      <div class="stat-card">
        <div class="stat-title">Sampel Terakhir</div>
        <div class="stat-value" style="font-size: 1.25rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #0F172A;">
          <?= $latest ? htmlspecialchars($latest['sample_name'] ?: $latest['hex']) : 'Belum Ada' ?>
        </div>
        <div class="stat-sub">
          <?= $latest ? htmlspecialchars($latest['created_at']) . ' WIT' : '-' ?>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-title">Warna Terakhir (HEX)</div>
        <div class="stat-value" style="display: flex; align-items: center; gap: 0.6rem;">
          <span style="display: inline-block; width: 22px; height: 22px; border-radius: 50%; background: <?= $latest ? htmlspecialchars($latest['hex']) : '#CBD5E1' ?>; border: 2px solid #E2E8F0;"></span>
          <span style="font-family: var(--font-mono); font-size: 1.3rem;"><?= $latest ? htmlspecialchars($latest['hex']) : '-' ?></span>
        </div>
        <div class="stat-sub">
          <?= $latest ? 'RGB (' . $latest['r'] . ', ' . $latest['g'] . ', ' . $latest['b'] . ')' : '-' ?>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-title">Rerata Toleransi ΔE</div>
        <div class="stat-value" style="color: var(--accent-green);"><?= number_format($avgDe, 2) ?></div>
        <div class="stat-sub">Standar CIE76 vs Referensi</div>
      </div>
    </section>

    <!-- Visual Charts Grid (CIE 1931 & 8-Band Spectral) -->
    <section class="charts-grid">
      <!-- CIE 1931 Canvas Card -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">🌈 Diagram Kromatisitas CIE 1931 xy</div>
          <span class="badge badge-blue">Standar D65</span>
        </div>
        <div class="card-body">
          <div class="canvas-container">
            <canvas id="cieCanvas" height="320"></canvas>
          </div>
          <div style="margin-top: 0.75rem; font-size: 0.78rem; color: var(--text-muted); text-align: center;">
            Memetakan koordinat ruang warna visual manusia berdasarkan panjang gelombang sensor AS7341.
          </div>
        </div>
      </div>

      <!-- 8-Band Spectrum Canvas Card -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📈 Kurva Daya Spektral 8-Kanal (AS7341)</div>
          <span class="badge badge-green">415nm - 680nm</span>
        </div>
        <div class="card-body">
          <div class="canvas-container">
            <canvas id="spectrumCanvas" height="320"></canvas>
          </div>
          <div style="margin-top: 0.75rem; font-size: 0.78rem; color: var(--text-muted); text-align: center;">
            Intensitas relatif kanal optik: F1 (Ungu), F2 (Nila), F3-F4 (Biru/Sian), F5 (Hijau), F6 (Kuning), F7-F8 (Merah).
          </div>
        </div>
      </div>
    </section>

    <!-- Measurement History Table with Bulk Selection -->
    <section class="card">
      <div class="card-header">
        <div class="card-title">
          <span>📋 Riwayat Pengukuran Spektrofotometri</span>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
          <a href="api.php?action=export_csv" class="btn btn-secondary btn-sm" title="Unduh format spreadsheet">
            📥 Ekspor CSV
          </a>
          <button onclick="window.print()" class="btn btn-secondary btn-sm">
            🖨️ Cetak
          </button>
        </div>
      </div>

      <!-- Search and Filter Bar -->
      <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem; background: #FAFAFA;">
        <form method="GET" action="index.php" style="display: flex; gap: 0.5rem; align-items: center;">
          <input type="text" name="search" class="form-control" style="width: 260px; padding: 0.45rem 0.85rem; font-size: 0.85rem;" placeholder="Cari nama sampel, HEX #..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-primary btn-sm">Cari</button>
          <?php if ($search !== ''): ?>
            <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
          <?php endif; ?>
        </form>

        <span style="font-size: 0.82rem; color: var(--text-secondary);">
          Menampilkan <strong><?= count($measurements) ?></strong> dari <strong><?= $totalCount ?></strong> data
        </span>
      </div>

      <!-- Data Table -->
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 40px; text-align: center;">
                <input type="checkbox" id="checkAll" onchange="toggleSelectAll(this)" title="Pilih Semua">
              </th>
              <th>Warna & HEX</th>
              <th>Nama Sampel</th>
              <th>Waktu (WIT)</th>
              <th>RGB</th>
              <th>CIE (x, y)</th>
              <th>CIE Lab (L*, a*, b*)</th>
              <th>Delta E*</th>
              <th>Pengaturan</th>
              <th style="text-align: right;">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($measurements)): ?>
              <tr>
                <td colspan="10" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                  Belum ada data pengukuran yang cocok.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($measurements as $row): ?>
                <tr id="row-<?= $row['id'] ?>">
                  <td style="text-align: center;">
                    <input type="checkbox" class="row-check" value="<?= $row['id'] ?>" onchange="onRowCheckChange(this)">
                  </td>
                  <td>
                    <div class="color-swatch-pill">
                      <span class="color-dot" style="background-color: <?= htmlspecialchars($row['hex']) ?>;"></span>
                      <span><?= htmlspecialchars($row['hex']) ?></span>
                    </div>
                  </td>
                  <td>
                    <div style="display: flex; align-items: center; gap: 0.35rem;">
                      <span style="font-weight: 600; color: var(--text-primary); font-size: 0.9rem;">
                        <?= htmlspecialchars($row['sample_name'] ?: 'Tanpa Nama') ?>
                      </span>
                      <button type="button" onclick="editSampleName(<?= $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['sample_name'])) ?>')" class="btn btn-secondary btn-sm" style="padding: 1px 6px; font-size: 0.7rem; border-radius: 4px;" title="Ubah atau Hapus Nama Sampel">
                        ✏️
                      </button>
                    </div>
                    <?php if (!empty($row['notes'])): ?>
                      <div style="font-size: 0.75rem; color: var(--text-secondary); max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;">
                        <?= htmlspecialchars($row['notes']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="color: var(--text-secondary); font-size: 0.8rem; white-space: nowrap;">
                    <?= htmlspecialchars($row['created_at']) ?>
                  </td>
                  <td style="font-family: var(--font-mono); font-size: 0.8rem; color: #334155;">
                    <?= $row['r'] ?>, <?= $row['g'] ?>, <?= $row['b'] ?>
                  </td>
                  <td style="font-family: var(--font-mono); font-size: 0.8rem; color: #334155;">
                    <?= number_format($row['cie_x'], 3) ?>, <?= number_format($row['cie_y'], 3) ?>
                  </td>
                  <td style="font-family: var(--font-mono); font-size: 0.8rem; color: #334155;">
                    <?= number_format($row['lab_l'], 1) ?>, <?= number_format($row['lab_a'], 1) ?>, <?= number_format($row['lab_b'], 1) ?>
                  </td>
                  <td>
                    <?php if ($row['de'] > 0): ?>
                      <?php
                        $deClass = 'badge-green';
                        if ($row['de'] >= 5.0) $deClass = 'badge-red';
                        elseif ($row['de'] >= 2.0) $deClass = 'badge-amber';
                        elseif ($row['de'] >= 1.0) $deClass = 'badge-blue';
                      ?>
                      <span class="badge <?= $deClass ?>">ΔE <?= number_format($row['de'], 2) ?></span>
                    <?php else: ?>
                      <span style="color: var(--text-muted); font-size: 0.78rem;">-</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size: 0.75rem; color: var(--text-secondary); white-space: nowrap;">
                    <?= htmlspecialchars($row['led_ma'] . 'mA | ' . $row['gain'] . ' | N=' . $row['samples']) ?>
                  </td>
                  <td style="text-align: right; white-space: nowrap;">
                    <a href="detail.php?id=<?= $row['id'] ?>" class="btn btn-secondary btn-sm" title="Lihat Analisis Detail">Detail 🔬</a>
                    <a href="compare.php?id=<?= $row['id'] ?>" class="btn btn-secondary btn-sm" title="Bandingkan Warna">Bandingkan ⚖️</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

  <!-- Floating Bulk Action Bar (Muncul Saat Data Dipilih) -->
  <div id="bulk-action-bar" class="bulk-bar">
    <div class="bulk-text" id="bulk-count">0 data terpilih</div>
    <button type="button" onclick="confirmBulkDelete()" class="btn btn-danger btn-sm">
      🗑️ Hapus Data Terpilih
    </button>
    <button type="button" onclick="clearSelection()" class="btn btn-secondary btn-sm">
      Batal
    </button>
  </div>

  <script src="assets/js/app.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Inisialisasi diagram CIE 1931
      const ciePoints = <?= json_encode($ciePoints) ?>;
      drawCie1931('cieCanvas', ciePoints);

      // Inisialisasi grafik spektrum
      const specVals = <?= json_encode($spectrumVals) ?>;
      drawSpectrumChart('spectrumCanvas', specVals);
    });
  </script>
</body>
</html>

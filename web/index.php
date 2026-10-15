<?php
require_once __DIR__ . '/db.php';

if (!isAppInstalled()) {
    header('Location: install.php');
    exit;
}

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

$stmt = $db->prepare("SELECT * FROM measurements $whereSql ORDER BY id DESC LIMIT 15");
$stmt->execute($params);
$measurements = $stmt->fetchAll();

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
  <link rel="stylesheet" href="assets/css/style.css">
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
      <a href="install.php" class="nav-link" title="Pengaturan & Instalasi Database">⚙️ Setup</a>
    </div>

    <!-- Device Telemetry Badge -->
    <div class="device-telemetry-badge">
      <span class="status-dot <?= ($device && (time() - strtotime($device['last_seen'])) < 120) ? '' : 'offline' ?>" id="device-status-dot"></span>
      <span id="device-status-text"><?= ($device && (time() - strtotime($device['last_seen'])) < 120) ? 'ESP32 Online' : 'ESP32 Standby' ?></span>
      <span class="bat-pill">
        🔋 <span id="device-bat-text"><?= $device ? htmlspecialchars($device['bat_percent'] . '% (' . number_format($device['bat_voltage'], 2) . 'V)') : '100% (4.0V)' ?></span>
        <span class="lightning-icon" id="device-chg-icon" style="<?= ($device && $device['charging']) ? '' : 'display:none;' ?>">⚡</span>
      </span>
    </div>
  </nav>

  <!-- Main Container -->
  <main class="container">

    <!-- Top Metrics Row -->
    <section class="stats-grid">
      <div class="card stat-card">
        <div class="stat-icon">🧪</div>
        <div class="stat-info">
          <div class="stat-label">Total Sampel Diuji</div>
          <div class="stat-value"><?= number_format($totalCount) ?></div>
          <div class="stat-sub">Data tersimpan di database</div>
        </div>
      </div>

      <div class="card stat-card">
        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.15); color: #10B981;">🎨</div>
        <div class="stat-info">
          <div class="stat-label">Warna Terakhir</div>
          <div class="stat-value" style="font-family: var(--font-mono); font-size: 1.3rem;">
            <?= $latest ? htmlspecialchars($latest['hex']) : '#------' ?>
          </div>
          <div class="stat-sub" style="color: var(--text-secondary);">
            <?= $latest ? htmlspecialchars($latest['sample_name']) : 'Belum ada data' ?>
          </div>
        </div>
      </div>

      <div class="card stat-card">
        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.15); color: #F59E0B;">⚡</div>
        <div class="stat-info">
          <div class="stat-label">Baterai Alat (ESP32-S2)</div>
          <div class="stat-value">
            <?= $device ? htmlspecialchars($device['bat_percent'] . '%') : '100%' ?>
            <?php if ($device && $device['charging']): ?>
              <span style="font-size: 0.9rem; color: #F59E0B;">⚡ Mengisi</span>
            <?php endif; ?>
          </div>
          <div class="stat-sub" style="color: var(--text-secondary);">
            Voltase: <?= $device ? number_format($device['bat_voltage'], 2) . ' Volt' : '4.00 V' ?>
          </div>
        </div>
      </div>

      <div class="card stat-card">
        <div class="stat-icon" style="background: rgba(139, 92, 246, 0.15); color: #8B5CF6;">🕒</div>
        <div class="stat-info">
          <div class="stat-label">Waktu Sistem (WIT)</div>
          <div class="stat-value" style="font-size: 1.25rem;">
            <?= date('H:i:s') ?> <span style="font-size: 0.8rem; font-weight: 500; color: #A78BFA;">WIT</span>
          </div>
          <div class="stat-sub" style="color: var(--text-secondary);"><?= date('d F Y') ?></div>
        </div>
      </div>
    </section>

    <!-- Visual Analysis Section (CIE 1931 & Spectrum Reflectance) -->
    <section class="visuals-grid">
      <!-- CIE 1931 Diagram Card -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">
            <span>🌐</span> Diagram Kromatisitas CIE 1931
          </div>
          <span class="badge badge-blue">D65 Illuminant</span>
        </div>
        <div class="cie-diagram-container">
          <canvas id="cieCanvas"></canvas>
        </div>
        <div style="font-size: 0.78rem; color: var(--text-secondary); text-align: center; margin-top: 0.5rem;">
          Titik koordinat spektral CIE 1931 (x, y) dari sampel uji dibandingkan titik standar D65 putih.
        </div>
      </div>

      <!-- 8-Band AS7341 Spectral Curve Card -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">
            <span>🌈</span> Distribusi Spektrum 8-Band AS7341 (415nm - 680nm)
          </div>
          <span class="badge badge-green"><?= $latest ? htmlspecialchars($latest['gain'] . ' Gain') : '64X' ?></span>
        </div>
        <div class="spectrum-chart-container">
          <canvas id="spectrumCanvas"></canvas>
        </div>
        <div style="font-size: 0.78rem; color: var(--text-secondary); text-align: center; margin-top: 0.5rem;">
          F1 (415nm Violet) s/d F8 (680nm Deep Red) & interpolasi spline kontinu.
        </div>
      </div>
    </section>

    <!-- Recent Measurements Table Section -->
    <section class="card">
      <div class="card-header">
        <div class="card-title">
          <span>📋</span> Riwayat Pengukuran Terkini
        </div>
        <div class="btn-group">
          <a href="api.php?action=export_csv" class="btn btn-secondary btn-sm">📥 Unduh CSV</a>
          <button onclick="window.print()" class="btn btn-secondary btn-sm">🖨️ Cetak Laporan</button>
        </div>
      </div>

      <!-- Search and Filter Bar -->
      <div class="filter-bar">
        <form method="GET" action="index.php" style="display: flex; gap: 0.5rem;">
          <input type="text" name="search" class="search-input" placeholder="Cari nama sampel, HEX #..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-primary btn-sm">Cari</button>
          <?php if ($search !== ''): ?>
            <a href="index.php" class="btn btn-secondary btn-sm">Reset</a>
          <?php endif; ?>
        </form>

        <span style="font-size: 0.8rem; color: var(--text-secondary);">
          Menampilkan <?= count($measurements) ?> dari <?= $totalCount ?> data
        </span>
      </div>

      <!-- Data Table -->
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
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
                <td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                  Belum ada data pengukuran yang cocok.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($measurements as $row): ?>
                <tr>
                  <td>
                    <div style="display: flex; align-items: center;">
                      <span class="table-swatch" style="background-color: <?= htmlspecialchars($row['hex']) ?>;"></span>
                      <span style="font-family: var(--font-mono); font-weight: 600;"><?= htmlspecialchars($row['hex']) ?></span>
                    </div>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($row['sample_name']) ?></div>
                    <?php if (!empty($row['notes'])): ?>
                      <div style="font-size: 0.75rem; color: var(--text-secondary); max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        <?= htmlspecialchars($row['notes']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="color: var(--text-secondary); font-size: 0.8rem; white-space: nowrap;">
                    <?= htmlspecialchars($row['created_at']) ?>
                  </td>
                  <td style="font-family: var(--font-mono); font-size: 0.8rem;">
                    <?= $row['r'] ?>, <?= $row['g'] ?>, <?= $row['b'] ?>
                  </td>
                  <td style="font-family: var(--font-mono); font-size: 0.8rem;">
                    <?= number_format($row['cie_x'], 3) ?>, <?= number_format($row['cie_y'], 3) ?>
                  </td>
                  <td style="font-family: var(--font-mono); font-size: 0.8rem;">
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
                      <span style="color: var(--text-muted); font-size: 0.75rem;">-</span>
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

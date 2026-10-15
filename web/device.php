<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireAuth();
$currentUser = getLoggedInUser();

$db = getDb();
$device = $db->query("SELECT * FROM device_status ORDER BY last_seen DESC LIMIT 1")->fetch();

// Deteksi alamat IP server lokal
$serverHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$apiUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$serverHost" . dirname($_SERVER['PHP_SELF']) . '/api.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Integrasi Perangkat & API - <?= APP_NAME ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
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
      <a href="index.php" class="nav-link">📊 Dashboard</a>
      <a href="compare.php" class="nav-link">⚖️ Bandingkan & ΔE</a>
      <a href="device.php" class="nav-link active">📡 Perangkat & API</a>
      <div class="user-menu">
        <span class="user-badge">👤 <?= htmlspecialchars($currentUser['username'] ?? 'Admin') ?></span>
        <a href="logout.php" class="btn btn-secondary btn-sm" title="Keluar dari sesi" style="padding: 0.35rem 0.75rem;">🚪 Keluar</a>
      </div>
    </div>
  </nav>

  <main class="container">

    <div style="margin-bottom: 1.5rem;">
      <h2 style="font-size: 1.6rem; font-weight: 700;">📡 Status Perangkat & Dokumentasi API</h2>
      <p style="color: var(--text-secondary); font-size: 0.88rem;">
        Konfigurasi integrasi antara mikrokontroler ESP32-S2 dan Server Web PHP untuk penyimpanan otomatis.
      </p>
    </div>

    <!-- Status Perangkat Terkini Card -->
    <div class="card" style="margin-bottom: 1.5rem;">
      <div class="card-header">
        <div class="card-title">📱 Telemetri Alat (ESP32-S2 Colorimeter)</div>
        <span class="badge <?= ($device && (time() - strtotime($device['last_seen'])) < 120) ? 'badge-green' : 'badge-red' ?>">
          <?= ($device && (time() - strtotime($device['last_seen'])) < 120) ? 'Online (Aktif)' : 'Offline / Standby' ?>
        </span>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
        <div style="background: #F8FAFC; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
          <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">DEVICE ID</div>
          <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #0284C7; margin-top: 0.25rem;">
            <?= $device ? htmlspecialchars($device['device_id']) : 'Belum Terhubung' ?>
          </div>
        </div>

        <div style="background: #F8FAFC; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
          <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">KONDISI BATERAI</div>
          <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #059669; margin-top: 0.25rem;">
            <?= $device ? htmlspecialchars($device['bat_percent'] . '% (' . number_format($device['bat_voltage'], 2) . 'V)') : '100% (4.0V)' ?>
            <?= ($device && $device['charging']) ? '⚡' : '' ?>
          </div>
        </div>

        <div style="background: #F8FAFC; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
          <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">SINYAL WIFI (RSSI)</div>
          <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #D97706; margin-top: 0.25rem;">
            <?= $device ? htmlspecialchars($device['rssi'] . ' dBm') : '- dBm' ?>
          </div>
        </div>

        <div style="background: #F8FAFC; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
          <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">TERAKHIR AKTIF</div>
          <div style="font-family: var(--font-mono); font-size: 0.95rem; font-weight: 600; color: var(--text-primary); margin-top: 0.25rem;">
            <?= $device ? htmlspecialchars($device['last_seen']) : '-' ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Endpoint API & Dokumentasi Card -->
    <div class="card" style="margin-bottom: 1.5rem;">
      <div class="card-header">
        <div class="card-title">🔗 Endpoint API Pengiriman Data ESP32</div>
      </div>

      <div style="background: #F8FAFC; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 1rem;">
        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 0.35rem;">URL API Server:</div>
        <div style="font-family: var(--font-mono); font-size: 1rem; color: #0284C7; word-break: break-all;">
          <strong>POST</strong> <?= htmlspecialchars($apiUrl) ?>
        </div>
      </div>

      <div style="margin-bottom: 1.25rem;">
        <label style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.35rem; font-weight: 600;">
          Contoh Payload JSON yang Dikirim ESP32:
        </label>
        <pre style="background: #F8FAFC; padding: 1rem; border-radius: var(--radius-md); font-family: var(--font-mono); font-size: 0.82rem; color: #0F172A; overflow-x: auto; border: 1px solid var(--border-color);">{
  "sample_name": "Sampel Daun Teh #01",
  "hex": "#4A7C59",
  "r": 74, "g": 124, "b": 89,
  "cie_x": 0.2845, "cie_y": 0.4215, "cie_z": 0.2940,
  "lab_l": 48.2, "lab_a": -26.4, "lab_b": 15.8,
  "de": 0.0,
  "is_relative": 0,
  "spectrum": [15.2, 34.5, 58.0, 92.4, 110.2, 78.4, 42.1, 28.0],
  "nir": 85.0, "clear": 1650.0,
  "led_ma": 12, "gain": "64X", "samples": 5,
  "device_id": "ESP32S2_COLORIMETER",
  "bat_v": 4.02, "bat_pct": 82, "charging": 0, "rssi": -65
}</pre>
      </div>

      <!-- Tombol Kirim Sampel Uji Coba Langsung -->
      <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
        <button onclick="sendTestSample()" class="btn btn-primary" id="btnTest">
          🚀 Kirim Sampel Uji Coba Sekarang (Simulasi Pengiriman ESP32)
        </button>
        <span style="font-size: 0.8rem; color: var(--text-secondary);">
          Tombol ini akan mengirimkan data spektrum acak ke API untuk menguji apakah database dan dashboard berfungsi normal.
        </span>
      </div>
    </div>

    <!-- Card Konfigurasi Server & Instalasi -->
    <div class="card" style="margin-bottom: 1.5rem; border-left: 4px solid var(--accent-green);">
      <div class="card-header">
        <div class="card-title">⚙️ Konfigurasi Server & Database</div>
        <?php if (!file_exists(__DIR__ . '/installed.lock') && !file_exists(__DIR__ . '/data/installed.lock')): ?>
          <a href="install.php" class="btn btn-secondary btn-sm">Buka Wizard Instalasi (install.php)</a>
        <?php else: ?>
          <span class="badge badge-green">🔒 Terkunci (Sudah Terinstall)</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
          <div style="background: #F8FAFC; padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">DRIVER DATABASE</div>
            <div style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-top: 0.2rem;">
              <?= strtoupper(defined('DB_TYPE') ? DB_TYPE : 'SQLITE') ?>
            </div>
          </div>
          <div style="background: #F8FAFC; padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">ZONA WAKTU</div>
            <div style="font-size: 1rem; font-weight: 700; color: var(--accent-amber); margin-top: 0.2rem;">
              <?= defined('APP_TIMEZONE') ? APP_TIMEZONE : 'WIT (UTC+9)' ?>
            </div>
          </div>
          <div style="background: #F8FAFC; padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">API KEY ACTIVE</div>
            <div style="font-family: var(--font-mono); font-size: 0.9rem; font-weight: 700; color: #0284C7; margin-top: 0.2rem;">
              <?= defined('API_SECRET_KEY') ? API_SECRET_KEY : 'colan_secret_2026' ?>
            </div>
          </div>
        </div>
        <p style="font-size: 0.84rem; color: var(--text-secondary); margin-bottom: 0;">
          <?php if (file_exists(__DIR__ . '/installed.lock') || file_exists(__DIR__ . '/data/installed.lock')): ?>
            Instalasi telah selesai dan terkunci demi keamanan. Untuk mengkonfigurasi ulang, hapus file <code>installed.lock</code> di server Anda.
          <?php else: ?>
            Wizard instalasi dapat dibuka melalui <a href="install.php" style="color: var(--accent-blue); text-decoration: underline;">install.php</a>.
          <?php endif; ?>
        </p>
      </div>
    </div>

  </main>

  <script src="assets/js/app.js"></script>
  <script>
    async function sendTestSample() {
      const btn = document.getElementById('btnTest');
      btn.disabled = true;
      btn.textContent = 'Mengirim...';

      // Warna acak
      const randomColor = Math.floor(Math.random()*16777215).toString(16).padStart(6, '0');
      const r = parseInt(randomColor.substring(0, 2), 16);
      const g = parseInt(randomColor.substring(2, 4), 16);
      const b = parseInt(randomColor.substring(4, 6), 16);

      const payload = {
        sample_name: "Uji Coba Web #" + Math.floor(Math.random() * 1000),
        hex: "#" + randomColor.toUpperCase(),
        r: r, g: g, b: b,
        cie_x: +(Math.random() * 0.4 + 0.15).toFixed(4),
        cie_y: +(Math.random() * 0.4 + 0.15).toFixed(4),
        cie_z: +(Math.random() * 0.4 + 0.15).toFixed(4),
        lab_l: +(Math.random() * 60 + 20).toFixed(1),
        lab_a: +(Math.random() * 80 - 40).toFixed(1),
        lab_b: +(Math.random() * 80 - 40).toFixed(1),
        de: +(Math.random() * 3.5).toFixed(2),
        is_relative: 0,
        spectrum: [
          +(Math.random() * 80 + 10).toFixed(1),
          +(Math.random() * 80 + 10).toFixed(1),
          +(Math.random() * 80 + 10).toFixed(1),
          +(Math.random() * 80 + 10).toFixed(1),
          +(Math.random() * 80 + 10).toFixed(1),
          +(Math.random() * 80 + 10).toFixed(1),
          +(Math.random() * 80 + 10).toFixed(1),
          +(Math.random() * 80 + 10).toFixed(1)
        ],
        nir: +(Math.random() * 50 + 10).toFixed(1),
        clear: +(Math.random() * 1500 + 500).toFixed(1),
        led_ma: 12,
        gain: "64X",
        samples: 5,
        device_id: "ESP32S2_COLORIMETER",
        bat_v: 4.02,
        bat_pct: 85,
        charging: 0,
        rssi: -58
      };

      try {
        const res = await fetch('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const json = await res.json();
        if (json.status === 'success') {
          showToast(`Berhasil menyimpan data uji: ${payload.sample_name}`, payload.hex);
          setTimeout(() => { window.location.href = 'index.php'; }, 1000);
        } else {
          alert(json.message || 'Gagal mengirim data uji');
        }
      } catch (err) {
        alert('Gagal menghubungi API: ' + err.message);
      } finally {
        btn.disabled = false;
        btn.textContent = '🚀 Kirim Sampel Uji Coba Sekarang';
      }
    }
  </script>
</body>
</html>

<?php
require_once __DIR__ . '/db.php';

$db = getDb();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM measurements WHERE id = :id");
$stmt->execute([':id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    header('Location: index.php');
    exit;
}

$spectrum = [
    (float)$item['f1_415'], (float)$item['f2_445'], (float)$item['f3_480'], (float)$item['f4_515'],
    (float)$item['f5_555'], (float)$item['f6_590'], (float)$item['f7_630'], (float)$item['f8_680']
];

// Perhitungan Warna Komplementer (Invert RGB)
$compR = 255 - (int)$item['r'];
$compG = 255 - (int)$item['g'];
$compB = 255 - (int)$item['b'];
$compHex = sprintf('#%02X%02X%02X', $compR, $compG, $compB);

// Perhitungan HSL sederhana
$rNorm = $item['r'] / 255; $gNorm = $item['g'] / 255; $bNorm = $item['b'] / 255;
$max = max($rNorm, $gNorm, $bNorm); $min = min($rNorm, $gNorm, $bNorm);
$h = 0; $s = 0; $l = ($max + $min) / 2;
if ($max !== $min) {
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    switch ($max) {
        case $rNorm: $h = ($gNorm - $bNorm) / $d + ($gNorm < $bNorm ? 6 : 0); break;
        case $gNorm: $h = ($bNorm - $rNorm) / $d + 2; break;
        case $bNorm: $h = ($rNorm - $gNorm) / $d + 4; break;
    }
    $h /= 6;
}
$hslStr = sprintf('%d°, %d%%, %d%%', round($h * 360), round($s * 100), round($l * 100));
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Detail Pengukuran: <?= htmlspecialchars($item['sample_name']) ?> - <?= APP_NAME ?></title>
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
      <a href="index.php" class="nav-link">← Kembali ke Dashboard</a>
      <a href="compare.php?id=<?= $item['id'] ?>" class="nav-link">⚖️ Bandingkan Sampel Ini</a>
      <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Cetak Laporan</button>
    </div>
  </nav>

  <main class="container">

    <!-- Header Judul Sampel -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
      <div>
        <h2 style="font-size: 1.6rem; font-weight: 700;"><?= htmlspecialchars($item['sample_name']) ?></h2>
        <div style="color: var(--text-secondary); font-size: 0.88rem; margin-top: 0.2rem;">
          Diukur pada: <strong><?= htmlspecialchars($item['created_at']) ?> WIT</strong> • ID Pengukuran: #<?= $item['id'] ?>
        </div>
      </div>
      <div class="btn-group">
        <button onclick="openEditModal()" class="btn btn-secondary">✏️ Edit Catatan</button>
        <button onclick="confirmDelete(<?= $item['id'] ?>)" class="btn btn-danger">🗑️ Hapus</button>
      </div>
    </div>

    <!-- Detail Grid: Swatch Hero & Color Spaces -->
    <div class="detail-grid">

      <!-- Kolom Kiri: Swatch & Telemetri -->
      <div>
        <div class="card" style="margin-bottom: 1.25rem;">
          <div class="swatch-hero" style="background-color: <?= htmlspecialchars($item['hex']) ?>;">
            <div class="swatch-hero-hex"><?= htmlspecialchars($item['hex']) ?></div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
            <div style="background: #000; border-radius: var(--radius-sm); padding: 0.75rem; text-align: center; border: 1px solid var(--border-subtle);">
              <span style="display: inline-block; width: 24px; height: 24px; border-radius: 4px; background: <?= htmlspecialchars($item['hex']) ?>; vertical-align: middle;"></span>
              <div style="font-size: 0.72rem; color: #94A3B8; margin-top: 4px;">Uji Kontras Gelap</div>
            </div>
            <div style="background: #FFF; border-radius: var(--radius-sm); padding: 0.75rem; text-align: center; border: 1px solid var(--border-subtle);">
              <span style="display: inline-block; width: 24px; height: 24px; border-radius: 4px; background: <?= htmlspecialchars($item['hex']) ?>; vertical-align: middle;"></span>
              <div style="font-size: 0.72rem; color: #475569; margin-top: 4px;">Uji Kontras Terang</div>
            </div>
          </div>

          <div style="border-top: 1px solid var(--border-subtle); padding-top: 0.75rem;">
            <div style="font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">Warna Komplementer:</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
              <span style="width: 32px; height: 32px; border-radius: var(--radius-sm); background: <?= $compHex ?>; border: 1px solid var(--border-subtle);"></span>
              <div>
                <div style="font-family: var(--font-mono); font-weight: 600;"><?= $compHex ?></div>
                <div style="font-size: 0.72rem; color: var(--text-muted);">RGB: <?= $compR ?>, <?= $compG ?>, <?= $compB ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Kartu Telemetri Sensor & Perangkat -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">📡 Parameter Akuisisi</div>
          </div>
          <table class="param-table">
            <tr>
              <td>Perangkat MCU</td>
              <td><?= htmlspecialchars($item['device_id']) ?></td>
            </tr>
            <tr>
              <td>Sensor Spektral</td>
              <td>Adafruit AS7341 (I2C)</td>
            </tr>
            <tr>
              <td>Arus LED Sensor</td>
              <td><?= $item['led_ma'] ?> mA</td>
            </tr>
            <tr>
              <td>Sensor Gain</td>
              <td><?= htmlspecialchars($item['gain']) ?></td>
            </tr>
            <tr>
              <td>Jumlah Sampel Rata-rata</td>
              <td><?= $item['samples'] ?> sampel</td>
            </tr>
            <tr>
              <td>Baterai Saat Akuisisi</td>
              <td><?= number_format($item['device_bat_v'], 2) ?> V (<?= $item['device_bat_pct'] ?>%)</td>
            </tr>
            <tr>
              <td>Status Pengisian Baterai</td>
              <td><?= $item['charging'] ? '⚡ Sedang di-cas' : 'Baterai Standalone' ?></td>
            </tr>
            <tr>
              <td>Catatan Analis</td>
              <td style="font-family: var(--font-main); font-weight: 400;"><?= !empty($item['notes']) ? htmlspecialchars($item['notes']) : '-' ?></td>
            </tr>
          </table>
        </div>
      </div>

      <!-- Kolom Kanan: Ruang Warna & Analisis Spektrum 8-Band -->
      <div>
        <!-- Nilai Koordinat Ruang Warna -->
        <div class="card" style="margin-bottom: 1.25rem;">
          <div class="card-header">
            <div class="card-title">📐 Koordinat Ruang Warna Kolorimetri</div>
            <?php if ($item['de'] > 0): ?>
              <span class="badge badge-green">ΔE: <?= number_format($item['de'], 2) ?></span>
            <?php endif; ?>
          </div>

          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
            <div style="background: rgba(31, 41, 55, 0.5); padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
              <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">sRGB (0 - 255)</div>
              <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-top: 0.25rem;">
                R: <?= $item['r'] ?> <br>G: <?= $item['g'] ?> <br>B: <?= $item['b'] ?>
              </div>
            </div>

            <div style="background: rgba(31, 41, 55, 0.5); padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
              <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">CIE 1931 xyY</div>
              <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #38BDF8; margin-top: 0.25rem;">
                x: <?= number_format($item['cie_x'], 4) ?><br>
                y: <?= number_format($item['cie_y'], 4) ?><br>
                z: <?= number_format($item['cie_z'], 4) ?>
              </div>
            </div>

            <div style="background: rgba(31, 41, 55, 0.5); padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
              <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">CIE L*a*b* (D65)</div>
              <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #34D399; margin-top: 0.25rem;">
                L*: <?= number_format($item['lab_l'], 2) ?><br>
                a*: <?= number_format($item['lab_a'], 2) ?><br>
                b*: <?= number_format($item['lab_b'], 2) ?>
              </div>
            </div>

            <div style="background: rgba(31, 41, 55, 0.5); padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
              <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">HSL (Derajat & Persen)</div>
              <div style="font-family: var(--font-mono); font-size: 1.1rem; font-weight: 700; color: #FBBF24; margin-top: 0.25rem;">
                <?= $hslStr ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Grafik & Tabel Distribusi Spektral AS7341 -->
        <div class="card" style="margin-bottom: 1.25rem;">
          <div class="card-header">
            <div class="card-title">🌈 Kurva Distribusi Spektrum Reflektansi (415nm - 680nm)</div>
          </div>
          <div class="spectrum-chart-container">
            <canvas id="specDetailCanvas"></canvas>
          </div>

          <!-- Tabel Rincian Panjang Gelombang -->
          <div class="table-responsive" style="margin-top: 1rem;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Kanal Sensor</th>
                  <th>Panjang Gelombang</th>
                  <th>Spektrum Warna</th>
                  <th style="text-align: right;">Nilai Intensitas (ADC Counts)</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>F1</td>
                  <td>415 nm</td>
                  <td><span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#7E00DB; vertical-align:middle;"></span> Violet</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['f1_415'], 2) ?></td>
                </tr>
                <tr>
                  <td>F2</td>
                  <td>445 nm</td>
                  <td><span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#1900D5; vertical-align:middle;"></span> Indigo</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['f2_445'], 2) ?></td>
                </tr>
                <tr>
                  <td>F3</td>
                  <td>480 nm</td>
                  <td><span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#0075FF; vertical-align:middle;"></span> Biru</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['f3_480'], 2) ?></td>
                </tr>
                <tr>
                  <td>F4</td>
                  <td>515 nm</td>
                  <td><span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#00E4D0; vertical-align:middle;"></span> Cyan</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['f4_515'], 2) ?></td>
                </tr>
                <tr>
                  <td>F5</td>
                  <td>555 nm</td>
                  <td><span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#42FF00; vertical-align:middle;"></span> Hijau</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['f5_555'], 2) ?></td>
                </tr>
                <tr>
                  <td>F6</td>
                  <td>590 nm</td>
                  <td><span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#FFDC00; vertical-align:middle;"></span> Kuning</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['f6_590'], 2) ?></td>
                </tr>
                <tr>
                  <td>F7</td>
                  <td>630 nm</td>
                  <td><span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#FF6D00; vertical-align:middle;"></span> Oranye</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['f7_630'], 2) ?></td>
                </tr>
                <tr>
                  <td>F8</td>
                  <td>680 nm</td>
                  <td><span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#E80000; vertical-align:middle;"></span> Merah Pekat</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['f8_680'], 2) ?></td>
                </tr>
                <tr style="border-top: 2px solid var(--border-subtle);">
                  <td>NIR</td>
                  <td>Near-Infrared</td>
                  <td>Inframerah Dekat</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['nir'], 2) ?></td>
                </tr>
                <tr>
                  <td>Clear</td>
                  <td>Full Spectrum</td>
                  <td>Intensitas Total Cahaya</td>
                  <td style="text-align: right; font-family: var(--font-mono); font-weight: 600;"><?= number_format($item['clear'], 2) ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Diagram CIE 1931 Sampel Ini -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">🌐 Posisi Kromatisitas CIE 1931 Sampel</div>
          </div>
          <div class="cie-diagram-container">
            <canvas id="cieDetailCanvas"></canvas>
          </div>
        </div>

      </div>
    </div>

  </main>

  <!-- Modal Edit Catatan -->
  <div class="modal-overlay" id="editModal">
    <div class="modal-dialog">
      <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 1rem;">Edit Nama Sampel & Catatan</h3>
      <form id="editForm" onsubmit="saveNotes(event)">
        <input type="hidden" name="id" value="<?= $item['id'] ?>">
        <div style="margin-bottom: 1rem;">
          <label style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.35rem;">Nama Sampel:</label>
          <input type="text" name="sample_name" class="search-input" style="width: 100%;" value="<?= htmlspecialchars($item['sample_name']) ?>" required>
        </div>
        <div style="margin-bottom: 1.25rem;">
          <label style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.35rem;">Catatan Analisis:</label>
          <textarea name="notes" class="search-input" style="width: 100%; height: 90px; resize: vertical;"><?= htmlspecialchars($item['notes']) ?></textarea>
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
          <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>

  <script src="assets/js/app.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Grafik Spektrum
      const specVals = <?= json_encode($spectrum) ?>;
      drawSpectrumChart('specDetailCanvas', specVals);

      // CIE 1931
      const pt = [{
        x: <?= (float)$item['cie_x'] ?>,
        y: <?= (float)$item['cie_y'] ?>,
        hex: '<?= $item['hex'] ?>',
        label: '<?= addslashes($item['sample_name']) ?>'
      }];
      drawCie1931('cieDetailCanvas', pt);
    });

    function openEditModal() {
      document.getElementById('editModal').classList.add('active');
    }
    function closeEditModal() {
      document.getElementById('editModal').classList.remove('active');
    }

    async function saveNotes(e) {
      e.preventDefault();
      const form = document.getElementById('editForm');
      const formData = new FormData(form);
      formData.append('action', 'update_notes');

      try {
        const res = await fetch('api.php', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.status === 'success') {
          showToast('Catatan berhasil diperbarui!', '#10B981');
          setTimeout(() => { window.location.reload(); }, 800);
        } else {
          alert(json.message || 'Gagal menyimpan');
        }
      } catch (err) {
        alert('Gagal menghubungi server');
      }
    }

    async function confirmDelete(id) {
      if (!confirm('Apakah Anda yakin ingin menghapus data pengukuran ini?')) return;
      try {
        const res = await fetch(`api.php?action=delete&id=${id}`, { method: 'POST' });
        const json = await res.json();
        if (json.status === 'success') {
          alert('Data berhasil dihapus');
          window.location.href = 'index.php';
        }
      } catch (e) {
        alert('Gagal menghapus');
      }
    }
  </script>
</body>
</html>

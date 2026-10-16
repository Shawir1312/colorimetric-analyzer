<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireAuth();

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

// Perhitungan HSL
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
  <title>Detail: <?= htmlspecialchars($item['sample_name'] ?: $item['hex']) ?> - <?= APP_NAME ?></title>
  <link rel="stylesheet" href="assets/css/style.css?v=20261015_light_v4">
  <style>
    .swatch-hero {
      height: 140px;
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      margin-bottom: 1.25rem;
      border: 1px solid var(--border-subtle);
    }
    .swatch-hero-hex {
      font-family: var(--font-mono);
      font-size: 1.5rem;
      font-weight: 800;
      background: rgba(255, 255, 255, 0.95);
      color: #0F172A;
      padding: 0.4rem 1.1rem;
      border-radius: var(--radius-full);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    .detail-grid {
      display: grid;
      grid-template-columns: 360px 1fr;
      gap: 1.5rem;
    }
    @media (max-width: 900px) {
      .detail-grid {
        grid-template-columns: 1fr;
      }
    }
    .param-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.86rem;
    }
    .param-table td {
      padding: 0.65rem 0.25rem;
      border-bottom: 1px solid var(--border-subtle);
    }
    .param-table td:first-child {
      color: var(--text-secondary);
      font-weight: 500;
      width: 45%;
    }
    .param-table td:last-child {
      font-weight: 600;
      color: var(--text-primary);
      text-align: right;
      font-family: var(--font-mono);
    }
  </style>
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
      <div class="user-menu">
        <a href="logout.php" class="btn btn-secondary btn-sm">🚪 Keluar</a>
      </div>
    </div>
  </nav>

  <main class="container">

    <!-- Header Judul Sampel -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
      <div>
        <div style="display: flex; align-items: center; gap: 0.6rem;">
          <h2 style="font-size: 1.6rem; font-weight: 800; color: #0F172A;">
            <?= htmlspecialchars($item['sample_name'] ?: 'Tanpa Nama') ?>
          </h2>
          <button onclick="editSampleName(<?= $item['id'] ?>, '<?= addslashes(htmlspecialchars($item['sample_name'])) ?>')" class="btn btn-secondary btn-sm" title="Ubah atau Hapus Nama">
            ✏️ Ubah / Hapus Nama
          </button>
        </div>
        <div style="color: var(--text-secondary); font-size: 0.88rem; margin-top: 0.2rem;">
          Diukur pada: <strong><?= htmlspecialchars($item['created_at']) ?> WIT</strong> • ID Pengukuran: #<?= $item['id'] ?>
        </div>
      </div>
      <div style="display: flex; gap: 0.5rem;">
        <button onclick="openEditModal()" class="btn btn-secondary">📝 Edit Catatan Lengkap</button>
        <button onclick="confirmDelete(<?= $item['id'] ?>)" class="btn btn-danger">🗑️ Hapus Sampel</button>
      </div>
    </div>

    <!-- Detail Grid -->
    <div class="detail-grid">

      <!-- Kolom Kiri -->
      <div>
        <div class="card" style="margin-bottom: 1.25rem;">
          <div class="swatch-hero" style="background-color: <?= htmlspecialchars($item['hex']) ?>;">
            <div class="swatch-hero-hex"><?= htmlspecialchars($item['hex']) ?></div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1rem;">
            <div style="background: #F0F7FF; border: 1px solid #BFDBFE; border-radius: var(--radius-sm); padding: 0.75rem; text-align: center;">
              <span style="display: inline-block; width: 22px; height: 22px; border-radius: 4px; background: <?= htmlspecialchars($item['hex']) ?>; vertical-align: middle; border: 1px solid #CBD5E1;"></span>
              <div style="font-size: 0.75rem; color: #1E3A8A; font-weight: 600; margin-top: 4px;">Uji Latar Biru Lembut</div>
            </div>
            <div style="background: #FFFFFF; border-radius: var(--radius-sm); padding: 0.75rem; text-align: center; border: 1px solid var(--border-subtle);">
              <span style="display: inline-block; width: 22px; height: 22px; border-radius: 4px; background: <?= htmlspecialchars($item['hex']) ?>; vertical-align: middle; border: 1px solid #CBD5E1;"></span>
              <div style="font-size: 0.75rem; color: #475569; font-weight: 600; margin-top: 4px;">Uji Latar Terang</div>
            </div>
          </div>

          <div style="border-top: 1px solid var(--border-subtle); padding-top: 0.75rem;">
            <div style="font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.5rem;">Warna Komplementer:</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
              <span style="width: 32px; height: 32px; border-radius: var(--radius-sm); background: <?= $compHex ?>; border: 1px solid var(--border-subtle);"></span>
              <div>
                <div style="font-family: var(--font-mono); font-weight: 600; color: #0F172A;"><?= $compHex ?></div>
                <div style="font-size: 0.72rem; color: var(--text-muted);">RGB: <?= $compR ?>, <?= $compG ?>, <?= $compB ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Parameter Akuisisi -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">📡 Parameter Akuisisi Sensor</div>
          </div>
          <div class="card-body">
            <table class="param-table">
              <tr>
                <td>Perangkat MCU</td>
                <td><?= htmlspecialchars($item['device_id']) ?></td>
              </tr>
              <tr>
                <td>Sensor Spektral</td>
                <td>AS7341 (8-Kanal)</td>
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
                <td>Sampel Rata-rata</td>
                <td><?= $item['samples'] ?>x averaging</td>
              </tr>
              <tr>
                <td>Baterai Saat Ukur</td>
                <td><?= number_format($item['device_bat_v'], 2) ?> V (<?= $item['device_bat_pct'] ?>%)</td>
              </tr>
              <tr>
                <td>Status Charger</td>
                <td><?= $item['charging'] ? '⚡ Mengisi Daya' : 'Baterai' ?></td>
              </tr>
              <tr>
                <td>Catatan Sampel</td>
                <td style="font-family: var(--font-main); font-weight: 400;"><?= !empty($item['notes']) ? htmlspecialchars($item['notes']) : '-' ?></td>
              </tr>
            </table>
          </div>
        </div>
      </div>

      <!-- Kolom Kanan -->
      <div>

        <!-- Kartu Hasil Uji Logam Berat (Jika Sampel Logam) -->
        <?php if (!empty($item['metal_type']) || strpos(strtolower($item['sample_name']), 'cu') !== false || strpos(strtolower($item['sample_name']), 'fe') !== false || strpos(strtolower($item['sample_name']), 'hg') !== false || strpos(strtolower($item['sample_name']), 'pb') !== false): 
          $mType = !empty($item['metal_type']) ? $item['metal_type'] : 'Logam Berat';
          $mConc = isset($item['metal_conc']) ? (float)$item['metal_conc'] : 0.0;
          $mUnit = !empty($item['metal_unit']) ? $item['metal_unit'] : 'mg/L';
          $pWl = !empty($item['peak_wl']) ? $item['peak_wl'] : 415;
          $stat = !empty($item['water_status']) ? $item['water_status'] : 'Hasil Analisis';
          $isDanger = (strpos(strtoupper($stat), 'TERCEMAR') !== false || strpos(strtoupper($stat), 'BAHAYA') !== false);
        ?>
        <div class="card" style="margin-bottom: 1.25rem; border-left: 5px solid <?= $isDanger ? 'var(--accent-red, #EF4444)' : 'var(--accent-green, #10B981)' ?>;">
          <div class="card-header" style="background: rgba(<?= $isDanger ? '239, 68, 68, 0.06' : '16, 185, 129, 0.06' ?>);">
            <div class="card-title" style="display: flex; align-items: center; gap: 8px;">
              <span>⚗️ Parameter Uji Logam Berat: <strong><?= htmlspecialchars($mType) ?></strong></span>
            </div>
            <span class="badge <?= $isDanger ? 'badge-red' : 'badge-green' ?>" style="font-size: 0.82rem; padding: 4px 10px;">
              <?= $isDanger ? '⚠️ ' . htmlspecialchars($stat) : '✅ ' . htmlspecialchars($stat) ?>
            </span>
          </div>
          <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
              <div style="background: #FFF; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Konsentrasi Logam Terukur</div>
                <div style="font-family: var(--font-mono); font-size: 1.6rem; font-weight: 800; color: <?= $isDanger ? '#DC2626' : '#059669' ?>; margin-top: 4px;">
                  <?= number_format($mConc, 3) ?> <span style="font-size: 0.95rem; font-weight: 600; color: #475569;"><?= htmlspecialchars($mUnit) ?></span>
                </div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 4px;">1 mg/L = 1 ppm = 1.000 ppb</div>
              </div>

              <div style="background: #FFF; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Panjang Gelombang Kunci (λ)</div>
                <div style="font-family: var(--font-mono); font-size: 1.3rem; font-weight: 800; color: #1E40AF; margin-top: 4px;">
                  λ = <?= $pWl ?> nm
                </div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 4px;">Resonansi Plasmon (AS7341 Channel)</div>
              </div>

              <div style="background: #FFF; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle); box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Metode Indikator Bahan Alam</div>
                <div style="font-size: 0.95rem; font-weight: 700; color: #0F172A; margin-top: 6px;">
                  <?= !empty($item['indicator']) ? htmlspecialchars($item['indicator']) : 'AgNP + Ekstrak Alami' ?>
                </div>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 4px;">Green Synthesis Biosensor</div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Ruang Warna -->
        <div class="card" style="margin-bottom: 1.25rem;">
          <div class="card-header">
            <div class="card-title">📐 Koordinat Ruang Warna</div>
            <?php if ($item['de'] > 0): ?>
              <span class="badge badge-green">ΔE: <?= number_format($item['de'], 2) ?></span>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 0.75rem;">
              <div style="background: #F8FAFC; padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
                <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">sRGB (0 - 255)</div>
                <div style="font-family: var(--font-mono); font-size: 1rem; font-weight: 700; color: #0F172A; margin-top: 0.25rem;">
                  R: <?= $item['r'] ?> <br>G: <?= $item['g'] ?> <br>B: <?= $item['b'] ?>
                </div>
              </div>

              <div style="background: #F8FAFC; padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
                <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">CIE 1931 xyY</div>
                <div style="font-family: var(--font-mono); font-size: 1rem; font-weight: 700; color: #0F172A; margin-top: 0.25rem;">
                  x: <?= number_format($item['cie_x'], 4) ?> <br>
                  y: <?= number_format($item['cie_y'], 4) ?> <br>
                  z: <?= number_format($item['cie_z'], 4) ?>
                </div>
              </div>

              <div style="background: #F8FAFC; padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
                <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">CIE L*a*b* (CIE76)</div>
                <div style="font-family: var(--font-mono); font-size: 1rem; font-weight: 700; color: #0F172A; margin-top: 0.25rem;">
                  L*: <?= number_format($item['lab_l'], 2) ?> <br>
                  a*: <?= number_format($item['lab_a'], 2) ?> <br>
                  b*: <?= number_format($item['lab_b'], 2) ?>
                </div>
              </div>

              <div style="background: #F8FAFC; padding: 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-subtle);">
                <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600;">HSL & NIR/Clear</div>
                <div style="font-family: var(--font-mono); font-size: 0.92rem; font-weight: 700; color: #0F172A; margin-top: 0.25rem;">
                  HSL: <?= $hslStr ?> <br>
                  NIR: <?= number_format($item['nir'], 1) ?> <br>
                  Clear: <?= number_format($item['clear'], 0) ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 8-Band Spectral Table & Chart -->
        <div class="card" style="margin-bottom: 1.25rem;">
          <div class="card-header">
            <div class="card-title">📈 Kurva Spektral 8-Kanal AS7341</div>
          </div>
          <div class="card-body">
            <div class="canvas-container" style="margin-bottom: 1rem;">
              <canvas id="specDetailCanvas" height="260"></canvas>
            </div>
            <div class="table-responsive">
              <table class="table" style="font-size: 0.82rem; text-align: center;">
                <thead>
                  <tr>
                    <th>F1 415nm</th><th>F2 445nm</th><th>F3 480nm</th><th>F4 515nm</th>
                    <th>F5 555nm</th><th>F6 590nm</th><th>F7 630nm</th><th>F8 680nm</th>
                  </tr>
                </thead>
                <tbody>
                  <tr style="font-family: var(--font-mono); font-weight: 600;">
                    <?php foreach ($spectrum as $v): ?>
                      <td><?= number_format($v, 1) ?></td>
                    <?php endforeach; ?>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Plot CIE 1931 -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">🌈 Plot Koordinat CIE 1931 xy</div>
          </div>
          <div class="card-body">
            <div class="canvas-container">
              <canvas id="cieDetailCanvas" height="300"></canvas>
            </div>
          </div>
        </div>

      </div>
    </div>

  </main>

  <!-- Modal Edit Catatan -->
  <div class="modal-backdrop" id="editModal">
    <div class="modal-content">
      <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 1.15rem; font-weight: 700; color: #0F172A;">Edit Sampel & Catatan</h3>
        <button onclick="closeEditModal()" class="btn btn-secondary btn-sm" style="border: none; font-size: 1.2rem; cursor: pointer;">✕</button>
      </div>
      <form id="editForm" onsubmit="saveNotes(event)" style="padding: 1.5rem;">
        <input type="hidden" name="id" value="<?= $item['id'] ?>">
        
        <div class="form-group" style="margin-bottom: 1rem;">
          <label class="form-label">Nama Sampel</label>
          <input type="text" name="sample_name" id="modal_sample_name" class="form-control" value="<?= htmlspecialchars($item['sample_name']) ?>" placeholder="Contoh: Daun Teh Segar (Kosongkan jika ingin menghapus)">
          <small style="color: var(--text-muted); font-size: 0.75rem;">Jika dikosongkan, nama sampel akan dihapus.</small>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
          <label class="form-label">Catatan Analisis Tambahan</label>
          <textarea name="notes" class="form-control" style="height: 90px; resize: vertical;"><?= htmlspecialchars($item['notes']) ?></textarea>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center;">
          <button type="button" onclick="document.getElementById('modal_sample_name').value = '';" class="btn btn-secondary btn-sm" style="color: var(--accent-red);">
            Hapus Nama Saja
          </button>
          <div style="display: flex; gap: 0.5rem;">
            <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script src="assets/js/app.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const specVals = <?= json_encode($spectrum) ?>;
      drawSpectrumChart('specDetailCanvas', specVals);

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

      try {
        // Simpan nama
        await fetch('api.php?action=update_sample_name', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            id: <?= $item['id'] ?>,
            sample_name: formData.get('sample_name').trim()
          })
        });

        // Simpan catatan
        await fetch('api.php?action=update_notes', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            id: <?= $item['id'] ?>,
            notes: formData.get('notes').trim()
          })
        });

        showToast('Data sampel berhasil diperbarui!', '#059669');
        setTimeout(() => { window.location.reload(); }, 600);
      } catch (err) {
        alert('Gagal menghubungi server');
      }
    }

    async function confirmDelete(id) {
      if (!confirm('Apakah Anda yakin ingin menghapus data pengukuran ini?')) return;
      try {
        const res = await fetch('api.php?action=delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: id })
        });
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

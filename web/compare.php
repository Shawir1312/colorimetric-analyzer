<?php
require_once __DIR__ . '/db.php';

$db = getDb();

// Ambil semua sampel untuk dropdown
$samples = $db->query("SELECT id, sample_name, hex, created_at, lab_l, lab_a, lab_b FROM measurements ORDER BY id DESC LIMIT 50")->fetchAll();

$id1 = (int)($_GET['id1'] ?? ($_GET['id'] ?? ($samples[0]['id'] ?? 0)));
$id2 = (int)($_GET['id2'] ?? ($samples[1]['id'] ?? ($samples[0]['id'] ?? 0)));

$stmt1 = $db->prepare("SELECT * FROM measurements WHERE id = :id");
$stmt1->execute([':id' => $id1]);
$s1 = $stmt1->fetch();

$stmt2 = $db->prepare("SELECT * FROM measurements WHERE id = :id");
$stmt2->execute([':id' => $id2]);
$s2 = $stmt2->fetch();

// Hitung Delta E76
$de76 = 0;
$dL = 0; $da = 0; $dbVal = 0;
if ($s1 && $s2) {
    $dL = $s2['lab_l'] - $s1['lab_l'];
    $da = $s2['lab_a'] - $s1['lab_a'];
    $dbVal = $s2['lab_b'] - $s1['lab_b'];
    $de76 = sqrt(($dL * $dL) + ($da * $da) + ($dbVal * $dbVal));
}

// Toleransi industri
$evalText = "Sama";
$evalBadge = "badge-green";
if ($de76 < 1.0) {
    $evalText = "Sempurna (< 1.0) - Perbedaan hampir tidak kasat mata bagi mata manusia";
    $evalBadge = "badge-green";
} elseif ($de76 < 2.0) {
    $evalText = "Sangat Baik (< 2.0) - Memenuhi standar toleransi industri & komersial ketat";
    $evalBadge = "badge-blue";
} elseif ($de76 < 5.0) {
    $evalText = "Perbedaan Terlihat (2.0 - 5.0) - Terlihat jelas jika disandingkan berdampingan";
    $evalBadge = "badge-amber";
} else {
    $evalText = "Perbedaan Signifikan (>= 5.0) - Perbedaan warna sangat mencolok";
    $evalBadge = "badge-red";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bandingkan Warna & Analisis ΔE - <?= APP_NAME ?></title>
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
      <a href="compare.php" class="nav-link active">⚖️ Bandingkan & ΔE</a>
      <a href="device.php" class="nav-link">📡 Perangkat & API</a>
      <a href="install.php" class="nav-link" title="Pengaturan & Instalasi Database">⚙️ Setup</a>
    </div>
  </nav>

  <main class="container">

    <div style="margin-bottom: 1.5rem;">
      <h2 style="font-size: 1.6rem; font-weight: 700;">⚖️ Komparasi Warna & Perhitungan Toleransi ΔE</h2>
      <p style="color: var(--text-secondary); font-size: 0.88rem;">
        Bandingkan dua sampel pengukuran secara objektif berdasarkan standar metrik kolorimetri CIE Lab (ΔE*ab).
      </p>
    </div>

    <!-- Dropdown Pemilihan Sampel -->
    <div class="card" style="margin-bottom: 1.5rem;">
      <form method="GET" action="compare.php" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 1rem; align-items: flex-end;">
        <div>
          <label style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.35rem; font-weight: 600;">
            Sampel 1 (Referensi / Standar):
          </label>
          <select name="id1" class="search-input" style="width: 100%;">
            <?php foreach ($samples as $sm): ?>
              <option value="<?= $sm['id'] ?>" <?= $sm['id'] == $id1 ? 'selected' : '' ?>>
                <?= htmlspecialchars($sm['sample_name']) ?> (<?= $sm['hex'] ?>) - <?= $sm['created_at'] ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label style="display: block; font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.35rem; font-weight: 600;">
            Sampel 2 (Sampel Uji / Batch):
          </label>
          <select name="id2" class="search-input" style="width: 100%;">
            <?php foreach ($samples as $sm): ?>
              <option value="<?= $sm['id'] ?>" <?= $sm['id'] == $id2 ? 'selected' : '' ?>>
                <?= htmlspecialchars($sm['sample_name']) ?> (<?= $sm['hex'] ?>) - <?= $sm['created_at'] ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <button type="submit" class="btn btn-primary" style="height: 38px;">Bandingkan Sekarang ⚖️</button>
        </div>
      </form>
    </div>

    <?php if ($s1 && $s2): ?>

      <!-- Visual Side-by-Side Swatch & Delta E -->
      <div class="compare-grid">
        <div class="card compare-swatch" style="background-color: <?= htmlspecialchars($s1['hex']) ?>;">
          <div class="swatch-hero-hex"><?= htmlspecialchars($s1['hex']) ?></div>
          <div style="background: rgba(0,0,0,0.7); padding: 4px 12px; border-radius: 6px; font-weight: 600; font-size: 0.9rem; color: #FFF;">
            <?= htmlspecialchars($s1['sample_name']) ?>
          </div>
        </div>

        <div class="vs-badge">
          VS
          <div style="font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); margin-top: 4px;">Perbedaan</div>
        </div>

        <div class="card compare-swatch" style="background-color: <?= htmlspecialchars($s2['hex']) ?>;">
          <div class="swatch-hero-hex"><?= htmlspecialchars($s2['hex']) ?></div>
          <div style="background: rgba(0,0,0,0.7); padding: 4px 12px; border-radius: 6px; font-weight: 600; font-size: 0.9rem; color: #FFF;">
            <?= htmlspecialchars($s2['sample_name']) ?>
          </div>
        </div>
      </div>

      <!-- Hasil Delta E Card -->
      <div class="card" style="margin-bottom: 1.5rem; text-align: center; padding: 2rem;">
        <div style="font-size: 0.9rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">
          Selisih Perbedaan Warna CIE 1976 (ΔE*ab)
        </div>
        <div style="font-size: 3.5rem; font-weight: 800; font-family: var(--font-mono); color: var(--text-primary); margin: 0.5rem 0;">
          <?= number_format($de76, 2) ?>
        </div>
        <div>
          <span class="badge <?= $evalBadge ?>" style="font-size: 0.95rem; padding: 0.4rem 1.2rem;">
            <?= $evalText ?>
          </span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; max-width: 600px; margin: 1.5rem auto 0 auto; text-align: left;">
          <div style="background: rgba(31, 41, 55, 0.5); padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
            <div style="font-size: 0.75rem; color: var(--text-secondary);">ΔL* (Kecerahan)</div>
            <div style="font-family: var(--font-mono); font-weight: 700; font-size: 1.1rem; color: <?= $dL >= 0 ? '#34D399' : '#F87171' ?>;">
              <?= $dL >= 0 ? '+' : '' ?><?= number_format($dL, 2) ?>
            </div>
            <div style="font-size: 0.7rem; color: var(--text-muted);"><?= $dL >= 0 ? 'Lebih terang' : 'Lebih gelap' ?></div>
          </div>

          <div style="background: rgba(31, 41, 55, 0.5); padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
            <div style="font-size: 0.75rem; color: var(--text-secondary);">Δa* (Merah-Hijau)</div>
            <div style="font-family: var(--font-mono); font-weight: 700; font-size: 1.1rem; color: <?= $da >= 0 ? '#F87171' : '#34D399' ?>;">
              <?= $da >= 0 ? '+' : '' ?><?= number_format($da, 2) ?>
            </div>
            <div style="font-size: 0.7rem; color: var(--text-muted);"><?= $da >= 0 ? 'Lebih kemerahan' : 'Lebih kehijauan' ?></div>
          </div>

          <div style="background: rgba(31, 41, 55, 0.5); padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
            <div style="font-size: 0.75rem; color: var(--text-secondary);">Δb* (Kuning-Biru)</div>
            <div style="font-family: var(--font-mono); font-weight: 700; font-size: 1.1rem; color: <?= $dbVal >= 0 ? '#FBBF24' : '#60A5FA' ?>;">
              <?= $dbVal >= 0 ? '+' : '' ?><?= number_format($dbVal, 2) ?>
            </div>
            <div style="font-size: 0.7rem; color: var(--text-muted);"><?= $dbVal >= 0 ? 'Lebih kekuningan' : 'Lebih kebiruan' ?></div>
          </div>
        </div>
      </div>

      <!-- Tabel Komparasi Spektral Detail 8-Band -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📊 Perbandingan Spektral 8-Band (AS7341)</div>
        </div>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Panjang Gelombang</th>
                <th>Sampel 1 (<?= htmlspecialchars($s1['sample_name']) ?>)</th>
                <th>Sampel 2 (<?= htmlspecialchars($s2['sample_name']) ?>)</th>
                <th style="text-align: right;">Selisih Relatif</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $bandsKeys = ['f1_415' => '415 nm (Violet)', 'f2_445' => '445 nm (Indigo)', 'f3_480' => '480 nm (Biru)',
                            'f4_515' => '515 nm (Cyan)', 'f5_555' => '555 nm (Hijau)', 'f6_590' => '590 nm (Kuning)',
                            'f7_630' => '630 nm (Oranye)', 'f8_680' => '680 nm (Merah)'];
              foreach ($bandsKeys as $k => $label):
                $v1 = (float)$s1[$k];
                $v2 = (float)$s2[$k];
                $diff = $v2 - $v1;
                $pctDiff = $v1 > 0 ? (($diff / $v1) * 100) : 0;
              ?>
                <tr>
                  <td><strong><?= $label ?></strong></td>
                  <td style="font-family: var(--font-mono);"><?= number_format($v1, 2) ?></td>
                  <td style="font-family: var(--font-mono);"><?= number_format($v2, 2) ?></td>
                  <td style="text-align: right; font-family: var(--font-mono); color: <?= $diff >= 0 ? '#34D399' : '#F87171' ?>;">
                    <?= $diff >= 0 ? '+' : '' ?><?= number_format($diff, 2) ?> (<?= number_format($pctDiff, 1) ?>%)
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php endif; ?>

  </main>

  <script src="assets/js/app.js"></script>
</body>
</html>

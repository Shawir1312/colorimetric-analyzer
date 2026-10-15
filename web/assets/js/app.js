/**
 * Colorimetric Analyzer - Client Application JavaScript
 * CIE 1931 Chromaticity Diagram & Spectral Power Distribution Rendering
 */

// Wavelength to approximate sRGB color helper
function wlToRgb(wl) {
  let r = 0, g = 0, b = 0;
  if (wl >= 380 && wl < 440) {
    r = -(wl - 440) / (440 - 380); b = 1.0;
  } else if (wl >= 440 && wl < 490) {
    g = (wl - 440) / (490 - 440); b = 1.0;
  } else if (wl >= 490 && wl < 510) {
    g = 1.0; b = -(wl - 510) / (510 - 490);
  } else if (wl >= 510 && wl < 580) {
    r = (wl - 510) / (580 - 510); g = 1.0;
  } else if (wl >= 580 && wl < 645) {
    r = 1.0; g = -(wl - 645) / (645 - 580);
  } else if (wl >= 645 && wl <= 780) {
    r = 1.0;
  }
  let factor = 1.0;
  if (wl >= 380 && wl < 420) factor = 0.3 + 0.7 * (wl - 380) / (420 - 380);
  else if (wl >= 700 && wl <= 780) factor = 0.3 + 0.7 * (780 - wl) / (780 - 700);

  const gamma = 0.8;
  const R = Math.round(255 * Math.pow(r * factor, gamma));
  const G = Math.round(255 * Math.pow(g * factor, gamma));
  const B = Math.round(255 * Math.pow(b * factor, gamma));
  return `rgb(${R},${G},${B})`;
}

// ---------------------------------------------------------------------
// 1. RENDER CIE 1931 CHROMATICITY DIAGRAM
// ---------------------------------------------------------------------
const CIE_LOCUS = [
  [0.1741, 0.0050], [0.1740, 0.0050], [0.1738, 0.0051], [0.1736, 0.0052], [0.1733, 0.0054],
  [0.1730, 0.0056], [0.1726, 0.0060], [0.1721, 0.0065], [0.1714, 0.0073], [0.1703, 0.0087],
  [0.1689, 0.0107], [0.1669, 0.0136], [0.1644, 0.0177], [0.1611, 0.0235], [0.1566, 0.0315],
  [0.1510, 0.0422], [0.1440, 0.0564], [0.1355, 0.0750], [0.1241, 0.0998], [0.1096, 0.1333],
  [0.0913, 0.1775], [0.0687, 0.2372], [0.0454, 0.3160], [0.0235, 0.4127], [0.0082, 0.5384],
  [0.0039, 0.6548], [0.0139, 0.7502], [0.0389, 0.8120], [0.0743, 0.8338], [0.1142, 0.8262],
  [0.1547, 0.8059], [0.1929, 0.7816], [0.2296, 0.7543], [0.2658, 0.7243], [0.3016, 0.6923],
  [0.3373, 0.6589], [0.3731, 0.6245], [0.4087, 0.5896], [0.4441, 0.5547], [0.4788, 0.5202],
  [0.5125, 0.4866], [0.5448, 0.4544], [0.5752, 0.4242], [0.6029, 0.3965], [0.6270, 0.3725],
  [0.6482, 0.3514], [0.6658, 0.3340], [0.6801, 0.3197], [0.6915, 0.3083], [0.7006, 0.2993],
  [0.7079, 0.2920], [0.7140, 0.2859], [0.7190, 0.2809], [0.7230, 0.2770], [0.7347, 0.2653]
];

function drawCie1931(canvasId, points = []) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const dpr = window.devicePixelRatio || 1;
  const width = canvas.parentElement.clientWidth || 340;
  const height = 320;

  canvas.width = width * dpr;
  canvas.height = height * dpr;
  canvas.style.width = width + 'px';
  canvas.style.height = height + 'px';
  ctx.scale(dpr, dpr);

  const padL = 36, padR = 20, padT = 20, padB = 30;
  const plotW = width - padL - padR;
  const plotH = height - padT - padB;

  const mapX = x => padL + (x / 0.8) * plotW;
  const mapY = y => padT + (1.0 - y / 0.9) * plotH;

  // Background
  ctx.fillStyle = '#0F172A';
  ctx.fillRect(0, 0, width, height);

  // Grid lines
  ctx.strokeStyle = 'rgba(255, 255, 255, 0.06)';
  ctx.lineWidth = 1;
  ctx.font = '10px JetBrains Mono';
  ctx.fillStyle = '#64748B';

  for (let x = 0; x <= 0.8; x += 0.1) {
    const px = mapX(x);
    ctx.beginPath();
    ctx.moveTo(px, padT);
    ctx.lineTo(px, padT + plotH);
    ctx.stroke();
    ctx.fillText(x.toFixed(1), px - 8, padT + plotH + 16);
  }

  for (let y = 0; y <= 0.9; y += 0.1) {
    const py = mapY(y);
    ctx.beginPath();
    ctx.moveTo(padL, py);
    ctx.lineTo(padL + plotW, py);
    ctx.stroke();
    ctx.fillText(y.toFixed(1), padL - 26, py + 3);
  }

  // Draw Spectral Locus Tongue
  ctx.beginPath();
  CIE_LOCUS.forEach((pt, i) => {
    const px = mapX(pt[0]);
    const py = mapY(pt[1]);
    if (i === 0) ctx.moveTo(px, py);
    else ctx.lineTo(px, py);
  });
  ctx.closePath();

  // Subtle interior gradient
  const grad = ctx.createLinearGradient(mapX(0.1), mapY(0.1), mapX(0.6), mapY(0.7));
  grad.addColorStop(0, 'rgba(59, 130, 246, 0.12)');
  grad.addColorStop(0.5, 'rgba(16, 185, 129, 0.12)');
  grad.addColorStop(1, 'rgba(245, 158, 11, 0.12)');
  ctx.fillStyle = grad;
  ctx.fill();

  ctx.strokeStyle = '#38BDF8';
  ctx.lineWidth = 2;
  ctx.stroke();

  // Purple line (connect ends of locus)
  ctx.beginPath();
  ctx.moveTo(mapX(CIE_LOCUS[0][0]), mapY(CIE_LOCUS[0][1]));
  ctx.lineTo(mapX(CIE_LOCUS[CIE_LOCUS.length - 1][0]), mapY(CIE_LOCUS[CIE_LOCUS.length - 1][1]));
  ctx.strokeStyle = 'rgba(236, 72, 153, 0.8)';
  ctx.stroke();

  // D65 Standard Illuminant Reference Point (x=0.3127, y=0.3290)
  const d65x = mapX(0.3127);
  const d65y = mapY(0.3290);
  ctx.fillStyle = '#FFFFFF';
  ctx.beginPath();
  ctx.arc(d65x, d65y, 3, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = '#94A3B8';
  ctx.fillText('D65', d65x + 6, d65y - 4);

  // Plot Sample Points
  points.forEach((pt, idx) => {
    if (!pt.x || !pt.y) return;
    const px = mapX(pt.x);
    const py = mapY(pt.y);

    // Glowing halo
    ctx.beginPath();
    ctx.arc(px, py, 9, 0, Math.PI * 2);
    ctx.fillStyle = pt.hex ? `${pt.hex}55` : 'rgba(59, 130, 246, 0.35)';
    ctx.fill();

    // Solid core
    ctx.beginPath();
    ctx.arc(px, py, 5, 0, Math.PI * 2);
    ctx.fillStyle = pt.hex || '#38BDF8';
    ctx.fill();
    ctx.lineWidth = 2;
    ctx.strokeStyle = '#FFFFFF';
    ctx.stroke();

    if (pt.label) {
      ctx.fillStyle = '#F8FAFC';
      ctx.font = 'bold 11px Outfit';
      ctx.fillText(pt.label, px + 8, py - 4);
    }
  });
}

// ---------------------------------------------------------------------
// 2. RENDER 8-BAND SPECTRAL REFLECTANCE GRAPH
// ---------------------------------------------------------------------
const BANDS_DEF = [
  { nm: 415, label: 'F1 415nm', color: '#7E00DB' },
  { nm: 445, label: 'F2 445nm', color: '#1900D5' },
  { nm: 480, label: 'F3 480nm', color: '#0075FF' },
  { nm: 515, label: 'F4 515nm', color: '#00E4D0' },
  { nm: 555, label: 'F5 555nm', color: '#42FF00' },
  { nm: 590, label: 'F6 590nm', color: '#FFDC00' },
  { nm: 630, label: 'F7 630nm', color: '#FF6D00' },
  { nm: 680, label: 'F8 680nm', color: '#E80000' }
];

function drawSpectrumChart(canvasId, values = []) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  const dpr = window.devicePixelRatio || 1;
  const width = canvas.parentElement.clientWidth || 340;
  const height = 320;

  canvas.width = width * dpr;
  canvas.height = height * dpr;
  canvas.style.width = width + 'px';
  canvas.style.height = height + 'px';
  ctx.scale(dpr, dpr);

  const padL = 44, padR = 16, padT = 24, padB = 40;
  const plotW = width - padL - padR;
  const plotH = height - padT - padB;

  ctx.fillStyle = '#0F172A';
  ctx.fillRect(0, 0, width, height);

  const maxVal = Math.max(10, ...values) * 1.15;

  // Grid
  ctx.strokeStyle = 'rgba(255, 255, 255, 0.06)';
  ctx.fillStyle = '#64748B';
  ctx.font = '10px JetBrains Mono';

  for (let s = 0; s <= 4; s++) {
    const yVal = (maxVal / 4) * s;
    const py = padT + plotH - (s / 4) * plotH;
    ctx.beginPath();
    ctx.moveTo(padL, py);
    ctx.lineTo(padL + plotW, py);
    ctx.stroke();
    ctx.fillText(Math.round(yVal), 8, py + 3);
  }

  // Draw Bars & Spectral Spline
  const barW = Math.max(14, (plotW / 8) - 10);
  const pts = [];

  values.forEach((val, i) => {
    const cx = padL + (i + 0.5) * (plotW / 8);
    const bh = (val / maxVal) * plotH;
    const by = padT + plotH - bh;

    pts.push({ x: cx, y: by, color: BANDS_DEF[i].color });

    // Bar
    const bGrad = ctx.createLinearGradient(0, by, 0, padT + plotH);
    bGrad.addColorStop(0, BANDS_DEF[i].color);
    bGrad.addColorStop(1, `${BANDS_DEF[i].color}33`);
    ctx.fillStyle = bGrad;
    ctx.fillRect(cx - barW / 2, by, barW, bh);

    // Border
    ctx.strokeStyle = BANDS_DEF[i].color;
    ctx.lineWidth = 1.5;
    ctx.strokeRect(cx - barW / 2, by, barW, bh);

    // X Axis Labels
    ctx.fillStyle = '#94A3B8';
    ctx.font = '10px Outfit';
    ctx.fillText(BANDS_DEF[i].nm, cx - 10, padT + plotH + 16);
    ctx.fillStyle = '#64748B';
    ctx.fillText(`F${i+1}`, cx - 6, padT + plotH + 28);
  });

  // Connecting Spline
  if (pts.length > 1) {
    ctx.beginPath();
    ctx.moveTo(pts[0].x, pts[0].y);
    for (let i = 0; i < pts.length - 1; i++) {
      const xc = (pts[i].x + pts[i + 1].x) / 2;
      const yc = (pts[i].y + pts[i + 1].y) / 2;
      ctx.quadraticCurveTo(pts[i].x, pts[i].y, xc, yc);
    }
    ctx.lineTo(pts[pts.length - 1].x, pts[pts.length - 1].y);
    ctx.strokeStyle = '#FFFFFF';
    ctx.lineWidth = 2.5;
    ctx.shadowColor = 'rgba(255,255,255,0.4)';
    ctx.shadowBlur = 8;
    ctx.stroke();
    ctx.shadowBlur = 0;
  }
}

// ---------------------------------------------------------------------
// 3. AUTO-REFRESH LIVE POLLING VIA API
// ---------------------------------------------------------------------
let lastKnownId = 0;
let autoRefreshEnabled = true;

function initLivePolling() {
  setInterval(async () => {
    if (!autoRefreshEnabled) return;
    try {
      const res = await fetch('api.php?action=latest');
      if (!res.ok) return;
      const json = await res.json();
      if (!json || json.status !== 'success') return;

      // Update Device telemetry widget
      if (json.device) {
        updateDevicePill(json.device);
      }

      // Check if new measurement was saved
      if (json.latest_measurement && lastKnownId > 0 && json.latest_measurement.id > lastKnownId) {
        lastKnownId = json.latest_measurement.id;
        showToast(`Data baru diterima: ${json.latest_measurement.sample_name || json.latest_measurement.hex}`, json.latest_measurement.hex);
        // Refresh page or update DOM seamlessly
        setTimeout(() => { window.location.reload(); }, 1200);
      } else if (json.latest_measurement) {
        lastKnownId = json.latest_measurement.id;
      }
    } catch (e) {
      console.warn('Polling error:', e);
    }
  }, 3500);
}

function updateDevicePill(dev) {
  const dot = document.getElementById('device-status-dot');
  const txt = document.getElementById('device-status-text');
  const bat = document.getElementById('device-bat-text');
  const chg = document.getElementById('device-chg-icon');

  if (dot && txt) {
    let isOnline = false;
    if (dev.is_online !== undefined) {
      isOnline = Boolean(dev.is_online);
    } else if (dev.last_seen_epoch) {
      isOnline = (Math.abs(Date.now() / 1000 - dev.last_seen_epoch) < 120);
    } else if (dev.last_seen) {
      const parsedTime = new Date(dev.last_seen.replace(' ', 'T')).getTime();
      isOnline = !isNaN(parsedTime) && (Math.abs(Date.now() - parsedTime) < 120000);
    }

    dot.className = isOnline ? 'status-dot' : 'status-dot offline';
    txt.textContent = isOnline ? 'ESP32 Online' : 'ESP32 Standby';
  }

  if (bat && dev.bat_percent !== undefined) {
    const v = parseFloat(dev.bat_voltage) || 0;
    bat.textContent = `${dev.bat_percent}% (${v.toFixed(2)}V)`;
  }
  if (chg) {
    chg.style.display = (dev.charging && dev.charging != '0') ? 'inline-block' : 'none';
  }
}

function showToast(msg, color = '#3B82F6') {
  let box = document.getElementById('app-toast-box');
  if (!box) {
    box = document.createElement('div');
    box.id = 'app-toast-box';
    box.style.position = 'fixed';
    box.style.bottom = '24px';
    box.style.right = '24px';
    box.style.zIndex = '9999';
    document.body.appendChild(box);
  }

  const toast = document.createElement('div');
  toast.style.background = '#1E293B';
  toast.style.color = '#FFFFFF';
  toast.style.padding = '12px 20px';
  toast.style.borderRadius = '12px';
  toast.style.borderLeft = `5px solid ${color}`;
  toast.style.boxShadow = '0 10px 25px rgba(0,0,0,0.5)';
  toast.style.marginBottom = '10px';
  toast.style.fontWeight = '500';
  toast.style.fontSize = '0.9rem';
  toast.style.display = 'flex';
  toast.style.alignItems = 'center';
  toast.style.gap = '10px';
  toast.innerHTML = `<span style="width:14px;height:14px;border-radius:4px;background:${color};display:inline-block;"></span> ${msg}`;

  box.appendChild(toast);
  setTimeout(() => { toast.remove(); }, 3500);
}

document.addEventListener('DOMContentLoaded', () => {
  initLivePolling();
});

# Colorimetric Analyzer

Alat analisis warna spektral mandiri (*portable spectrophotometer / colorimeter*) berbasis **ESP32-S2 Mini**, sensor spektral 8-kanal **GY-AS7341**, dan layar sentuh **TFT 3.2" ILI9341** (Touch controller XPT2046).

---

## Fitur Utama

- **Analisis Spektral Multikanal**: Mengukur reflektansi 8 pita spektral cahaya tampak (F1–F8: 415nm hingga 680nm), serta kanal Clear dan NIR.
- **Kalkulasi Warna Standar**:
  - CIE 1931 xy chromaticity coordinates
  - sRGB & representasi Kode HEX
  - Ruang warna CIELAB ($L^*a^*b^*$)
  - Perhitungan perbedaan warna ($\Delta E$) terhadap referensi
- **Kalibrasi 2-Titik**:
  - Kalibrasi *Dark* (tanpa cahaya / sensor ditutup)
  - Kalibrasi *White* (referensi reflektansi putih standar)
- **Antarmuka Grafis Sentuh**:
  - Tampilan GUI responsif di layar TFT 3.2" (resolusi 320x240)
  - Kalibrasi touch 5-titik tersimpan di Preferences/NVS
  - Keyboard virtual untuk konfigurasi WiFi
- **WiFi & OTA Update (Over-The-Air)**:
  - Pemindaian WiFi langsung dari layar alat
  - Cek dan instal pembaruan firmware otomatis dari **GitHub Releases**

---

## Wiring & Skema Pin

### 1. TFT 3.2" ILI9341 & XPT2046 (SPI)
> **Catatan:** Jumper di balik modul TFT: `T_CLK` ke `SCK`, `T_DIN` ke `SDI`, `T_DO` ke `SDO`.

| Pin TFT / Touch | Pin ESP32-S2 Mini | Keterangan |
| :--- | :--- | :--- |
| **VCC** | 3V3 | Catu daya 3.3V |
| **GND** | GND | Ground |
| **CS** | GPIO 12 | TFT Chip Select |
| **RESET** | GPIO 8 | TFT Reset |
| **DC** | GPIO 9 | TFT Data/Command |
| **SDI (MOSI)** | GPIO 11 | SPI MOSI |
| **SCK (CLK)** | GPIO 7 | SPI Clock |
| **SDO (MISO)** | GPIO 13 | SPI MISO |
| **T_CS** | GPIO 14 | Touch Chip Select |
| **LED** | 3V3 / GPIO PIN_BL | Backlight (selalu 3.3V atau PWM) |

### 2. Sensor GY-AS7341 (I2C)
| Pin AS7341 | Pin ESP32-S2 Mini |
| :--- | :--- |
| **VIN** | 3V3 |
| **GND** | GND |
| **SDA** | GPIO 33 |
| **SCL** | GPIO 35 |

### 3. Baterai 18650 & Modul Cas TP4056
| Fungsi | Rangkaian Pembagi Tegangan (*Voltage Divider*) | Pin ESP32-S2 Mini |
| :--- | :--- | :--- |
| **Sensor Tegangan Baterai** | Baterai (+) $\rightarrow$ `[1kΩ]` $\rightarrow$ **IO 1** $\rightarrow$ `[1kΩ]` $\rightarrow$ GND | **GPIO 1** (ADC1) |
| **Deteksi Charger USB 5V** | USB Input TP4056 (+) $\rightarrow$ `[5kΩ]` $\rightarrow$ **IO 2** $\rightarrow$ `[1kΩ]` (atau `[5kΩ]`) $\rightarrow$ GND | **GPIO 2** (ADC1) |
| **Ground Bersama** | Baterai (-), TP4056 GND & ESP32 GND terhubung bersama | **GND** |

---

## Konfigurasi Arduino IDE

1. **Board**: `LOLIN S2 Mini` (dari platform `esp32` by Espressif)
2. **Pengaturan Tools**:
   - **USB CDC On Boot**: `Enabled`
   - **Partition Scheme**: `Default 4MB with spiffs (1.2MB APP/1.5MB SPIFFS)` *(Wajib memiliki skema partisi OTA)*
3. **Library yang Dibutuhkan**:
   - `Adafruit GFX Library`
   - `Adafruit ILI9341`
   - `XPT2046_Touchscreen` (oleh Paul Stoffregen)
   - `Adafruit AS7341` (+ `Adafruit BusIO`)

---

## Sistem Pembaruan Firmware (OTA)

Alat ini dilengkapi fitur pembaruan firmware over-the-air langsung dari GitHub Releases pada repository ini:
`https://github.com/Shawir1312/colorimetric-analyzer`

### Cara Membuat Rilis Pembaruan Baru:
1. Naikkan versi firmware di dalam kode (misalnya `#define FW_VERSION "v1.1.1"`).
2. Lakukan kompilasi / ekspor binary sketch:
   - File binary hasil build di-rename menjadi **`firmware.bin`**.
   - Buat file teks **`version.txt`** yang berisi satu baris versi baru (contoh: `v1.1.1`).
3. Buat rilis baru di GitHub:
   - Masuk ke tab **Releases** > **Draft a new release**.
   - Berikan tag versi (misal: `v1.1.1`).
   - Unggah kedua aset: `version.txt` dan `firmware.bin`.
   - Klik **Publish release**.
4. Pada alat Colorimetric Analyzer:
   - Hubungkan ke WiFi via menu **Pengaturan > WiFi**.
   - Buka menu **Pengaturan > Update Firmware**.
   - Tekan tombol **Cek Update**. Alat akan mendeteksi versi baru dan mengaktifkan tombol **Pasang Update**.
   - Tekan **Pasang Update** untuk mengunduh dan memasang firmware secara otomatis.

---

## 🌐 Aplikasi Web & Database Kolorimeter (Full PHP)

Folder `web/` berisi sistem dashboard web modern berbasis PHP murni untuk menyimpan, menganalisis, dan membandingkan hasil pengukuran warna spektrofotometri dari mikrokontroler ESP32-S2 secara nirkabel (WiFi).

### Fitur Web:
- **📊 Dashboard Utama (`index.php`)**: Live monitoring status baterai, status charging (⚡), sinyal WiFi, kurva spektral 8-kanal (415nm - 680nm), dan diagram kromatisitas CIE 1931 xy.
- **🔍 Cek Detail Pengukuran (`detail.php`)**: Dual contrast preview (gelap & terang), konversi ruang warna sRGB, CIE xyY, CIE $L^*a^*b^*$, HSL, grafik batang 8 kanal spektral + NIR + Clear, parameter akuisisi sensor (LED mA, gain, averaging samples), serta catatan sampel yang dapat diedit.
- **⚖️ Komparasi Warna & Analisis $\Delta E$ (`compare.php`)**: Komparasi 2 sampel berdampingan, perhitungan toleransi industri $\Delta E^*_{ab}$, serta kurva absorpsi spektrum komparatif.
- **📡 Telemetri Alat & API (`device.php`)**: Status koneksi ESP32, live polling, dan simulasi pengujian API satu-klik.
- **⚙️ Installer Server 1-Klik (`install.php`)**:
  - Pemeriksaan otomatis kesiapan server (PHP $\ge 7.4$, PDO, izin tulis folder).
  - Pilihan database **SQLite** (Zero-Config 1-Klik Selesai) atau **MySQL** (cPanel / Dedicated).
  - Pengaturan zona waktu (WIT UTC+9, WITA UTC+8, WIB UTC+7) dan API Key.
  - Proteksi pengunci instalasi (`installed.lock`).

### Menjalankan Server Lokal:
```bash
cd web
php -S 0.0.0.0:8080
```
Buka di browser: `http://localhost:8080/install.php` atau `http://localhost:8080/`

### Integrasi Pengiriman Otomatis ESP32:
Setiap kali tombol **Save** ditekan pada layar TFT alat saat terhubung ke WiFi, alat akan mengirimkan data pengukuran lengkap format JSON via HTTP POST ke endpoint:
```
POST http://<ip-server>:8080/api.php
Header: X-API-KEY: colan_secret_2026
```


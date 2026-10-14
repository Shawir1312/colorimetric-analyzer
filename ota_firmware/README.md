# Firmware OTA & Pengujian Update

Folder ini berisi binary firmware hasil kompilasi untuk ESP32-S2 Mini (LOLIN S2 Mini).

## Struktur File:

```
ota_firmware/
├── v1.1.0/
│   ├── firmware.bin                     # Binary v1.1.0 untuk update OTA
│   ├── colorimetric_analyzer1.ino.bin   # Binary sketch
│   ├── colorimetric_analyzer1.ino.merged.bin # Full flash image (termasuk bootloader & partisi)
│   └── version.txt                      # Berisi: v1.1.0
└── test_update_v1.1.1/
    ├── firmware.bin                     # Binary v1.1.1 untuk diuji coba OTA
    ├── colorimetric_analyzer1.ino.bin   # Binary sketch v1.1.1
    ├── colorimetric_analyzer1.ino.merged.bin # Full flash image v1.1.1
    └── version.txt                      # Berisi: v1.1.1
```

---

## Langkah Pengujian Update OTA

### 1. Pastikan Alat Menjalankan Firmware v1.1.0
- Jika alat belum diflash atau masih versi awal, flash sketch dari Arduino IDE atau flash file `v1.1.0/colorimetric_analyzer1.ino.merged.bin`.
- Di layar alat, buka menu **Pengaturan > Update Firmware**.
- Terlihat **Versi terpasang: v1.1.0**.

### 2. Buat Rilis di GitHub
1. Buka repository Anda: https://github.com/Shawir1312/colorimetric-analyzer
2. Klik tab **Releases** di sisi kanan, lalu klik **Create a new release** (atau **Draft a new release**).
3. Isi:
   - **Choose a tag**: ketik `v1.1.1` lalu klik *Create new tag*.
   - **Release title**: `Colorimetric Analyzer v1.1.1 (Test Update)`
   - **Description**: Catatan perubahan / rilis uji coba update.
4. Pada bagian **Attach binaries by dropping them here or selecting them**, unggah **DUA FILE** berikut dari folder `ota_firmware/test_update_v1.1.1/`:
   - `version.txt`
   - `firmware.bin`
5. Klik **Publish release**.

> **Penting**: Pastikan nama kedua file di Release persis `version.txt` dan `firmware.bin`.

### 3. Jalankan Update dari Alat
1. Pastikan ESP32 sudah terhubung ke WiFi:
   - Masuk ke **Pengaturan > WiFi**.
   - Pilih SSID, masukkan password via keyboard layar.
2. Masuk ke **Pengaturan > Update Firmware**.
3. Tekan tombol **Cek Update**:
   - ESP32 akan mengunduh `https://github.com/Shawir1312/colorimetric-analyzer/releases/latest/download/version.txt`.
   - Muncul status berwarna hijau: **"Tersedia versi v1.1.1"**.
   - Tombol **Pasang Update** akan berubah menjadi hijau dan aktif.
4. Tekan tombol **Pasang Update**:
   - ESP32 akan mengunduh `firmware.bin` dengan indikator persentase (progress bar).
   - Setelah 100%, ESP32 akan reboot otomatis.
5. Setelah reboot:
   - Cek menu **Tentang** atau **Update Firmware**.
   - **Versi terpasang** kini berubah menjadi **v1.1.1**!

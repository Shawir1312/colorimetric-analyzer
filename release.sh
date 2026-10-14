#!/usr/bin/env bash
set -e

# ==============================================================
# Skrip Otomatis Rilis Firmware & OTA GitHub
# Colorimetric Analyzer (ESP32-S2 Mini)
# ==============================================================

REPO="Shawir1312/colorimetric-analyzer"
SKETCH="colorimetric_analyzer1.ino"
ARDUINO_CLI="/Applications/Arduino IDE.app/Contents/Resources/app/lib/backend/resources/arduino-cli"
BOARD_FQBN="esp32:esp32:lolin_s2_mini:CDCOnBoot=default,PartitionScheme=default"

# 1. Cek argumen versi
NEW_VERSION="$1"
NOTES="${2:-Pembaruan firmware OTA}"

if [ -z "$NEW_VERSION" ]; then
  echo "❌ Penggunaan: ./release.sh <versi_baru> [catatan_rilis]"
  echo "   Contoh: ./release.sh v1.1.2 \"Perbaikan bug dan penyesuaian sensor\""
  exit 1
fi

# Pastikan format diawali 'v' jika belum
if [[ ! "$NEW_VERSION" =~ ^v ]]; then
  NEW_VERSION="v$NEW_VERSION"
fi

echo "=========================================================="
echo "🚀 Memulai Rilis Firmware: $NEW_VERSION"
echo "   Catatan: $NOTES"
echo "=========================================================="

# 2. Cek Arduino CLI
if [ ! -f "$ARDUINO_CLI" ]; then
  echo "❌ Arduino CLI tidak ditemukan di: $ARDUINO_CLI"
  exit 1
fi

# 3. Update FW_VERSION di dalam sketch
echo "📝 Memperbarui FW_VERSION di $SKETCH menjadi $NEW_VERSION..."
sed -i '' "s/#define FW_VERSION \".*\"/#define FW_VERSION \"$NEW_VERSION\"/" "$SKETCH"

# 4. Buat folder build sementara
BUILD_DIR="./build_release"
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

echo "⚙️  Mengompilasi firmware dengan Arduino CLI..."
"$ARDUINO_CLI" compile -b "$BOARD_FQBN" --output-dir "$BUILD_DIR" .

# Siapkan firmware.bin dan version.txt
cp "$BUILD_DIR/$SKETCH.bin" "$BUILD_DIR/firmware.bin"
printf "%s" "$NEW_VERSION" > "$BUILD_DIR/version.txt"

echo "✅ Kompilasi sukses! Ukuran binary: $(ls -lh "$BUILD_DIR/firmware.bin" | awk '{print $5}')"

# 5. Git Commit & Push
echo "📦 Menyimpan perubahan ke Git..."
git add "$SKETCH"
# Jika ada file lain yang diubah, ikut sertakan
git add -u
git commit -m "Release $NEW_VERSION: $NOTES" || echo "Tidak ada perubahan teks kode untuk di-commit"
echo "⬆️  Push ke GitHub (branch main)..."
git push origin main

# 6. Buat Release di GitHub via API
echo "🌐 Menghubungkan ke GitHub API..."
TOKEN=$(printf "protocol=https\nhost=github.com\n" | git credential fill 2>/dev/null | grep "^password=" | cut -d= -f2)

if [ -z "$TOKEN" ]; then
  echo "⚠️  Token GitHub tidak ditemukan di osxkeychain."
  echo "   Silakan buat rilis manual di GitHub dan unggah:"
  echo "   - $BUILD_DIR/firmware.bin"
  echo "   - $BUILD_DIR/version.txt"
  exit 0
fi

echo "🏷️  Membuat Release $NEW_VERSION di GitHub..."
RELEASE_JSON=$(curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/vnd.github+json" \
  https://api.github.com/repos/$REPO/releases \
  -d "{\"tag_name\":\"$NEW_VERSION\",\"target_commitish\":\"main\",\"name\":\"Colorimetric Analyzer $NEW_VERSION\",\"body\":\"$NOTES\",\"draft\":false,\"prerelease\":false}")

RELEASE_ID=$(echo "$RELEASE_JSON" | grep -m1 '"id":' | tr -dc '0-9')

if [ -z "$RELEASE_ID" ]; then
  echo "❌ Gagal membuat release di GitHub. Respon:"
  echo "$RELEASE_JSON"
  exit 1
fi

echo "📤 Mengunggah version.txt ke Release..."
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: text/plain" \
  --data-binary @"$BUILD_DIR/version.txt" \
  "https://uploads.github.com/repos/$REPO/releases/$RELEASE_ID/assets?name=version.txt" > /dev/null

echo "📤 Mengunggah firmware.bin ke Release..."
curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @"$BUILD_DIR/firmware.bin" \
  "https://uploads.github.com/repos/$REPO/releases/$RELEASE_ID/assets?name=firmware.bin" > /dev/null

# Bersihkan build sementara
rm -rf "$BUILD_DIR"

echo ""
echo "=========================================================="
echo "🎉 RILIS BERHASIL DIPUBLIKASIKAN!"
echo "   Versi: $NEW_VERSION"
echo "   URL: https://github.com/$REPO/releases/tag/$NEW_VERSION"
echo "=========================================================="
echo "Sekarang Anda dapat membuka menu 'Pengaturan > Update Firmware' di alat dan menekan 'Cek Update'!"

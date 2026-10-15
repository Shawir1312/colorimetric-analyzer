/*
  COLORIMETRIC ANALYZER  v1.1.0
  ESP32-S2 Mini + TFT 3.2" ILI9341 (touch XPT2046) + sensor GY-AS7341

  Library (Library Manager):
    1. Adafruit GFX Library
    2. Adafruit ILI9341
    3. XPT2046_Touchscreen (Paul Stoffregen)
    4. Adafruit AS7341  (+ Adafruit BusIO jika diminta)

  Board  : "LOLIN S2 Mini"
  Setting: USB CDC On Boot = Enabled

  Wiring TFT (jumper di modul: T_CLK-SCK, T_DIN-SDI, T_DO-SDO):
    VCC->3V3  GND->GND  CS->GPIO12  RESET->GPIO8  DC->GPIO9
    SDI->GPIO11  SCK->GPIO7  SDO->GPIO13  T_CS->GPIO14
    LED -> 3V3 (backlight selalu menyala)
           atau ke GPIO PIN_BL di bawah kalau ingin kecerahan bisa diatur
  Wiring AS7341:  VIN->3V3  GND->GND  SDA->GPIO33  SCL->GPIO35
  Wiring Baterai & Cas (TP4056 + 18650):
    - Tegangan Baterai 18650 : Bat(+) -> [1k ohm] -> GPIO1 -> [1k ohm] -> GND
    - Deteksi Cas USB (TP4056): USB(+) -> [5k ohm] -> GPIO2 -> [1k ohm] -> GND
    - GND TP4056 & Baterai   : terhubung ke GND ESP32

  Alur pemakaian:
    1. Pertama kali: kalibrasi touch (5 titik).
    2. Home > Kalibrasi Sensor: Dark (sensor ditutup) lalu White (referensi putih).
    3. Home > Analisis Warna > Mulai Analisis.
    4. Hasil: warna, CIE xy, RGB, HEX, Lab, dE terhadap referensi.
    5. Simpan ke riwayat / jadikan referensi untuk perbandingan (Color Match).

  Cara hitung warna (perkiraan):
    - Reflektansi tiap pita F1-F8 = (sampel - dark) / (white - dark).
    - XYZ dihitung dari 8 pita dengan fungsi CIE 1931 2 derajat dan iluminan D65,
      dinormalisasi supaya reflektansi 100% = putih D65.
    - Hanya 8 pita spektral, jadi akurasinya perkiraan, bukan setara spektrofotometer.

  Kalibrasi ulang touch: Home > Kalibrasi Touch, atau tahan BOOT (GPIO0) saat RESET.

  WiFi & OTA (v1.1.0):
    - Home > Pengaturan > WiFi: pindai jaringan, ketuk nama, ketik password lewat keyboard layar.
    - Pengaturan > Update Firmware: cek versi dan unduh firmware dari GitHub Releases.
    - OTA_BASE_URL: https://github.com/Shawir1312/colorimetric-analyzer/releases/latest/download/
    - Setiap rilis harus punya dua file:
        version.txt  (isi satu baris, mis. v1.1.1)  dan  firmware.bin
    - Tools > Partition Scheme harus punya OTA (mis. "Default 4MB with spiffs"
      atau "Minimal SPIFFS (1.9MB APP with OTA)").

  CATATAN v1.0.1: semua tipe (struct/enum/typedef) sengaja diletakkan SEBELUM fungsi
  pertama, supaya prototipe otomatis Arduino IDE tidak muncul sebelum tipenya dikenal.
*/

#include <SPI.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_ILI9341.h>
#include <XPT2046_Touchscreen.h>
#include <Adafruit_AS7341.h>
#include <Preferences.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Update.h>
#include <Fonts/FreeSans9pt7b.h>
#include <Fonts/FreeSansBold9pt7b.h>
#include <Fonts/FreeSansBold12pt7b.h>
#include <time.h>
#include <sys/time.h>
#include <math.h>

#define FW_VERSION "v1.1.2"

// ======================= OTA (GitHub Releases) =======================
// Repo publik GitHub untuk OTA update
#define OTA_BASE_URL "https://github.com/Shawir1312/colorimetric-analyzer/releases/latest/download/"
#define OTA_VER_FILE "version.txt"
#define OTA_BIN_FILE "firmware.bin"

// ======================= PIN =======================
#define PIN_SCK    7
#define PIN_MISO   13
#define PIN_MOSI   11
#define TFT_CS     12
#define TFT_DC     9
#define TFT_RST    8
#define TOUCH_CS   14
#define PIN_BOOT   0
#define AS_SDA     33
#define AS_SCL     35
#define PIN_BL     10      // Pin PWM kecerahan layar LED TFT (GPIO 10)
#define PIN_BAT_ADC 1      // Pembaca tegangan baterai 18650 (pembagi tegangan 1K + 1K)
#define PIN_CHG_DET 2      // Deteksi charger USB 5V TP4056 (pembagi tegangan 5K + 1K / 5K + 5K)
#define BAT_DIVIDER_RATIO 2.0f // Faktor pembagi tegangan 1k + 1k (Vbat = Vpin * 2.0)

// ======================= TIPE DATA (HARUS DI ATAS FUNGSI PERTAMA) =======================
struct Rect { int16_t x, y, w, h; };

enum Screen {
  S_BOOT, S_HOME, S_SAMPLE, S_PROCESS, S_RESULT, S_DETAIL,
  S_HISTORY, S_CALSENS, S_SETTINGS, S_TIME, S_ABOUT, S_CONFIRM,
  S_WIFI, S_KEYB, S_OTA, S_BATCAL
};

struct BatConfig {
  float vFull;    // Tegangan baterai penuh (100%), default 4.00V sesuai baterai user
  float vEmpty;   // Tegangan baterai kosong (0%), default 3.30V
  float calRatio; // Faktor pengali pembagi tegangan ADC (default 2.00)
};

struct Spectrum { float f[8]; float clr; float nir; };

struct TouchCal {
  float aL, aR, bT, bB;
  uint8_t swapXY;
  uint8_t valid;
};

struct SensorCal {
  float darkF[8];
  float darkClr, darkNir;
  float whiteNet[8];
  float darkMean, whiteMean, noise;
  uint8_t hasDark, hasWhite;
  uint8_t ledIdx, gainIdx;
  uint8_t warn;            // 0 = baik, 1 = jenuh, 2 = lemah
};

struct Settings {
  uint8_t ledIdx, gainIdx, nIdx, blIdx;
};

struct RefColor {
  float L, a, b;
  uint8_t r, g, bl;
  uint8_t valid;
};

struct Result {
  float R[8];
  bool relative;
  uint32_t ts;
  float X, Y, Z, cx, cy, L, a, b;
  float dE;
  bool hasDE;
  uint8_t rr, gg, bb;
  float hue, sat, val;
  float clr, nir;
  bool saved;
};

struct HistEntry {
  uint32_t ts;
  uint8_t relative;
  uint8_t pad[3];
  float R[8];
};

typedef void (*ProcCb)(float frac, const Spectrum* s, int done, int total);

// ======================= OBJEK =======================
Adafruit_ILI9341 tft(&SPI, TFT_DC, TFT_CS, TFT_RST);
XPT2046_Touchscreen ts(TOUCH_CS);
Adafruit_AS7341 as7341;
Preferences prefs;

const int SCREEN_W = 320;
const int SCREEN_H = 240;

// ======================= TEMA =======================
#define RGB565(r, g, b) ((((r) & 0xF8) << 8) | (((g) & 0xFC) << 3) | ((b) >> 3))
const uint16_t COL_BG     = RGB565(5, 12, 32);
const uint16_t COL_CARD   = RGB565(13, 27, 60);
const uint16_t COL_CARD2  = RGB565(24, 44, 88);
const uint16_t COL_LINE   = RGB565(38, 60, 110);
const uint16_t COL_TXT    = 0xFFFF;
const uint16_t COL_MUTED  = RGB565(150, 168, 200);
const uint16_t COL_BLUE   = RGB565(22, 128, 255);
const uint16_t COL_GREEN  = RGB565(34, 200, 110);
const uint16_t COL_PURPLE = RGB565(124, 72, 228);
const uint16_t COL_RED    = RGB565(240, 72, 72);
const uint16_t COL_ORANGE = RGB565(255, 168, 40);
const uint16_t COL_YELLOW = RGB565(255, 208, 50);

const GFXfont* const F9   = &FreeSans9pt7b;
const GFXfont* const F9B  = &FreeSansBold9pt7b;
const GFXfont* const F12B = &FreeSansBold12pt7b;

// ======================= PILIHAN PENGATURAN =======================
const uint16_t LED_OPTS[6] = { 4, 8, 12, 20, 30, 50 };
const as7341_gain_t GAIN_OPTS[5] = { AS7341_GAIN_16X, AS7341_GAIN_32X, AS7341_GAIN_64X,
                                     AS7341_GAIN_128X, AS7341_GAIN_256X };
const char* const GAIN_TXT[5] = { "16X", "32X", "64X", "128X", "256X" };
const uint8_t N_OPTS[4] = { 1, 3, 5, 10 };
const uint8_t BL_OPTS[4] = { 25, 50, 75, 100 };

// ======================= DATA GLOBAL =======================
Screen scr = S_BOOT;
bool sensorOk = false;

TouchCal tcal = {0, 0, 0, 0, 0, 0};
SensorCal sc, wc;
Settings cfg = { 0, 2, 2, 3 };
RefColor refc = {0, 0, 0, 0, 0, 0, 0};

Result cur;
bool hasCur = false;

const int HIST_MAX = 12;
HistEntry hist[HIST_MAX];
int histCount = 0;
int histScroll = 0;
int setScroll = 0;
int pickerType = 0;     // 0: none, 1: Arus LED, 2: Gain, 3: Sampel, 4: Kecerahan

int sampleMode = 0;     // 0 = Sample (LED), 1 = Manual (cahaya, LED mati)
int calStep = 0;        // 0 dark, 1 white, 2 selesai
int detailTab = 0;
int confirmAction = 0;  // 0 = reset pabrik, 1 = hapus riwayat
struct tm editTm;
bool lastCancelled = false;
char wifiSsid[33] = "";
char wifiPass[64] = "";
char webApiUrl[96] = "http://srv4.shawir.id:9070/api.php";
char webApiKey[32] = "color2026";

// Tabel warna (dihitung saat boot)
const float BAND_WL[8] = { 415, 445, 480, 515, 555, 590, 630, 680 };
const float DW[8]      = { 30, 32.5, 35, 37.5, 37.5, 37.5, 45, 50 };
const float CMF_X[8] = { 0.0889, 0.3423, 0.0956, 0.0363, 0.5140, 1.0263, 0.6424, 0.0468 };
const float CMF_Y[8] = { 0.0026, 0.0305, 0.1390, 0.6065, 0.9950, 0.7570, 0.2650, 0.0170 };
const float CMF_Z[8] = { 0.4265, 1.7596, 0.8130, 0.1182, 0.0063, 0.0011, 0.0000, 0.0000 };
const float ILL_D65[8] = { 92.46, 110.9, 115.92, 106.3, 102.0, 88.69, 83.29, 78.28 };
float WX[8], WY[8], WZ[8], SUMX, SUMY, SUMZ;

// Garis tepi spektrum CIE 1931
const float CIE_WL[25] = { 380, 400, 420, 440, 450, 460, 470, 480, 490, 500, 510, 520, 530,
                           540, 550, 560, 570, 580, 590, 600, 610, 620, 640, 660, 700 };
const float CIE_XY[25][2] = {
  {0.1741, 0.0050}, {0.1733, 0.0048}, {0.1714, 0.0051}, {0.1644, 0.0109}, {0.1566, 0.0177},
  {0.1440, 0.0297}, {0.1241, 0.0578}, {0.0913, 0.1327}, {0.0454, 0.2950}, {0.0082, 0.5384},
  {0.0139, 0.7502}, {0.0743, 0.8338}, {0.1547, 0.8059}, {0.2296, 0.7543}, {0.3016, 0.6923},
  {0.3731, 0.6245}, {0.4441, 0.5547}, {0.5125, 0.4866}, {0.5752, 0.4242}, {0.6270, 0.3725},
  {0.6658, 0.3340}, {0.6915, 0.3083}, {0.7190, 0.2809}, {0.7260, 0.2740}, {0.7347, 0.2653}
};

// ======================= PROTOTYPE =======================
void goScreen(Screen s);
void redrawScreen();
void toast(const char* msg, uint16_t col = 0);

// ======================= UTILITAS =======================
inline bool hit(const Rect &r, int x, int y) {
  return x >= r.x && x < r.x + r.w && y >= r.y && y < r.y + r.h;
}

// ======================= UTILITAS TEKS =======================
void txt(int x, int y, const char* s, uint16_t col, const GFXfont* f = nullptr,
         uint8_t sz = 1, int ha = 0) {
  tft.setFont(f);
  tft.setTextSize(sz);
  tft.setTextColor(col);
  int16_t bx, by;
  uint16_t bw, bh;
  tft.getTextBounds(s, 0, 0, &bx, &by, &bw, &bh);
  int cx = x;
  if (ha == 1) cx = x - bw / 2;
  else if (ha == 2) cx = x - bw;
  tft.setCursor(cx - bx, y - by);
  tft.print(s);
  tft.setFont(nullptr);
  tft.setTextSize(1);
}

void txtC(int cx, int cy, const char* s, uint16_t col, const GFXfont* f = nullptr, uint8_t sz = 1) {
  tft.setFont(f);
  tft.setTextSize(sz);
  tft.setTextColor(col);
  int16_t bx, by;
  uint16_t bw, bh;
  tft.getTextBounds(s, 0, 0, &bx, &by, &bw, &bh);
  tft.setCursor(cx - bw / 2 - bx, cy - bh / 2 - by);
  tft.print(s);
  tft.setFont(nullptr);
  tft.setTextSize(1);
}

// Teks tengah yang otomatis mengecil kalau terlalu lebar
void txtFitC(int cx, int cy, const char* s, uint16_t col, int maxW) {
  const GFXfont* fonts[2] = { F9B, F9 };
  for (int i = 0; i < 2; i++) {
    tft.setFont(fonts[i]);
    tft.setTextSize(1);
    int16_t bx, by;
    uint16_t bw, bh;
    tft.getTextBounds(s, 0, 0, &bx, &by, &bw, &bh);
    if (bw <= maxW) {
      tft.setFont(nullptr);
      txtC(cx, cy, s, col, fonts[i]);
      return;
    }
  }
  tft.setFont(nullptr);
  txtC(cx, cy, s, col, nullptr, 1);
}

int textW(const char* s, const GFXfont* f) {
  tft.setFont(f);
  tft.setTextSize(1);
  int16_t bx, by;
  uint16_t bw, bh;
  tft.getTextBounds(s, 0, 0, &bx, &by, &bw, &bh);
  tft.setFont(nullptr);
  return bw;
}

// Salin teks dan potong dari belakang sampai muat di lebar maxW
void fitStr(char* out, size_t n, const char* s, int maxW, const GFXfont* f) {
  strlcpy(out, s, n);
  while (strlen(out) > 1 && textW(out, f) > maxW) out[strlen(out) - 1] = 0;
}

void btn(const Rect &r, uint16_t col, const char* label, const GFXfont* f = F9B,
         uint16_t tcol = COL_TXT) {
  tft.fillRoundRect(r.x, r.y, r.w, r.h, 8, col);
  txtC(r.x + r.w / 2, r.y + r.h / 2, label, tcol, f);
}

void card(int x, int y, int w, int h, uint16_t col = COL_CARD) {
  tft.fillRoundRect(x, y, w, h, 8, col);
  tft.drawRoundRect(x, y, w, h, 8, COL_LINE);
}

void badge(int x, int y, int w, int h, const char* label, uint16_t col) {
  tft.fillRoundRect(x, y, w, h, h / 2, col);
  txtC(x + w / 2, y + h / 2, label, COL_TXT, nullptr, 1);
}

// ======================= WAKTU =======================
void timeStr(char* b, size_t n) {
  time_t t = time(nullptr);
  struct tm tmv;
  localtime_r(&t, &tmv);
  snprintf(b, n, "%02d:%02d", tmv.tm_hour, tmv.tm_min);
}

void dateStr(uint32_t ts, char* b, size_t n) {
  time_t t = ts;
  struct tm tmv;
  localtime_r(&t, &tmv);
  snprintf(b, n, "%02d-%02d-%04d %02d:%02d", tmv.tm_mday, tmv.tm_mon + 1,
           tmv.tm_year + 1900, tmv.tm_hour, tmv.tm_min);
}

int daysInMonth(int year, int month) {  // month 0-11
  static const int d[12] = { 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 };
  if (month == 1) {
    bool leap = (year % 4 == 0 && year % 100 != 0) || (year % 400 == 0);
    return leap ? 29 : 28;
  }
  return d[month];
}

// ======================= PENYIMPANAN =======================
bool loadBlob(const char* k, void* p, size_t n) {
  if (prefs.getBytesLength(k) != n) return false;
  prefs.getBytes(k, p, n);
  return true;
}
void saveBlob(const char* k, const void* p, size_t n) { prefs.putBytes(k, p, n); }

void saveCfg()   { saveBlob("cfg", &cfg, sizeof(cfg)); }
void saveTcal()  { saveBlob("tcal", &tcal, sizeof(tcal)); }
void saveScal()  { saveBlob("scal", &sc, sizeof(sc)); }
void saveRef()   { saveBlob("ref", &refc, sizeof(refc)); }
void saveHist()  {
  prefs.putUChar("hn", (uint8_t)histCount);
  if (histCount > 0) saveBlob("hist", hist, sizeof(HistEntry) * histCount);
}
void saveEpoch() { prefs.putUInt("epoch", (uint32_t)time(nullptr)); }

BatConfig batCfg = { 4.00f, 3.30f, 2.00f };

void saveBatCfg() { saveBlob("batcfg", &batCfg, sizeof(batCfg)); }
void loadBatCfg() {
  if (!loadBlob("batcfg", &batCfg, sizeof(batCfg))) {
    batCfg = { 4.00f, 3.30f, 2.00f };
  }
  if (batCfg.vFull < 3.70f || batCfg.vFull > 4.35f) batCfg.vFull = 4.00f;
  if (batCfg.vEmpty < 2.90f || batCfg.vEmpty > 3.60f) batCfg.vEmpty = 3.30f;
  if (batCfg.calRatio < 1.40f || batCfg.calRatio > 2.80f) batCfg.calRatio = 2.00f;
}

void loadAll() {
  if (!loadBlob("cfg", &cfg, sizeof(cfg))) cfg = { 0, 2, 2, 3 };
  if (cfg.ledIdx > 5 || cfg.gainIdx > 4 || cfg.nIdx > 3 || cfg.blIdx > 3) cfg = { 0, 2, 2, 3 };
  if (!loadBlob("tcal", &tcal, sizeof(tcal))) memset(&tcal, 0, sizeof(tcal));
  if (!loadBlob("scal", &sc, sizeof(sc))) memset(&sc, 0, sizeof(sc));
  if (!loadBlob("ref", &refc, sizeof(refc))) memset(&refc, 0, sizeof(refc));
  loadBatCfg();

  histCount = prefs.getUChar("hn", 0);
  if (histCount > HIST_MAX) histCount = 0;
  if (histCount > 0 && !loadBlob("hist", hist, sizeof(HistEntry) * histCount)) histCount = 0;

  {
    String ws = prefs.getString("wssid", "");
    String wp = prefs.getString("wpass", "");
    strlcpy(wifiSsid, ws.c_str(), sizeof(wifiSsid));
    strlcpy(wifiPass, wp.c_str(), sizeof(wifiPass));
    String wu = prefs.getString("weburl", "");
    if (wu.length() > 7 && wu.indexOf("192.168.1.5") < 0) {
      strlcpy(webApiUrl, wu.c_str(), sizeof(webApiUrl));
    } else {
      strlcpy(webApiUrl, "http://srv4.shawir.id:9070/api.php", sizeof(webApiUrl));
      prefs.putString("weburl", webApiUrl);
    }
    String wk = prefs.getString("webkey", "");
    if (wk.length() > 0 && wk.indexOf("colan_secret") < 0) {
      strlcpy(webApiKey, wk.c_str(), sizeof(webApiKey));
    } else {
      strlcpy(webApiKey, "color2026", sizeof(webApiKey));
      prefs.putString("webkey", webApiKey);
    }
  }

  uint32_t ep = prefs.getUInt("epoch", 1792065600UL);  // default 15-10-2026 12:00
  struct timeval tv = { (time_t)ep, 0 };
  settimeofday(&tv, nullptr);
}

// ======================= BACKLIGHT =======================
void applyBacklight() {
#if PIN_BL >= 0
  ledcWrite(PIN_BL, (uint32_t)BL_OPTS[cfg.blIdx] * 255 / 100);
#endif
}

// ======================= SENSOR =======================
void applySensor() {
  if (!sensorOk) return;
  as7341.setATIME(100);
  as7341.setASTEP(999);
  as7341.setGain(GAIN_OPTS[cfg.gainIdx]);
}

bool calValid() {
  return sc.hasDark && sc.hasWhite && sc.ledIdx == cfg.ledIdx && sc.gainIdx == cfg.gainIdx;
}

// ======================= TOUCH =======================
bool getRaw(int &rx, int &ry) {
  if (!ts.touched()) return false;
  TS_Point p = ts.getPoint();
  if (p.z < 300) return false;
  rx = p.x;
  ry = p.y;
  return true;
}

const int TMX = 24;  // margin titik kalibrasi touch
const int TPX[5] = { TMX, SCREEN_W - TMX, SCREEN_W - TMX, TMX, SCREEN_W / 2 };
const int TPY[5] = { TMX, TMX, SCREEN_H - TMX, SCREEN_H - TMX, SCREEN_H / 2 };

bool mapTouch(int rx, int ry, int &x, int &y) {
  if (!tcal.valid) { x = rx; y = ry; return true; }
  float a = tcal.swapXY ? ry : rx;
  float b = tcal.swapXY ? rx : ry;
  float fx = TMX + (a - tcal.aL) * (float)(SCREEN_W - 2 * TMX) / (tcal.aR - tcal.aL);
  float fy = TMX + (b - tcal.bT) * (float)(SCREEN_H - 2 * TMX) / (tcal.bB - tcal.bT);
  x = constrain((int)fx, 0, SCREEN_W - 1);
  y = constrain((int)fy, 0, SCREEN_H - 1);
  return true;
}

bool readTouch(int &x, int &y) {
  int rx, ry;
  if (!getRaw(rx, ry)) return false;
  return mapTouch(rx, ry, x, y);
}

void waitRelease(uint32_t timeoutMs = 1500) {
  uint32_t t0 = millis();
  while (ts.touched() && millis() - t0 < timeoutMs) delay(10);
  delay(60);
}

// ======================= GAMBAR DASAR =======================
void drawLogo(int cx, int cy, int r) {
  tft.fillCircle(cx, cy - r * 6 / 10, r, RGB565(30, 150, 255));
  tft.fillCircle(cx - r * 7 / 10, cy + r * 4 / 10, r, RGB565(210, 60, 225));
  tft.fillCircle(cx + r * 7 / 10, cy + r * 4 / 10, r, RGB565(255, 205, 40));
}

int rssiLevel(int r) { return r > -55 ? 4 : (r > -65 ? 3 : (r > -75 ? 2 : 1)); }

void drawBars(int x, int yBottom, int lvl, uint16_t on) {
  for (int i = 0; i < 4; i++) {
    int h = 3 + i * 3;
    tft.fillRect(x + i * 5, yBottom - h, 3, h, i < lvl ? on : COL_LINE);
  }
}

// ======================= SISTEM BATERAI & CAS =======================
float batVoltage      = 3.95f; // Tegangan baterai terukur di kutub (Volt)
float batRawV         = 3.95f; // Tegangan instan ADC
int   batPercent      = 79;    // Persentase baterai 0 - 100% yang ditampilkan
float batPercentFloat = 79.0f; // Akumulator persentase halus
bool  batCharging     = false; // Status charger terhubung
bool  batLastCharge   = false;
bool  batInitialized  = false;
uint32_t lastBatCheck = 0;
uint32_t lastPctUpdate = 0;

// Konversi tegangan baterai ke persentase kapasitas riil (0 - 100%) menggunakan batCfg
int calcBatPercent(float v) {
  if (v >= batCfg.vFull) return 100;
  if (v <= batCfg.vEmpty) return 0;
  float range = batCfg.vFull - batCfg.vEmpty;
  if (range < 0.2f) range = 0.7f;
  float frac = (v - batCfg.vEmpty) / range;
  int pct = (int)(frac * 100.0f + 0.5f);
  if (pct > 100) pct = 100;
  if (pct < 0) pct = 0;
  return pct;
}

void readBatterySensors(bool force = false) {
  // 1. Deteksi kabel charger USB 5V (5K + 1K menghasilkan ~833mV)
  uint32_t chgMv = analogReadMilliVolts(PIN_CHG_DET);
  batCharging = (chgMv > 450);

  // 2. Baca tegangan baterai (1K + 1K) dengan 16x oversampling
  uint32_t sumMv = 0;
  for (int i = 0; i < 16; i++) {
    sumMv += analogReadMilliVolts(PIN_BAT_ADC);
    delayMicroseconds(50);
  }
  float pinMv = (float)sumMv / 16.0f;
  batRawV = (pinMv * batCfg.calRatio) / 1000.0f;

  if (force || !batInitialized) {
    batVoltage = batRawV;
    batInitialized = true;
    float vInit = batVoltage;
    if (batCharging) vInit -= 0.20f;
    batPercent = calcBatPercent(vInit);
    batPercentFloat = (float)batPercent;
  } else {
    // Low-pass filter halus untuk tegangan
    batVoltage = batVoltage * 0.90f + batRawV * 0.10f;
  }

  // 3. Kompensasi tegangan saat dicas:
  // Arus pengisian TP4056 menyebabkan kenaikan tegangan semu di kutub baterai (~0.20V).
  // Kita kompensasikan agar persentase mencerminkan kapasitas asli sel kimia, bukan tegangan dorong cas.
  float vCell = batVoltage;
  if (batCharging) {
    float chgLift = 0.20f;
    if (batVoltage > batCfg.vFull) {
      chgLift = 0.20f * (1.0f - (batVoltage - batCfg.vFull) / 0.15f);
      if (chgLift < 0.02f) chgLift = 0.02f;
    }
    vCell = batVoltage - chgLift;
    if (vCell < batCfg.vEmpty) vCell = batCfg.vEmpty;
  }

  int targetPct = calcBatPercent(vCell);

  // 4. Damping / Rate Limiting (Mencegah lonjakan drastis saat colok/cabut)
  uint32_t now = millis();
  if (force) {
    batPercentFloat = (float)targetPct;
    batPercent = targetPct;
  } else if (now - lastPctUpdate > 4000) {
    lastPctUpdate = now;
    if (targetPct > batPercent) {
      batPercentFloat += 0.25f; // Naik bertahap saat diisi (~1% per 16 detik)
      if (batPercentFloat > targetPct) batPercentFloat = (float)targetPct;
    } else if (targetPct < batPercent) {
      batPercentFloat -= 0.25f; // Turun bertahap saat dipakai
      if (batPercentFloat < targetPct) batPercentFloat = (float)targetPct;
    }
    batPercent = (int)(batPercentFloat + 0.5f);
    if (batPercent > 100) batPercent = 100;
    if (batPercent < 0) batPercent = 0;
  }
}

void drawLightning(int x, int y, uint16_t col) {
  tft.fillTriangle(x + 5, y,      x,     y + 7,  x + 4, y + 7,  col);
  tft.fillTriangle(x + 4, y + 6,  x + 8, y + 6,  x + 3, y + 13, col);
  tft.fillTriangle(x + 2, y + 7,  x + 5, y + 6,  x + 4, y + 8,  col);
}

void drawHeaderBattery() {
  // Bersihkan area baterai di header (X: 166 s/d 265, terisolasi penuh dari WiFi di X=138-162)
  tft.fillRect(166, 4, 100, 22, COL_BG);

  // Teks persentase (FreeSans 9pt, rata kanan di 216 sehingga selalu di kanan X=172, aman dari WiFi)
  char b[8];
  snprintf(b, sizeof(b), "%d%%", batPercent);
  txt(216, 7, b, COL_TXT, F9, 1, 2);

  // Logo petir kuning tegas di samping kiri bar baterai saat di-cas
  if (batCharging) {
    drawLightning(222, 7, COL_YELLOW);
  }

  // Bingkai baterai diperbesar (W=24, H=14) dengan rounded border ganda
  int bx = 236, by = 7, bw = 24, bh = 14;
  uint16_t frameCol = COL_LINE;
  tft.drawRoundRect(bx, by, bw, bh, 3, frameCol);
  tft.drawRoundRect(bx + 1, by + 1, bw - 2, bh - 2, 2, frameCol);
  tft.fillRoundRect(bx + bw, by + 4, 3, bh - 8, 1, frameCol); // tip kutub (+)

  // Isi bar baterai (warna murni sesuai persentase kapasitas, TANPA diubah saat di-cas)
  int maxFill = bw - 6; // 18 px
  int fillW = maxFill * batPercent / 100;
  if (fillW < 2 && batPercent > 0) fillW = 2;
  if (fillW > maxFill) fillW = maxFill;

  uint16_t fillCol = COL_GREEN;
  if (batPercent <= 20) fillCol = COL_RED;
  else if (batPercent <= 50) fillCol = COL_ORANGE;

  if (fillW > 0) {
    tft.fillRect(bx + 3, by + 3, fillW, bh - 6, fillCol);
  }
}

void drawHeaderClock() {
  tft.fillRect(268, 4, 52, 22, COL_BG);
  char b[8];
  timeStr(b, sizeof(b));
  txt(314, 7, b, COL_TXT, F9B, 1, 2);
}

void drawWifiIcon() {
  tft.fillRect(138, 4, 24, 20, COL_BG);
  if (WiFi.status() != WL_CONNECTED) return;
  drawBars(140, 20, rssiLevel(WiFi.RSSI()), COL_GREEN);
}

void drawHeader(const char* title, bool back) {
  tft.fillRect(0, 0, SCREEN_W, 28, COL_BG);
  int x = 10;
  if (back) {
    tft.drawLine(18, 7, 11, 13, COL_TXT);
    tft.drawLine(11, 13, 18, 19, COL_TXT);
    tft.drawLine(19, 7, 12, 13, COL_TXT);
    tft.drawLine(12, 13, 19, 19, COL_TXT);
    x = 28;
  }
  char safeTitle[36];
  fitStr(safeTitle, sizeof(safeTitle), title, 134 - x, F9B);
  txt(x, 6, safeTitle, COL_TXT, F9B);
  drawWifiIcon();
  drawHeaderBattery();
  drawHeaderClock();
  tft.drawFastHLine(0, 28, SCREEN_W, COL_LINE);
}

const Rect R_BACK = { 0, 0, 80, 28 };

void drawCheck(int cx, int cy, int r, uint16_t col) {
  tft.drawCircle(cx, cy, r, col);
  tft.drawCircle(cx, cy, r - 1, col);
  tft.drawCircle(cx, cy, r - 2, col);
  int k = r / 3;
  for (int d = -1; d <= 1; d++) {
    tft.drawLine(cx - k * 2 + d, cy, cx - k / 2 + d, cy + k + 1, col);
    tft.drawLine(cx - k / 2 + d, cy + k + 1, cx + k * 2 + d, cy - k, col);
  }
}

void drawWarnIcon(int cx, int cy, int r, uint16_t col) {
  tft.drawCircle(cx, cy, r, col);
  tft.drawCircle(cx, cy, r - 1, col);
  tft.fillRect(cx - 2, cy - r / 2, 4, r / 2 + 2, col);
  tft.fillCircle(cx, cy + r / 2 - 1, 2, col);
}

void arcSeg(int cx, int cy, int ro, int ri, float a0, float a1, uint16_t col) {
  for (float a = a0; a < a1; a += 4) {
    float b = fminf(a + 5, a1);
    float ca = cosf(a * 0.0174533f), sa = sinf(a * 0.0174533f);
    float cb = cosf(b * 0.0174533f), sb = sinf(b * 0.0174533f);
    int x1 = cx + ro * ca, y1 = cy + ro * sa;
    int x2 = cx + ro * cb, y2 = cy + ro * sb;
    int x3 = cx + ri * cb, y3 = cy + ri * sb;
    int x4 = cx + ri * ca, y4 = cy + ri * sa;
    tft.fillTriangle(x1, y1, x2, y2, x3, y3, col);
    tft.fillTriangle(x1, y1, x3, y3, x4, y4, col);
  }
}

uint16_t wlColor(float w) {
  float r = 0, g = 0, b = 0;
  if (w < 380) w = 380;
  if (w > 780) w = 780;
  if (w < 440)      { r = -(w - 440) / 60; b = 1; }
  else if (w < 490) { g = (w - 440) / 50; b = 1; }
  else if (w < 510) { g = 1; b = -(w - 510) / 20; }
  else if (w < 580) { r = (w - 510) / 70; g = 1; }
  else if (w < 645) { r = 1; g = -(w - 645) / 65; }
  else              { r = 1; }
  float f = 1.0f;
  if (w < 420) f = 0.3f + 0.7f * (w - 380) / 40;
  else if (w > 700) f = 0.3f + 0.7f * (780 - w) / 80;
  int R = (int)(255 * powf(r * f, 0.8f));
  int G = (int)(255 * powf(g * f, 0.8f));
  int B = (int)(255 * powf(b * f, 0.8f));
  return RGB565(R, G, B);
}

void toast(const char* msg, uint16_t col) {
  if (col == 0) col = COL_CARD2;
  tft.fillRoundRect(25, 170, 270, 34, 10, col);
  tft.drawRoundRect(25, 170, 270, 34, 10, COL_TXT);
  txtC(160, 187, msg, COL_TXT, F9);
  delay(1200);
  redrawScreen();
}

// ======================= WARNA: HITUNG =======================
void initColorTables() {
  SUMX = SUMY = SUMZ = 0;
  for (int i = 0; i < 8; i++) {
    WX[i] = ILL_D65[i] * CMF_X[i] * DW[i];
    WY[i] = ILL_D65[i] * CMF_Y[i] * DW[i];
    WZ[i] = ILL_D65[i] * CMF_Z[i] * DW[i];
    SUMX += WX[i];
    SUMY += WY[i];
    SUMZ += WZ[i];
  }
}

static float labF(float t) {
  return t > 0.008856f ? cbrtf(t) : (7.787f * t + 16.0f / 116.0f);
}
static float gammaEnc(float c) {
  if (c <= 0) return 0;
  if (c >= 1) return 1;
  return c <= 0.0031308f ? 12.92f * c : 1.055f * powf(c, 1.0f / 2.4f) - 0.055f;
}

void computeResult(Result &r) {
  float X = 0, Y = 0, Z = 0;
  if (!r.relative) {
    for (int i = 0; i < 8; i++) {
      X += r.R[i] * WX[i];
      Y += r.R[i] * WY[i];
      Z += r.R[i] * WZ[i];
    }
    X = X / SUMX * 95.047f;
    Y = Y / SUMY * 100.0f;
    Z = Z / SUMZ * 108.883f;
  } else {
    for (int i = 0; i < 8; i++) {
      X += r.R[i] * CMF_X[i] * DW[i];
      Y += r.R[i] * CMF_Y[i] * DW[i];
      Z += r.R[i] * CMF_Z[i] * DW[i];
    }
    float k = Y > 1e-6f ? 50.0f / Y : 0;
    X *= k; Y *= k; Z *= k;
  }
  r.X = X; r.Y = Y; r.Z = Z;
  float s = X + Y + Z;
  r.cx = s > 1e-6f ? X / s : 0.3127f;
  r.cy = s > 1e-6f ? Y / s : 0.3290f;

  float fx = labF(X / 95.047f), fy = labF(Y / 100.0f), fz = labF(Z / 108.883f);
  r.L = 116 * fy - 16;
  r.a = 500 * (fx - fy);
  r.b = 200 * (fy - fz);

  float xr = X / 100, yr = Y / 100, zr = Z / 100;
  float lr =  3.2406f * xr - 1.5372f * yr - 0.4986f * zr;
  float lg = -0.9689f * xr + 1.8758f * yr + 0.0415f * zr;
  float lb =  0.0557f * xr - 0.2040f * yr + 1.0570f * zr;
  if (r.relative) {
    float m = fmaxf(lr, fmaxf(lg, lb));
    if (m > 1e-6f) { lr /= m; lg /= m; lb /= m; }
  }
  r.rr = (uint8_t)(gammaEnc(lr) * 255 + 0.5f);
  r.gg = (uint8_t)(gammaEnc(lg) * 255 + 0.5f);
  r.bb = (uint8_t)(gammaEnc(lb) * 255 + 0.5f);

  // HSV
  float R = r.rr / 255.0f, G = r.gg / 255.0f, B = r.bb / 255.0f;
  float mx = fmaxf(R, fmaxf(G, B)), mn = fminf(R, fminf(G, B)), d = mx - mn;
  float h = 0;
  if (d > 1e-6f) {
    if (mx == R) h = fmodf((G - B) / d, 6.0f);
    else if (mx == G) h = (B - R) / d + 2;
    else h = (R - G) / d + 4;
    h *= 60;
    if (h < 0) h += 360;
  }
  r.hue = h;
  r.val = mx;
  r.sat = mx > 1e-6f ? d / mx : 0;

  r.hasDE = false;
  r.dE = 0;
  if (!r.relative && refc.valid) {
    float dl = r.L - refc.L, da = r.a - refc.a, db = r.b - refc.b;
    r.dE = sqrtf(dl * dl + da * da + db * db);
    r.hasDE = true;
  }
}

void spectrumToR(const Spectrum &s, float R[8], bool relative) {
  float e[8], mx = 1e-6f;
  for (int i = 0; i < 8; i++) {
    float dk = sc.hasDark ? sc.darkF[i] : 0;
    e[i] = fmaxf(0, s.f[i] - dk);
  }
  if (relative) {
    for (int i = 0; i < 8; i++) mx = fmaxf(mx, e[i]);
    for (int i = 0; i < 8; i++) R[i] = e[i] / mx;
  } else {
    for (int i = 0; i < 8; i++) {
      R[i] = e[i] / fmaxf(sc.whiteNet[i], 1.0f);
      if (R[i] > 1.5f) R[i] = 1.5f;
    }
  }
}

const char* gradeText(float dE, uint16_t &col) {
  if (dE < 1.5f) { col = COL_GREEN;  return "Sangat Baik"; }
  if (dE < 3.0f) { col = RGB565(110, 200, 60); return "Baik"; }
  if (dE < 6.0f) { col = COL_ORANGE; return "Cukup"; }
  col = COL_RED;
  return "Berbeda";
}

float interpR(float wl) {
  if (wl <= BAND_WL[0]) return cur.R[0];
  if (wl >= BAND_WL[7]) return cur.R[7];
  for (int i = 0; i < 7; i++) {
    if (wl <= BAND_WL[i + 1]) {
      float t = (wl - BAND_WL[i]) / (BAND_WL[i + 1] - BAND_WL[i]);
      return cur.R[i] + t * (cur.R[i + 1] - cur.R[i]);
    }
  }
  return cur.R[7];
}

// ======================= AKUISISI SENSOR =======================
const Rect R_CANCEL = { 110, 198, 100, 34 };

bool cancelPressed() {
  int x, y;
  return readTouch(x, y) && hit(R_CANCEL, x, y);
}

bool acquire(Spectrum &out, bool led, int n, ProcCb cb, float* series) {
  memset(&out, 0, sizeof(out));
  lastCancelled = false;
  double sum[8] = { 0 };
  double sClr = 0, sNir = 0;
  int got = 0;
  bool ok = true;

  if (led) {
    as7341.setLEDCurrent(LED_OPTS[cfg.ledIdx]);
    as7341.enableLED(true);
    delay(200);
  }

  for (int i = 0; i < n && ok; i++) {
    if (!as7341.startReading()) { ok = false; break; }
    uint32_t t0 = millis(), lastUi = 0;
    while (!as7341.checkReadingProgress()) {
      delay(5);
      uint32_t el = millis() - t0;
      if (el > 4000) { ok = false; break; }
      if (cancelPressed()) { lastCancelled = true; ok = false; break; }
      if (cb && millis() - lastUi > 120) {
        lastUi = millis();
        cb((i + fminf(el / 650.0f, 0.95f)) / n, nullptr, i, n);
      }
    }
    if (!ok) break;

    uint16_t r[12];
    if (!as7341.getAllChannels(r)) { ok = false; break; }

    float v[8] = { (float)r[0], (float)r[1], (float)r[2], (float)r[3],
                   (float)r[6], (float)r[7], (float)r[8], (float)r[9] };
    float tot = 0;
    for (int k = 0; k < 8; k++) { sum[k] += v[k]; tot += v[k]; }
    sClr += (r[4] + r[10]) / 2.0;
    sNir += (r[5] + r[11]) / 2.0;
    got++;
    if (series) series[i] = tot;

    if (cb) {
      Spectrum avg;
      for (int k = 0; k < 8; k++) avg.f[k] = sum[k] / got;
      avg.clr = sClr / got;
      avg.nir = sNir / got;
      cb((i + 1.0f) / n, &avg, i + 1, n);
    }
  }

  if (led) as7341.enableLED(false);
  if (!ok || got == 0) return false;

  for (int k = 0; k < 8; k++) out.f[k] = sum[k] / got;
  out.clr = sClr / got;
  out.nir = sNir / got;
  return true;
}

// ----- tampilan proses -----
const int PX = 74, PY = 118, PRO = 46, PRI = 36;
float procLast = -90;
int procLastPct = -1;

void procDraw(const char* title, const char* sub) {
  tft.fillScreen(COL_BG);
  drawHeader(title, false);
  txt(160, 36, sub, COL_TXT, F9, 1, 1);
  arcSeg(PX, PY, PRO, PRI, -90, 270, COL_CARD2);
  card(140, 60, 172, 112);
  btn(R_CANCEL, COL_CARD2, "Batal", F9);
  procLast = -90;
  procLastPct = -1;
  txtC(PX, PY, "0%", COL_TXT, F12B);
}

void procCb(float frac, const Spectrum* s, int done, int total) {
  float ang = -90 + 360.0f * fminf(frac, 1.0f);
  if (ang > procLast + 1) {
    arcSeg(PX, PY, PRO, PRI, procLast, ang, COL_BLUE);
    procLast = ang;
  }
  int pct = (int)(frac * 100);
  if (pct != procLastPct) {
    procLastPct = pct;
    char b[8];
    snprintf(b, sizeof(b), "%d%%", pct);
    tft.fillRect(PX - 31, PY - 14, 62, 28, COL_BG);
    txtC(PX, PY, b, COL_TXT, F12B);
  }
  if (s) {
    static const char* lbl[4] = { "Clear", "F2 445nm", "F5 555nm", "F7 630nm" };
    uint16_t dot[4] = { COL_TXT, COL_BLUE, COL_GREEN, COL_RED };
    float val[4] = { s->clr, s->f[1], s->f[4], s->f[6] };
    for (int i = 0; i < 4; i++) {
      int y = 70 + i * 26;
      tft.fillRect(146, y, 160, 20, COL_CARD);
      tft.fillCircle(154, y + 8, 5, dot[i]);
      txt(166, y + 2, lbl[i], COL_TXT, F9);
      char b[12];
      snprintf(b, sizeof(b), "%d", (int)val[i]);
      txt(304, y + 2, b, COL_MUTED, F9, 1, 2);
    }
    char b[24];
    snprintf(b, sizeof(b), "Sampel %d/%d", done, total);
    tft.fillRect(0, 172, 140, 16, COL_BG);
    txt(PX, 176, b, COL_MUTED, nullptr, 1, 1);
  }
}

bool runProcess(const char* title, const char* sub, bool led, int n, Spectrum &out, float* series) {
  waitRelease();
  procDraw(title, sub);
  bool ok = acquire(out, led, n, procCb, series);
  return ok;
}

// ======================= UI: BOOT =======================
void bootDraw(int pct, const char* msg) {
  static bool first = true;
  if (first) {
    first = false;
    tft.fillScreen(COL_BG);
    drawLogo(160, 50, 22);
    txtC(160, 96, "Colorimetric Analyzer", COL_TXT, F12B);
    txtC(160, 118, "Precision Spectral Sensor", COL_MUTED, F9);
    // Bingkai progress bar
    tft.drawRoundRect(40, 160, 240, 16, 8, COL_LINE);
    tft.drawRoundRect(39, 159, 242, 18, 9, COL_CARD2);
    txtC(160, 224, FW_VERSION, COL_MUTED, nullptr, 1);
  }

  // 1. Tampilkan persentase di atas bar dengan area yang DIBERSIHKAN TOTAL (tidak tumpang tindih)
  tft.fillRect(100, 134, 120, 22, COL_BG);
  char pb[12];
  snprintf(pb, sizeof(pb), "%d%%", pct);
  txtC(160, 145, pb, COL_TXT, F9B);

  // 2. Isi bar persentase (biru cerah)
  tft.fillRoundRect(42, 162, 236, 12, 6, COL_CARD);
  int w = 236 * pct / 100;
  if (w > 0) {
    tft.fillRoundRect(42, 162, w, 12, 6, COL_BLUE);
  }

  // 3. Pesan status di bawah bar dengan area yang DIBERSIHKAN TOTAL
  tft.fillRect(0, 184, SCREEN_W, 30, COL_BG);
  txtC(160, 198, msg, COL_MUTED, F9);
}

// ======================= UI: HOME =======================
Rect homeTile(int i) {
  int c = i % 3, r = i / 3;
  return Rect{ (int16_t)(9 + c * 102), (int16_t)(34 + r * 92), 98, 88 };
}

void drawIcon(int k, int cx, int cy, uint16_t c, uint16_t bg) {
  switch (k) {
    case 0:  // labu
      tft.drawLine(cx - 4, cy - 13, cx - 4, cy - 3, c);
      tft.drawLine(cx + 4, cy - 13, cx + 4, cy - 3, c);
      tft.drawLine(cx - 4, cy - 3, cx - 13, cy + 11, c);
      tft.drawLine(cx + 4, cy - 3, cx + 13, cy + 11, c);
      tft.drawLine(cx - 13, cy + 11, cx + 13, cy + 11, c);
      tft.drawLine(cx - 7, cy - 13, cx + 7, cy - 13, c);
      tft.fillTriangle(cx - 8, cy + 5, cx + 8, cy + 5, cx + 11, cy + 10, c);
      tft.fillTriangle(cx - 8, cy + 5, cx - 11, cy + 10, cx + 11, cy + 10, c);
      break;
    case 1:  // target
      tft.drawCircle(cx, cy, 14, c);
      tft.drawCircle(cx, cy, 13, c);
      tft.drawCircle(cx, cy, 8, c);
      tft.fillCircle(cx, cy, 3, c);
      break;
    case 2:  // crosshair
      tft.drawCircle(cx, cy, 10, c);
      tft.drawFastHLine(cx - 15, cy, 31, c);
      tft.drawFastVLine(cx, cy - 15, 31, c);
      break;
    case 3:  // daftar
      for (int i = 0; i < 3; i++) {
        tft.fillCircle(cx - 11, cy - 9 + i * 9, 2, c);
        tft.fillRect(cx - 5, cy - 10 + i * 9, 18, 3, c);
      }
      break;
    case 4:  // gear
      for (int a = 0; a < 360; a += 45) {
        int tx = cx + 11 * cosf(a * 0.0174533f);
        int ty = cy + 11 * sinf(a * 0.0174533f);
        tft.fillCircle(tx, ty, 2, c);
      }
      tft.fillCircle(cx, cy, 9, c);
      tft.fillCircle(cx, cy, 4, bg);
      break;
    case 5:  // info
      tft.drawCircle(cx, cy, 13, c);
      tft.drawCircle(cx, cy, 12, c);
      tft.fillCircle(cx, cy - 6, 2, c);
      tft.fillRect(cx - 1, cy - 2, 3, 10, c);
      break;
  }
}

void drawHomeStatus() {
  const char* s;
  uint16_t c;
  if (!sensorOk)        { s = "Sensor AS7341 tidak terdeteksi"; c = COL_RED; }
  else if (calValid())  { s = "Sensor OK   |   Kalibrasi OK"; c = COL_GREEN; }
  else                  { s = "Sensor OK   |   Perlu kalibrasi sensor"; c = COL_ORANGE; }
  tft.fillRect(0, 220, SCREEN_W, 20, COL_BG);
  txt(160, 224, s, c, nullptr, 1, 1);
}

void drawHome() {
  tft.fillScreen(COL_BG);
  drawLogo(18, 14, 5);
  txt(34, 6, "Colorimeter", COL_TXT, F9B);
  drawWifiIcon();
  drawHeaderBattery();
  drawHeaderClock();
  tft.drawFastHLine(0, 28, SCREEN_W, COL_LINE);

  static const char* T1[6] = { "Analisis", "Kalibrasi", "Kalibrasi", "Riwayat", "Pengaturan", "Tentang" };
  static const char* T2[6] = { "Ukur warna", "Sensor AS7341", "Layar touch", "Data tersimpan", "Sistem", FW_VERSION };
  uint16_t col[6] = { COL_BLUE, COL_GREEN, COL_PURPLE, COL_CARD2, COL_CARD2, COL_CARD2 };
  for (int i = 0; i < 6; i++) {
    Rect r = homeTile(i);
    tft.fillRoundRect(r.x, r.y, r.w, r.h, 10, col[i]);
    drawIcon(i, r.x + r.w / 2, r.y + 28, COL_TXT, col[i]);
    txtFitC(r.x + r.w / 2, r.y + 58, T1[i], COL_TXT, r.w - 8);
    txt(r.x + r.w / 2, r.y + 72, T2[i], RGB565(215, 225, 245), nullptr, 1, 1);
  }
  drawHomeStatus();
}

// ======================= UI: SAMPLE =======================
const Rect R_TAB_S = { 20, 36, 136, 26 };
const Rect R_TAB_M = { 164, 36, 136, 26 };
const Rect R_START = { 20, 194, 280, 40 };

void drawSample() {
  tft.fillScreen(COL_BG);
  drawHeader("Analisis Warna", true);

  tft.fillRoundRect(R_TAB_S.x, R_TAB_S.y, R_TAB_S.w, R_TAB_S.h, 13, sampleMode == 0 ? COL_BLUE : COL_CARD2);
  tft.fillRoundRect(R_TAB_M.x, R_TAB_M.y, R_TAB_M.w, R_TAB_M.h, 13, sampleMode == 1 ? COL_BLUE : COL_CARD2);
  txtC(R_TAB_S.x + R_TAB_S.w / 2, R_TAB_S.y + R_TAB_S.h / 2, "Sample", COL_TXT, F9);
  txtC(R_TAB_M.x + R_TAB_M.w / 2, R_TAB_M.y + R_TAB_M.h / 2, "Manual", COL_TXT, F9);

  // Ilustrasi dudukan sensor
  card(20, 70, 280, 78);
  tft.fillRoundRect(130, 76, 60, 60, 10, RGB565(70, 82, 108));
  tft.drawRoundRect(130, 76, 60, 60, 10, RGB565(130, 145, 175));
  tft.fillCircle(160, 106, 20, sampleMode == 0 ? COL_YELLOW : RGB565(235, 240, 255));
  tft.drawCircle(160, 106, 20, COL_TXT);
  if (sampleMode == 1) {  // simbol cahaya
    for (int a = 0; a < 360; a += 45)
      tft.drawLine(160 + 24 * cosf(a * 0.0174533f), 106 + 24 * sinf(a * 0.0174533f),
                   160 + 29 * cosf(a * 0.0174533f), 106 + 29 * sinf(a * 0.0174533f), COL_YELLOW);
  }

  if (sampleMode == 0) {
    txt(160, 154, "Masukkan sampel dan tekan mulai.", COL_TXT, F9, 1, 1);
    txt(160, 172, "LED sensor menyala saat mengukur (reflektansi).", COL_MUTED, nullptr, 1, 1);
  } else {
    txt(160, 154, "Arahkan sensor ke sumber cahaya.", COL_TXT, F9, 1, 1);
    txt(160, 172, "LED mati. Hasil berupa warna relatif.", COL_MUTED, nullptr, 1, 1);
  }

  char b[64];
  uint16_t c;
  if (sampleMode == 1) {
    snprintf(b, sizeof(b), "Rata-rata %d sampel", N_OPTS[cfg.nIdx]);
    c = COL_MUTED;
  } else if (calValid()) {
    snprintf(b, sizeof(b), "Kalibrasi OK  |  LED %d mA  |  Gain %s  |  %d sampel",
             LED_OPTS[cfg.ledIdx], GAIN_TXT[cfg.gainIdx], N_OPTS[cfg.nIdx]);
    c = COL_GREEN;
  } else {
    snprintf(b, sizeof(b), "Kalibrasi sensor diperlukan");
    c = COL_ORANGE;
  }
  txt(160, 182, b, c, nullptr, 1, 1);
  btn(R_START, COL_GREEN, "Mulai Analisis", F9B);
}

// ======================= UI: HASIL =======================
Rect rb(int i) { return Rect{ (int16_t)(7 + i * 78), 196, 72, 38 }; }

void cieDiagram(int x0, int y0, int w, int h, float px, float py) {
  tft.fillRect(x0, y0, w, h, COL_BG);
  tft.drawRect(x0, y0, w, h, COL_LINE);
  auto mx = [&](float x) { return x0 + (int)(x / 0.8f * (w - 1)); };
  auto my = [&](float y) { return y0 + h - 1 - (int)(y / 0.9f * (h - 1)); };
  for (int i = 0; i < 24; i++) {
    tft.drawLine(mx(CIE_XY[i][0]), my(CIE_XY[i][1]), mx(CIE_XY[i + 1][0]), my(CIE_XY[i + 1][1]),
                 wlColor(CIE_WL[i]));
  }
  tft.drawLine(mx(CIE_XY[24][0]), my(CIE_XY[24][1]), mx(CIE_XY[0][0]), my(CIE_XY[0][1]),
               RGB565(170, 70, 200));
  tft.drawPixel(mx(0.3127f), my(0.3290f), COL_TXT);  // titik putih D65
  tft.drawCircle(mx(0.3127f), my(0.3290f), 2, COL_MUTED);
  int sx = mx(px), sy = my(py);
  tft.fillCircle(sx, sy, 3, COL_TXT);
  tft.drawCircle(sx, sy, 4, COL_BG);
}

void drawResult() {
  tft.fillScreen(COL_BG);
  drawHeader("Hasil Warna", true);

  // Warna sampel
  card(7, 34, 146, 84);
  txt(15, 40, cur.relative ? "Warna (relatif)" : "Warna Sampel", COL_TXT, F9);
  tft.fillRoundRect(15, 60, 130, 50, 8, RGB565(cur.rr, cur.gg, cur.bb));
  tft.drawRoundRect(15, 60, 130, 50, 8, COL_TXT);

  // CIE
  card(159, 34, 154, 84);
  txt(167, 40, "CIE 1931", COL_TXT, F9);
  char b[32];
  snprintf(b, sizeof(b), "x  %.3f", cur.cx);
  txt(167, 62, b, COL_MUTED, F9);
  snprintf(b, sizeof(b), "y  %.3f", cur.cy);
  txt(167, 84, b, COL_MUTED, F9);
  cieDiagram(243, 42, 62, 70, cur.cx, cur.cy);

  // RGB / HEX / dE
  card(7, 124, 306, 66);
  uint16_t dc[3] = { COL_RED, COL_GREEN, COL_BLUE };
  const char* dl[3] = { "R", "G", "B" };
  int vals[3] = { cur.rr, cur.gg, cur.bb };
  for (int i = 0; i < 3; i++) {
    int y = 130 + i * 18;
    tft.fillCircle(18, y + 7, 4, dc[i]);
    txt(28, y, dl[i], dc[i], F9B);
    snprintf(b, sizeof(b), "%d", vals[i]);
    txt(78, y, b, COL_TXT, F9, 1, 2);
  }
  tft.drawFastVLine(92, 130, 54, COL_LINE);
  txt(102, 130, "HEX", COL_MUTED, F9);
  snprintf(b, sizeof(b), "#%02X%02X%02X", cur.rr, cur.gg, cur.bb);
  txt(102, 154, b, COL_TXT, F9B);
  tft.drawFastVLine(206, 130, 54, COL_LINE);
  txt(216, 130, "Delta E*", COL_MUTED, F9);
  if (cur.relative) {
    txt(216, 156, "mode relatif", COL_MUTED, nullptr, 1);
  } else if (cur.hasDE) {
    snprintf(b, sizeof(b), "%.2f", cur.dE);
    txt(216, 148, b, COL_TXT, F12B);
    uint16_t gc;
    const char* g = gradeText(cur.dE, gc);
    badge(216, 172, 90, 14, g, gc);
  } else {
    txt(216, 152, "Belum ada ref", COL_MUTED, nullptr, 1);
    txt(216, 166, "(Detail > Set Ref)", COL_MUTED, nullptr, 1);
  }

  btn(rb(0), cur.saved ? COL_CARD2 : COL_BLUE, "Simpan", F9);
  btn(rb(1), COL_CARD2, "Ulangi", F9);
  btn(rb(2), COL_CARD2, "Detail", F9);
  btn(rb(3), COL_CARD2, "Menu", F9);
}

// ======================= UI: DETAIL =======================
Rect tabRect(int i) { return Rect{ (int16_t)(7 + i * 78), 34, 72, 24 }; }

void drawSpecTab() {
  const int px = 38, py = 66, pw = 192, ph = 100;
  float ymax = 1.0f;
  for (int i = 0; i < 8; i++) ymax = fmaxf(ymax, cur.R[i]);
  tft.drawRect(px - 1, py - 1, pw + 2, ph + 2, COL_LINE);
  for (int i = 0; i < pw; i++) {
    float wl = 400 + 300.0f * i / pw;
    int h = (int)(interpR(wl) / ymax * ph);
    if (h > 0) tft.drawFastVLine(px + i, py + ph - h, h, wlColor(wl));
  }
  char b[12];
  txt(px - 4, py - 2, cur.relative ? "1.0" : "1.0", COL_MUTED, nullptr, 1, 2);
  txt(px - 4, py + ph / 2 - 4, "0.5", COL_MUTED, nullptr, 1, 2);
  txt(px - 4, py + ph - 8, "0.0", COL_MUTED, nullptr, 1, 2);
  for (int w = 400; w <= 700; w += 100) {
    int x = px + (w - 400) * pw / 300;
    snprintf(b, sizeof(b), "%d", w);
    txt(x, py + ph + 4, b, COL_MUTED, nullptr, 1, 1);
  }
  txt(px + pw / 2, py + ph + 16, "Reflektansi vs panjang gelombang (nm)", COL_MUTED, nullptr, 1, 1);

  card(238, 62, 74, 108);
  txt(275, 66, "Nilai", COL_TXT, F9, 1, 1);
  uint16_t dc[4] = { COL_RED, COL_GREEN, COL_BLUE, COL_MUTED };
  const char* dl[4] = { "R", "G", "B", "C" };
  float v[4] = { cur.R[6] * 100, cur.R[4] * 100, cur.R[1] * 100, cur.clr };
  for (int i = 0; i < 4; i++) {
    int y = 88 + i * 20;
    tft.fillCircle(248, y + 6, 4, dc[i]);
    txt(256, y, dl[i], COL_TXT, F9);
    if (i < 3) snprintf(b, sizeof(b), "%.0f%%", v[i]);
    else snprintf(b, sizeof(b), "%d", (int)v[i]);
    txt(306, y, b, COL_MUTED, nullptr, 1, 2);
  }
}

void drawCieTab() {
  cieDiagram(20, 64, 170, 120, cur.cx, cur.cy);
  txt(105, 186, "Diagram CIE 1931 (x, y)", COL_MUTED, nullptr, 1, 1);
  card(200, 64, 112, 120);
  char b[24];
  txt(208, 70, "Koordinat", COL_TXT, F9);
  snprintf(b, sizeof(b), "x  %.3f", cur.cx); txt(208, 92, b, COL_MUTED, F9);
  snprintf(b, sizeof(b), "y  %.3f", cur.cy); txt(208, 110, b, COL_MUTED, F9);
  snprintf(b, sizeof(b), "X  %.1f", cur.X);  txt(208, 132, b, COL_MUTED, F9);
  snprintf(b, sizeof(b), "Y  %.1f", cur.Y);  txt(208, 150, b, COL_MUTED, F9);
  snprintf(b, sizeof(b), "Z  %.1f", cur.Z);  txt(208, 168, b, COL_MUTED, F9);
}

void drawRgbTab() {
  uint16_t dc[3] = { COL_RED, COL_GREEN, COL_BLUE };
  const char* dl[3] = { "R", "G", "B" };
  int vals[3] = { cur.rr, cur.gg, cur.bb };
  char b[12];
  for (int i = 0; i < 3; i++) {
    int y = 72 + i * 34;
    tft.fillCircle(20, y + 10, 9, dc[i]);
    txtC(20, y + 10, dl[i], COL_TXT, nullptr, 1);
    tft.fillRoundRect(38, y + 4, 140, 12, 6, COL_CARD2);
    int w = vals[i] * 140 / 255;
    if (w > 0) tft.fillRoundRect(38, y + 4, w, 12, 6, dc[i]);
    snprintf(b, sizeof(b), "%d", vals[i]);
    txt(214, y + 3, b, COL_TXT, F9, 1, 2);
  }
  tft.fillRoundRect(228, 70, 84, 56, 8, RGB565(cur.rr, cur.gg, cur.bb));
  tft.drawRoundRect(228, 70, 84, 56, 8, COL_TXT);
  char h[12];
  snprintf(h, sizeof(h), "#%02X%02X%02X", cur.rr, cur.gg, cur.bb);
  txt(270, 134, h, COL_TXT, F9B, 1, 1);
  txt(270, 154, "sRGB", COL_MUTED, F9, 1, 1);
}

void drawLabTab() {
  char b[32];
  // Lab
  card(7, 62, 148, 62);
  txt(15, 66, "Lab Color (L*a*b*)", COL_TXT, nullptr, 1);
  snprintf(b, sizeof(b), "L*   %.2f", cur.L); txt(15, 78, b, COL_MUTED, F9);
  snprintf(b, sizeof(b), "a*   %.2f", cur.a); txt(15, 92, b, COL_MUTED, F9);
  snprintf(b, sizeof(b), "b*   %.2f", cur.b); txt(15, 106, b, COL_MUTED, F9);

  // Color match
  card(161, 62, 152, 62);
  txt(169, 66, "Color Match", COL_TXT, nullptr, 1);
  if (cur.relative) {
    txt(169, 92, "tidak tersedia", COL_MUTED, nullptr, 1);
    txt(169, 104, "(mode relatif)", COL_MUTED, nullptr, 1);
  } else if (refc.valid) {
    tft.fillRoundRect(169, 78, 28, 22, 4, RGB565(refc.r, refc.g, refc.bl));
    tft.fillRoundRect(201, 78, 28, 22, 4, RGB565(cur.rr, cur.gg, cur.bb));
    tft.drawRoundRect(169, 78, 28, 22, 4, COL_TXT);
    tft.drawRoundRect(201, 78, 28, 22, 4, COL_TXT);
    txt(183, 102, "ref", COL_MUTED, nullptr, 1, 1);
    txt(215, 102, "now", COL_MUTED, nullptr, 1, 1);
    snprintf(b, sizeof(b), "dE %.2f", cur.dE);
    txt(240, 80, b, COL_TXT, F9);
    uint16_t gc;
    const char* g = gradeText(cur.dE, gc);
    badge(238, 100, 70, 14, g, gc);
  } else {
    txt(169, 92, "Belum ada referensi.", COL_MUTED, nullptr, 1);
    txt(169, 104, "Tekan Set Ref.", COL_MUTED, nullptr, 1);
  }

  // Kualitas warna
  card(7, 130, 306, 60);
  txt(15, 134, "Kualitas Warna", COL_TXT, nullptr, 1);
  const char* nm[3] = { "Kecerahan", "Kejenuhan", "Hue" };
  float pv[3] = { cur.val, cur.sat, cur.hue / 360.0f };
  uint16_t pc[3] = { COL_GREEN, COL_GREEN, COL_GREEN };
  for (int i = 0; i < 3; i++) {
    int y = 146 + i * 14;
    txt(15, y, nm[i], COL_MUTED, nullptr, 1);
    tft.fillRoundRect(88, y + 1, 170, 7, 3, COL_CARD2);
    int w = (int)(pv[i] * 170);
    if (w > 0) tft.fillRoundRect(88, y + 1, w, 7, 3, pc[i]);
    snprintf(b, sizeof(b), "%d%%", (int)(pv[i] * 100 + 0.5f));
    txt(304, y, b, COL_TXT, nullptr, 1, 2);
  }
}

void drawDetail() {
  tft.fillScreen(COL_BG);
  drawHeader("Detail Hasil", true);
  const char* tn[4] = { "Spektrum", "CIE 1931", "RGB", "Lab" };
  for (int i = 0; i < 4; i++) {
    Rect t = tabRect(i);
    tft.fillRoundRect(t.x, t.y, t.w, t.h, 12, detailTab == i ? COL_BLUE : COL_CARD2);
    txtC(t.x + t.w / 2, t.y + t.h / 2, tn[i], COL_TXT, nullptr, 1);
  }
  switch (detailTab) {
    case 0: drawSpecTab(); break;
    case 1: drawCieTab();  break;
    case 2: drawRgbTab();  break;
    default: drawLabTab(); break;
  }
  btn(rb(0), cur.saved ? COL_CARD2 : COL_BLUE, "Simpan", F9);
  btn(rb(1), COL_CARD2, "Ulangi", F9);
  btn(rb(2), COL_CARD2, "Set Ref", F9);
  btn(rb(3), COL_CARD2, "Kembali", F9);
}

// ======================= UI: RIWAYAT =======================
Rect histRow(int i) { return Rect{ 7, (int16_t)(34 + i * 42), 306, 38 }; }
const Rect R_H_UP = { 7, 204, 70, 30 };
const Rect R_H_DN = { 83, 204, 70, 30 };
const Rect R_H_DEL = { 213, 204, 100, 30 };

void histToResult(const HistEntry &e, Result &r) {
  memset(&r, 0, sizeof(r));
  for (int i = 0; i < 8; i++) r.R[i] = e.R[i];
  r.relative = e.relative;
  r.ts = e.ts;
  r.saved = true;
  computeResult(r);
}

void drawHistory() {
  tft.fillScreen(COL_BG);
  drawHeader("Riwayat Hasil", true);
  if (histCount == 0) {
    txt(160, 100, "Belum ada riwayat.", COL_MUTED, F9, 1, 1);
    txt(160, 120, "Simpan hasil dari layar Hasil.", COL_MUTED, nullptr, 1, 1);
  }
  for (int i = 0; i < 4; i++) {
    int idx = histScroll + i;
    if (idx >= histCount) break;
    Result r;
    histToResult(hist[idx], r);
    Rect rw = histRow(i);
    tft.fillRoundRect(rw.x, rw.y, rw.w, rw.h, 6, COL_CARD);
    tft.fillRoundRect(rw.x + 7, rw.y + 5, 28, 28, 5, RGB565(r.rr, r.gg, r.bb));
    char b[32];
    snprintf(b, sizeof(b), "#%02X%02X%02X%s", r.rr, r.gg, r.bb, r.relative ? " (rel)" : "");
    txt(rw.x + 44, rw.y + 4, b, COL_TXT, F9B);
    dateStr(r.ts, b, sizeof(b));
    txt(rw.x + 44, rw.y + 24, b, COL_MUTED, nullptr, 1);
    if (r.hasDE) {
      snprintf(b, sizeof(b), "dE %.2f", r.dE);
      txt(rw.x + 270, rw.y + 12, b, COL_TXT, F9, 1, 2);
    }
    tft.drawLine(rw.x + 286, rw.y + 14, rw.x + 292, rw.y + 19, COL_MUTED);
    tft.drawLine(rw.x + 292, rw.y + 19, rw.x + 286, rw.y + 24, COL_MUTED);
  }
  btn(R_H_UP, COL_CARD2, "Naik", F9);
  btn(R_H_DN, COL_CARD2, "Turun", F9);
  btn(R_H_DEL, COL_RED, "Hapus Semua", F9);
  char b[16];
  snprintf(b, sizeof(b), "%d/%d", histCount, HIST_MAX);
  txt(185, 212, b, COL_MUTED, nullptr, 1, 1);
}

// ======================= UI: KALIBRASI SENSOR =======================
const Rect R_CS_START = { 40, 184, 240, 34 };
const Rect R_CS_SAVE = { 60, 188, 200, 32 };
const Rect R_CS_FOOT = { 0, 220, 120, 20 };

void drawCalSens() {
  tft.fillScreen(COL_BG);
  drawHeader("Kalibrasi Sensor", true);
  const char* tn[3] = { "1. Dark", "2. White", "3. Finish" };
  for (int i = 0; i < 3; i++) {
    int x = 4 + i * 106;
    tft.fillRoundRect(x, 34, 100, 22, 11, calStep == i ? COL_BLUE : COL_CARD2);
    txtC(x + 50, 45, tn[i], COL_TXT, nullptr, 1);
  }

  if (calStep < 2) {
    card(110, 64, 100, 62);
    if (calStep == 0) {  // penutup gelap
      tft.fillRoundRect(130, 72, 60, 46, 8, RGB565(20, 24, 36));
      tft.drawRoundRect(130, 72, 60, 46, 8, COL_MUTED);
      tft.fillCircle(160, 95, 12, RGB565(5, 8, 14));
      tft.drawCircle(160, 95, 12, COL_MUTED);
      txt(160, 138, "Tutup sensor dengan", COL_TXT, F9, 1, 1);
      txt(160, 156, "penutup gelap (dark cover).", COL_TXT, F9, 1, 1);
    } else {             // referensi putih
      tft.fillRoundRect(130, 72, 60, 46, 8, RGB565(70, 82, 108));
      tft.fillRoundRect(140, 80, 40, 30, 5, RGB565(245, 248, 255));
      txt(160, 138, "Letakkan white reference", COL_TXT, F9, 1, 1);
      txt(160, 156, "(kertas putih) pada sensor.", COL_TXT, F9, 1, 1);
    }
    btn(R_CS_START, COL_BLUE, calStep == 0 ? "Mulai Kalibrasi Dark" : "Mulai Kalibrasi White", F9B);
    txt(10, 226, "Batal", COL_MUTED, F9);
    char b[8];
    snprintf(b, sizeof(b), "%d/3", calStep + 1);
    txt(312, 226, b, COL_MUTED, F9, 1, 2);
  } else {
    uint16_t c = wc.warn == 0 ? COL_GREEN : COL_ORANGE;
    if (wc.warn == 0) drawCheck(160, 86, 22, c);
    else drawWarnIcon(160, 86, 22, c);
    txt(160, 114, wc.warn == 0 ? "Kalibrasi Selesai" : "Kalibrasi Selesai (periksa)", COL_TXT, F9B, 1, 1);
    char b[40];
    snprintf(b, sizeof(b), "Offset : %.0f", wc.darkMean);   txt(100, 134, b, COL_TXT, F9);
    snprintf(b, sizeof(b), "Level  : %.0f", wc.whiteMean);  txt(100, 150, b, COL_TXT, F9);
    snprintf(b, sizeof(b), "Noise  : %.2f %%", wc.noise);   txt(100, 166, b, COL_TXT, F9);
    if (wc.warn == 1) txt(160, 176, "JENUH: turunkan Gain atau arus LED.", COL_ORANGE, nullptr, 1, 1);
    else if (wc.warn == 2) txt(160, 176, "Sinyal lemah: naikkan Gain / LED.", COL_ORANGE, nullptr, 1, 1);
    btn(R_CS_SAVE, COL_BLUE, "Simpan", F9B);
    txt(10, 226, "Kembali", COL_MUTED, F9);
    txt(312, 226, "3/3", COL_MUTED, F9, 1, 2);
  }
}

void calDark() {
  Spectrum sp;
  if (!runProcess("Kalibrasi Sensor", "Kalibrasi Dark...", false, 5, sp, nullptr)) {
    redrawScreen();
    if (!lastCancelled) toast("Gagal membaca sensor", COL_RED);
    return;
  }
  float m = 0;
  for (int i = 0; i < 8; i++) { wc.darkF[i] = sp.f[i]; m += sp.f[i]; }
  wc.darkClr = sp.clr;
  wc.darkNir = sp.nir;
  wc.darkMean = m / 8;
  wc.hasDark = 1;
  calStep = 1;
  redrawScreen();
}

void calWhite() {
  Spectrum sp;
  float series[10];
  if (!runProcess("Kalibrasi Sensor", "Kalibrasi White...", true, 5, sp, series)) {
    redrawScreen();
    if (!lastCancelled) toast("Gagal membaca sensor", COL_RED);
    return;
  }
  float m = 0, mx = 0;
  for (int i = 0; i < 8; i++) {
    float net = sp.f[i] - wc.darkF[i];
    wc.whiteNet[i] = net < 1 ? 1 : net;
    m += wc.whiteNet[i];
    mx = fmaxf(mx, sp.f[i]);
  }
  wc.whiteMean = m / 8;

  float mean = 0, var = 0;
  for (int i = 0; i < 5; i++) mean += series[i];
  mean /= 5;
  for (int i = 0; i < 5; i++) var += (series[i] - mean) * (series[i] - mean);
  var /= 4;
  wc.noise = mean > 0 ? sqrtf(var) / mean * 100.0f : 0;

  wc.warn = 0;
  if (mx >= 65000) wc.warn = 1;
  else if (wc.whiteMean < 1500) wc.warn = 2;

  wc.hasWhite = 1;
  wc.ledIdx = cfg.ledIdx;
  wc.gainIdx = cfg.gainIdx;
  calStep = 2;
  redrawScreen();
}

// ======================= WIFI: UTILITAS =======================
bool wifiUp() { return WiFi.status() == WL_CONNECTED; }

void wifiMsgBox(const char* msg) {
  tft.fillRoundRect(25, 100, 270, 40, 10, COL_CARD2);
  tft.drawRoundRect(25, 100, 270, 40, 10, COL_TXT);
  txtC(160, 120, msg, COL_TXT, F9);
}

// ======================= WIFI: PINDAI & SAMBUNG =======================
const int SCAN_MAX = 16;
char scanSsid[SCAN_MAX][33];
int8_t scanRssi[SCAN_MAX];
uint8_t scanEnc[SCAN_MAX];
int scanCount = 0, scanScroll = 0;

void doScan() {
  wifiMsgBox("Memindai jaringan...");
  int n = WiFi.scanNetworks(false, false);
  scanCount = 0;
  scanScroll = 0;
  for (int i = 0; i < n && scanCount < SCAN_MAX; i++) {
    String s = WiFi.SSID(i);
    if (s.length() == 0) continue;
    bool dup = false;
    for (int j = 0; j < scanCount; j++) {
      if (strcmp(scanSsid[j], s.c_str()) == 0) {
        dup = true;
        if (WiFi.RSSI(i) > scanRssi[j]) scanRssi[j] = WiFi.RSSI(i);
        break;
      }
    }
    if (dup) continue;
    strlcpy(scanSsid[scanCount], s.c_str(), sizeof(scanSsid[0]));
    scanRssi[scanCount] = WiFi.RSSI(i);
    scanEnc[scanCount] = (WiFi.encryptionType(i) != WIFI_AUTH_OPEN) ? 1 : 0;
    scanCount++;
  }
  WiFi.scanDelete();
}

void drawLock(int x, int y, uint16_t c) {
  tft.drawCircle(x + 4, y + 3, 3, c);
  tft.fillRect(x, y + 5, 9, 7, c);
}

const Rect R_W_SCAN   = { 7, 76, 148, 28 };
const Rect R_W_FORGET = { 165, 76, 148, 28 };
const Rect R_W_UP     = { 7, 212, 70, 26 };
const Rect R_W_DN     = { 83, 212, 70, 26 };
Rect wifiRow(int i) { return Rect{ 7, (int16_t)(108 + i * 26), 306, 24 }; }

void drawWifi() {
  tft.fillScreen(COL_BG);
  drawHeader("WiFi", true);

  card(7, 34, 306, 38);
  char b[56], s[40];
  if (wifiUp()) {
    fitStr(s, sizeof(s), WiFi.SSID().c_str(), 160, F9B);
    snprintf(b, sizeof(b), "Terhubung: %s", s);
    txt(15, 38, b, COL_GREEN, F9B);
    snprintf(b, sizeof(b), "IP %s   %d dBm", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    txt(15, 57, b, COL_MUTED, nullptr, 1);
  } else {
    txt(15, 38, "Tidak terhubung", COL_ORANGE, F9B);
    if (wifiSsid[0]) {
      fitStr(s, sizeof(s), wifiSsid, 200, nullptr);
      snprintf(b, sizeof(b), "Tersimpan: %s", s);
    } else {
      snprintf(b, sizeof(b), "Pilih jaringan, lalu masukkan password.");
    }
    txt(15, 57, b, COL_MUTED, nullptr, 1);
  }

  btn(R_W_SCAN, COL_BLUE, "Pindai Ulang", F9);
  btn(R_W_FORGET, COL_CARD2, "Putus / Lupakan", F9);

  if (scanCount == 0) {
    txt(160, 150, "Belum ada jaringan. Tekan Pindai.", COL_MUTED, F9, 1, 1);
  }
  for (int i = 0; i < 4; i++) {
    int idx = scanScroll + i;
    if (idx >= scanCount) break;
    Rect r = wifiRow(i);
    tft.fillRoundRect(r.x, r.y, r.w, r.h, 6, COL_CARD);
    bool curNet = wifiUp() && strcmp(WiFi.SSID().c_str(), scanSsid[idx]) == 0;
    fitStr(s, sizeof(s), scanSsid[idx], 225, F9);
    txt(r.x + 10, r.y + 5, s, curNet ? COL_GREEN : COL_TXT, F9);
    if (scanEnc[idx]) drawLock(r.x + 254, r.y + 6, COL_MUTED);
    drawBars(r.x + 276, r.y + 19, rssiLevel(scanRssi[idx]), COL_GREEN);
  }

  btn(R_W_UP, COL_CARD2, "Naik", F9);
  btn(R_W_DN, COL_CARD2, "Turun", F9);
  snprintf(b, sizeof(b), "%d jaringan", scanCount);
  txt(165, 221, b, COL_MUTED, nullptr, 1);
}

bool wifiConnectUi(const char* ssid, const char* pass) {
  waitRelease();
  tft.fillScreen(COL_BG);
  drawHeader("Menyambung WiFi", false);
  char s[40];
  fitStr(s, sizeof(s), ssid, 290, F9B);
  txt(160, 60, s, COL_TXT, F9B, 1, 1);
  txt(160, 84, "Menyambungkan...", COL_MUTED, F9, 1, 1);
  tft.drawRoundRect(60, 120, 200, 12, 6, COL_LINE);
  btn(R_CANCEL, COL_CARD2, "Batal", F9);

  WiFi.disconnect();
  delay(100);
  WiFi.begin(ssid, pass);
  lastCancelled = false;
  const uint32_t TMO = 20000;
  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < TMO) {
    delay(150);
    if (cancelPressed()) { lastCancelled = true; break; }
    uint32_t el = millis() - t0;
    if (el > TMO) el = TMO;
    int w = (int)(196UL * el / TMO);
    if (w > 0) tft.fillRect(62, 122, w, 8, COL_BLUE);
  }

  bool ok = (WiFi.status() == WL_CONNECTED);
  if (ok) {
    strlcpy(wifiSsid, ssid, sizeof(wifiSsid));
    strlcpy(wifiPass, pass, sizeof(wifiPass));
    prefs.putString("wssid", wifiSsid);
    prefs.putString("wpass", wifiPass);
  } else {
    WiFi.disconnect();
    if (wifiSsid[0]) WiFi.begin(wifiSsid, wifiPass);  // kembali ke jaringan lama
  }
  return ok;
}

void wifiConnectAndShow(const char* ssid, const char* pass) {
  bool ok = wifiConnectUi(ssid, pass);
  scr = S_WIFI;
  drawWifi();
  if (ok) toast("WiFi tersambung", COL_GREEN);
  else if (!lastCancelled) toast("Gagal: cek password / sinyal", COL_RED);
}

void wifiForget() {
  WiFi.disconnect();
  wifiSsid[0] = 0;
  wifiPass[0] = 0;
  prefs.remove("wssid");
  prefs.remove("wpass");
}

void wifiEnter() {
  scr = S_WIFI;
  drawWifi();
  if (scanCount == 0) {
    doScan();
    drawWifi();
  }
}

// ======================= KEYBOARD LAYAR =======================
char kbSsid[33];
char kbPass[64];
int  kbLen = 0;
int  kbMode = 0;      // 0 huruf kecil, 1 huruf besar, 2 simbol 1, 3 simbol 2
bool kbShow = false;

const char* const KB_ROWS[4][4] = {
  { "1234567890", "qwertyuiop", "asdfghjkl", "zxcvbnm" },
  { "1234567890", "QWERTYUIOP", "ASDFGHJKL", "ZXCVBNM" },
  { "1234567890", "!@#$%^&*()", "-_=+[]{};", ":'\"/?|\\" },
  { "1234567890", "~`<>", "", "" }
};

const Rect R_KB_BOX   = { 7, 34, 250, 36 };
const Rect R_KB_EYE   = { 262, 34, 51, 36 };
const Rect R_KB_SHIFT = { 2, 168, 44, 28 };
const Rect R_KB_BKSP  = { 274, 168, 44, 28 };
const Rect R_KB_MODE  = { 2, 198, 56, 28 };
const Rect R_KB_COMMA = { 60, 198, 30, 28 };
const Rect R_KB_SPACE = { 92, 198, 104, 28 };
const Rect R_KB_DOT   = { 198, 198, 30, 28 };
const Rect R_KB_OK    = { 230, 198, 88, 28 };

Rect kbKeyRect(int r, int i, int n) {
  int x0 = (r == 3) ? 50 : (SCREEN_W - n * 32) / 2;
  return Rect{ (int16_t)(x0 + i * 32 + 1), (int16_t)(78 + r * 30), 30, 28 };
}

void drawKey(const Rect &k, const char* label, uint16_t bg) {
  tft.fillRoundRect(k.x, k.y, k.w, k.h, 5, bg);
  tft.setFont(F9);
  tft.setTextSize(1);
  tft.setTextColor(COL_TXT);
  int16_t bx, by;
  uint16_t bw, bh;
  tft.getTextBounds(label, 0, 0, &bx, &by, &bw, &bh);
  tft.setCursor(k.x + (k.w - bw) / 2 - bx, k.y + 19);
  tft.print(label);
  tft.setFont(nullptr);
}

void drawKbInput() {
  tft.fillRoundRect(R_KB_BOX.x, R_KB_BOX.y, R_KB_BOX.w, R_KB_BOX.h, 6, COL_CARD);
  tft.drawRoundRect(R_KB_BOX.x, R_KB_BOX.y, R_KB_BOX.w, R_KB_BOX.h, 6, COL_LINE);
  char full[72];
  if (kbShow) {
    strlcpy(full, kbPass, sizeof(full));
  } else {
    for (int i = 0; i < kbLen; i++) full[i] = '*';
    full[kbLen] = 0;
  }
  const char* p = full;
  while (*p && textW(p, F9) > R_KB_BOX.w - 24) p++;
  tft.setFont(F9);
  tft.setTextSize(1);
  tft.setTextColor(COL_TXT);
  tft.setCursor(R_KB_BOX.x + 8, R_KB_BOX.y + 24);
  tft.print(p);
  tft.setFont(nullptr);
  int w = textW(p, F9);
  tft.fillRect(R_KB_BOX.x + 10 + w, R_KB_BOX.y + 8, 2, 20, COL_BLUE);  // kursor
}

void drawKeyboard() {
  tft.fillScreen(COL_BG);
  char s[24], t[40];
  fitStr(s, sizeof(s), kbSsid, 120, F9B);
  snprintf(t, sizeof(t), "Password: %s", s);
  drawHeader(t, true);
  drawKbInput();
  drawKey(R_KB_EYE, kbShow ? "Tutup" : "Lihat", COL_CARD2);
  for (int r = 0; r < 4; r++) {
    const char* str = KB_ROWS[kbMode][r];
    int n = strlen(str);
    for (int i = 0; i < n; i++) {
      char l[2] = { str[i], 0 };
      drawKey(kbKeyRect(r, i, n), l, COL_CARD2);
    }
  }
  const char* sl = kbMode < 2 ? "Shift" : (kbMode == 2 ? "1/2" : "2/2");
  drawKey(R_KB_SHIFT, sl, kbMode == 1 ? COL_BLUE : COL_CARD2);
  drawKey(R_KB_BKSP, "Del", COL_CARD2);
  drawKey(R_KB_MODE, kbMode < 2 ? "?123" : "ABC", COL_CARD2);
  drawKey(R_KB_COMMA, ",", COL_CARD2);
  drawKey(R_KB_SPACE, "spasi", COL_CARD2);
  drawKey(R_KB_DOT, ".", COL_CARD2);
  drawKey(R_KB_OK, "Sambung", COL_GREEN);
}

void kbOpenFor(const char* ssid) {
  strlcpy(kbSsid, ssid, sizeof(kbSsid));
  kbPass[0] = 0;
  kbLen = 0;
  kbMode = 0;
  kbShow = false;
  goScreen(S_KEYB);
}

void kbTap(int x, int y) {
  if (hit(R_BACK, x, y)) { scr = S_WIFI; drawWifi(); return; }
  if (hit(R_KB_EYE, x, y)) { kbShow = !kbShow; drawKeyboard(); return; }
  if (hit(R_KB_SHIFT, x, y)) {
    kbMode = (kbMode == 0) ? 1 : (kbMode == 1 ? 0 : (kbMode == 2 ? 3 : 2));
    drawKeyboard();
    return;
  }
  if (hit(R_KB_MODE, x, y)) { kbMode = (kbMode < 2) ? 2 : 0; drawKeyboard(); return; }
  if (hit(R_KB_BKSP, x, y)) {
    if (kbLen > 0) kbPass[--kbLen] = 0;
    drawKbInput();
    return;
  }
  if (hit(R_KB_OK, x, y)) {
    if (kbLen < 8) { toast("Password minimal 8 karakter", COL_ORANGE); return; }
    wifiConnectAndShow(kbSsid, kbPass);
    return;
  }
  char ch = 0;
  if (hit(R_KB_SPACE, x, y)) ch = ' ';
  else if (hit(R_KB_COMMA, x, y)) ch = ',';
  else if (hit(R_KB_DOT, x, y)) ch = '.';
  else {
    for (int r = 0; r < 4 && !ch; r++) {
      const char* str = KB_ROWS[kbMode][r];
      int n = strlen(str);
      for (int i = 0; i < n; i++) {
        if (hit(kbKeyRect(r, i, n), x, y)) { ch = str[i]; break; }
      }
    }
  }
  if (ch && kbLen < 63) {
    kbPass[kbLen++] = ch;
    kbPass[kbLen] = 0;
    drawKbInput();
  }
}

void wifiTap(int x, int y) {
  if (hit(R_BACK, x, y)) { goScreen(S_SETTINGS); return; }
  if (hit(R_W_SCAN, x, y)) { doScan(); drawWifi(); return; }
  if (hit(R_W_FORGET, x, y)) {
    wifiForget();
    drawWifi();
    toast("Jaringan dilupakan");
    return;
  }
  if (hit(R_W_UP, x, y)) { scanScroll = max(0, scanScroll - 4); drawWifi(); return; }
  if (hit(R_W_DN, x, y)) {
    if (scanScroll + 4 < scanCount) scanScroll += 4;
    drawWifi();
    return;
  }
  for (int i = 0; i < 4; i++) {
    int idx = scanScroll + i;
    if (idx < scanCount && hit(wifiRow(i), x, y)) {
      if (scanEnc[idx]) kbOpenFor(scanSsid[idx]);
      else wifiConnectAndShow(scanSsid[idx], "");
      return;
    }
  }
}

// ======================= OTA UPDATE (GitHub) =======================
int  otaState = 0;        // 0 belum dicek, 1 terbaru, 2 ada update, 3 galat
char otaLatest[24] = "";
char otaMsg[48] = "Tekan Cek Update";
int  otaLastPct = -1;

bool otaConfigured() { return strstr(OTA_BASE_URL, "USERNAME") == nullptr; }

long verNum(const char* s) {
  while (*s && (*s < '0' || *s > '9')) s++;
  int a = 0, b = 0, c = 0;
  sscanf(s, "%d.%d.%d", &a, &b, &c);
  return (long)a * 10000 + b * 100 + c;
}

void otaHttpErr(int code) {
  if (code < 0) snprintf(otaMsg, sizeof(otaMsg), "Koneksi ke server gagal");
  else snprintf(otaMsg, sizeof(otaMsg), "Server: HTTP %d", code);
}

bool otaFetchVersion() {
  WiFiClientSecure client;
  client.setInsecure();   // tanpa verifikasi sertifikat (lebih sederhana)
  HTTPClient http;
  http.setFollowRedirects(HTTPC_FORCE_FOLLOW_REDIRECTS);
  http.setTimeout(10000);
  String url = String(OTA_BASE_URL) + OTA_VER_FILE;
  if (!http.begin(client, url)) { snprintf(otaMsg, sizeof(otaMsg), "Gagal membuka URL"); return false; }
  int code = http.GET();
  if (code != HTTP_CODE_OK) { otaHttpErr(code); http.end(); return false; }
  String body = http.getString();
  http.end();
  body.trim();
  if (body.length() == 0 || body.length() > 20) {
    snprintf(otaMsg, sizeof(otaMsg), "Isi version.txt tidak valid");
    return false;
  }
  strlcpy(otaLatest, body.c_str(), sizeof(otaLatest));
  return true;
}

const Rect R_O_CHECK   = { 20, 150, 280, 36 };
const Rect R_O_INSTALL = { 20, 194, 280, 36 };

void drawOta() {
  tft.fillScreen(COL_BG);
  drawHeader("Update Firmware", true);
  card(7, 34, 306, 108);
  txt(18, 42, "Versi terpasang", COL_MUTED, F9);
  txt(302, 42, FW_VERSION, COL_TXT, F9B, 1, 2);
  txt(18, 64, "Versi terbaru", COL_MUTED, F9);
  txt(302, 64, otaLatest[0] ? otaLatest : "-", COL_TXT, F9B, 1, 2);
  tft.drawFastHLine(14, 86, 292, COL_LINE);
  uint16_t c = COL_MUTED;
  if (otaState == 1 || otaState == 2) c = COL_GREEN;
  else if (otaState == 3) c = COL_RED;
  char m[48];
  fitStr(m, sizeof(m), otaMsg, 285, F9);
  txt(160, 94, m, c, F9, 1, 1);
  char b[64], s[24];
  if (wifiUp()) {
    fitStr(s, sizeof(s), WiFi.SSID().c_str(), 150, nullptr);
    snprintf(b, sizeof(b), "WiFi: %s  (%d dBm)", s, WiFi.RSSI());
    txt(160, 122, b, COL_MUTED, nullptr, 1, 1);
  } else {
    txt(160, 122, "WiFi belum tersambung (Pengaturan > WiFi)", COL_ORANGE, nullptr, 1, 1);
  }
  btn(R_O_CHECK, COL_BLUE, "Cek Update", F9B);
  btn(R_O_INSTALL, otaState == 2 ? COL_GREEN : COL_CARD2, "Pasang Update", F9B);
}

void otaEnter() {
  scr = S_OTA;
  drawOta();
}

void otaCheck() {
  if (!wifiUp()) { toast("WiFi belum tersambung", COL_ORANGE); return; }
  if (!otaConfigured()) { toast("OTA_BASE_URL belum diatur", COL_ORANGE); return; }
  wifiMsgBox("Memeriksa versi terbaru...");
  if (!otaFetchVersion()) {
    otaState = 3;
  } else if (verNum(otaLatest) > verNum(FW_VERSION)) {
    otaState = 2;
    snprintf(otaMsg, sizeof(otaMsg), "Tersedia versi %s", otaLatest);
  } else {
    otaState = 1;
    snprintf(otaMsg, sizeof(otaMsg), "Firmware sudah terbaru");
  }
  drawOta();
}

void otaDrawBase() {
  tft.fillScreen(COL_BG);
  drawHeader("Update Firmware", false);
  txt(160, 52, "Mengunduh firmware...", COL_TXT, F9B, 1, 1);
  txt(160, 76, "Jangan matikan alat.", COL_MUTED, F9, 1, 1);
  tft.drawRoundRect(40, 116, 240, 16, 8, COL_LINE);
  btn(R_CANCEL, COL_CARD2, "Batal", F9);
  otaLastPct = -1;
}

void otaProg(int pct) {
  if (pct == otaLastPct) return;
  otaLastPct = pct;
  int w = 236 * pct / 100;
  if (w > 0) tft.fillRect(42, 118, w, 12, COL_BLUE);
  char b[8];
  snprintf(b, sizeof(b), "%d%%", pct);
  tft.fillRect(120, 140, 80, 28, COL_BG);
  txtC(160, 154, b, COL_TXT, F12B);
}

bool otaInstall() {
  waitRelease();
  otaDrawBase();
  lastCancelled = false;

  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  http.setFollowRedirects(HTTPC_FORCE_FOLLOW_REDIRECTS);
  http.setTimeout(15000);
  String url = String(OTA_BASE_URL) + OTA_BIN_FILE;
  if (!http.begin(client, url)) { snprintf(otaMsg, sizeof(otaMsg), "Gagal membuka URL"); return false; }
  int code = http.GET();
  if (code != HTTP_CODE_OK) { otaHttpErr(code); http.end(); return false; }
  int total = http.getSize();
  if (total <= 0) { snprintf(otaMsg, sizeof(otaMsg), "Ukuran file tidak diketahui"); http.end(); return false; }
  if (!Update.begin(total)) { snprintf(otaMsg, sizeof(otaMsg), "Ruang OTA tidak cukup"); http.end(); return false; }

  WiFiClient* stream = http.getStreamPtr();
  static uint8_t buf[1024];
  int written = 0;
  uint32_t lastData = millis(), lastTouch = 0;
  bool ok = true;

  while (written < total) {
    int avail = stream->available();
    if (avail > 0) {
      int want = avail > (int)sizeof(buf) ? (int)sizeof(buf) : avail;
      int rd = stream->readBytes(buf, want);
      if (rd > 0) {
        if (Update.write(buf, rd) != (size_t)rd) {
          snprintf(otaMsg, sizeof(otaMsg), "Gagal menulis flash");
          ok = false;
          break;
        }
        written += rd;
        lastData = millis();
        otaProg((int)((long long)written * 100 / total));
      }
    } else {
      if (!http.connected()) { snprintf(otaMsg, sizeof(otaMsg), "Koneksi terputus"); ok = false; break; }
      if (millis() - lastData > 15000) { snprintf(otaMsg, sizeof(otaMsg), "Timeout unduhan"); ok = false; break; }
      delay(2);
    }
    if (millis() - lastTouch > 150) {
      lastTouch = millis();
      if (cancelPressed()) {
        lastCancelled = true;
        snprintf(otaMsg, sizeof(otaMsg), "Dibatalkan. Versi %s tersedia", otaLatest);
        ok = false;
        break;
      }
    }
  }
  http.end();
  if (!ok) { Update.abort(); return false; }
  if (!Update.end(true)) { snprintf(otaMsg, sizeof(otaMsg), "Verifikasi firmware gagal"); return false; }
  return true;
}

void otaRun() {
  if (otaState != 2) { toast("Cek update dulu", COL_ORANGE); return; }
  if (!wifiUp()) { toast("WiFi terputus", COL_ORANGE); return; }
  bool ok = otaInstall();
  if (ok) {
    tft.fillScreen(COL_BG);
    drawCheck(160, 80, 26, COL_GREEN);
    txt(160, 126, "Update berhasil", COL_TXT, F9B, 1, 1);
    txt(160, 148, "Alat akan restart...", COL_MUTED, F9, 1, 1);
    delay(2000);
    ESP.restart();
  } else {
    otaState = lastCancelled ? 2 : 3;
    scr = S_OTA;
    drawOta();
  }
}

void otaTap(int x, int y) {
  if (hit(R_BACK, x, y)) { goScreen(S_SETTINGS); return; }
  if (hit(R_O_CHECK, x, y)) { otaCheck(); return; }
  if (hit(R_O_INSTALL, x, y)) { otaRun(); return; }
}

// ======================= UI: PENGATURAN =======================
const int SET_COUNT = 9;
const int SET_PAGE_ROWS = 4;
Rect setRow(int i) { return Rect{ 8, (int16_t)(34 + i * 42), 292, 38 }; }
const Rect R_SET_UP = { 8, 204, 76, 30 };
const Rect R_SET_DN = { 224, 204, 76, 30 };
const Rect R_PICKER_MODAL = { 18, 22, 284, 196 };
const Rect R_PICKER_CANCEL = { 80, 164, 160, 30 };

Rect pickerOptRect(int type, int j) {
  if (type == 1 || type == 2) {
    if (type == 2 && j == 4) {
      return Rect{ 97, 126, 126, 28 };
    }
    int col = j % 2;
    int row = j / 2;
    int16_t x = (col == 0) ? 28 : 166;
    int16_t y = 58 + row * 34;
    return Rect{ x, y, 126, 28 };
  } else {
    int col = j % 2;
    int row = j / 2;
    int16_t x = (col == 0) ? 28 : 166;
    int16_t y = 64 + row * 46;
    return Rect{ x, y, 126, 38 };
  }
}

void drawPickerModal() {
  // Modal background box
  tft.fillRoundRect(R_PICKER_MODAL.x, R_PICKER_MODAL.y, R_PICKER_MODAL.w, R_PICKER_MODAL.h, 10, COL_CARD2);
  tft.drawRoundRect(R_PICKER_MODAL.x, R_PICKER_MODAL.y, R_PICKER_MODAL.w, R_PICKER_MODAL.h, 10, COL_BLUE);
  tft.drawRoundRect(R_PICKER_MODAL.x + 1, R_PICKER_MODAL.y + 1, R_PICKER_MODAL.w - 2, R_PICKER_MODAL.h - 2, 9, COL_BLUE);

  // Modal header
  tft.fillRoundRect(R_PICKER_MODAL.x + 4, R_PICKER_MODAL.y + 4, R_PICKER_MODAL.w - 8, 26, 6, COL_BG);
  const char* title = "";
  if (pickerType == 1) title = "Pilih Arus LED";
  else if (pickerType == 2) title = "Pilih Gain Sensor";
  else if (pickerType == 3) title = "Pilih Sampel";
  else if (pickerType == 4) title = "Pilih Kecerahan";
  txtC(160, 39, title, COL_TXT, F9B);

  // Options
  if (pickerType == 1) {
    for (int j = 0; j < 6; j++) {
      Rect r = pickerOptRect(1, j);
      bool sel = (cfg.ledIdx == j);
      tft.fillRoundRect(r.x, r.y, r.w, r.h, 6, sel ? COL_BLUE : COL_CARD);
      tft.drawRoundRect(r.x, r.y, r.w, r.h, 6, sel ? COL_TXT : COL_LINE);
      char buf[16];
      snprintf(buf, sizeof(buf), "%d mA", LED_OPTS[j]);
      txtC(r.x + r.w / 2, r.y + r.h / 2, buf, COL_TXT, sel ? F9B : F9);
    }
  } else if (pickerType == 2) {
    for (int j = 0; j < 5; j++) {
      Rect r = pickerOptRect(2, j);
      bool sel = (cfg.gainIdx == j);
      tft.fillRoundRect(r.x, r.y, r.w, r.h, 6, sel ? COL_BLUE : COL_CARD);
      tft.drawRoundRect(r.x, r.y, r.w, r.h, 6, sel ? COL_TXT : COL_LINE);
      txtC(r.x + r.w / 2, r.y + r.h / 2, GAIN_TXT[j], COL_TXT, sel ? F9B : F9);
    }
  } else if (pickerType == 3) {
    const char* nLabels[4] = { "1 (Cepat)", "3 (Normal)", "5 (Standar)", "10 (Akurat)" };
    for (int j = 0; j < 4; j++) {
      Rect r = pickerOptRect(3, j);
      bool sel = (cfg.nIdx == j);
      tft.fillRoundRect(r.x, r.y, r.w, r.h, 6, sel ? COL_BLUE : COL_CARD);
      tft.drawRoundRect(r.x, r.y, r.w, r.h, 6, sel ? COL_TXT : COL_LINE);
      txtC(r.x + r.w / 2, r.y + r.h / 2, nLabels[j], COL_TXT, sel ? F9B : F9);
    }
  } else if (pickerType == 4) {
    const char* blLabels[4] = { "25% (Hemat)", "50% (Sedang)", "75% (Terang)", "100% (Maks)" };
    for (int j = 0; j < 4; j++) {
      Rect r = pickerOptRect(4, j);
      bool sel = (cfg.blIdx == j);
      tft.fillRoundRect(r.x, r.y, r.w, r.h, 6, sel ? COL_BLUE : COL_CARD);
      tft.drawRoundRect(r.x, r.y, r.w, r.h, 6, sel ? COL_TXT : COL_LINE);
      txtC(r.x + r.w / 2, r.y + r.h / 2, blLabels[j], COL_TXT, sel ? F9B : F9);
    }
  }

  // Tombol Batal
  btn(R_PICKER_CANCEL, COL_CARD, "Batal", F9, COL_TXT);
  tft.drawRoundRect(R_PICKER_CANCEL.x, R_PICKER_CANCEL.y, R_PICKER_CANCEL.w, R_PICKER_CANCEL.h, 8, COL_LINE);
}

void drawSettings() {
  tft.fillScreen(COL_BG);
  drawHeader("Pengaturan", true);
  const char* lb[9] = { "Arus LED sensor", "Gain sensor", "Jumlah sampel", "Kecerahan layar",
                        "Kalibrasi Baterai", "Tanggal & Waktu", "WiFi", "Update Firmware (OTA)", "Reset Pabrik" };
  char v[9][34];
  snprintf(v[0], 20, "%d mA", LED_OPTS[cfg.ledIdx]);
  snprintf(v[1], 20, "%s", GAIN_TXT[cfg.gainIdx]);
  snprintf(v[2], 20, "%d", N_OPTS[cfg.nIdx]);
#if PIN_BL >= 0
  snprintf(v[3], 20, "%d%%", BL_OPTS[cfg.blIdx]);
#else
  snprintf(v[3], 20, "tetap");
#endif
  snprintf(v[4], 20, "%.2fV (%d%%)", batVoltage, batPercent);

  char tb[20];
  time_t t = time(nullptr);
  struct tm tmv;
  localtime_r(&t, &tmv);
  snprintf(tb, sizeof(tb), "%02d-%02d-%04d", tmv.tm_mday, tmv.tm_mon + 1, tmv.tm_year + 1900);
  snprintf(v[5], 34, "%s (WIT)", tb);
  if (wifiUp()) fitStr(v[6], 34, WiFi.SSID().c_str(), 110, F9);
  else snprintf(v[6], 34, "Tidak terhubung");
  snprintf(v[7], 34, "%s", FW_VERSION);
  v[8][0] = 0;

  // Render 4 baris yang sedang aktif sesuai scroll
  for (int i = 0; i < SET_PAGE_ROWS; i++) {
    int idx = setScroll + i;
    if (idx >= SET_COUNT) break;
    Rect r = setRow(i);
    tft.fillRoundRect(r.x, r.y, r.w, r.h, 8, COL_CARD);
    tft.drawRoundRect(r.x, r.y, r.w, r.h, 8, COL_LINE);
    txt(r.x + 12, r.y + 11, lb[idx], idx == 8 ? COL_RED : COL_TXT, F9);
    if (v[idx][0]) {
      uint16_t valCol = (idx <= 3) ? COL_BLUE : COL_MUTED;
      txt(r.x + 264, r.y + 11, v[idx], valCol, F9, 1, 2);
    }
    // Chevron >
    tft.drawLine(r.x + 276, r.y + 13, r.x + 282, r.y + 19, COL_MUTED);
    tft.drawLine(r.x + 282, r.y + 19, r.x + 276, r.y + 25, COL_MUTED);
  }

  // Indikator Scrollbar di sisi kanan
  tft.fillRoundRect(304, 34, 8, 164, 4, COL_CARD);
  tft.drawRoundRect(304, 34, 8, 164, 4, COL_LINE);
  int thumbH = 44;
  int thumbY = 34 + (setScroll * (164 - thumbH)) / (SET_COUNT - SET_PAGE_ROWS);
  tft.fillRoundRect(305, thumbY, 6, thumbH, 3, COL_BLUE);

  // Tombol navigasi bawah (Naik / Turun)
  btn(R_SET_UP, setScroll > 0 ? COL_CARD2 : COL_CARD, "Naik", F9, setScroll > 0 ? COL_TXT : COL_MUTED);
  if (setScroll == 0) tft.drawRoundRect(R_SET_UP.x, R_SET_UP.y, R_SET_UP.w, R_SET_UP.h, 8, COL_LINE);

  btn(R_SET_DN, setScroll < (SET_COUNT - SET_PAGE_ROWS) ? COL_CARD2 : COL_CARD, "Turun", F9,
      setScroll < (SET_COUNT - SET_PAGE_ROWS) ? COL_TXT : COL_MUTED);
  if (setScroll >= (SET_COUNT - SET_PAGE_ROWS)) {
    tft.drawRoundRect(R_SET_DN.x, R_SET_DN.y, R_SET_DN.w, R_SET_DN.h, 8, COL_LINE);
  }

  // Teks indikator jumlah item
  char ind[24];
  snprintf(ind, sizeof(ind), "%d-%d dari 9", setScroll + 1, min(9, setScroll + 4));
  txtC(150, 219, ind, COL_MUTED, F9);

  // Jika modal picker sedang aktif, tampilkan di atas
  if (pickerType != 0) {
    drawPickerModal();
  }
}

// ======================= UI: KALIBRASI BATERAI =======================
const Rect R_BC_FULL_M = { 170, 94, 38, 26 };
const Rect R_BC_FULL_P = { 260, 94, 38, 26 };
const Rect R_BC_LOCK   = { 16, 126, 288, 24 };
const Rect R_BC_CAL_M  = { 170, 156, 38, 26 };
const Rect R_BC_CAL_P  = { 260, 156, 38, 26 };
const Rect R_BC_SAVE   = { 20, 196, 280, 36 };

void drawBatCal() {
  tft.fillScreen(COL_BG);
  drawHeader("Kalibrasi Baterai", true);

  // Kartu 1: Status Terkini
  card(7, 32, 306, 54);
  txt(16, 38, "Voltase Terbaca:", COL_MUTED, F9);
  char bv[16];
  snprintf(bv, sizeof(bv), "%.2f V", batVoltage);
  txt(150, 38, bv, COL_TXT, F9B);

  const char* st = batCharging ? (batPercent >= 99 ? "[Penuh]" : "[Di-cas]") : "[Baterai]";
  uint16_t sc = batCharging ? COL_YELLOW : COL_GREEN;
  txt(300, 38, st, sc, F9, 1, 2);

  txt(16, 60, "Kapasitas Terhitung:", COL_MUTED, nullptr);
  char bp[16];
  snprintf(bp, sizeof(bp), "%d%%", batPercent);
  txt(150, 60, bp, COL_GREEN, F9B);

  // Kartu 2: Pengaturan Tegangan Penuh (100%)
  card(7, 90, 306, 64);
  txt(16, 98, "Penuh (100%):", COL_TXT, F9);
  btn(R_BC_FULL_M, COL_CARD2, "-", F9B);
  char vf[12];
  snprintf(vf, sizeof(vf), "%.2fV", batCfg.vFull);
  txtC(234, 107, vf, COL_YELLOW, F9B);
  btn(R_BC_FULL_P, COL_CARD2, "+", F9B);

  btn(R_BC_LOCK, COL_BLUE, "Kunci Voltase Sekarang = 100%", nullptr);

  // Kartu 3: Kalibrasi Pengali ADC Multimeter
  card(7, 158, 306, 34);
  txt(16, 166, "Faktor ADC (Multimeter):", COL_TXT, nullptr);
  btn(R_BC_CAL_M, COL_CARD2, "-", F9B);
  char cr[12];
  snprintf(cr, sizeof(cr), "%.2f", batCfg.calRatio);
  txtC(234, 169, cr, COL_TXT, F9B);
  btn(R_BC_CAL_P, COL_CARD2, "+", F9B);

  btn(R_BC_SAVE, COL_GREEN, "Simpan Kalibrasi", F9B);
}

void batCalTap(int x, int y) {
  if (hit(R_BACK, x, y)) { goScreen(S_SETTINGS); return; }

  // Kurangi VFull
  if (hit(R_BC_FULL_M, x, y)) {
    batCfg.vFull -= 0.02f;
    if (batCfg.vFull < 3.70f) batCfg.vFull = 3.70f;
    batPercent = calcBatPercent(batVoltage);
    drawBatCal();
    return;
  }
  // Tambah VFull
  if (hit(R_BC_FULL_P, x, y)) {
    batCfg.vFull += 0.02f;
    if (batCfg.vFull > 4.30f) batCfg.vFull = 4.30f;
    batPercent = calcBatPercent(batVoltage);
    drawBatCal();
    return;
  }
  // Kunci tegangan saat ini sebagai 100% (Instant 1-Touch Calibration!)
  if (hit(R_BC_LOCK, x, y)) {
    readBatterySensors(true);
    batCfg.vFull = batVoltage;
    batPercent = 100;
    batPercentFloat = 100.0f;
    saveBatCfg();
    drawBatCal();
    toast("Terkunci: Baterai ini = 100%", COL_GREEN);
    return;
  }
  // Kurangi faktor ADC
  if (hit(R_BC_CAL_M, x, y)) {
    batCfg.calRatio -= 0.02f;
    if (batCfg.calRatio < 1.50f) batCfg.calRatio = 1.50f;
    readBatterySensors(true);
    drawBatCal();
    return;
  }
  // Tambah faktor ADC
  if (hit(R_BC_CAL_P, x, y)) {
    batCfg.calRatio += 0.02f;
    if (batCfg.calRatio > 2.60f) batCfg.calRatio = 2.60f;
    readBatterySensors(true);
    drawBatCal();
    return;
  }
  // Simpan
  if (hit(R_BC_SAVE, x, y)) {
    saveBatCfg();
    readBatterySensors(true);
    toast("Kalibrasi Baterai Tersimpan", COL_GREEN);
    goScreen(S_SETTINGS);
    return;
  }
}

// ======================= UI: WAKTU =======================
const Rect R_T_CANCEL = { 20, 196, 130, 36 };
const Rect R_T_SAVE = { 170, 196, 130, 36 };

int timeField(int i) {
  switch (i) {
    case 0: return editTm.tm_year + 1900;
    case 1: return editTm.tm_mon + 1;
    case 2: return editTm.tm_mday;
    case 3: return editTm.tm_hour;
    default: return editTm.tm_min;
  }
}

Rect timeMinus(int i) { return Rect{ 170, (int16_t)(36 + i * 31), 44, 27 }; }
Rect timePlus(int i)  { return Rect{ 262, (int16_t)(36 + i * 31), 44, 27 }; }

void drawTimeEdit() {
  tft.fillScreen(COL_BG);
  drawHeader("Tanggal & Waktu", true);
  const char* lb[5] = { "Tahun", "Bulan", "Tanggal", "Jam", "Menit" };
  for (int i = 0; i < 5; i++) {
    int y = 36 + i * 31;
    tft.fillRoundRect(7, y, 306, 27, 6, COL_CARD);
    txt(16, y + 6, lb[i], COL_TXT, F9);
    btn(timeMinus(i), COL_CARD2, "-", F9B);
    btn(timePlus(i), COL_CARD2, "+", F9B);
    char b[8];
    snprintf(b, sizeof(b), i == 0 ? "%d" : "%02d", timeField(i));
    txtC(238, y + 13, b, COL_TXT, F9B);
  }
  btn(R_T_CANCEL, COL_CARD2, "Batal", F9);
  btn(R_T_SAVE, COL_BLUE, "Simpan", F9B);
}

void timeAdjust(int i, int d) {
  int y = editTm.tm_year + 1900, mo = editTm.tm_mon, dd = editTm.tm_mday;
  switch (i) {
    case 0: y = constrain(y + d, 2024, 2099); break;
    case 1: mo = (mo + d + 12) % 12; break;
    case 2: dd += d; break;
    case 3: editTm.tm_hour = (editTm.tm_hour + d + 24) % 24; break;
    case 4: editTm.tm_min = (editTm.tm_min + d + 60) % 60; break;
  }
  int dim = daysInMonth(y, mo);
  if (i == 2) { if (dd < 1) dd = dim; if (dd > dim) dd = 1; }
  if (dd > dim) dd = dim;
  editTm.tm_year = y - 1900;
  editTm.tm_mon = mo;
  editTm.tm_mday = dd;
}

// ======================= UI: TENTANG & KONFIRMASI =======================
void drawAbout() {
  tft.fillScreen(COL_BG);
  drawHeader("Tentang", true);
  drawLogo(160, 52, 12);
  txt(160, 80, "Colorimetric Analyzer", COL_TXT, F9B, 1, 1);
  card(40, 102, 240, 108);
  const char* k[6] = { "Hardware", "Firmware", "Sensor", "Display", "MCU", "Dibuat oleh" };
  const char* v[6] = { "v1.0", FW_VERSION, "AS7341", "ILI9341 (3.2\")", "ESP32-S2", "Mushawir " };
  for (int i = 0; i < 6; i++) {
    int y = 108 + i * 16;
    txt(54, y, k[i], COL_MUTED, F9);
    txt(266, y, v[i], COL_TXT, F9, 1, 2);
  }
  txt(160, 220, "(c) 2026 Shawir", COL_MUTED, nullptr, 1, 1);
}

const Rect R_CF_NO = { 30, 170, 120, 38 };
const Rect R_CF_YES = { 170, 170, 120, 38 };

void drawConfirm() {
  tft.fillScreen(COL_BG);
  drawHeader(confirmAction == 0 ? "Reset Pabrik" : "Hapus Riwayat", false);
  drawWarnIcon(160, 78, 22, COL_ORANGE);
  if (confirmAction == 0) {
    txt(160, 112, "Hapus semua data?", COL_TXT, F9B, 1, 1);
    txt(160, 134, "Kalibrasi, riwayat, dan pengaturan", COL_MUTED, F9, 1, 1);
    txt(160, 150, "akan dihapus. Alat restart.", COL_MUTED, F9, 1, 1);
  } else {
    txt(160, 112, "Hapus semua riwayat?", COL_TXT, F9B, 1, 1);
    txt(160, 134, "Tindakan ini tidak bisa dibatalkan.", COL_MUTED, F9, 1, 1);
  }
  btn(R_CF_NO, COL_CARD2, "Batal", F9);
  btn(R_CF_YES, COL_RED, "Ya, hapus", F9B);
}

// ======================= DISPATCH GAMBAR =======================
void redrawScreen() {
  switch (scr) {
    case S_HOME:     drawHome(); break;
    case S_SAMPLE:   drawSample(); break;
    case S_RESULT:   drawResult(); break;
    case S_DETAIL:   drawDetail(); break;
    case S_HISTORY:  drawHistory(); break;
    case S_CALSENS:  drawCalSens(); break;
    case S_SETTINGS: drawSettings(); break;
    case S_TIME:     drawTimeEdit(); break;
    case S_ABOUT:    drawAbout(); break;
    case S_CONFIRM:  drawConfirm(); break;
    case S_WIFI:     drawWifi(); break;
    case S_KEYB:     drawKeyboard(); break;
    case S_OTA:      drawOta(); break;
    case S_BATCAL:   drawBatCal(); break;
    default: break;
  }
}

void goScreen(Screen s) {
  if (s == S_SETTINGS) pickerType = 0;
  scr = s;
  redrawScreen();
}

// ======================= KALIBRASI TOUCH =======================
void drawTouchTarget(int i, bool canCancel) {
  tft.fillScreen(COL_BG);
  txt(160, 14, "Kalibrasi Touch", COL_TXT, F9B, 1, 1);
  txt(160, 62, "Sentuh titik yang ditampilkan", COL_MUTED, F9, 1, 1);
  txt(160, 78, "secara berurutan.", COL_MUTED, F9, 1, 1);
  char b[8];
  snprintf(b, sizeof(b), "%d/5", i + 1);
  txt(160, 160, b, COL_TXT, F9, 1, 1);
  if (canCancel) txt(160, 212, "Tekan BOOT untuk batal", COL_MUTED, nullptr, 1, 1);

  int x = TPX[i], y = TPY[i];
  tft.drawCircle(x, y, 14, COL_BLUE);
  tft.drawCircle(x, y, 13, COL_BLUE);
  tft.drawFastHLine(x - 9, y, 19, COL_TXT);
  tft.drawFastVLine(x, y - 9, 19, COL_TXT);
}

float touchErrPx = 0;

bool runTouchCal(bool canCancel) {
  while (true) {
    int rawX[5], rawY[5];
    for (int i = 0; i < 5; i++) {
      drawTouchTarget(i, canCancel);
      int rx, ry;
      while (true) {
        if (canCancel && digitalRead(PIN_BOOT) == LOW) {
          while (digitalRead(PIN_BOOT) == LOW) delay(10);
          return false;
        }
        if (getRaw(rx, ry)) break;
        delay(10);
      }
      delay(150);
      long sx = 0, sy = 0;
      int n = 0;
      for (int k = 0; k < 25; k++) {
        if (getRaw(rx, ry)) { sx += rx; sy += ry; n++; }
        delay(10);
      }
      while (ts.touched()) delay(10);
      delay(300);
      if (n < 8) { i--; continue; }
      rawX[i] = sx / n;
      rawY[i] = sy / n;
    }

    int dX = abs(rawX[1] - rawX[0]);
    int dY = abs(rawY[1] - rawY[0]);
    bool swapXY = dY > dX;
    int a[5], b[5];
    for (int i = 0; i < 5; i++) {
      a[i] = swapXY ? rawY[i] : rawX[i];
      b[i] = swapXY ? rawX[i] : rawY[i];
    }
    float aL = (a[0] + a[3]) / 2.0f, aR = (a[1] + a[2]) / 2.0f;
    float bT = (b[0] + b[1]) / 2.0f, bB = (b[2] + b[3]) / 2.0f;

    if (fabsf(aR - aL) < 800 || fabsf(bB - bT) < 800) {
      tft.fillScreen(COL_BG);
      txt(160, 108, "Gagal, ulangi kalibrasi...", COL_RED, F9B, 1, 1);
      delay(1500);
      continue;
    }

    tcal.aL = aL; tcal.aR = aR; tcal.bT = bT; tcal.bB = bB;
    tcal.swapXY = swapXY;
    tcal.valid = 1;
    saveTcal();

    // Uji titik tengah
    int cxp, cyp;
    mapTouch(rawX[4], rawY[4], cxp, cyp);
    float ex = cxp - SCREEN_W / 2, ey = cyp - SCREEN_H / 2;
    touchErrPx = sqrtf(ex * ex + ey * ey);
    return true;
  }
}

void touchCalFinishScreen() {
  tft.fillScreen(COL_BG);
  txt(160, 12, "Kalibrasi Touch", COL_TXT, F9B, 1, 1);
  bool good = touchErrPx < 25;
  if (good) drawCheck(160, 74, 26, COL_GREEN);
  else drawWarnIcon(160, 74, 26, COL_ORANGE);
  txt(160, 118, "Kalibrasi Touch Selesai", COL_TXT, F9B, 1, 1);
  char b[48];
  if (good) snprintf(b, sizeof(b), "Semua titik terdeteksi dengan baik.");
  else snprintf(b, sizeof(b), "Galat titik tengah %.0f px. Ulangi bila perlu.", touchErrPx);
  txt(160, 142, b, good ? COL_MUTED : COL_ORANGE, nullptr, 1, 1);
  Rect ok = { 60, 176, 200, 40 };
  btn(ok, COL_BLUE, "Selesai", F9B);
  waitRelease();
  while (true) {
    int x, y;
    if (readTouch(x, y) && hit(ok, x, y)) break;
    delay(10);
  }
  waitRelease();
}

// ======================= PENGIRIMAN WEB API & TELEMETRI =======================
void sendWebPing() {
  if (WiFi.status() != WL_CONNECTED || webApiUrl[0] == 0) return;
  HTTPClient http;
  if (!http.begin(webApiUrl)) return;
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-KEY", webApiKey);
  http.setTimeout(4000);

  char payload[256];
  snprintf(payload, sizeof(payload),
    "{\"action\":\"ping\",\"device_id\":\"ESP32S2_COLORIMETER\","
    "\"bat_v\":%.2f,\"bat_pct\":%d,\"charging\":%d,\"rssi\":%d,"
    "\"fw_version\":\"%s\",\"api_key\":\"%s\"}",
    batVoltage, batPercent, batCharging ? 1 : 0, WiFi.RSSI(),
    FW_VERSION, webApiKey
  );

  http.POST(payload);
  http.end();
}

bool uploadResultToWeb(const Result &r) {
  if (WiFi.status() != WL_CONNECTED || webApiUrl[0] == 0) return false;

  HTTPClient http;
  if (!http.begin(webApiUrl)) return false;
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-KEY", webApiKey);
  http.setTimeout(5000);

  char payload[640];
  char dateBuf[32];
  dateStr(r.ts, dateBuf, sizeof(dateBuf));

  snprintf(payload, sizeof(payload),
    "{\"sample_name\":\"Sampel %s\","
    "\"hex\":\"#%02X%02X%02X\","
    "\"r\":%d,\"g\":%d,\"b\":%d,"
    "\"cie_x\":%.4f,\"cie_y\":%.4f,\"cie_z\":%.4f,"
    "\"lab_l\":%.2f,\"lab_a\":%.2f,\"lab_b\":%.2f,"
    "\"de\":%.2f,\"is_relative\":%d,"
    "\"spectrum\":[%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f,%.2f],"
    "\"nir\":%.2f,\"clear\":%.2f,"
    "\"led_ma\":%d,\"gain\":\"%s\",\"samples\":%d,"
    "\"device_id\":\"ESP32S2_COLORIMETER\","
    "\"bat_v\":%.2f,\"bat_pct\":%d,\"charging\":%d,\"rssi\":%d,"
    "\"api_key\":\"%s\"}",
    dateBuf,
    r.rr, r.gg, r.bb,
    r.rr, r.gg, r.bb,
    r.cx, r.cy, (1.0f - r.cx - r.cy),
    r.L, r.a, r.b,
    r.hasDE ? r.dE : 0.0f, r.relative ? 1 : 0,
    r.R[0], r.R[1], r.R[2], r.R[3], r.R[4], r.R[5], r.R[6], r.R[7],
    r.nir, r.clr,
    LED_OPTS[cfg.ledIdx], GAIN_TXT[cfg.gainIdx], N_OPTS[cfg.nIdx],
    batVoltage, batPercent, batCharging ? 1 : 0, WiFi.RSSI(),
    webApiKey
  );

  int code = http.POST(payload);
  http.end();
  return (code >= 200 && code < 300);
}

// ======================= AKSI =======================
void saveCurrent() {
  if (!hasCur) return;
  if (cur.saved) { toast("Sudah tersimpan"); return; }
  int n = histCount < HIST_MAX ? histCount : HIST_MAX - 1;
  for (int i = n; i > 0; i--) hist[i] = hist[i - 1];
  memset(&hist[0], 0, sizeof(HistEntry));
  hist[0].ts = cur.ts;
  hist[0].relative = cur.relative ? 1 : 0;
  for (int i = 0; i < 8; i++) hist[0].R[i] = cur.R[i];
  histCount = n + 1;
  saveHist();
  saveEpoch();
  cur.saved = true;

  bool webOk = false;
  if (wifiUp()) {
    webOk = uploadResultToWeb(cur);
  }

  if (webOk) {
    toast("Tersimpan & Diunggah ke Web!", COL_GREEN);
  } else {
    toast("Tersimpan ke riwayat", COL_GREEN);
  }
}

void setReference() {
  if (!hasCur || cur.relative) { toast("Referensi hanya untuk mode Sample"); return; }
  refc.L = cur.L; refc.a = cur.a; refc.b = cur.b;
  refc.r = cur.rr; refc.g = cur.gg; refc.bl = cur.bb;
  refc.valid = 1;
  saveRef();
  computeResult(cur);
  toast("Referensi warna diatur", COL_GREEN);
}

void startAnalysis() {
  if (!sensorOk) { toast("Sensor AS7341 tidak terdeteksi", COL_RED); return; }
  if (sampleMode == 0 && !calValid()) {
    memset(&wc, 0, sizeof(wc));
    calStep = 0;
    goScreen(S_CALSENS);
    toast("Kalibrasi sensor diperlukan", COL_ORANGE);
    return;
  }
  Spectrum sp;
  scr = S_PROCESS;
  bool ok = runProcess("Analisis Warna", "Mengambil data warna...", sampleMode == 0,
                       N_OPTS[cfg.nIdx], sp, nullptr);
  if (!ok) {
    goScreen(S_SAMPLE);
    if (!lastCancelled) toast("Gagal membaca sensor", COL_RED);
    return;
  }
  memset(&cur, 0, sizeof(cur));
  cur.relative = (sampleMode == 1);
  spectrumToR(sp, cur.R, cur.relative);
  cur.ts = (uint32_t)time(nullptr);
  cur.clr = fmaxf(0, sp.clr - (sc.hasDark ? sc.darkClr : 0));
  cur.nir = fmaxf(0, sp.nir - (sc.hasDark ? sc.darkNir : 0));
  cur.saved = false;
  computeResult(cur);
  hasCur = true;
  Serial.printf("R: %.3f %.3f %.3f %.3f %.3f %.3f %.3f %.3f | RGB %d,%d,%d | Lab %.1f %.1f %.1f\n",
                cur.R[0], cur.R[1], cur.R[2], cur.R[3], cur.R[4], cur.R[5], cur.R[6], cur.R[7],
                cur.rr, cur.gg, cur.bb, cur.L, cur.a, cur.b);
  detailTab = 0;
  goScreen(S_RESULT);
}

// ======================= PENANGANAN SENTUHAN =======================
void handleTap(int x, int y) {
  // Ketuk area baterai di header untuk info detail voltase & status cas
  if (x >= 166 && x <= 265 && y <= 28 && scr != S_BOOT && scr != S_KEYB && scr != S_CONFIRM) {
    char msg[48];
    readBatterySensors(true);
    const char* st = batCharging ? (batPercent >= 99 ? "[Penuh]" : "[Mengisi]") : "";
    snprintf(msg, sizeof(msg), "Baterai: %.2fV (%d%%) %s", batVoltage, batPercent, st);
    toast(msg, batCharging ? (batPercent >= 99 ? COL_GREEN : COL_YELLOW) : COL_BLUE);
    return;
  }
  // Ketuk jam di header untuk info tanggal & waktu lengkap (WIT)
  if (x >= 268 && x <= 320 && y <= 28 && scr != S_BOOT && scr != S_KEYB && scr != S_CONFIRM) {
    char msg[48];
    dateStr(time(nullptr), msg, sizeof(msg));
    strlcat(msg, " WIT", sizeof(msg));
    toast(msg, COL_CARD2);
    return;
  }

  switch (scr) {
    case S_HOME:
      for (int i = 0; i < 6; i++) {
        if (!hit(homeTile(i), x, y)) continue;
        waitRelease();
        switch (i) {
          case 0: goScreen(S_SAMPLE); break;
          case 1: memset(&wc, 0, sizeof(wc)); calStep = 0; goScreen(S_CALSENS); break;
          case 2:
            if (runTouchCal(true)) touchCalFinishScreen();
            goScreen(S_HOME);
            break;
          case 3: histScroll = 0; goScreen(S_HISTORY); break;
          case 4: setScroll = 0; pickerType = 0; goScreen(S_SETTINGS); break;
          case 5: goScreen(S_ABOUT); break;
        }
        return;
      }
      break;

    case S_SAMPLE:
      if (hit(R_BACK, x, y)) { goScreen(S_HOME); }
      else if (hit(R_TAB_S, x, y)) { sampleMode = 0; drawSample(); }
      else if (hit(R_TAB_M, x, y)) { sampleMode = 1; drawSample(); }
      else if (hit(R_START, x, y)) { startAnalysis(); }
      break;

    case S_RESULT:
      if (hit(R_BACK, x, y) || hit(rb(3), x, y)) goScreen(S_HOME);
      else if (hit(rb(0), x, y)) saveCurrent();
      else if (hit(rb(1), x, y)) { waitRelease(); startAnalysis(); }
      else if (hit(rb(2), x, y)) { detailTab = 0; goScreen(S_DETAIL); }
      break;

    case S_DETAIL:
      if (hit(R_BACK, x, y) || hit(rb(3), x, y)) goScreen(S_RESULT);
      else if (hit(rb(0), x, y)) saveCurrent();
      else if (hit(rb(1), x, y)) { waitRelease(); startAnalysis(); }
      else if (hit(rb(2), x, y)) setReference();
      else {
        for (int i = 0; i < 4; i++) {
          if (hit(tabRect(i), x, y)) { detailTab = i; drawDetail(); break; }
        }
      }
      break;

    case S_HISTORY:
      if (hit(R_BACK, x, y)) { goScreen(S_HOME); break; }
      if (hit(R_H_UP, x, y)) { histScroll = max(0, histScroll - 4); drawHistory(); break; }
      if (hit(R_H_DN, x, y)) {
        if (histScroll + 4 < histCount) histScroll += 4;
        drawHistory();
        break;
      }
      if (hit(R_H_DEL, x, y)) {
        if (histCount > 0) { confirmAction = 1; goScreen(S_CONFIRM); }
        break;
      }
      for (int i = 0; i < 4; i++) {
        int idx = histScroll + i;
        if (idx < histCount && hit(histRow(i), x, y)) {
          histToResult(hist[idx], cur);
          hasCur = true;
          detailTab = 0;
          goScreen(S_RESULT);
          break;
        }
      }
      break;

    case S_CALSENS:
      if (hit(R_BACK, x, y) || hit(R_CS_FOOT, x, y)) { goScreen(S_HOME); }
      else if (calStep == 0 && hit(R_CS_START, x, y)) { calDark(); }
      else if (calStep == 1 && hit(R_CS_START, x, y)) { calWhite(); }
      else if (calStep == 2 && hit(R_CS_SAVE, x, y)) {
        sc = wc;
        saveScal();
        goScreen(S_HOME);
        toast("Kalibrasi sensor tersimpan", COL_GREEN);
      }
      break;

    case S_SETTINGS:
      if (pickerType != 0) {
        if (hit(R_PICKER_CANCEL, x, y) || !hit(R_PICKER_MODAL, x, y)) {
          pickerType = 0;
          drawSettings();
          break;
        }
        if (pickerType == 1) {
          for (int j = 0; j < 6; j++) {
            if (hit(pickerOptRect(1, j), x, y)) {
              cfg.ledIdx = j;
              saveCfg();
              pickerType = 0;
              drawSettings();
              break;
            }
          }
        } else if (pickerType == 2) {
          for (int j = 0; j < 5; j++) {
            if (hit(pickerOptRect(2, j), x, y)) {
              cfg.gainIdx = j;
              saveCfg();
              applySensor();
              pickerType = 0;
              drawSettings();
              break;
            }
          }
        } else if (pickerType == 3) {
          for (int j = 0; j < 4; j++) {
            if (hit(pickerOptRect(3, j), x, y)) {
              cfg.nIdx = j;
              saveCfg();
              pickerType = 0;
              drawSettings();
              break;
            }
          }
        } else if (pickerType == 4) {
          for (int j = 0; j < 4; j++) {
            if (hit(pickerOptRect(4, j), x, y)) {
              cfg.blIdx = j;
              saveCfg();
              applyBacklight();
              pickerType = 0;
              drawSettings();
              break;
            }
          }
        }
        break;
      }

      if (hit(R_BACK, x, y)) { goScreen(S_HOME); break; }
      if (hit(R_SET_UP, x, y)) {
        if (setScroll > 0) { setScroll--; drawSettings(); }
        break;
      }
      if (hit(R_SET_DN, x, y)) {
        if (setScroll < (SET_COUNT - SET_PAGE_ROWS)) { setScroll++; drawSettings(); }
        break;
      }
      if (x >= 300 && x <= 320 && y >= 34 && y <= 198) {
        int target = ((y - 34) * (SET_COUNT - SET_PAGE_ROWS + 1)) / 164;
        target = constrain(target, 0, SET_COUNT - SET_PAGE_ROWS);
        if (target != setScroll) {
          setScroll = target;
          drawSettings();
        }
        break;
      }

      for (int i = 0; i < SET_PAGE_ROWS; i++) {
        int idx = setScroll + i;
        if (idx >= SET_COUNT) break;
        if (!hit(setRow(i), x, y)) continue;
        switch (idx) {
          case 0: pickerType = 1; drawSettings(); return;
          case 1: pickerType = 2; drawSettings(); return;
          case 2: pickerType = 3; drawSettings(); return;
          case 3:
#if PIN_BL >= 0
            pickerType = 4; drawSettings(); return;
#else
            toast("Sambung LED TFT ke GPIO, set PIN_BL");
            return;
#endif
          case 4: goScreen(S_BATCAL); return;
          case 5: {
            time_t t = time(nullptr);
            localtime_r(&t, &editTm);
            goScreen(S_TIME);
            return;
          }
          case 6: wifiEnter(); return;
          case 7: otaEnter(); return;
          case 8: confirmAction = 0; goScreen(S_CONFIRM); return;
        }
        break;
      }
      break;

    case S_TIME:
      if (hit(R_BACK, x, y) || hit(R_T_CANCEL, x, y)) { goScreen(S_SETTINGS); break; }
      if (hit(R_T_SAVE, x, y)) {
        editTm.tm_sec = 0;
        time_t t = mktime(&editTm);
        struct timeval tv = { t, 0 };
        settimeofday(&tv, nullptr);
        saveEpoch();
        goScreen(S_SETTINGS);
        break;
      }
      for (int i = 0; i < 5; i++) {
        if (hit(timeMinus(i), x, y)) { timeAdjust(i, -1); drawTimeEdit(); break; }
        if (hit(timePlus(i), x, y))  { timeAdjust(i, +1); drawTimeEdit(); break; }
      }
      break;

    case S_WIFI: wifiTap(x, y); break;
    case S_KEYB: kbTap(x, y);   break;
    case S_OTA:  otaTap(x, y);  break;

    case S_ABOUT:
      if (hit(R_BACK, x, y)) goScreen(S_HOME);
      break;

    case S_CONFIRM:
      if (hit(R_CF_NO, x, y)) {
        goScreen(confirmAction == 0 ? S_SETTINGS : S_HISTORY);
      } else if (hit(R_CF_YES, x, y)) {
        if (confirmAction == 0) {
          prefs.clear();
          tft.fillScreen(COL_BG);
          txt(160, 108, "Data dihapus. Restart...", COL_TXT, F9B, 1, 1);
          delay(1000);
          ESP.restart();
        } else {
          histCount = 0;
          histScroll = 0;
          saveHist();
          goScreen(S_HISTORY);
        }
      }
      break;

    case S_BATCAL:
      batCalTap(x, y);
      break;

    default: break;
  }
}

// ======================= SETUP & LOOP =======================
void setup() {
  Serial.begin(115200);
  pinMode(PIN_BOOT, INPUT_PULLUP);
  pinMode(PIN_BAT_ADC, INPUT);
  pinMode(PIN_CHG_DET, INPUT);
  analogReadResolution(12);

  SPI.begin(PIN_SCK, PIN_MISO, PIN_MOSI, -1);
  tft.begin(40000000);        // turunkan ke 20000000 jika layar glitch
  tft.setRotation(1);
  tft.fillScreen(COL_BG);

  ts.begin();
  ts.setRotation(0);

  // 1. Memulai sistem (~0.8s)
  bootDraw(10, "Memulai sistem...");
  delay(800);

  // 2. Memuat preferensi & kalibrasi (~0.9s)
  prefs.begin("colan", false);
  loadAll();
  readBatterySensors(true);
  batLastCharge = batCharging;
#if PIN_BL >= 0
  ledcAttach(PIN_BL, 5000, 8);
  applyBacklight();
#endif
  bootDraw(25, "Memuat preferensi...");
  delay(900);

  // 3. Konfigurasi Waktu & NTP (Timezone WIT = UTC+9) (~0.9s)
  configTime(9 * 3600, 0, "pool.ntp.org", "id.pool.ntp.org", "time.google.com");
  setenv("TZ", "WIT-9", 1);
  tzset();

  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  if (wifiSsid[0]) WiFi.begin(wifiSsid, wifiPass);   // sambung otomatis di latar belakang
  bootDraw(45, "Sinkronisasi WiFi & Waktu (WIT)...");
  delay(900);

  // 4. Sensor AS7341 (~1.0s)
  bootDraw(65, "Menghubungkan sensor AS7341...");
  Wire.begin(AS_SDA, AS_SCL);
  Wire.setClock(400000);
  sensorOk = as7341.begin();
  applySensor();
  Serial.println(sensorOk ? "AS7341 OK" : "AS7341 TIDAK terdeteksi");
  delay(1000);

  // 5. Tabel warna CIE 1931 (~0.9s)
  bootDraw(80, "Inisialisasi tabel CIE 1931...");
  initColorTables();
  delay(900);

  // 6. Diagnostik baterai & charger (~0.8s)
  readBatterySensors(true);
  char batMsg[40];
  snprintf(batMsg, sizeof(batMsg), "Baterai: %.2fV (%d%%)%s", batVoltage, batPercent, batCharging ? " [Cas]" : "");
  bootDraw(92, batMsg);
  delay(800);

  // 7. Selesai & siap digunakan (~0.7s) -> Total tepat 6.0 detik!
  bootDraw(100, "Sistem Siap Digunakan");
  delay(700);

  if (digitalRead(PIN_BOOT) == LOW || !tcal.valid) {
    runTouchCal(false);
    touchCalFinishScreen();
  }

  goScreen(S_HOME);
}

void loop() {
  static bool down = false;
  static uint32_t lastSeen = 0, downMs = 0, lastRep = 0;
  uint32_t now = millis();
  int x, y;

  if (readTouch(x, y)) {
    lastSeen = now;
    if (!down) {
      down = true;
      downMs = now;
      handleTap(x, y);
    } else if ((scr == S_TIME || (scr == S_KEYB && hit(R_KB_BKSP, x, y))) &&
               now - downMs > 500 && now - lastRep > 110) {
      lastRep = now;
      handleTap(x, y);   // tahan tombol +/- untuk mengulang
    }
  } else if (down && now - lastSeen > 80) {
    down = false;
  }

  // Jam di header diperbarui berkala tiap 10 detik
  static uint32_t lastClockUpdate = 0;
  if (now - lastClockUpdate > 10000) {
    lastClockUpdate = now;
    if (scr != S_BOOT && scr != S_PROCESS && scr != S_KEYB) {
      drawHeaderClock();
    }
  }

  // Sinkronisasi otomatis waktu via NTP ketika WiFi terhubung (Zona Waktu WIT)
  static bool ntpSynced = false;
  static wl_status_t lastWifiSt = WL_DISCONNECTED;
  wl_status_t curWifiSt = WiFi.status();
  if (curWifiSt != lastWifiSt) {
    lastWifiSt = curWifiSt;
    if (curWifiSt == WL_CONNECTED) {
      configTime(9 * 3600, 0, "pool.ntp.org", "id.pool.ntp.org", "time.google.com");
      sendWebPing(); // Kirim status telemetri ke server seketika begitu terhubung
    }
    if (scr != S_BOOT && scr != S_PROCESS) {
      drawWifiIcon();
    }
  }
  if (curWifiSt == WL_CONNECTED && !ntpSynced) {
    time_t tnow = time(nullptr);
    struct tm tmv;
    localtime_r(&tnow, &tmv);
    if (tmv.tm_year >= (2025 - 1900)) {
      ntpSynced = true;
      saveEpoch();
      if (scr != S_BOOT && scr != S_PROCESS && scr != S_KEYB) {
        drawHeaderClock();
      }
    }
  }

  // Kirim Heartbeat / Telemetri berkala ke server web (setiap 25 detik saat WiFi aktif)
  static uint32_t lastPingLoop = 0;
  if (curWifiSt == WL_CONNECTED && (now - lastPingLoop > 25000)) {
    lastPingLoop = now;
    sendWebPing();
  }

  // Periksa baterai & status cas secara berkala
  static uint32_t lastBatLoop = 0;
  if (now - lastBatLoop > 1500) {
    lastBatLoop = now;
    bool prevCharge = batCharging;
    int  prevPct    = batPercent;
    readBatterySensors(false);

    // Notifikasi pop-up saat kabel cas dicolok / dilepas
    if (batCharging != prevCharge) {
      batLastCharge = batCharging;
      if (batCharging) {
        toast("Sedang Mengisi Daya...", COL_GREEN);
      } else {
        toast("Kabel Cas Dilepas", COL_ORANGE);
      }
    } else if (batPercent != prevPct && scr != S_BOOT && scr != S_PROCESS) {
      drawHeaderBattery();
    }
  }
  delay(5);
}

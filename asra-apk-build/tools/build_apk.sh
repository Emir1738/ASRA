#!/usr/bin/env bash
# ============================================================================
#  Asra Android — derleme betiği
#
#  Derleme Gradle ile yapılır: kaynak (res) birleştirme, manifest
#  birleştirme ve imzalama adımları elle yapıldığında hata üretir.
#  (v1.7.0'da Firebase bağımlılığı kaldırıldı; Gradle düzeni korundu.)
#
#  Girdiler (varsayılan yollar, ortam değişkeniyle değiştirilebilir):
#    JAVA_HOME            JDK 17          -> $ASRA_BUILD_HOME/jdk17
#    ANDROID_SDK_ROOT     Android SDK 34  -> $ASRA_BUILD_HOME/android-sdk
#    Gradle               8.5             -> $ASRA_BUILD_HOME/gradle-8.5
#
#  Çıktı:
#    app/build/outputs/apk/release/app-release.apk  (imzalı, hizalanmış)
#    ayrıca asra-apk-build/asra-<sürüm>.apk olarak kopyalanır
# ============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_HOME="${ASRA_BUILD_HOME:-/home/user/.asra-build}"
SDK="${ANDROID_SDK_ROOT:-$BUILD_HOME/android-sdk}"
JAVA_HOME="${JAVA_HOME:-$BUILD_HOME/jdk17}"
GRADLE_BIN="${GRADLE_BIN:-$BUILD_HOME/gradle-8.5/bin/gradle}"
BT="$SDK/build-tools/34.0.0"

# Sürüm adı derlenen manifestten okunur (tek kaynak: app/build.gradle.kts).
# Betikte sabit tutulursa 1.7.1 derlenip 1.7.0 dosya adıyla çıkabiliyordu.
export JAVA_HOME
export ANDROID_SDK_ROOT="$SDK"
export ANDROID_HOME="$SDK"
export PATH="$JAVA_HOME/bin:$PATH"
# Gradle önbelleği depoyu kirletmesin diye çalışma alanında tutulur
export GRADLE_USER_HOME="${GRADLE_USER_HOME:-$BUILD_HOME/gradle-home}"

need() { [[ -e "$1" ]] || { echo "eksik: $1" >&2; exit 1; }; }
need "$JAVA_HOME/bin/javac"
need "$GRADLE_BIN"
need "$SDK/platforms/android-34/android.jar"
need "$BT/apksigner"
need "$ROOT/asra-release.jks"
if [[ ! -f "$ROOT/signing.properties" ]] &&
   { [[ -z "${ASRA_SIGN_STORE_PASSWORD:-}" ]] || [[ -z "${ASRA_SIGN_KEY_PASSWORD:-}" ]]; }; then
  echo "İmza parolaları eksik: ASRA_SIGN_STORE_PASSWORD / ASRA_SIGN_KEY_PASSWORD veya git dışı signing.properties gerekli" >&2
  exit 1
fi

cd "$ROOT"

echo "[1/4] bagimliliklar cozuluyor + derleniyor (assembleRelease)"
"$GRADLE_BIN" --no-daemon --console=plain \
  -Dorg.gradle.java.home="$JAVA_HOME" \
  assembleRelease

APK="$ROOT/app/build/outputs/apk/release/app-release.apk"
need "$APK"

echo "[2/4] imza dogrulamasi"
SIGNATURE=$("$BT/apksigner" verify --verbose --print-certs "$APK")
printf '%s\n' "$SIGNATURE" | grep -E 'Verified using v[23] scheme|certificate SHA-256 digest'
for scheme in 2 3; do
  printf '%s\n' "$SIGNATURE" | grep -q "Verified using v$scheme scheme (APK Signature Scheme v$scheme): true" || {
    echo "v$scheme imzasi bulunamadi" >&2; exit 1;
  }
done

# Önceki APK'nın üzerine kurulabilir olmalı: aynı sertifika ve daha büyük kod.
# Dağıtımdan kaldırılan sürüm yoksa korunmuş 1.7.1 orijinaliyle karşılaştır.
PREVIOUS_APK="$ROOT/asra-1.7.2.apk"
[[ -f "$PREVIOUS_APK" ]] || PREVIOUS_APK="$ROOT/asra-1.7.1.apk"
EXPECTED_CERT='3527c22176f863d0846d328c86c90adce00605e5a6155d1f18398fcaf3e916c0'
NEW_SIGN=$(printf '%s\n' "$SIGNATURE" | grep 'certificate SHA-256 digest:' | head -1 | awk '{print $NF}')
[[ "$NEW_SIGN" == "$EXPECTED_CERT" ]] || { echo "Beklenen guncelleme sertifikasi degisti" >&2; exit 1; }
if [[ -f "$PREVIOUS_APK" ]]; then
  OLD_SIGN=$("$BT/apksigner" verify --print-certs "$PREVIOUS_APK" | grep 'certificate SHA-256 digest:' | head -1 | awk '{print $NF}')
  [[ -n "$OLD_SIGN" && "$OLD_SIGN" == "$NEW_SIGN" ]] || { echo "Sertifika eskisiyle farkli" >&2; exit 1; }
fi

echo "[3/4] paket bilgisi"
BADGE=$("$BT/aapt2" dump badging "$APK")
printf '%s\n' "$BADGE" | sed -n '1,6p'
PACKAGE=$(printf '%s\n' "$BADGE" | sed -nE "s/^package: name='([^']+)'.*/\1/p" | head -1)
VERSION_NAME=$(printf '%s\n' "$BADGE" | sed -nE "s/^package:.*versionName='([^']+)'.*/\1/p" | head -1)
VERSION_CODE=$(printf '%s\n' "$BADGE" | sed -nE "s/^package:.*versionCode='([0-9]+)'.*/\1/p" | head -1)
[[ "$PACKAGE" == 'app.asra.client' && "$VERSION_NAME" == '1.7.3' && "$VERSION_CODE" == 30 ]] || {
  echo "Paket kimligi/surum 1.7.3 (30) olmali" >&2; exit 1;
}
if [[ -f "$PREVIOUS_APK" ]]; then
  OLD_CODE=$("$BT/aapt2" dump badging "$PREVIOUS_APK" | sed -nE "s/^package:.*versionCode='([0-9]+)'.*/\1/p" | head -1)
  [[ "$VERSION_CODE" -gt "${OLD_CODE:-0}" ]] || { echo "Surum kodu eskisinden buyuk olmali" >&2; exit 1; }
fi
OUT_APK_NAME="asra-${VERSION_NAME}.apk"

echo "[4/4] kopyalama"
cp -f "$APK" "$ROOT/$OUT_APK_NAME"

echo
ls -l "$ROOT/$OUT_APK_NAME"
echo "SHA-256: $(sha256sum "$ROOT/$OUT_APK_NAME" | cut -d' ' -f1)"
echo BUILD_OK

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

VERSION_NAME="1.7.0"
OUT_APK_NAME="asra-${VERSION_NAME}.apk"

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

cd "$ROOT"

echo "[1/4] bagimliliklar cozuluyor + derleniyor (assembleRelease)"
"$GRADLE_BIN" --no-daemon --console=plain \
  -Dorg.gradle.java.home="$JAVA_HOME" \
  assembleRelease

APK="$ROOT/app/build/outputs/apk/release/app-release.apk"
need "$APK"

echo "[2/4] imza dogrulamasi"
"$BT/apksigner" verify --verbose --print-certs "$APK"

echo "[3/4] paket bilgisi"
"$BT/aapt2" dump badging "$APK" | head -n 6

echo "[4/4] kopyalama"
cp -f "$APK" "$ROOT/$OUT_APK_NAME"

echo
ls -l "$ROOT/$OUT_APK_NAME"
echo "SHA-256: $(sha256sum "$ROOT/$OUT_APK_NAME" | cut -d' ' -f1)"
echo BUILD_OK

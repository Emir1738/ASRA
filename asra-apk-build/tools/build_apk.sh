#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_HOME="${ASRA_BUILD_HOME:-/home/user/.asra-build}"
SDK="${ANDROID_SDK_ROOT:-$BUILD_HOME/android-sdk}"
JAVA_HOME="${JAVA_HOME:-$BUILD_HOME/jdk17}"
KOTLINC="${KOTLINC:-$BUILD_HOME/kotlinc/bin/kotlinc}"
BT="$SDK/build-tools/34.0.0"
ANDROID_JAR="$SDK/platforms/android-34/android.jar"
AAPT2="$BT/aapt2"
D8="$BT/d8"
ZIPALIGN="$BT/zipalign"
APKSIGNER="$BT/apksigner"

APP="$ROOT/app"
SRC="$APP/src/main"
RES="$SRC/res"
MANIFEST="$SRC/AndroidManifest.xml"
OUT="$ROOT/build"
GEN="$OUT/generated"
CLASSES="$OUT/classes"
DEX="$OUT/dex"
OBJ="$OUT/obj"
KEYSTORE="$ROOT/asra-release.jks"
STOREPASS="asra-release-2026"

export JAVA_HOME
export PATH="$JAVA_HOME/bin:$PATH"

need() {
  if [[ ! -e "$1" ]]; then
    echo "missing: $1" >&2
    exit 1
  fi
}

need "$AAPT2"
need "$D8"
need "$ZIPALIGN"
need "$APKSIGNER"
need "$ANDROID_JAR"
need "$KOTLINC"
need "$JAVA_HOME/bin/javac"
need "$MANIFEST"

rm -rf "$OUT"
mkdir -p "$GEN" "$CLASSES" "$DEX" "$OBJ" "$OUT/compiled"

echo "[1/7] compiling resources"
"$AAPT2" compile --dir "$RES" -o "$OUT/compiled/res.zip"

echo "[2/7] linking resources"
"$AAPT2" link \
  -o "$OUT/app-unsigned.apk" \
  -I "$ANDROID_JAR" \
  --manifest "$MANIFEST" \
  --java "$GEN" \
  --custom-package app.asra.client \
  --min-sdk-version 24 \
  --target-sdk-version 34 \
  --version-code 24 \
  --version-name 1.5.7 \
  --auto-add-overlay \
  "$OUT/compiled/res.zip"

R_JAVA="$(find "$GEN" -name R.java | head -n 1)"
need "$R_JAVA"

echo "[3/7] compiling R.java"
"$JAVA_HOME/bin/javac" \
  --release 11 \
  -classpath "$ANDROID_JAR" \
  -d "$CLASSES" \
  "$R_JAVA"

echo "[4/7] compiling Kotlin"
mapfile -t KT_FILES < <(find "$SRC/java" -name '*.kt' | sort)
"$KOTLINC" \
  -jvm-target 11 \
  -classpath "$ANDROID_JAR:$CLASSES" \
  -d "$CLASSES" \
  -J-Xmx512m \
  "${KT_FILES[@]}"

echo "[5/7] dexing"
mapfile -t CLASS_FILES < <(find "$CLASSES" -name '*.class')
STDLIB="$BUILD_HOME/kotlinc/lib/kotlin-stdlib.jar"
need "$STDLIB"
"$D8" \
  --min-api 24 \
  --lib "$ANDROID_JAR" \
  --output "$DEX" \
  "${CLASS_FILES[@]}" \
  "$STDLIB"

echo "[6/7] packaging"
cp "$OUT/app-unsigned.apk" "$OUT/app-unaligned.apk"
(
  cd "$DEX"
  zip -q -u "$OUT/app-unaligned.apk" classes.dex
)

"$ZIPALIGN" -f -p 4 "$OUT/app-unaligned.apk" "$OUT/app-aligned.apk"

if [[ ! -f "$KEYSTORE" ]]; then
  echo "generating signing key"
  "$JAVA_HOME/bin/keytool" -genkeypair \
    -keystore "$KEYSTORE" \
    -alias asra \
    -keyalg RSA \
    -keysize 2048 \
    -validity 10000 \
    -storepass "$STOREPASS" \
    -keypass "$STOREPASS" \
    -dname "CN=Asra, OU=Mobile, O=Asra, L=Istanbul, ST=Istanbul, C=TR"
fi

echo "[7/7] signing"
"$APKSIGNER" sign \
  --ks "$KEYSTORE" \
  --ks-key-alias asra \
  --ks-pass "pass:$STOREPASS" \
  --key-pass "pass:$STOREPASS" \
  --out "$OUT/asra-release.apk" \
  "$OUT/app-aligned.apk"

"$APKSIGNER" verify --verbose "$OUT/asra-release.apk"
"$AAPT2" dump badging "$OUT/asra-release.apk" | head -n 20 || true

cp -f "$OUT/asra-release.apk" /home/user/asra-1.5.7.apk
cp -f "$OUT/asra-release.apk" "$ROOT/asra-1.5.7.apk"
ls -lh /home/user/asra-1.5.7.apk
echo BUILD_OK

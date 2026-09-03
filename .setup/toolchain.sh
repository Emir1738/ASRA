#!/usr/bin/env bash
# ASRA derleme zinciri kurulumu: JDK 17 + Android SDK (platform 34, build-tools 34) + Gradle
set -uo pipefail
B=/home/user/.asra-build
mkdir -p "$B"
log(){ echo "[$(date +%H:%M:%S)] $*"; }
ok(){ echo "OK  : $*"; }
fail(){ echo "FAIL: $*"; }

retry(){ local n=0; until "$@"; do n=$((n+1)); [ $n -ge 3 ] && return 1; log "retry $n: $*"; sleep 4; done; }

log "=== 1) JDK 17 ==="
if [ -d "$B/jdk17/bin" ] && "$B/jdk17/bin/javac" -version 2>/dev/null | grep -q '17\.'; then
  ok "jdk17 zaten kurulu"
else
  retry sudo apt-get update -qq
  if sudo apt-get install -y -qq openjdk-17-jdk-headless >/dev/null 2>&1; then
    ok "apt ile openjdk-17 kuruldu"
  else
    fail "apt yok, tarball indiriliyor"
    cd "$B"
    U="https://api.adoptium.net/v3/binary/latest/17/ga/linux/x64/jdk/hotspot/normal/eclipse"
    retry curl -fsSL --retry 3 -o jdk17.tgz "$U"
    mkdir -p jdk17 && tar -xzf jdk17.tgz -C jdk17 --strip-components=1 && rm -f jdk17.tgz
  fi
  if [ ! -x "$B/jdk17/bin/javac" ]; then
    P="$(dirname "$(dirname "$(readlink -f "$(command -v javac 2>/dev/null || echo /usr/lib/jvm/jdk-11/bin/javac)")")")"
    for c in /usr/lib/jvm/*17*; do [ -x "$c/bin/javac" ] && P="$c" && break; done
    log "sistem JDK17 yolu: $P"; ln -sfn "$P" "$B/jdk17" 2>/dev/null || cp -a "$P" "$B/jdk17"
  fi
fi
"$B/jdk17/bin/java" -version 2>&1 | head -2

log "=== 2) Android cmdline-tools ==="
cd "$B"
if [ ! -x "$B/android-sdk/cmdline-tools/latest/bin/sdkmanager" ]; then
  retry curl -fsSL --retry 3 -o cmdtools.zip https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip
  mkdir -p "$B/android-sdk/cmdline-tools"
  unzip -qo cmdtools.zip -d "$B/android-sdk/cmdline-tools"
  rm -rf "$B/android-sdk/cmdline-tools/latest"
  mv "$B/android-sdk/cmdline-tools/cmdline-tools" "$B/android-sdk/cmdline-tools/latest"
  rm -f cmdtools.zip
fi
export JAVA_HOME="$B/jdk17"
export ANDROID_SDK_ROOT="$B/android-sdk"
export ANDROID_HOME="$B/android-sdk"
SM="$B/android-sdk/cmdline-tools/latest/bin/sdkmanager"
ls -l "$SM"

log "=== 3) SDK paketleri (lisanslar kabul) ==="
yes 2>/dev/null | "$SM" --licenses >/dev/null 2>&1
retry "$SM" --install "platform-tools" "platforms;android-34" "build-tools;34.0.0"
ok "SDK paketleri kuruldu"
ls "$B/android-sdk/build-tools" "$B/android-sdk/platforms"

log "=== 4) Gradle 8.5 ==="
if [ ! -x "$B/gradle-8.5/bin/gradle" ]; then
  cd "$B"
  retry curl -fsSL --retry 3 -o gradle.zip https://services.gradle.org/distributions/gradle-8.5-bin.zip
  unzip -qo gradle.zip && rm -f gradle.zip
fi
"$B/gradle-8.5/bin/gradle" --version 2>&1 | head -6

log "=== 5) Sonuc ==="
"$B/jdk17/bin/javac" -version
"$B/android-sdk/build-tools/34.0.0/aapt2" version 2>&1 | head -1
"$B/android-sdk/build-tools/34.0.0/apksigner" --version 2>&1 | head -1
df -h /home/user | tail -1
echo "TOOLCHAIN_OK"

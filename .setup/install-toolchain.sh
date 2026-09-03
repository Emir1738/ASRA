#!/usr/bin/env bash
# ASRA Android derleme araç zinciri kurulumu (JDK 17 + Android SDK 34 + Gradle 8.5)
set -uo pipefail
B=/home/user/.asra-build
mkdir -p "$B" /tmp/dl
# sdkmanager, sistemdeki eski Java'yı seçerse UnsupportedClassVersionError verir
# (class file 61 = Java 17 gerekir). Bu yüzden JDK açıkça bağlanır.
export JAVA_HOME="$B/jdk17"
export PATH="$JAVA_HOME/bin:$PATH"
cd /tmp/dl

echo "=== [1/4] Temurin JDK 17 ==="
if [ ! -x "$B/jdk17/bin/javac" ]; then
  curl -sSL -o jdk.tar.gz "https://api.adoptium.net/v3/binary/latest/17/ga/linux/x64/jdk/hotspot/normal/eclipse" \
    || curl -sSL -o jdk.tar.gz "https://github.com/adoptium/temurin17-binaries/releases/download/jdk-17.0.13%2B11/OpenJDK17U-jdk_x64_linux_hotspot_17.0.13_11.tar.gz"
  mkdir -p "$B/jdk17"
  tar -xzf jdk.tar.gz -C "$B/jdk17" --strip-components=1
fi
"$B/jdk17/bin/javac" -version

echo "=== [2/4] Gradle 8.5 ==="
if [ ! -x "$B/gradle-8.5/bin/gradle" ]; then
  curl -sSL -o gradle.zip "https://services.gradle.org/distributions/gradle-8.5-bin.zip"
  (cd "$B" && unzip -qo /tmp/dl/gradle.zip)
fi
"$B/gradle-8.5/bin/gradle" --version 2>&1 | grep -E "^Gradle" | head -1

echo "=== [3/4] Android SDK cmdline-tools ==="
SDK="$B/android-sdk"
mkdir -p "$SDK/cmdline-tools"
if [ ! -d "$SDK/cmdline-tools/latest" ]; then
  curl -sSL -o cmdtools.zip "https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip"
  (cd /tmp/dl && unzip -qo cmdtools.zip)
  rm -rf "$SDK/cmdline-tools/latest"
  mv /tmp/dl/cmdline-tools "$SDK/cmdline-tools/latest"
fi
yes | "$SDK/cmdline-tools/latest/bin/sdkmanager" --licenses >/dev/null 2>&1

echo "=== [4/4] platform 34 + build-tools 34.0.0 + platform-tools ==="
"$SDK/cmdline-tools/latest/bin/sdkmanager" "platforms;android-34" "build-tools;34.0.0" "platform-tools" 2>&1 | tail -3

echo
echo "=== SONUÇ ==="
"$B/jdk17/bin/javac" -version
ls -d "$SDK/platforms/android-34" "$SDK/build-tools/34.0.0" 2>&1
"$SDK/build-tools/34.0.0/aapt2" version 2>&1 | head -1
"$SDK/build-tools/34.0.0/apksigner" version 2>&1 | head -1
echo "TOOLCHAIN_OK"

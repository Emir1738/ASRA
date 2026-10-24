#!/usr/bin/env bash
# Asra Android APK dağıtım kapısı: sürüm + eski sertifikayla güncellenebilirlik
# + izinler + ağ politikası (dosya yerine paket içindeki gerçek manifest).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BT="${ANDROID_SDK_ROOT:-/home/user/.asra-build/android-sdk}/build-tools/34.0.0"
NEW="$ROOT/asra-apk-build/asra-1.7.3.apk"
OLD="$ROOT/asra-apk-build/asra-1.7.2.apk"
[[ -f "$OLD" ]] || OLD="$ROOT/asra-apk-build/asra-1.7.1.apk"
EXPECTED_CERT='3527c22176f863d0846d328c86c90adce00605e5a6155d1f18398fcaf3e916c0'
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL + 1)); echo "  ✗ $1"; }
for file in "$NEW" "$BT/aapt2" "$BT/apksigner"; do
  [[ -e "$file" ]] || { echo "Eksik: $file" >&2; exit 2; }
done
N_BADGE=$("$BT/aapt2" dump badging "$NEW")
N_SIGN=$("$BT/apksigner" verify --verbose --print-certs "$NEW")
if [[ -f "$OLD" ]]; then
  O_BADGE=$("$BT/aapt2" dump badging "$OLD")
  O_SIGN=$("$BT/apksigner" verify --verbose --print-certs "$OLD")
else
  O_BADGE=''
  O_SIGN=''
fi
TREE=$("$BT/aapt2" dump xmltree --file AndroidManifest.xml "$NEW")
code() { sed -nE "s/^package:.*versionCode='([0-9]+)'.*/\1/p" <<< "$1" | head -1; }
cert() { sed -nE 's/^Signer #1 certificate SHA-256 digest: ([a-f0-9]+)$/\1/p' <<< "$1" | head -1; }

[[ "$N_BADGE" == *"name='app.asra.client'"* && "$N_BADGE" == *"versionName='1.7.3'"* ]] \
  && ok 'paket kimliği ve versionName 1.7.3' || bad 'paket/sürüm yanlış'
[[ $(code "$N_BADGE") -eq 30 &&
   ( -z "$O_BADGE" || $(code "$N_BADGE") -gt $(code "$O_BADGE") ) ]] \
  && ok 'versionCode 30 > önceki 29' || bad 'versionCode yanlış/geri gitmiş'
[[ "$N_BADGE" == *"sdkVersion:'24'"* && "$N_BADGE" == *"targetSdkVersion:'34'"* ]] \
  && ok 'minSdk 24 / targetSdk 34' || bad 'SDK düzeyleri yanlış'
for scheme in 2 3; do
  [[ "$N_SIGN" == *"Verified using v$scheme scheme (APK Signature Scheme v$scheme): true"* ]] \
    && ok "imza v$scheme doğrulandı" || bad "imza v$scheme yok"
done
[[ $(cert "$N_SIGN") == "$EXPECTED_CERT" &&
   ( -z "$O_SIGN" || $(cert "$N_SIGN") == $(cert "$O_SIGN") ) ]] \
  && ok 'eski sürümün sertifikasıyla imzalı' || bad 'sertifika farklı — üstüne kurulamaz'
PERMS=$(grep '^uses-permission:' <<< "$N_BADGE" | sed -nE "s/.*name='([^']+)'.*/\1/p" | sort)
EXPECTED=$(printf '%s\n' \
  android.permission.INTERNET android.permission.ACCESS_NETWORK_STATE \
  android.permission.CAMERA android.permission.RECORD_AUDIO \
  android.permission.MODIFY_AUDIO_SETTINGS | sort)
[[ "$PERMS" == "$EXPECTED" ]] && ok 'yalnızca 5 gerekli izin' || bad 'fazladan/eksik izin'
[[ "$TREE" == *'usesCleartextTraffic(0x010104ec)=false'* ]] \
  && ok 'manifest HTTP açık trafiği engelliyor' || bad 'HTTP açık trafik açık'
[[ "$TREE" == *'app.asra.client.CaptureFileProvider'* && "$TREE" == *'exported(0x01010010)=false'* ]] \
  && ok 'kamera sağlayıcısı dışa açık değil' || bad 'kamera sağlayıcısı kontrol edilmeli'
if grep -Eq 'E: (service|receiver) ' <<< "$TREE"; then bad 'bildirim servisi/alıcı var'; else ok 'bildirim servisi/alıcı yok'; fi
[[ "$(cat "$ROOT/asra-apk-build/app/src/main/res/xml/network_security_config.xml")" == *'cleartextTrafficPermitted="false"'* ]] \
  && ok 'ağ güvenlik yapılandırması da HTTP kapalı' || bad 'ağ güvenlik yapılandırması HTTP açık'
echo "SONUÇ: GEÇTİ $PASS / BAŞARISIZ $FAIL"
exit "$FAIL"

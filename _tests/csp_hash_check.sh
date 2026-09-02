#!/usr/bin/env bash
# ============================================================================
#  CSP hash bütünlük kontrolü
#
#  index.html içindeki <style> ve <script> bloklarının SHA-256 hash'leri,
#  CSP meta etiketindeki style-src / script-src hash'leriyle BİREBİR
#  eşleşmek zorunda. Eşleşmezse tarayıcı ilgili bloğu tamamen engeller:
#  style hash uymazsa -> BÜTÜN TASARIM KAYBOLUR (Times New Roman, renksiz)
#  script hash uymazsa -> site çalışmaz
#
#  index.html'deki CSS/JS'e DOKUNDUKTAN SONRA bu scripti çalıştır;
#  hash'leri güncellemek gerekiyorsa yeni değerleri yazdırır.
# ============================================================================
set -u
F="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/asra-site-fix/index.html}"

python3 - "$F" <<'EOF'
import re, sys, hashlib, base64

path = sys.argv[1]
html = open(path, encoding='utf-8').read()

style = re.search(r'<style[^>]*>([\s\S]*?)</style>', html)
script = re.search(r'<script(?![^>]*src=)[^>]*>([\s\S]*?)</script>', html)
meta = re.search(r'<meta[^>]*Content-Security-Policy[^>]*content="([^"]*)"', html)
if not (style and script and meta):
    print('YAPI HATASI: style/script/CSP meta bulunamadı'); sys.exit(1)

def h(content):
    return base64.b64encode(hashlib.sha256(content.encode('utf-8')).digest()).decode()

m = meta.group(1)
style_hash = re.search(r"style-src[^;]*'sha256-([^']+)'", m)
script_hashes = re.findall(r"script-src[^;]*'sha256-([^']+)'", m)
style_actual = h(style.group(1))
script_actual = h(script.group(1))

fail = 0
if style_hash and style_hash.group(1) != style_actual:
    print(f'✗ style hash UYUMLU DEĞİL\n  CSP : {style_hash.group(1)}\n  gerçek: {style_actual}')
    print(f'  -> meta içindeki style-src hash\'ini {style_actual} olarak güncelle')
    fail = 1
else:
    print('✓ style hash uyumlu')

if script_hashes and script_actual not in script_hashes:
    if "'unsafe-inline'" in m:
        print('  (script-src unsafe-inline kullanılıyor — hash kontrolü atlandı)')
    else:
        print(f'✗ script hash UYUMLU DEĞİL\n  CSP : {script_hashes}\n  gerçek: {script_actual}')
        print(f'  -> meta içindeki script-src hash\'lerini {script_actual} olarak güncelle')
        fail = 1
else:
    print('✓ script hash uyumlu')

sys.exit(fail)
EOF

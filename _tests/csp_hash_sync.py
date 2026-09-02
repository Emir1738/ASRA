#!/usr/bin/env python3
"""index.html içindeki satır içi <script> bloğunun CSP sha256 hash'ini tazeler.

Satır içi betik her değiştiğinde meta içindeki script-src hash'i de değişmelidir;
aksi hâlde tarayıcı betiğin TAMAMINI sessizce engeller. Bu betik, csp_hash_check.sh
ile aynı hesap yöntemini kullanır ve hash'i yerinde günceller.
"""
import base64
import hashlib
import io
import re
import sys

path = sys.argv[1] if len(sys.argv) > 1 else 'asra-site-fix/index.html'
s = io.open(path, encoding='utf-8').read()
blocks = re.findall(r'<script>(.*?)</script>', s, re.S)
if len(blocks) != 1:
    print(f'BEKLENMEDİK: {len(blocks)} adet satır içi <script> bloğu bulundu')
    sys.exit(2)
digest = base64.b64encode(hashlib.sha256(blocks[0].encode('utf-8')).digest()).decode('ascii')
old = re.findall(r"'sha256-([A-Za-z0-9+/=]{44})'", s)
script_hashes = []
meta = re.search(r'<meta http-equiv="Content-Security-Policy" content="([^"]*)"', s)
if not meta:
    print('CSP meta bulunamadı')
    sys.exit(2)
for part in meta.group(1).split(';'):
    part = part.strip()
    if part.startswith('script-src'):
        script_hashes = re.findall(r"'sha256-([A-Za-z0-9+/=]{44})'", part)
if not script_hashes:
    print('script-src içinde sha256 bulunamadı')
    sys.exit(2)
if len(script_hashes) != 1:
    print(f'BEKLENMEDİK: script-src içinde {len(script_hashes)} hash var')
    sys.exit(2)
current = script_hashes[0]
if current == digest:
    print(f'✓ script hash zaten güncel: {digest}')
    sys.exit(0)
s = s.replace(f"'sha256-{current}'", f"'sha256-{digest}'", 1)
io.open(path, 'w', encoding='utf-8').write(s)
print(f'✓ script hash güncellendi: {current} -> {digest}')

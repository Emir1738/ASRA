#!/usr/bin/env python3
"""1.7.3 için GitHub'a gönderilebilir, canlı sır içermeyen kaynak+APK paketi.

Yerel asra-site-fix/auth.php ve asra-yukleme/auth.php üretim bağlantısı içerir;
onları asla doğrudan GitHub'a kopyalama. Bu betik geçici, arındırılmış bir
ağaç kurar, kontrol eder ve yalnızca doğrulanmış ZIP'i çalışma alanına yazar.
Eski Git geçmişindeki sızıntıları temizlemez; sırlar ayrıca döndürülmelidir.
"""
from __future__ import annotations

import hashlib
import re
import shutil
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUTPUT = ROOT / "asra-1.7.3-github-safe.zip"
APK = "asra-1.7.3.apk"


def source_files():
    direct = [ROOT / ".gitignore", ROOT / "README.md", ROOT / "tools/prepare_github.py"]
    for directory in (".setup", "_tests", "asra-apk-build/app/src"):
        base = ROOT / directory
        direct.extend(f for f in base.rglob("*") if f.is_file() and not any(
            part == "build" or part == ".gradle" or part == "__pycache__" or
            part == "run" or part.startswith("run_") for part in f.relative_to(base).parts
        ))
    direct += [ROOT / f for f in (
        "asra-apk-build/app/build.gradle.kts",
        "asra-apk-build/build.gradle.kts",
        "asra-apk-build/gradle.properties",
        "asra-apk-build/settings.gradle.kts",
        "asra-apk-build/tools/build_apk.sh",
        f"asra-apk-build/{APK}",
        "asra-site-fix/auth.php", "asra-site-fix/index.html", "asra-site-fix/.htaccess",
        "asra-yukleme/auth.php", "asra-yukleme/index.html", "asra-yukleme/.htaccess",
        f"asra-yukleme/{APK}",
    )]
    files = sorted(set(direct))
    for file in files:
        if not file.is_file() or file.is_symlink():
            raise RuntimeError(f"Kaynak dosya eksik/geçersiz: {file.relative_to(ROOT)}")
    return files


def redacted_php(source: str) -> str:
    """Üretim DSN/kullanıcı/parolası dışa aktarılmaz; sunucu ortamı gerekir."""
    for key in ("ASRA_DB_DSN", "ASRA_DB_USER", "ASRA_DB_PASSWORD"):
        # DSN çok satırlıdır; parola/kullanıcı aynı satırda olabilir.
        pattern = r"(?s)('" + key + r"'\s*,\s*)'[^'\n]*'"
        source, count = re.subn(pattern, lambda m: m.group(1) + "''", source)
        if count != 2:  # db() ve herkese açık health() tanı ucu
            raise RuntimeError(f"{key} dağıtım konumu beklenmedik: {count}")
    source = source.replace(
        "// Bağlantı bilgileri: geçerli InfinityFree hesabı (sql303 / if0_42805855).\n"
        "    // Tek dosyalık kurulum: auth.php tek başına yeterlidir. Farklı bir\n"
        "    // veritabanına bağlanmak gerekirse ASRA_DB_DSN / ASRA_DB_USER /\n"
        "    // ASRA_DB_PASSWORD ortam değişkenleri bu varsayılanları ezer.",
        "// GitHub kaynak kopyası: üretim bilgisi yok. Dağıtımdan önce\n"
        "    // ASRA_DB_DSN / ASRA_DB_USER / ASRA_DB_PASSWORD ortamda tanımlanmalı."
    )
    if re.search(r"envValue\('ASRA_DB_PASSWORD',\s*'[^']+'\)", source):
        raise RuntimeError("Parola varsayılanı hâlâ dolu")
    if "sql303.infinityfree.com" in source or "if0_42805855" in source:
        raise RuntimeError("Üretim veritabanı adresi/hesabı hâlâ kaynakta")
    return source


def exported_readme(original: str) -> str:
    prefix = """# GitHub kaynak paketi · ASRA 1.7.3

**Bu kopya canlı dağıtım dosyası değildir.** `auth.php` yalnızca
`ASRA_DB_DSN`, `ASRA_DB_USER`, `ASRA_DB_PASSWORD` sunucu ortam değişkenleri
sağlanırsa veritabanına bağlanır. Canlı veritabanı parolası, GitHub tokenı ve
APK imza anahtarı/parolası bu pakete konulmadı. Eski Git geçmişinde yayımlanan
sırlar yalnızca yeni commit ile kaybolmaz; parola/tokenı döndürün ve geçmişi
ayrıca güvenli yöntemle temizleyin. Android APK'sı aynı eski imzayla güncellenir.

"""
    return prefix + original.replace(
        "Sunucuya yüklenecek **hazır paket**:",
        "Veritabanı ortam değişkenleri sağlanınca yüklenebilecek **örnek paket**:"
    )


def exported_note(apk_path: Path, php_path: Path, html_path: Path) -> str:
    def line(file):
        return f"{file.name}: {file.stat().st_size} bayt · SHA-256 {hashlib.sha256(file.read_bytes()).hexdigest()}"
    return ("ASRA 1.7.3 — GITHUB KAYNAK PAKETİ (canlı parola içermez)\n\n"
            "Android APK: v2/v3 imzalı, versionCode 30. Mevcut 1.7.1/1.7.2\n"
            "kurulumuna aynı sertifikayla güncelleme olarak yüklenir.\n\n"
            + line(apk_path) + "\n" + line(php_path) + "\n" + line(html_path)
            + "\n\nSunucu: auth.php + index.html + .htaccess birlikte yüklenir; ancak\n"
            "önce sunucu ortamında ASRA_DB_DSN, ASRA_DB_USER ve\n"
            "ASRA_DB_PASSWORD tanımlanmalıdır. GitHub'daki auth.php\n"
            "üretim veritabanı parolası içermez. Anahtarı veya tokenı repoya\n"
            "koymayın. İmza için yerel asra-release.jks ve git dışı ortam\n"
            "değişkenleri gerekir. 1.7.2 APK dosyası paketten çıkarıldı.\n")


def verify_tree(stage: Path) -> list[Path]:
    files = sorted(f for f in stage.rglob("*") if f.is_file())
    for file in files:
        rel = file.relative_to(stage)
        if file.suffix in (".jks", ".keystore", ".pem", ".key", ".p12"):
            raise RuntimeError(f"Özel anahtar dosyası: {rel}")
        if file.suffix == ".apk" and file.name != APK:
            raise RuntimeError(f"Eski APK dosyası: {rel}")
        if file.suffix in (".apk", ".png", ".jpg", ".ico"):
            continue
        data = file.read_bytes()
        if re.search(rb"\b(?:gh[pousr]_[a-zA-Z0-9]{20,}|github_pat_[a-zA-Z0-9_]{35,})", data):
            raise RuntimeError(f"Erişim belirteci algılandı: {rel}")
        marker = b"-----BEGIN "
        if marker + b"PRIVATE KEY-----" in data or marker + b"RSA PRIVATE KEY-----" in data:
            raise RuntimeError(f"Özel anahtar algılandı: {rel}")
    for name in ("asra-site-fix/auth.php", "asra-yukleme/auth.php"):
        php = (stage / name).read_text()
        if not all(len(re.findall(r"'" + key + r"'\s*,\s*''", php)) == 2
                   for key in ("ASRA_DB_DSN", "ASRA_DB_USER", "ASRA_DB_PASSWORD")):
            raise RuntimeError(f"DB değerleri boş değil: {name}")
    if (stage / "asra-site-fix/auth.php").read_bytes() != (stage / "asra-yukleme/auth.php").read_bytes():
        raise RuntimeError("GitHub sunucu kaynakları farklı")
    if (stage / f"asra-apk-build/{APK}").read_bytes() != (stage / f"asra-yukleme/{APK}").read_bytes():
        raise RuntimeError("GitHub APK kopyaları farklı")
    return files


def main():
    with tempfile.TemporaryDirectory(prefix="asra-public-173-", dir="/var/tmp") as tmp:
        stage = Path(tmp) / "ASRA"
        stage.mkdir()
        for file in source_files():
            target = stage / file.relative_to(ROOT)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(file, target)
        php = redacted_php((stage / "asra-site-fix/auth.php").read_text())
        for name in ("asra-site-fix/auth.php", "asra-yukleme/auth.php"):
            (stage / name).write_text(php)
        readme = stage / "README.md"
        readme.write_text(exported_readme(readme.read_text()))
        ignore = stage / ".gitignore"
        text = ignore.read_text()
        text = text.replace("/asra-site-fix/auth.php\n", "").replace("/asra-yukleme/auth.php\n", "")
        ignore.write_text(text)
        note = stage / "asra-yukleme/YUKLEME-NOTU.txt"
        note.write_text(exported_note(stage / f"asra-yukleme/{APK}",
                                     stage / "asra-yukleme/auth.php",
                                     stage / "asra-yukleme/index.html"))
        files = verify_tree(stage)
        with zipfile.ZipFile(OUTPUT, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for file in files:
                z.write(file, file.relative_to(stage).as_posix())
        print(f"GitHub'a uygun kaynak+APK arşivi: {OUTPUT.name} ({len(files)} dosya)")
        print(f"ZIP SHA-256: {hashlib.sha256(OUTPUT.read_bytes()).hexdigest()}")
        print("Üretim parolaları, imza anahtarı, eski APK ve .git geçmişi pakette yok.")


if __name__ == "__main__":
    main()

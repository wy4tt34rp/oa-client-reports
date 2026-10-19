#!/usr/bin/env python3
"""Build a deterministic release ZIP from an explicit allowlist."""
from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
version = re.search(r" \* Version: ([0-9.]+)", (root / "oa-client-reports.php").read_text()).group(1)
destination = root.parents[1] / "archived zips" / f"oa-client-reports-v{version}.zip"
files = [root / "oa-client-reports.php", root / "README.md", root / "readme.txt", root / "RELEASE-VALIDATION.md"]
files += sorted((root / "includes").glob("*.php"))
files += sorted((root / "assets").glob("*.js"))
files += sorted((root / "assets").glob("*.css"))
for file in files:
    content = file.read_bytes()
    if b"-----BEGIN PRIVATE KEY-----" in content or b'"private_key":' in content:
        raise SystemExit(f"Credential-like content in {file.name}")
with zipfile.ZipFile(destination, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for file in files:
        info = zipfile.ZipInfo("oa-client-reports/" + file.relative_to(root).as_posix(), (2026, 10, 19, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, file.read_bytes())
with zipfile.ZipFile(destination) as archive:
    assert archive.testzip() is None
    for file in files:
        assert archive.read("oa-client-reports/" + file.relative_to(root).as_posix()) == file.read_bytes()
digest = hashlib.sha256(destination.read_bytes()).hexdigest()
print(f"{destination}\n{len(files)} files; {destination.stat().st_size} bytes\nSHA-256 {digest}")

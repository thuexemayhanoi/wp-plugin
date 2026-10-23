#!/usr/bin/env python3
"""Build the installable Qwen Local Writer ZIP (dist/qwen-local-writer.zip).

Only runtime files are packaged: no tests, no legacy commercial-API classes and
no model weights. Vendored runtime files are verified against manifest.json
before packaging. Standard library only.
"""
from __future__ import annotations

import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

root = Path(__file__).resolve().parents[1]
src = root / "qwen-local-writer"
out = root / "dist" / "qwen-local-writer.zip"
slug = "qwen-local-writer"

required = [
    "qwen-local-writer.php",
    "uninstall.php",
    "readme.txt",
    "LICENSE.txt",
    "includes/class-quality.php",
    "includes/class-markdown.php",
    "includes/class-plugin.php",
    "includes/class-ajax.php",
    "includes/view-admin.php",
    "assets/local-writer.js",
    "assets/local-writer.css",
    "assets/qlw-core.js",
    "assets/qwen-worker.js",
    "assets/vendor/manifest.json",
]
# Vendored runtime files are not committed; fetch them from npm (integrity-checked) if absent.
subprocess.run([sys.executable, str(root / "scripts" / "vendor_qwen_deps.py")], check=True)
manifest = json.loads((src / "assets/vendor/manifest.json").read_text())
for package in manifest["packages"]:
    for target, meta in package["files"].items():
        path = src / "assets/vendor" / target
        if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != meta["sha256"]:
            sys.exit(f"Vendor file missing or modified: {target} (run scripts/vendor_qwen_deps.py)")
        required.append(f"assets/vendor/{target}")

for name in required:
    if not (src / name).is_file():
        sys.exit(f"Missing {name}")

header = (src / "qwen-local-writer.php").read_text()
version = re.search(r"(?m)^ \* Version: (\S+)", header).group(1)
constant = re.search(r"define\('QLW_VERSION', '([^']+)'\)", header).group(1)
stable = re.search(r"(?m)^Stable tag: (\S+)", (src / "readme.txt").read_text()).group(1)
if not version == constant == stable:
    sys.exit(f"Version mismatch: header {version}, constant {constant}, readme {stable}")

forbidden = re.compile(r"cdn\.jsdelivr|unpkg\.com|esm\.sh|cdnjs|api\.openai|api\.anthropic|generativelanguage\.googleapis|x-api-key|OPENAI_API_KEY", re.I)
for name in required:
    if name.endswith((".php", ".js", ".css")) and "/vendor/" not in name:
        text = (src / name).read_text()
        if forbidden.search(text):
            sys.exit(f"Forbidden remote/API reference in {name}: {forbidden.search(text).group(0)}")

out.parent.mkdir(exist_ok=True)
with ZipFile(out, "w", ZIP_DEFLATED, compresslevel=9) as z:
    for name in sorted(required):
        info = ZipInfo(f"{slug}/{name}", date_time=(2026, 1, 1, 0, 0, 0))
        info.compress_type = ZIP_DEFLATED
        info.external_attr = 0o644 << 16
        z.writestr(info, (src / name).read_bytes(), compresslevel=9)

with ZipFile(out) as z:
    assert z.testzip() is None
    names = z.namelist()
    assert len(names) == len(required)
    assert all(n.startswith(f"{slug}/") for n in names)
    assert not any(re.search(r"claude|openai|gemini|/tests?/", n, re.I) for n in names)
    assert not any(n.endswith((".onnx", ".safetensors", ".bin", ".gguf")) for n in names), "model weights must not be packaged"
print(f"Built {out} — {slug} {version}, {len(names)} files, {out.stat().st_size} bytes")

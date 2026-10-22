#!/usr/bin/env python3
"""Build only the Qwen local plugin: no legacy API classes are included."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
root = Path(__file__).resolve().parents[1]
src = root / "qwen-local-writer"
out = root / "dist" / "qwen-local-writer.zip"
required = [
    "qwen-local-writer.php",
    "includes/class-quality.php",
    "assets/local-writer.js",
    "assets/qwen-worker.js",
    "readme.txt",
]
for name in required:
    assert (src / name).is_file(), f"Missing {name}"
out.parent.mkdir(exist_ok=True)
with ZipFile(out, "w", ZIP_DEFLATED, compresslevel=9) as z:
    for name in required:
        z.write(src / name, "qwen-local-writer/" + name)
with ZipFile(out) as z:
    assert not z.testzip()
    names = z.namelist()
    assert len(names) == len(required)
    assert all(n.startswith("qwen-local-writer/") for n in names)
    assert not any("claude" in n or "openai" in n or "gemini" in n for n in names)
print(f"Built {out} ({out.stat().st_size} bytes)")

#!/usr/bin/env python3
"""Build installable WordPress ZIP from source checkout. Python standard library only."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import sys

root = Path(__file__).resolve().parents[1]
slug = 'multi-ai-seo-content-factory'
out = root / 'dist' / f'{slug}.zip'
exclude_parts = {'.git', '.github', '.idea', '.vscode', 'dist', 'scripts', 'tests', '__pycache__', '.pytest_cache'}
exclude_files = {'AGENTS.md', 'SECURITY.md', '.gitignore', '.DS_Store', 'TEST-REPORT-V110.md'}
files = sorted(p for p in root.rglob('*') if p.is_file() and not (set(p.relative_to(root).parts) & exclude_parts) and p.name not in exclude_files)
if not (root / f'{slug}.php').is_file():
    sys.exit('Missing entrypoint')
if not (root / 'vendor/action-scheduler/action-scheduler.php').is_file():
    sys.exit('Missing vendored Action Scheduler')
out.parent.mkdir(exist_ok=True)
with ZipFile(out, 'w', ZIP_DEFLATED, compresslevel=9) as zf:
    for p in files:
        zf.write(p, f'{slug}/{p.relative_to(root).as_posix()}')
with ZipFile(out) as zf:
    bad = zf.testzip()
    if bad or any(not x.startswith(f'{slug}/') for x in zf.namelist()):
        sys.exit(f'Invalid package: {bad}')
print(f'Built {out} ({len(files)} files; {out.stat().st_size} bytes)')

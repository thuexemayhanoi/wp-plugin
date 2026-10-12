#!/usr/bin/env python3
"""Deterministic installable ZIP of the WordPress plugin. Standard library only."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import sys

root = Path(__file__).resolve().parents[1]
slug = 'multi-ai-seo-content-factory'
out = root / 'dist' / f'{slug}.zip'
root_files = {f'{slug}.php', 'uninstall.php', 'readme.txt', 'LICENSE.txt'}
source_dirs = {'includes', 'assets', 'vendor', 'languages', 'samples'}
excluded_names = {'AGENTS.md', 'CLAUDE.md', '.DS_Store', '.env', 'wp-config.php'}
files = sorted(
    p for p in root.rglob('*')
    if p.is_file() and not p.is_symlink()
    and (p.relative_to(root).as_posix() in root_files or p.relative_to(root).parts[0] in source_dirs)
    and p.name not in excluded_names
    and not p.name.endswith(('.bak', '.log', '.tmp', '.swp', '.pyc'))
    and '.git' not in p.relative_to(root).parts
)
for required in (f'{slug}.php', 'vendor/action-scheduler/action-scheduler.php'):
    if not (root / required).is_file():
        sys.exit(f'Missing required source: {required}')
out.parent.mkdir(exist_ok=True)
with ZipFile(out, 'w', ZIP_DEFLATED, compresslevel=9) as zf:
    for p in files:
        zf.write(p, f'{slug}/{p.relative_to(root).as_posix()}')
with ZipFile(out) as zf:
    bad = zf.testzip()
    names = zf.namelist()
    assert not bad
    assert all(n.startswith(f'{slug}/') for n in names)
    assert all(not n.startswith(f'{slug}/tests/') for n in names)
    assert all(not n.startswith(f'{slug}/docs/') for n in names)
print(f'Built {out} ({len(files)} files; {out.stat().st_size} bytes)')

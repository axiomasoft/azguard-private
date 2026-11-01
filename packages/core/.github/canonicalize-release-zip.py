#!/usr/bin/env python3
"""Remove ZIP container variability while retaining all file bytes and modes."""
import os
from pathlib import Path
import sys
import tempfile
import zipfile

path = Path(sys.argv[1])
with zipfile.ZipFile(path) as source:
    entries = [entry for entry in source.infolist() if not entry.is_dir()]
    if len({entry.filename for entry in entries}) != len(entries):
        raise ValueError('duplicate archive member')
    files = [(entry.filename, entry.external_attr, source.read(entry)) for entry in entries]

fd, name = tempfile.mkstemp(dir=path.parent, prefix='.release-zip-')
os.close(fd)
try:
    with zipfile.ZipFile(name, 'w', compression=zipfile.ZIP_STORED) as output:
        for filename, attributes, contents in sorted(files):
            entry = zipfile.ZipInfo(filename, (1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = attributes
            output.writestr(entry, contents)
    os.replace(name, path)
finally:
    if os.path.exists(name):
        os.unlink(name)

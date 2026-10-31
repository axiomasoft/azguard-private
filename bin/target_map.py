#!/usr/bin/env python3
"""Generate the ownership oracle from its preserved revision-2 snapshot.

The snapshot preserves the output of the historical target_map.py (architecture
revision 2); later verified additions belong in ADDITIONS below. Run from the
repository root: python3 bin/target_map.py
"""

import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FIXTURES = ROOT / 'tests/Arch/Fixtures'
ADDITIONS = [
    ('AzGuard\\Diagnostics\\Internal\\Checks\\MorphIdentity', 'class', 'P4',
     'read-only doctor check for stable morph identity'),
    ('AzGuard\\Configuration\\Internal\\CacheSigningConfig', 'class', 'P4',
     'internal cache signing configuration'),
    ('AzGuard\\Kernel\\Internal\\PrimaryReadContext', 'interface', 'P4',
     'internal context for primary authority reads'),
]


def main():
    oracle = json.loads((FIXTURES / 'target-map-baseline.json').read_text())
    targets = {row['target'] for row in oracle['rows']}
    for fqcn, kind, phase, reason in ADDITIONS:
        if fqcn in targets:
            raise ValueError(f'duplicate target: {fqcn}')
        path = 'packages/core/src/' + fqcn.removeprefix('AzGuard\\').replace('\\', '/') + '.php'
        source = (ROOT / path).read_text()
        if kind + ' ' + fqcn.rsplit('\\', 1)[-1] not in source:
            raise ValueError(f'missing declaration: {fqcn}')
        oracle['rows'].append({
            'current': None, 'current_path': None, 'kind': kind, 'lines': None,
            'current_marker': None, 'owner': 'core', 'target': fqcn, 'target_path': path,
            'marker': 'internal', 'enum': None, 'action': 'new', 'phase': phase,
            'reason': reason, 'used_by': None,
        })
        targets.add(fqcn)
    data = json.dumps(oracle, indent=1, ensure_ascii=False).encode()
    (FIXTURES / 'target-map.json').write_bytes(data)
    (FIXTURES / 'target-map.sha256').write_text(hashlib.sha256(data).hexdigest() + '\n')


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Bind before/after read-only proof to a clean isolated candidate."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess


def inventory(root):
    return {str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest()
            for folder in ['packages', 'tests', 'bin']
            for path in (root / folder).rglob('*') if path.is_file()}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--candidate', required=True, type=Path)
    parser.add_argument('--snapshot', required=True, type=Path)
    parser.add_argument('--mode', required=True, choices=['before', 'after'])
    args = parser.parse_args()
    root = args.candidate.resolve()
    if root == Path('/home/vostrikov/projects/packages/azguard').resolve():
        raise RuntimeError('review requires the isolated clean candidate')
    status = subprocess.run(['git', 'status', '--porcelain', '--', 'packages', 'tests', 'bin'],
                            cwd=root, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    if status.stdout:
        print(status.stdout)
        raise RuntimeError('P1.7 literal clean status requirement failed')
    files = inventory(root)
    if args.mode == 'before':
        args.snapshot.parent.mkdir(parents=True, exist_ok=True)
        with args.snapshot.open('x') as target:
            json.dump({'candidate': str(root), 'files': files}, target, indent=2)
        print(json.dumps({'mode': 'before', 'clean': True, 'files': len(files)}))
    else:
        before = json.loads(args.snapshot.read_text())
        if before['candidate'] != str(root) or before['files'] != files:
            raise RuntimeError('P1.7 packages/tests/bin changed during review')
        print(json.dumps({'mode': 'after', 'clean': True, 'unchanged': True, 'files': len(files)}))


if __name__ == '__main__':
    main()

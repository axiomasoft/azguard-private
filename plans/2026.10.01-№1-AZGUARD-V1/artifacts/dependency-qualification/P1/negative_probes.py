#!/usr/bin/env python3
"""Actual P1 negative proofs. Mutate only a disposable copy of a supplied candidate."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

LIVE = Path('/home/vostrikov/projects/packages/azguard').resolve()
SUBJECT = 'packages/core/src/Kernel/Identity/SubjectRef.php'
CODEC = 'packages/core/src/Kernel/Identity/IdentityCodec.php'


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def replace_once(text, old, new):
    if text.count(old) != 1:
        raise RuntimeError('mutation anchor must occur exactly once: ' + old)
    return text.replace(old, new, 1)


def expected_red(output, exit_code, needles):
    if exit_code != 1:
        return False
    try:
        report = json.loads(output.strip())
    except json.JSONDecodeError:
        report = None
        for line in output.splitlines():
            try:
                candidate = json.loads(line)
            except json.JSONDecodeError:
                continue
            if isinstance(candidate, dict) and candidate.get('tool') == 'pest':
                report = candidate
                break
    if isinstance(report, dict) and report.get('tool') == 'pest':
        failures = report.get('failures')
        failed = report.get('failed')
        if (report.get('result') != 'failed' or type(failed) is not int or failed <= 0
                or not isinstance(failures, list) or not failures):
            return False
        names = [re.sub(r'_+', ' ', failure.get('test', '').split('::')[-1]).lower()
                 for failure in failures if isinstance(failure, dict)
                 and isinstance(failure.get('test'), str)]
        return all(any(needle.lower() in name for name in names) for needle in needles)
    return 'FAIL' in output and all(needle in output for needle in needles)


def vendor_copy(source, target):
    # Reflinks use separate inodes; fallback is a physical copy, never hardlinks.
    subprocess.run(['cp', '-a', '--reflink=auto', str(source), str(target)], check=True)
    for name, package in [('azguard', 'core'), ('azguard-filament', 'filament')]:
        path = target / 'axiomasoft' / name
        if path.is_symlink():
            path.unlink()
        elif path.exists():
            shutil.rmtree(path)
        path.symlink_to('../../packages/' + package, target_is_directory=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--candidate', type=Path, required=True)
    parser.add_argument('--item', choices=['P1.1', 'P1.4', 'P1.8'], required=True)
    parser.add_argument('--logs', type=Path, required=True)
    args = parser.parse_args()
    candidate = args.candidate.resolve()
    logs = args.logs.resolve()
    if candidate == LIVE or candidate.is_relative_to(LIVE) or logs.is_relative_to(LIVE):
        raise RuntimeError('supply isolated candidate and external log directory')
    logs.mkdir(parents=True, exist_ok=False)
    originals = {str(p.relative_to(candidate)): sha(p) for folder in ['packages', 'tests', 'bin']
                 for p in (candidate / folder).rglob('*') if p.is_file()}
    results = []

    def run(scratch, name, argv, expected, needles=()):
        env = os.environ.copy()
        # phpunit.xml also force-selects SQLite :memory:; override inherited DB selectors.
        env.update({'APP_ENV': 'testing', 'DB_CONNECTION': 'sqlite', 'DB_DATABASE': ':memory:'})
        process = subprocess.run(argv, cwd=scratch, env=env, text=True,
                                 stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300)
        output = process.stdout
        (logs / (name + '.log')).write_text(output)
        clean = re.sub(r'\x1b\[[0-9;]*m', '', output)
        passed = ((process.returncode == 0) if expected == 'green' else
                  expected_red(clean, process.returncode, needles))
        results.append({'id': name, 'argv': argv, 'exit_code': process.returncode,
                        'expected': expected, 'expected_failure_names': list(needles),
                        'passed': passed, 'log': str(logs / (name + '.log'))})
        if not passed:
            raise RuntimeError(name + ': unexpected outcome; see its preserved log')

    try:
        with tempfile.TemporaryDirectory(prefix='azguard-' + args.item + '-negative-') as tmp:
            scratch = Path(tmp)
            for folder in ['packages', 'tests', 'bin']:
                shutil.copytree(candidate / folder, scratch / folder, symlinks=False)
            for name in ['composer.json', 'composer.lock', 'phpunit.xml', 'phpunit.xml.dist',
                         '.env.testing', 'pint.json', 'phpstan.neon', 'phpstan.neon.dist']:
                if (candidate / name).is_file():
                    shutil.copy2(candidate / name, scratch / name)
            vendor_copy(candidate / 'vendor', scratch / 'vendor')
            # Neither package classes nor SourceScan may resolve back to candidate/live tree.
            autoload_proof = "require 'vendor/autoload.php'; foreach ([AzGuard\\Kernel\\Identity\\IdentityCodec::class, AzGuard\\Kernel\\Identity\\SubjectRef::class, AzGuard\\Tests\\Arch\\SourceScan::class] as $c) {echo (new ReflectionClass($c))->getFileName(), PHP_EOL;}"
            proof = subprocess.run(['php', '-r', autoload_proof], cwd=scratch, text=True,
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30)
            (logs / 'autoload-paths.log').write_text(proof.stdout)
            if proof.returncode or len(proof.stdout.splitlines()) != 3 or any(
                    not Path(line).resolve().is_relative_to(scratch) for line in proof.stdout.splitlines()):
                raise RuntimeError('scratch autoload did not resolve all three classes locally')

            pest = ['php', '-d', 'memory_limit=1G', 'vendor/bin/pest', '--colors=never']
            if args.item == 'P1.1':
                argv = pest + ['tests/Unit/Kernel/Identity/IdentityCodecTest.php', 'tests/Regression/P07Test.php',
                               '--filter', 'gives distinct.*tuples distinct digests|keeps an assignment scope key unambiguous']
                run(scratch, 'compose-baseline', argv, 'green')
                path = scratch / CODEC
                original = path.read_bytes()
                old = 'return json_encode([self::VERSION, ...self::normalize($parts)], self::JSON_FLAGS);'
                new = '''$normalized = self::normalize($parts);
            $flat = [self::VERSION];
            array_walk_recursive($normalized, static function (mixed $part) use (&$flat): void {
                $flat[] = (string) $part;
            });
            return implode(':', $flat);'''
                try:
                    path.write_text(replace_once(original.decode(), old, new))
                    run(scratch, 'compose-red', argv, 'red', ['gives distinct', 'keeps an assignment scope key unambiguous'])
                finally:
                    path.write_bytes(original)
                if path.read_bytes() != original:
                    raise RuntimeError('codec restoration mismatch')
                run(scratch, 'compose-restored', argv, 'green')
            else:
                argv = pest + ['tests/Arch']
                run(scratch, 'arch-baseline', argv, 'green')
                mutations = []
                if args.item == 'P1.4':
                    mutations = [
                        ('framework-import', SUBJECT, 'namespace AzGuard\\Kernel\\Identity;',
                         'namespace AzGuard\\Kernel\\Identity;\n\nuse Illuminate\\Support\\Str;',
                         'kernel depends on nothing but PHP'),
                        ('framework-helper', SUBJECT, 'public function key(): string\n    {',
                         'public function key(): string\n    {\n        now();',
                         'keeps the kernel free of framework helper calls'),
                        ('task-comment', SUBJECT, 'namespace AzGuard\\Kernel\\Identity;',
                         'namespace AzGuard\\Kernel\\Identity;\n\n// see P1.4',
                         'keeps internal task codes out of source comments'),
                        ('public-signature', SUBJECT, 'public function equals(self $other): bool',
                         'public function equals(self $other, bool $strict = true): bool',
                         'keeps both api manifests current'),
                        ('untagged-contract', 'packages/core/src/Contracts/QualificationProbe.php', None,
                         '<?php\n\ndeclare(strict_types=1);\n\nnamespace AzGuard\\Contracts;\n\nfinal class QualificationProbe {}\n',
                         'tags every contract as api or spi'),
                    ]
                else:
                    mutations = [
                        ('debug-call', SUBJECT, 'public function key(): string\n    {',
                         'public function key(): string\n    {\n        dump(1);',
                         'keeps package sources free of debug calls'),
                        ('framework-helper', SUBJECT, 'public function key(): string\n    {',
                         'public function key(): string\n    {\n        now();',
                         'keeps the kernel free of framework helper calls'),
                    ]
                for name, relative, anchor, changed, failure in mutations:
                    path = scratch / relative
                    original = path.read_bytes() if path.exists() else None
                    try:
                        if anchor is None:
                            if original is not None:
                                raise RuntimeError('probe file already exists')
                            path.write_text(changed)
                        else:
                            path.write_text(replace_once(original.decode(), anchor, changed))
                        run(scratch, name + '-red', argv, 'red', [failure])
                    finally:
                        if original is None:
                            path.unlink(missing_ok=True)
                        else:
                            path.write_bytes(original)
                    if original is not None and path.read_bytes() != original:
                        raise RuntimeError(name + ': restoration mismatch')
                    run(scratch, name + '-restored', argv, 'green')
            summary = {'item': args.item, 'candidate': str(candidate), 'passed': True,
                       'results': results, 'mutable_candidate_hashes': originals}
    except Exception as exc:
        summary = {'item': args.item, 'candidate': str(candidate), 'passed': False,
                   'error': str(exc), 'results': results}
    after = {str(p.relative_to(candidate)): sha(p) for folder in ['packages', 'tests', 'bin']
             for p in (candidate / folder).rglob('*') if p.is_file()}
    summary['candidate_unchanged'] = originals == after
    summary['passed'] = summary['passed'] and summary['candidate_unchanged']
    (logs / 'summary.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps({key: summary[key] for key in ['item', 'passed', 'candidate_unchanged']}))
    return 0 if summary['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())

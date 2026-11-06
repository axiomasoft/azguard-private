#!/usr/bin/env python3
import json
import io
import os
from pathlib import Path
import subprocess
import tempfile
import tarfile
import unittest
import zipfile


ROOT = Path(__file__).resolve().parent.parent
TOOL = ROOT / 'bin/provider-archive-evidence.py'


class ProviderArchiveEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='azguard-provider-fixture-')
        self.root = Path(self.temp.name)
        self.repo = self.root / 'repo'
        self.repo.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        (self.repo / '.gitattributes').write_text('/excluded.txt export-ignore\n')
        (self.repo / 'README.md').write_text('fixture\n')
        (self.repo / 'excluded.txt').write_text('not exported\n')
        (self.repo / 'run.sh').write_text('#!/bin/sh\nexit 0\n')
        (self.repo / 'run.sh').chmod(0o755)
        os.symlink('README.md', self.repo / 'readme-link')
        self.git('add', '.gitattributes', 'README.md', 'excluded.txt', 'run.sh', 'readme-link')
        self.git('commit', '-qm', 'fixture')
        self.sha = self.git('rev-parse', 'HEAD').decode().strip()
        self.zip = self.root / 'snapshot.zip'
        self.tgz = self.root / 'snapshot.tar.gz'
        self.zip.write_bytes(self.git('archive', '--format=zip', '--prefix=fixture-abc1234/', self.sha))
        self.tgz.write_bytes(self.git('archive', '--format=tar.gz', '--prefix=fixture-abc1234/', self.sha))

    def tearDown(self):
        self.temp.cleanup()

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.repo), *args], check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

    def verify(self, archive, kind, name, sha=None):
        out = self.root / name
        result = subprocess.run(['python3', str(TOOL), '--repository', str(self.repo), '--commit', sha or self.sha,
                                 '--archive', str(archive), '--kind', kind, '--out', str(out)],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        return result, out

    def test_zip_and_tar_are_nonrelease_and_checksum_complete(self):
        for archive, kind in ((self.zip, 'zip'), (self.tgz, 'tar.gz')):
            with self.subTest(kind=kind):
                result, out = self.verify(archive, kind, 'out-' + kind)
                self.assertEqual(result.returncode, 0, result.stderr)
                mapping = json.loads((out / 'snapshot-mapping.json').read_text())
                bom = json.loads((out / 'snapshot-source.cdx.json').read_text())
                fmt = json.loads((ROOT / 'bin/provider-archive-format.json').read_text())
                fixture = json.loads((ROOT / 'bin/provider-archive-compat.json').read_text())
                self.assertEqual(list(mapping), fmt['required'])
                self.assertEqual(list(fixture), fmt['required'])
                self.assertEqual(mapping['schema'], fixture['schema'])
                self.assertEqual(mapping['qualification'], 'nonrelease-unsigned')
                self.assertFalse(mapping['provider_identity_verified'])
                self.assertIsNone(mapping['resolved_dependency_sbom'])
                self.assertEqual(mapping['commit'], self.sha)
                self.assertNotIn('excluded.txt', [component['name'] for component in bom['components']])
                self.assertEqual(subprocess.run(['sha256sum', '--strict', '--check', 'SHA256SUMS'], cwd=out,
                                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode, 0)

    def test_wrong_content_and_wrong_commit_rejected(self):
        wrong = self.root / 'wrong.zip'
        with zipfile.ZipFile(self.zip) as source, zipfile.ZipFile(wrong, 'w') as target:
            for item in source.infolist():
                data = source.read(item)
                if item.filename.endswith('/README.md'):
                    data = b'modified\n'
                target.writestr(item, data)
        result, _ = self.verify(wrong, 'zip', 'wrong-content')
        self.assertNotEqual(result.returncode, 0)
        (self.repo / 'README.md').write_text('second commit\n')
        self.git('add', 'README.md')
        self.git('commit', '-qm', 'second')
        other = self.git('rev-parse', 'HEAD').decode().strip()
        result, _ = self.verify(self.tgz, 'tar.gz', 'wrong-commit', other)
        self.assertNotEqual(result.returncode, 0)

    def test_traversal_duplicate_and_mode_change_rejected(self):
        for mutation in ('traversal', 'duplicate', 'mode'):
            bad = self.root / (mutation + '.zip')
            with zipfile.ZipFile(self.zip) as source, zipfile.ZipFile(bad, 'w') as target:
                for item in source.infolist():
                    data = source.read(item)
                    if mutation == 'mode' and item.filename.endswith('/run.sh'):
                        item.external_attr = 0o100644 << 16
                    target.writestr(item, data)
                    if mutation == 'duplicate' and item.filename.endswith('/README.md'):
                        target.writestr(item, data)
                if mutation == 'traversal':
                    info = zipfile.ZipInfo('fixture-abc1234/../escape')
                    info.create_system = 3
                    info.external_attr = 0o100644 << 16
                    target.writestr(info, b'escape')
            result, _ = self.verify(bad, 'zip', 'bad-' + mutation)
            self.assertNotEqual(result.returncode, 0, mutation)

        bad_tar = self.root / 'traversal.tar.gz'
        with tarfile.open(self.tgz, 'r:gz') as source, tarfile.open(bad_tar, 'w:gz') as target:
            for item in source:
                target.addfile(item, source.extractfile(item) if item.isfile() else None)
            evil = tarfile.TarInfo('fixture-abc1234/../escape')
            evil.size = 6
            target.addfile(evil, io.BytesIO(b'escape'))
        result, _ = self.verify(bad_tar, 'tar.gz', 'bad-tar-traversal')
        self.assertNotEqual(result.returncode, 0)

    def test_container_change_is_a_distinct_digest(self):
        changed = self.root / 'changed.zip'
        changed.write_bytes(self.zip.read_bytes())
        with zipfile.ZipFile(changed, 'a') as archive:
            archive.comment = b'different container bytes'
        first, one = self.verify(self.zip, 'zip', 'first')
        second, two = self.verify(changed, 'zip', 'second')
        self.assertEqual(first.returncode, 0, first.stderr)
        self.assertEqual(second.returncode, 0, second.stderr)
        one_map = json.loads((one / 'snapshot-mapping.json').read_text())
        two_map = json.loads((two / 'snapshot-mapping.json').read_text())
        self.assertNotEqual(one_map['archive_sha256'], two_map['archive_sha256'])
        self.assertEqual(one_map['source_sbom_sha256'], two_map['source_sbom_sha256'])

    def test_existing_output_is_not_overwritten(self):
        out = self.root / 'occupied'
        out.mkdir()
        marker = out / 'snapshot-mapping.json'
        marker.write_text('preserve')
        result, _ = self.verify(self.zip, 'zip', 'occupied')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(marker.read_text(), 'preserve')
        self.assertEqual(sorted(path.name for path in out.iterdir()), ['snapshot-mapping.json'])


if __name__ == '__main__':
    unittest.main()

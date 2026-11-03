#!/usr/bin/env python3
"""Compare one provider snapshot with an exact Git commit; never creates an attestation.

This local evidence describes the exact archive bytes supplied to this invocation.
It cannot guarantee what a provider serves for a later request.
"""

import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import stat
import subprocess
import sys
import tarfile
import tempfile
import zipfile

MAX_MEMBER = 256 * 1024 * 1024
MAX_TOTAL = 1024 * 1024 * 1024
MAX_ARCHIVE = 256 * 1024 * 1024


def checked_name(name):
    if not name or name.startswith('/') or '\\' in name or '\0' in name:
        raise ValueError('unsafe archive path')
    parts = name.rstrip('/').split('/')
    if not parts or any(part in ('', '.', '..') for part in parts):
        raise ValueError('unsafe archive path')
    return parts


def digest_stream(stream, size):
    if size < 0 or size > MAX_MEMBER:
        raise ValueError('archive member exceeds size limit')
    digest = hashlib.sha256()
    remaining = size
    while remaining:
        chunk = stream.read(min(1024 * 1024, remaining))
        if not chunk:
            raise ValueError('truncated archive member')
        digest.update(chunk)
        remaining -= len(chunk)
    if stream.read(1):
        raise ValueError('archive member exceeds declared size')
    return digest.hexdigest()


def read_zip(path):
    entries = []
    with zipfile.ZipFile(path) as archive:
        for item in archive.infolist():
            parts = checked_name(item.filename)
            mode = item.external_attr >> 16
            kind = stat.S_IFMT(mode)
            if item.is_dir():
                if kind not in (0, stat.S_IFDIR) or item.file_size != 0:
                    raise ValueError('invalid ZIP directory')
                entries.append((parts, 'directory', 0, None, 0))
            elif kind == stat.S_IFLNK:
                with archive.open(item) as stream:
                    digest = digest_stream(stream, item.file_size)
                entries.append((parts, 'symlink', 0, digest, item.file_size))
            elif kind in (0, stat.S_IFREG):
                # Git's ZIP writer encodes ordinary non-executable files as
                # DOS entries with zero mode; executable files use Unix mode.
                if not ((item.create_system == 0 and mode == 0)
                        or (item.create_system == 3 and kind == stat.S_IFREG)):
                    raise ValueError('ZIP lacks Unix file mode')
                with archive.open(item) as stream:
                    digest = digest_stream(stream, item.file_size)
                entries.append((parts, 'file', bool(mode & 0o111), digest, item.file_size))
            else:
                raise ValueError('unsupported ZIP member type')
    return entries


def read_tar(fileobj, mode):
    entries = []
    with tarfile.open(fileobj=fileobj, mode=mode) as archive:
        for item in archive:
            parts = checked_name(item.name)
            if item.isdir():
                if item.size != 0:
                    raise ValueError('invalid TAR directory')
                entries.append((parts, 'directory', 0, None, 0))
            elif item.issym():
                if item.size != 0:
                    raise ValueError('invalid TAR symlink')
                target = item.linkname.encode('utf-8')
                if len(target) > MAX_MEMBER:
                    raise ValueError('symlink target exceeds size limit')
                entries.append((parts, 'symlink', 0, hashlib.sha256(target).hexdigest(), len(target)))
            elif item.isfile():
                stream = archive.extractfile(item)
                if stream is None:
                    raise ValueError('unreadable TAR member')
                with stream:
                    digest = digest_stream(stream, item.size)
                entries.append((parts, 'file', bool(item.mode & 0o111), digest, item.size))
            else:
                raise ValueError('unsupported TAR member type')
    return entries


def normalize(entries, prefixed):
    result = {}
    seen = set()
    prefix = None
    root_seen = False
    total = 0
    for parts, kind, executable, digest, size in entries:
        if prefixed:
            if prefix is None:
                prefix = parts[0]
            if parts[0] != prefix:
                raise ValueError('archive has multiple roots')
            parts = parts[1:]
            if not parts:
                if kind != 'directory' or root_seen:
                    raise ValueError('archive root is not a directory')
                root_seen = True
                continue
        name = '/'.join(parts)
        if name in seen:
            raise ValueError('duplicate archive member')
        seen.add(name)
        if kind != 'directory':
            total += size
            if total > MAX_TOTAL:
                raise ValueError('archive exceeds size limit')
            result[name] = {'type': kind, 'executable': executable, 'sha256': digest}
    if not result:
        raise ValueError('empty archive')
    return dict(sorted(result.items()))


def git_output(repository, *args):
    completed = subprocess.run(['git', '-C', str(repository), *args], stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, check=False)
    if completed.returncode != 0:
        raise ValueError('git could not read the requested commit')
    return completed.stdout


def build_evidence(repository, commit, archive, archive_kind):
    if re.fullmatch(r'[0-9a-f]{40}', commit) is None:
        raise ValueError('expected a full SHA-1 commit')
    actual = git_output(repository, 'rev-parse', '--verify', commit + '^{commit}').decode().strip()
    if actual != commit:
        raise ValueError('commit identity mismatch')
    tree = git_output(repository, 'rev-parse', '--verify', commit + '^{tree}').decode().strip()
    expected_tar = git_output(repository, 'archive', '--format=tar', commit)
    expected = normalize(read_tar(io.BytesIO(expected_tar), 'r:'), False)
    with open(archive, 'rb') as stream:
        archive_bytes = stream.read(MAX_ARCHIVE + 1)
    if len(archive_bytes) > MAX_ARCHIVE:
        raise ValueError('compressed archive exceeds size limit')
    if archive_kind == 'zip':
        observed = normalize(read_zip(io.BytesIO(archive_bytes)), True)
    elif archive_kind == 'tar.gz':
        observed = normalize(read_tar(io.BytesIO(archive_bytes), 'r:gz'), True)
    else:
        raise ValueError('unsupported archive kind')
    if observed != expected:
        raise ValueError('provider archive differs from exported Git tree')
    components = []
    for name, entry in observed.items():
        components.append({'type': 'file', 'name': name,
                           'hashes': [{'alg': 'SHA-256', 'content': entry['sha256']}],
                           'properties': [{'name': 'azguard:git-entry-type', 'value': entry['type']},
                                          {'name': 'azguard:executable', 'value': str(bool(entry['executable'])).lower()}]})
    sbom = {'bomFormat': 'CycloneDX', 'specVersion': '1.5', 'version': 1,
            'metadata': {'component': {'type': 'application', 'name': 'git-source-snapshot', 'version': commit}},
            'components': components}
    archive_hash = hashlib.sha256(archive_bytes).hexdigest()
    return sbom, {'schema': 'azguard.provider-archive.local.v1', 'qualification': 'nonrelease-unsigned',
                  'commit': commit, 'tree_git_sha1': tree, 'archive_kind': archive_kind,
                  'archive_sha256': archive_hash, 'source_sbom_sha256': None,
                  'resolved_dependency_sbom': None, 'qualification_references': [],
                  'provider_identity_verified': False,
                  'limitation': 'Only these bytes were checked; future provider downloads may differ.'}, archive_bytes


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repository', required=True)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--archive', required=True)
    parser.add_argument('--kind', choices=['zip', 'tar.gz'], required=True)
    parser.add_argument('--out', required=True)
    args = parser.parse_args()
    try:
        sbom, mapping, archive_bytes = build_evidence(Path(args.repository), args.commit, Path(args.archive), args.kind)
        sbom_bytes = (json.dumps(sbom, indent=2, ensure_ascii=False) + '\n').encode()
        with tempfile.NamedTemporaryFile(prefix='azguard-provider-sbom-', suffix='.json') as temporary:
            temporary.write(sbom_bytes)
            temporary.flush()
            result = subprocess.run(['bash', str(Path(__file__).resolve().parent / 'security-tools.sh'),
                                     'cyclonedx-cli', '--', 'validate', '--input-file', temporary.name,
                                     '--input-format', 'json', '--input-version', 'v1_5', '--fail-on-errors'],
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
            if result.returncode != 0:
                raise ValueError('CycloneDX schema verification unavailable or failed')
        mapping['source_sbom_sha256'] = hashlib.sha256(sbom_bytes).hexdigest()
        mapping_bytes = (json.dumps(mapping, indent=2, ensure_ascii=False) + '\n').encode()
        archive_name = Path(args.archive).name
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]*', archive_name) or archive_name in ('SHA256SUMS', 'snapshot-source.cdx.json', 'snapshot-mapping.json'):
            raise ValueError('unsafe archive filename')
        checksums = (mapping['archive_sha256'] + '  ' + archive_name + '\n'
                     + mapping['source_sbom_sha256'] + '  snapshot-source.cdx.json\n'
                     + hashlib.sha256(mapping_bytes).hexdigest() + '  snapshot-mapping.json\n').encode()
        out = Path(args.out)
        out.mkdir(parents=True, exist_ok=True)
        written = []
        try:
            for name, contents in [(archive_name, archive_bytes), ('snapshot-source.cdx.json', sbom_bytes), ('snapshot-mapping.json', mapping_bytes), ('SHA256SUMS', checksums)]:
                path = out / name
                with open(path, 'xb') as stream:
                    written.append(path)
                    stream.write(contents)
        except BaseException:
            for path in written:
                path.unlink(missing_ok=True)
            raise
        print('Verified these archive bytes against Git; nonrelease, unsigned, provider identity not verified.')
        return 0
    except (OSError, ValueError, subprocess.SubprocessError, tarfile.TarError, zipfile.BadZipFile) as error:
        print('provider archive: ' + str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())

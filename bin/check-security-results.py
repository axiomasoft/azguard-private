#!/usr/bin/env python3
"""Fail closed on scanner status and reports, and record their commit and scope."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

SCOPES = {
    'codeql-actions': 'CodeQL Actions extraction across the checked-out repository',
    'codeql-javascript-typescript': 'CodeQL JavaScript/TypeScript extraction across the checked-out repository, including docs/.vitepress/config.ts',
    'zizmor': 'GitHub Actions workflows in .github/workflows; pedantic, medium and higher',
    'gitleaks-worktree': 'Gitleaks directory scan of the checked-out worktree using the pinned rules without allowlists',
    'gitleaks-history': 'Gitleaks git patch scan of all commits reachable from the checked-out commit, including merge changes, using the pinned rules without allowlists',
}


def validate_sarif(data, scanner):
    if not isinstance(data, dict) or data.get('version') != '2.1.0':
        raise ValueError('invalid SARIF version')
    runs = data.get('runs')
    if not isinstance(runs, list) or not runs:
        raise ValueError('SARIF has no runs')
    expected = 'codeql' if scanner.startswith('codeql-') else 'zizmor'
    for run in runs:
        if not isinstance(run, dict):
            raise ValueError('invalid SARIF run')
        tool = run.get('tool', {}).get('driver', {}).get('name', '')
        if not isinstance(tool, str) or expected not in tool.lower():
            raise ValueError('unexpected SARIF tool')
        if scanner.startswith('codeql-'):
            # The workflow supplies this category to CodeQL for each language.
            # A clean report from a different matrix lane must not satisfy it.
            category = run.get('automationDetails', {}).get('id')
            if category != f'/language:{scanner.removeprefix("codeql-")}/':
                raise ValueError('CodeQL SARIF category does not match scanner scope')
        results = run.get('results')
        if not isinstance(results, list) or results:
            raise ValueError('SARIF results missing, invalid or contain findings')
        invocations = run.get('invocations')
        if not isinstance(invocations, list) or not invocations:
            raise ValueError('missing or invalid SARIF invocations')
        for invocation in invocations:
            if not isinstance(invocation, dict) or invocation.get('executionSuccessful') is not True:
                raise ValueError('SARIF invocation failed or incomplete')
            for key in ('toolExecutionNotifications', 'toolConfigurationNotifications'):
                notifications = invocation.get(key, [])
                if not isinstance(notifications, list) or any(
                    not isinstance(item, dict) or item.get('level', 'warning') == 'error'
                    for item in notifications
                ):
                    raise ValueError('SARIF contains an error notification')


def check(scanner, outcome, sha, report_dir, metadata):
    errors = []
    report_dir = Path(report_dir)
    if scanner not in SCOPES:
        errors.append('unknown scanner scope')
    if outcome != 'success':
        errors.append(f'scanner outcome is {outcome!r}, not success')
    if not re.fullmatch(r'[0-9a-f]{40}', sha):
        errors.append('invalid commit SHA')
    try:
        head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
        if sha != head:
            errors.append('checkout does not match requested SHA')
    except (OSError, subprocess.CalledProcessError):
        errors.append('could not identify checkout SHA')
    reports = sorted(report_dir.glob('*.json' if scanner.startswith('gitleaks-') else '*.sarif'))
    # metadata is not a scanner report.
    reports = [path for path in reports if path != Path(metadata)]
    digest = None
    if len(reports) != 1:
        errors.append('expected exactly one scanner report')
    else:
        try:
            raw = reports[0].read_bytes()
            digest = hashlib.sha256(raw).hexdigest()
            data = json.loads(raw)
            if scanner.startswith('gitleaks-'):
                if not isinstance(data, list) or data:
                    raise ValueError('Gitleaks report invalid or contains findings')
            else:
                validate_sarif(data, scanner)
        except (OSError, ValueError, TypeError, AttributeError) as exc:
            errors.append(f'invalid scanner report: {exc}')
    record = {'sha': sha, 'scanner': scanner, 'scope': SCOPES.get(scanner),
              'outcome': outcome, 'report_sha256': digest, 'errors': errors}
    try:
        Path(metadata).parent.mkdir(parents=True, exist_ok=True)
        Path(metadata).write_text(json.dumps(record, indent=2) + '\n')
    except OSError as exc:
        errors.append(f'cannot write metadata: {exc}')
    for error in errors:
        print(f'security result: {error}', file=sys.stderr)
    return bool(errors)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--scanner', required=True)
    parser.add_argument('--outcome', required=True)
    parser.add_argument('--sha', required=True)
    parser.add_argument('--report-dir', required=True)
    parser.add_argument('--metadata', required=True)
    return check(**vars(parser.parse_args()))


if __name__ == '__main__':
    sys.exit(main())

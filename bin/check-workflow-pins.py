#!/usr/bin/env python3
"""Fail closed on unsafe workflow references and permissions (requires system PyYAML)."""

import pathlib
import re
import sys

import yaml

ROOT = pathlib.Path(__file__).resolve().parent.parent
LOCAL_WORKFLOWS = {
    './.github/workflows/' + name + '.yml' for name in (
        'tests', 'static-analysis', 'code-style', 'docs', 'tests-nightly',
        'compatibility', 'workers', 'security', 'mutation', 'split',
    )
}
WRITE_PERMISSIONS = {
    ('codeql.yml', 'analyze'): {'security-events'},
    ('docs-deploy.yml', 'deploy'): {'pages', 'id-token'},
    ('release.yml', 'artifacts'): {'id-token', 'attestations'},
    ('release.yml', 'release'): {'contents'},
    ('dependabot-auto-merge.yml', 'auto-merge'): {'contents', 'pull-requests'},
}
EXTERNAL = re.compile(r'^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*@[0-9a-f]{40}$')
UNTRUSTED = re.compile(r'\$\{\{[^}]*(?:github\.event\.|github\.head_ref|github\.ref_name|inputs\.)', re.I)


class Loader(yaml.SafeLoader):
    pass


def mapping(loader, node, deep=False):
    result = {}
    for key_node, value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        if key in result:
            raise ValueError(f'duplicate YAML key: {key}')
        result[key] = loader.construct_object(value_node, deep=deep)
    return result


Loader.add_constructor(yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, mapping)


def check_workflow(text, filename):
    errors = []
    try:
        workflow = yaml.load(text, Loader=Loader)
        if not isinstance(workflow, dict):
            raise ValueError('workflow must be a mapping')
        if workflow.get('permissions') != {'contents': 'read'}:
            errors.append('root permissions must be contents: read')
        jobs = workflow.get('jobs')
        if not isinstance(jobs, dict):
            raise ValueError('jobs must be a mapping')
        events = workflow.get('on', workflow.get(True))
        if events == 'pull_request_target' or isinstance(events, (dict, list)) and 'pull_request_target' in events:
            errors.append('pull_request_target is not permitted')
        for job_id, job in jobs.items():
            if not isinstance(job, dict):
                raise ValueError(f'{job_id}: job must be a mapping')
            permissions = job.get('permissions', {'contents': 'read'})
            if not isinstance(permissions, dict):
                errors.append(f'{job_id}: permissions must be a mapping')
            else:
                allowed = WRITE_PERMISSIONS.get((filename, job_id), set())
                for permission, level in permissions.items():
                    if level not in ('read', 'write', 'none') or (level == 'write' and permission not in allowed):
                        errors.append(f'{job_id}: forbidden permission {permission}: {level}')
            nodes = [job] + job.get('steps', [])
            for node in nodes:
                if not isinstance(node, dict):
                    raise ValueError(f'{job_id}: step must be a mapping')
                ref = node.get('uses')
                if ref is not None:
                    if not isinstance(ref, str) or not (ref in LOCAL_WORKFLOWS or EXTERNAL.fullmatch(ref)):
                        errors.append(f'{job_id}: forbidden uses: {ref}')
                    if isinstance(ref, str) and ref.lower().startswith('actions/checkout@'):
                        if not isinstance(node.get('with'), dict) or node['with'].get('persist-credentials') is not False:
                            errors.append(f'{job_id}: checkout must disable persisted credentials')
                run = node.get('run', '')
                if not isinstance(run, str) or UNTRUSTED.search(run):
                    errors.append(f'{job_id}: untrusted expression in run')
    except (yaml.YAMLError, ValueError, TypeError) as exc:
        errors.append(f'invalid workflow: {exc}')
    return errors


def main():
    paths = sorted((ROOT / '.github/workflows').glob('*.yml')) + sorted((ROOT / '.github/workflows').glob('*.yaml'))
    if not paths:
        print('No workflows found', file=sys.stderr)
        return 1
    errors = [(path, error) for path in paths for error in check_workflow(path.read_text(), path.name)]
    for path, error in errors:
        print(f'{path.relative_to(ROOT)}: {error}', file=sys.stderr)
    if not errors:
        print(f'Checked {len(paths)} workflows')
    return bool(errors)


if __name__ == '__main__':
    sys.exit(main())

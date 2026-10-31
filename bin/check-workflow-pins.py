#!/usr/bin/env python3
"""Fail closed on unsafe workflow references and permissions."""

import pathlib
import argparse
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
    ('dependabot-auto-merge.yml', 'auto-merge'): {'pull-requests'},
    ('dependabot-auto-merge.yml', 'sweep'): {'pull-requests'},
}
EXTERNAL = re.compile(r'^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*@[0-9a-f]{40}$')
UNTRUSTED = re.compile(r'\$\{\{[^}]*(?:\bgithub\b|\binputs\b)', re.I)
# Only these action inputs are known to consume the value as data, not script.
DATA_INPUTS = {
    ('softprops/action-gh-release', 'name'),
    ('softprops/action-gh-release', 'prerelease'),
    ('actions/upload-artifact', 'name'),
    ('symplify/monorepo-split-github-action', 'tag'),
}
SHELL_ENV = {'BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS', 'CDPATH', 'GLOBIGNORE', 'IFS'}


def untrusted(value):
    return isinstance(value, str) and UNTRUSTED.search(value)


def check_shell_defaults(defaults, errors, where):
    if isinstance(defaults, dict) and isinstance(defaults.get('run'), dict):
        if untrusted(defaults['run'].get('shell')):
            errors.append(f'{where}: untrusted expression in shell')


def check_env(env, errors, where):
    if not isinstance(env, dict):
        return set()
    names = {str(name) for name, value in env.items() if untrusted(value)}
    for name in names & SHELL_ENV:
        errors.append(f'{where}: untrusted shell control environment {name}')
    return names


def unquoted_env_reference(run, name):
    # Accept ordinary double-quoted data expansions only. Other shell constructs
    # (including indirect and parameter-operator expansions) fail closed.
    variable = re.escape(name)
    safe = re.compile(r'"[^"\n]*\$(?:' + variable + r'\b|\{' + variable + r'\})[^"\n]*"')
    remainder = safe.sub('', run)
    return re.search(r'\$(?:' + variable + r'\b|\{[^}]*\b' + variable + r'\b[^}]*\}|!)', remainder) is not None


def executes_env_as_script(run, names):
    # Quoting does not help when a value is explicitly handed to a shell.
    sink = re.search(r'\b(?:eval|source)\b|(?:^|[\n;&|])\s*\.\s|\b(?:ba|da|z|k)?sh\s+-[^\n]*c\b', run)
    return bool(sink and any(re.search(r'\$(?:' + re.escape(name) + r'\b|\{[^}]*\b' + re.escape(name) + r'\b)', run) for name in names))

# This privileged trigger must never check out or execute PR-controlled code.
# Deliberately require the complete job: changes to this seam require policy review.
DEPENDABOT_JOB = {
    'name': 'Disable auto-merge on Dependabot PR', 'runs-on': 'ubuntu-latest',
    'concurrency': {'group': 'dependabot-auto-merge-${{ github.event.pull_request.number }}', 'cancel-in-progress': False},
    'if': "github.event_name == 'pull_request_target' && github.event.pull_request.user.login == 'dependabot[bot]' && github.event.pull_request.head.repo.full_name == github.repository && github.event.pull_request.base.repo.full_name == github.repository\n",
    'permissions': {'pull-requests': 'write'},
    'steps': [
        {'name': 'Fetch Dependabot metadata', 'id': 'meta', 'uses': 'dependabot/fetch-metadata@25dd0e34f4fe68f24cc83900b1fe3fe149efef98'},
        {'name': 'Disable Dependabot auto-merge', 'if': 'always()',
         'run': '''enabled=$(gh pr view "$PR_URL" --json autoMergeRequest --jq '.autoMergeRequest != null')
if [[ "$enabled" == true ]]; then
  gh pr merge --disable-auto "$PR_URL"
fi
[[ "$META_RESULT" == success ]]
''',
         'env': {'PR_URL': '${{ github.event.pull_request.html_url }}', 'GH_TOKEN': '${{ secrets.GITHUB_TOKEN }}',
                 'META_RESULT': '${{ steps.meta.outcome }}'}},
    ],
}
DEPENDABOT_SWEEP = {
    'name': 'Disable existing Dependabot auto-merge requests',
    'if': "github.event_name != 'pull_request_target'",
    'runs-on': 'ubuntu-latest',
    'permissions': {'pull-requests': 'write'},
    'steps': [{'name': 'Reconcile open Dependabot PRs',
               'run': '''numbers=$(gh api --paginate "repos/$REPOSITORY/pulls?state=open&per_page=100" --jq '.[] | select(.user.login == "dependabot[bot]") | .number')
for number in $numbers; do
  [[ "$number" =~ ^[0-9]+$ ]]
  enabled=$(gh pr view "$number" --repo "$REPOSITORY" --json autoMergeRequest --jq '.autoMergeRequest != null')
  if [[ "$enabled" == true ]]; then
    gh pr merge --disable-auto "$number" --repo "$REPOSITORY"
  fi
done
''',
               'env': {'REPOSITORY': '${{ github.repository }}', 'GH_TOKEN': '${{ secrets.GITHUB_TOKEN }}'}}],
}


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
        check_shell_defaults(workflow.get('defaults'), errors, 'workflow')
        workflow_env = check_env(workflow.get('env'), errors, 'workflow')
        if events == 'pull_request_target' or isinstance(events, (dict, list)) and 'pull_request_target' in events:
            if not (filename == 'dependabot-auto-merge.yml'
                    and events == {'pull_request_target': {'types': ['opened', 'synchronize', 'reopened']}, 'workflow_dispatch': None, 'schedule': [{'cron': '*/15 * * * *'}]}
                    and jobs == {'auto-merge': DEPENDABOT_JOB, 'sweep': DEPENDABOT_SWEEP}
                    and set(workflow) <= {'name', 'on', True, 'permissions', 'defaults', 'jobs'}
                    and workflow.get('defaults') == {'run': {'shell': 'bash'}}):
                errors.append('pull_request_target is only permitted for the fixed Dependabot job')
        for job_id, job in jobs.items():
            if not isinstance(job, dict):
                raise ValueError(f'{job_id}: job must be a mapping')
            permissions = job.get('permissions', {'contents': 'read'})
            check_shell_defaults(job.get('defaults'), errors, job_id)
            job_env = workflow_env | check_env(job.get('env'), errors, job_id)
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
                    inputs = node.get('with', {})
                    if isinstance(inputs, dict):
                        action = ref.split('@')[0].lower() if isinstance(ref, str) else ''
                        for key, value in inputs.items():
                            if untrusted(value) and (action, key) not in DATA_INPUTS:
                                errors.append(f'{job_id}: untrusted expression in action input {key}')
                if untrusted(node.get('shell')):
                    errors.append(f'{job_id}: untrusted expression in shell')
                env_names = job_env | check_env(node.get('env'), errors, job_id)
                run = node.get('run', '')
                if not isinstance(run, str) or UNTRUSTED.search(run):
                    errors.append(f'{job_id}: untrusted expression in run')
                elif any(unquoted_env_reference(run, name) for name in env_names):
                    errors.append(f'{job_id}: unquoted untrusted environment in run')
                elif executes_env_as_script(run, env_names):
                    errors.append(f'{job_id}: untrusted environment passed to shell execution')
    except (yaml.YAMLError, ValueError, TypeError) as exc:
        errors.append(f'invalid workflow: {exc}')
    return errors


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', nargs='?', type=pathlib.Path, default=ROOT / '.github/workflows')
    directory = parser.parse_args().directory
    paths = sorted(directory.glob('*.yml')) + sorted(directory.glob('*.yaml'))
    if not paths:
        print('No workflows found', file=sys.stderr)
        return 1
    errors = [(path, error) for path in paths for error in check_workflow(path.read_text(), path.name)]
    for path, error in errors:
        print(f'{path}: {error}', file=sys.stderr)
    if not errors:
        print(f'Checked {len(paths)} workflows')
    return bool(errors)


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""Fail closed on unsafe workflow references and permissions."""

import pathlib
import argparse
import hashlib
import re
import sys

import yaml

ROOT = pathlib.Path(__file__).resolve().parent.parent
LOCAL_WORKFLOWS = {
    './.github/workflows/' + name + '.yml' for name in (
        'tests', 'static-analysis', 'code-style', 'docs', 'tests-nightly',
        'compatibility', 'workers', 'security', 'split',
        'codeql', 'zizmor', 'secret-scan',
    )
}
SELF_WORKFLOWS = {'$' + name[1:] for name in LOCAL_WORKFLOWS}
WRITE_PERMISSIONS = {
    ('codeql.yml', 'analyze'): {'security-events'},
    ('release.yml', 'codeql'): {'security-events'},
    ('docs-deploy.yml', 'deploy'): {'pages', 'id-token'},
    ('release.yml', 'provenance'): {'id-token', 'attestations'},
    ('release.yml', 'release'): {'contents'},
    ('dependabot-auto-merge.yml', 'auto-merge'): {'pull-requests'},
    ('dependabot-auto-merge.yml', 'sweep'): {'pull-requests'},
}
EXTERNAL = re.compile(r'^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*@[0-9a-f]{40}$')
# Exact workflow snapshots are the review boundary, not a shell-safety proof.
# Any change to a snapshot requires reviewing the complete workflow and updating
# its digest. Changes to this checker or its digests themselves require trusted
# review enforced outside this checker (for example, repository rules/CODEOWNERS).
# Pin and permissions checks still apply to every snapshot.
REVIEWED_WORKFLOWS = {
    'bench.yml': '7c74b235f4378986e824240728c964dc86ffe667a8f665e001156181c5107f9d',
    'code-style.yml': '47fc04265aa785339ee1f6e024feb4179268f5b50e69038894803d200ce0d51d',
    'codeql.yml': '16e1c3bf853fdb28d2e0cab5f2a1c2d646fb34b95afd9833b33bcaf5fa64d276',
    'compatibility.yml': '3d3728c0af2f0f3cb5ebf591ebd85f8ff3ec29f748844ac235de19862ee44212',
    'dependabot-auto-merge.yml': '37976e5af8911e7ba507395c74cf5d453f9ddbafc3b627ba87118e6eab8f0a54',
    'docs-deploy.yml': '15efe0198116c95ea5205fc336f8eef90139efc68e157b00d1259c1dece6d5bf',
    'docs.yml': '4ae6826bdb0d02c9df13a82b4865c85bc21811b0f296412c8f02018422ba22d6',
    'pr-check.yml': 'b72cad5c81a8fe3d9692fd65af390ace4a63c705f7219dc6f8b5f929fd0bb493',
    'release.yml': '5583f04692309b8faa76bc650fba150699538c7b7d4fb2138a7fa99da431e7a7',
    'secret-scan.yml': 'f290709551ac446e93dc2350348fe5f11dfa7a21d36638d7082abdf0a5918ae7',
    'security.yml': '3c4974e434f88bf2350d8083e89744b8e1d9d62d17d404dcd2d3441539a746bc',
    'split.yml': '44098f68ec5a8db29afba6ccab2ca91b2539dbc0d9622ba6da59051b1a912d11',
    'static-analysis.yml': '75cfd1b9002f8fd952cf48a51dd07ab94a3836385ba3c6b9351ed79b677d1b05',
    'tests-nightly.yml': 'f8a9a70f743fd556b8907537fa45c959d6f55b187d98d0f3d87bd9152a98021c',
    'tests.yml': '7917d7d2da679fa6318914499a48f31ed0843fc05d5d55cc2de2e0fd3bccc9ec',
    'workers.yml': 'a0d14c246f8bb936af5fac18326cfc84c795304bf93f31869b5ff5a1522ac546',
    'zizmor.yml': 'd7338cef329eff163bb36db65998823d8ff92839c1b100f29eb481575ae95581',
}
# Set each digest only after independent review of the exact package workflow.
# Until then these required workflows deliberately fail closed.
REVIEWED_PACKAGE_WORKFLOWS = {
    'packages/core/.github/workflows/provenance.yml': '5d927ba9ef7e699543b204b5749eeb7bdf00952cb42ae389e4fcdbf5c28332ac',
    'packages/filament/.github/workflows/provenance.yml': '5d927ba9ef7e699543b204b5749eeb7bdf00952cb42ae389e4fcdbf5c28332ac',
}
UNTRUSTED = re.compile(r'\$\{\{.*?(?:\bgithub\b|\binputs\b|\benv\b)', re.I | re.S)
# Only these action inputs are known to consume the value as data, not script.
DATA_INPUTS = {
    ('softprops/action-gh-release', 'name'),
    ('softprops/action-gh-release', 'prerelease'),
    ('actions/upload-artifact', 'name'),
    ('symplify/monorepo-split-github-action', 'tag'),
}
SHELL_ENV = {'BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS', 'CDPATH', 'GLOBIGNORE', 'IFS'}

# Reviewed scripts with quoted untrusted data. Any change requires a fresh review;
# this is deliberately not a general-purpose shell or data-flow analyzer.
REVIEWED_RUNS = {
    ('compatibility.yml', 'compare', '103f19fdbdfbff709f6df45dc4acdb2f71d6fa98a3b848c891d5ca1ea8e10f5a'): {'LABELS'},
    ('release.yml', 'validate', '11304bf0fcbccfddb7306b4af5beaa5f8d1d0e2130781ad755a5f4792be09680'): {'TAG', 'TAGGED_SHA'},
    ('release.yml', 'validate', 'b630d6f8922cfa92d2d800e473ba4b233a18d2f895e99d275b9e06680fe5d80c'): {'TAGGED_SHA'},
    ('release.yml', 'validate', '5913c5f5011094899d980d03e64ee6179f91653ab30c3da98f9bd3bba0af9421'): {'TAG', 'TAGGED_SHA'},
    ('bench.yml', 'bench', '836383ae1fbf4b725d9c7b0375601884902fff4f1a9510872b199caf77a795e9'): {'TIER'},
    ('workers.yml', 'worker', '78051647250c4c30636dfc866d1c0eec6ce72adc9879cac59d60df1acab4dd95'): {'WORKER_EVENT', 'WORKER_MEMORY'},
    ('dependabot-auto-merge.yml', 'auto-merge', 'ed305084cd8b31a50d22da4295e95763771a6146013ddb30f2bee050fa36b167'): {'PR_URL'},
    ('dependabot-auto-merge.yml', 'sweep', 'dde5b3086abe897f595e157606e8b1583c12877e935c6b1211eda3ae722e3027'): {'REPOSITORY'},
}


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


def safe_env_use(run, names, filename, job_id):
    used = {name for name in names if re.search(r'\$(?:' + re.escape(name) + r'\b|\{[^}]*\b' + re.escape(name) + r'\b|!)', run)}
    if not used:
        return True
    key = (filename, job_id, hashlib.sha256(run.encode()).hexdigest())
    if used <= REVIEWED_RUNS.get(key, set()):
        return True
    # Small data-only subset; reject other shell syntax and commands rather than
    # attempting to recognize every interpreter or execution sink.
    if not re.fullmatch(r'(?:echo|git diff) (?:[A-Za-z0-9_./:! -]|"[A-Za-z0-9_./:!-]+"|"\$(?:[A-Za-z_][A-Za-z0-9_]*|\{[A-Za-z_][A-Za-z0-9_]*\})")+', run):
        return False
    return not any(unquoted_env_reference(run, name) for name in used)

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
         'run': '''set -Eeuo pipefail
enabled=$(gh pr view "$PR_URL" --json autoMergeRequest --jq '.autoMergeRequest != null')
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
               'run': '''set -Eeuo pipefail
numbers=$(gh api --paginate "repos/$REPOSITORY/pulls?state=open&per_page=100" --jq '.[] | select(.user.login == "dependabot[bot]") | .number')
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


def check_unreviewed(workflow, jobs, errors):
    """Accept only a tiny non-executing data example without a snapshot.

    This is intentionally not shell analysis. In particular, unknown env names,
    outputs, runners, containers, actions and scripts require a full review.
    """
    if set(workflow) - {'name', 'on', True, 'permissions', 'jobs'}:
        errors.append('unreviewed workflow configuration')
    for job_id, job in jobs.items():
        if not isinstance(job, dict):
            continue
        if set(job) - {'name', 'runs-on', 'permissions', 'steps'} or job.get('runs-on') != 'ubuntu-latest':
            errors.append(f'{job_id}: unreviewed job configuration')
        for step in job.get('steps', []):
            if not isinstance(step, dict):
                continue
            if set(step) - {'name', 'uses', 'with', 'run', 'env'}:
                errors.append(f'{job_id}: unreviewed step configuration')
            env = step.get('env', {})
            if not isinstance(env, dict) or set(env) - {'TITLE'}:
                errors.append(f'{job_id}: unreviewed environment')
            elif 'TITLE' in env and (not isinstance(env['TITLE'], str) or
                                     ('${{' in env['TITLE'] and env['TITLE'] != '${{ github.event.pull_request.title }}')):
                errors.append(f'{job_id}: unreviewed TITLE value')
            if 'run' in step and step['run'] not in ('echo safe', 'echo "$TITLE"'):
                errors.append(f'{job_id}: unreviewed script')
            if 'uses' in step:
                ref = step['uses']
                action = ref.split('@')[0].lower() if isinstance(ref, str) else ''
                if action != 'actions/checkout' and ref not in LOCAL_WORKFLOWS:
                    errors.append(f'{job_id}: unreviewed action')
                inputs = step.get('with', {})
                permitted = {'persist-credentials'}
                if not isinstance(inputs, dict) or set(inputs) - permitted:
                    errors.append(f'{job_id}: unreviewed action inputs')
                if env:
                    errors.append(f'{job_id}: unreviewed action environment')


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
        reviewed = hashlib.sha256(text.encode()).hexdigest() == REVIEWED_WORKFLOWS.get(filename)
        if not reviewed and filename in REVIEWED_WORKFLOWS:
            errors.append('reviewed workflow changed: full review and digest update required')
        if not reviewed:
            check_unreviewed(workflow, jobs, errors)
        events = workflow.get('on', workflow.get(True))
        check_shell_defaults(workflow.get('defaults'), errors, 'workflow')
        workflow_env = check_env(workflow.get('env'), errors, 'workflow')
        if events == 'pull_request_target' or isinstance(events, (dict, list)) and 'pull_request_target' in events:
            if not (filename == 'dependabot-auto-merge.yml'
                    and events == {'pull_request_target': {'types': ['opened', 'synchronize', 'reopened', 'auto_merge_enabled']}, 'workflow_dispatch': None, 'schedule': [{'cron': '*/15 * * * *'}]}
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
                allowed = WRITE_PERMISSIONS.get((filename, job_id), set()) if reviewed else set()
                for permission, level in permissions.items():
                    if level not in ('read', 'write', 'none') or (level == 'write' and permission not in allowed):
                        errors.append(f'{job_id}: forbidden permission {permission}: {level}')
            nodes = [job] + job.get('steps', [])
            for node in nodes:
                if not isinstance(node, dict):
                    raise ValueError(f'{job_id}: step must be a mapping')
                ref = node.get('uses')
                if ref is not None:
                    if not isinstance(ref, str) or not (ref in LOCAL_WORKFLOWS or
                            node is job and ref in SELF_WORKFLOWS or EXTERNAL.fullmatch(ref)):
                        errors.append(f'{job_id}: forbidden uses: {ref}')
                    if isinstance(ref, str) and ref.lower().startswith('actions/checkout@'):
                        if not isinstance(node.get('with'), dict) or node['with'].get('persist-credentials') is not False:
                            errors.append(f'{job_id}: checkout must disable persisted credentials')
                    inputs = node.get('with', {})
                    if isinstance(inputs, dict):
                        action = ref.split('@')[0].lower() if isinstance(ref, str) else ''
                        for key, value in inputs.items():
                            candidate_checkout = (action == 'actions/checkout' and key == 'ref'
                                                  and value == '${{ github.sha }}')
                            release_artifact = (action == 'actions/download-artifact' and key == 'name'
                                                and value == 'release-artifacts-${{ github.run_attempt }}')
                            if untrusted(value) and (action, key) not in DATA_INPUTS and not candidate_checkout and not release_artifact:
                                errors.append(f'{job_id}: untrusted expression in action input {key}')
                if untrusted(node.get('shell')):
                    errors.append(f'{job_id}: untrusted expression in shell')
                env_names = job_env | check_env(node.get('env'), errors, job_id)
                run = node.get('run', '')
                if not isinstance(run, str) or UNTRUSTED.search(run):
                    errors.append(f'{job_id}: untrusted expression in run')
                elif any(unquoted_env_reference(run, name) for name in env_names):
                    errors.append(f'{job_id}: unquoted untrusted environment in run')
                elif not safe_env_use(run, env_names, filename, job_id):
                    errors.append(f'{job_id}: unreviewed use of untrusted environment')
    except (yaml.YAMLError, ValueError, TypeError) as exc:
        errors.append(f'invalid workflow: {exc}')
    return errors


def check_package_workflows(root):
    errors = []
    present = set(root.glob('packages/*/.github/workflows/*.yml')) | set(root.glob('packages/*/.github/workflows/*.yaml'))
    expected = {root / name for name in REVIEWED_PACKAGE_WORKFLOWS}
    errors.extend((path, 'unknown package workflow requires independent review')
                  for path in sorted(present - expected))
    for name, digest in REVIEWED_PACKAGE_WORKFLOWS.items():
        path = root / name
        try:
            text = path.read_text()
            workflow = yaml.load(text, Loader=Loader)
            if not isinstance(workflow, dict) or not isinstance(workflow.get('jobs'), dict):
                raise ValueError('workflow and jobs must be mappings')
            if digest is None or hashlib.sha256(text.encode()).hexdigest() != digest:
                errors.append((path, 'package workflow requires independent review and digest update'))
            if workflow.get('permissions') != {'contents': 'read'}:
                errors.append((path, 'root permissions must be contents: read'))
            for job_id, job in workflow['jobs'].items():
                if not isinstance(job, dict):
                    raise ValueError(f'{job_id}: job must be a mapping')
                permissions = job.get('permissions', {'contents': 'read'})
                if not isinstance(permissions, dict) or any(
                    level not in ('read', 'none') and not (permission in {'id-token', 'attestations'} and level == 'write')
                    for permission, level in permissions.items()
                ):
                    errors.append((path, f'{job_id}: forbidden permissions'))
                steps = job.get('steps', [])
                if not isinstance(steps, list):
                    raise ValueError(f'{job_id}: steps must be a list')
                for node in [job, *steps]:
                    if not isinstance(node, dict):
                        raise ValueError(f'{job_id}: step must be a mapping')
                    ref = node.get('uses')
                    if ref is not None and (not isinstance(ref, str) or not EXTERNAL.fullmatch(ref)):
                        errors.append((path, f'{job_id}: forbidden uses: {ref}'))
                    if isinstance(ref, str) and ref.lower().startswith('actions/checkout@'):
                        if not isinstance(node.get('with'), dict) or node['with'].get('persist-credentials') is not False:
                            errors.append((path, f'{job_id}: checkout must disable persisted credentials'))
        except (OSError, UnicodeError, yaml.YAMLError, ValueError, TypeError) as exc:
            errors.append((path, f'missing or invalid package workflow: {exc}'))
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
    # Missing snapshots can remove a gate just as effectively as modifying one.
    present = {path.name for path in paths}
    errors.extend((directory / name, 'reviewed workflow missing')
                  for name in sorted(REVIEWED_WORKFLOWS.keys() - present))
    errors.extend(check_package_workflows(ROOT))
    for path, error in errors:
        print(f'{path}: {error}', file=sys.stderr)
    if not errors:
        print(f'Checked {len(paths)} workflows')
    return bool(errors)


if __name__ == '__main__':
    sys.exit(main())

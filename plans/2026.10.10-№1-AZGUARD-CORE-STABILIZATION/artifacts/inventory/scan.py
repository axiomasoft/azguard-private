"""Raw inventory of AzGuard production PHP types at the baseline: declarations, markers, imports, users.
Usage: python3 scan.py <repo-root> <out.json>"""
import json, os, re, sys, hashlib, subprocess

root, out = sys.argv[1], sys.argv[2]
pkgs = {'core': 'packages/core/src', 'filament': 'packages/filament/src'}
decl_re = re.compile(r'^(?P<mods>(?:(?:final|abstract|readonly)\s+)*)(?P<kind>class|interface|trait|enum)\s+(?P<name>\w+)(?P<rest>[^{]*)', re.M)
manifest = {}
for p in ('packages/core/api-manifest.json', 'packages/filament/api-manifest.json'):
    for e in json.load(open(os.path.join(root, p)))['classes']:
        manifest[e['name']] = {'stability': e['stability'], 'via': e['via'],
                               'public_methods': sum(1 for m in e['methods'] if m.get('visibility', 'public') == 'public') if isinstance(e['methods'], list) else None}
types = []
for pkg, src in pkgs.items():
    for dp, _, fs in os.walk(os.path.join(root, src)):
        for f in sorted(fs):
            if not f.endswith('.php'):
                continue
            path = os.path.join(dp, f)
            text = open(path, encoding='utf-8').read()
            ns = re.search(r'^namespace\s+([^;]+);', text, re.M).group(1)
            m = decl_re.search(text)
            if not m:
                continue
            head = text[:m.start()]
            doc = head[head.rfind('/**'):] if '/**' in head else ''
            fq = ns + '\\' + m.group('name')
            uses = re.findall(r'^use\s+([^;]+);', text, re.M)
            imports = []
            for u in uses:
                if u.startswith(('function ', 'const ')):
                    continue
                if '{' in u:
                    base, inner = u.split('{', 1)
                    imports += [base + x.strip().split(' as ')[0] for x in inner.rstrip('}').split(',') if x.strip()]
                else:
                    imports.append(u.split(' as ')[0].strip())
            rest = m.group('rest')
            ext = re.search(r'extends\s+([\w\\,\s]+?)(?:implements|$)', rest)
            impl = re.search(r'implements\s+([\w\\,\s]+)', rest)
            rel = os.path.relpath(path, root)
            types.append({
                'fqcn': fq, 'package': pkg, 'path': rel, 'kind': m.group('kind'),
                'modifiers': m.group('mods').split(), 'lines': text.count('\n'),
                'tags': sorted(set(re.findall(r'@(api|spi|internal)\b', doc))),
                'manifest': manifest.get(fq),
                'extends': [x.strip() for x in ext.group(1).split(',')] if ext else [],
                'implements': [x.strip() for x in impl.group(1).split(',')] if impl else [],
                'imports_azguard': sorted(i for i in imports if i.startswith('AzGuard\\')),
                'sha256': hashlib.sha256(text.encode()).hexdigest(),
            })
names = {t['fqcn'] for t in types}
users = {n: set() for n in names}
scan_roots = ['packages/core/src', 'packages/filament/src', 'tests', 'packages/core/stubs', 'packages/core/config', 'packages/core/database']
for r in scan_roots:
    for dp, _, fs in os.walk(os.path.join(root, r)):
        for f in fs:
            if not (f.endswith('.php') or f.endswith('.stub')):
                continue
            p = os.path.relpath(os.path.join(dp, f), root)
            t = open(os.path.join(dp, f), encoding='utf-8', errors='replace').read()
            for u in re.findall(r'\b(AzGuard\\[A-Za-z0-9_\\]+)', t):
                u = u.rstrip('\\')
                if u in users:
                    users[u].add(p)
for t in types:
    us = users[t['fqcn']] - {t['path']}
    t['used_by'] = {'core': sum(1 for u in us if u.startswith('packages/core/src')),
                    'filament': sum(1 for u in us if u.startswith('packages/filament')),
                    'tests': sum(1 for u in us if u.startswith('tests')),
                    'stubs_config_migrations': sorted(u for u in us if u.startswith(('packages/core/stubs', 'packages/core/config', 'packages/core/database')))}
head = subprocess.check_output(['git', '-C', root, 'rev-parse', 'HEAD']).decode().strip()
json.dump({'generated_from': head, 'code_baseline': '09612160c98ca6133c894a8f90f5653b9dac6b8f', 'types': types}, open(out, 'w'), indent=1, ensure_ascii=False)
print(len(types), 'types')

from pathlib import Path
import re, unicodedata
root=Path(__file__).resolve().parents[1]
def anchors(text):
 a=set(re.findall(r'<a\s+(?:id|name)=["\']([^"\']+)',text))
 counts={}
 for title in re.findall(r'^#{1,6}\s+(.+?)\s*#*$',text,re.M):
  t=re.sub(r'\[([^]]+)\]\([^)]+\)',r'\1',title)
  t=re.sub(r'<[^>]+>','',t).lower().strip()
  t=''.join(c for c in t if c in '-_ ' or unicodedata.category(c)[0] in 'LN')
  t=t.replace(' ','-'); n=counts.get(t,0);counts[t]=n+1
  a.add(t if n==0 else f'{t}-{n}')
 return a
issues=[];count=0
documentation=[f for f in sorted(root.glob('*.md')) if f.name!='01-review.md']
documentation += [root/'evidence'/name for name in ('design-review.md','rename-consistency.md','flexibility-review.md','oop-review.md')]
for f in documentation:
 if f.name=='01-review.md':continue
 text=f.read_text()
 # Strip fenced code to avoid interpreting inline PHP arrays as markdown.
 text=re.sub(r'^```.*?^```\s*$', '', text, flags=re.M|re.S)
 for label,url in re.findall(r'\[([^]\n]+)\]\(([^)\s]+)\)',text):
  if re.match(r'\w+://|mailto:',url):continue
  path,_,frag=url.partition('#');target=(f.parent/path).resolve() if path else f.resolve();count+=1
  if not target.exists():issues.append(f'{f.name}: missing {url}')
  elif frag and target.suffix=='.md' and frag not in anchors(target.read_text()):issues.append(f'{f.name}: anchor {url}')
print(f'{count} local documentation links examined')
print('\n'.join(issues) if issues else 'All local links and anchors resolve')
d=(root/'02-decisions.md').read_text();v=(root/'14-verification.md').read_text();c=(root/'16-crm-and-workflows.md').read_text()
for name,got,expected in [('D',re.findall(r'^### D(\d+) ',d,re.M),set(range(1,84))),('V',re.findall(r'^\| V(\d+) \|',v,re.M),set(range(1,121))-{40,41,42}),('R',re.findall(r'^\| R(\d+) \|',(root/'17-crm-acceptance-tests.md').read_text(),re.M),set(range(1,69))),('F',re.findall(r'^\| F(\d+) \|',(root/'20-process-map.md').read_text(),re.M),set(range(1,25))),('C',re.findall(r'^\| C(\d+) \|',c,re.M),set(range(1,23)))]:
 nums=[int(x) for x in got]; bad=set(nums)^expected
 duplicates=sorted(n for n in set(nums) if nums.count(n)>1)
 print(name, 'missing/extra',sorted(bad),'duplicates',duplicates)
 if bad or duplicates: issues.append(name+' numbering')

owners=set(re.findall(r'^\| (P\d+\.\d+) \|', (root/'13-workstreams.md').read_text(),re.M))
for f in root.glob('*.md'):
 if f.name=='01-review.md':continue
 missing=set(re.findall(r'\bP\d+\.\d+\b', f.read_text()))-owners
 if missing:issues.append(f'{f.name}: unknown owning items {sorted(missing)}')
owner_rows=re.findall(r'^\| (P\d+\.\d+) \|',(root/'13-workstreams.md').read_text(),re.M)
if len(owner_rows)!=len(set(owner_rows)): issues.append('Duplicate owning item rows')
print('Owning item references checked')
for f in root.glob('*.md'):
 if f.name=='01-review.md':continue
 content=f.read_text()
 old_path=re.search(r'Resources/(?:\{Resource\}|\{Group\}|\w+)/(?:Permissions|Policies|Queries|Abilities)',content)
 old_tree=re.search(r'^[│ ]*[├└]── Resources/',content,re.M)
 old_config=re.search(r"'resources'\s*=>\s*'Resources'",content)
 if old_path or old_tree or old_config:issues.append(f'{f.name}: obsolete panel layout')
print('D72 panel layout paths checked')
for f in documentation:
 content=f.read_text()
 obsolete_calls=re.search(r'->(?:sources|subjects|inPanel)\s*\(',content)
 code='\n'.join(re.findall(r'^```[^\n]*\n(.*?)^```',content,re.M|re.S))
 obsolete_code=re.search(r'#\[Domain\b|public\s+function\s+subjects\(array|Permissions/\s+Domain\b|public\s+\?string\s+\$domain\b',code)
 obsolete_cli='make:panel\\|domain' in content
 if obsolete_calls or obsolete_code or obsolete_cli:issues.append(f'{f.name}: obsolete target naming')
api=(root/'05-php-api.md').read_text()
builder=re.search(r'final class PanelBuilder\s*\{(.*?)^\}',api,re.M|re.S)
if builder is None:
 issues.append('PanelBuilder API block missing')
else:
 methods=re.findall(r'public function (\w+)\(',builder[1])
 duplicate_methods={name for name in methods if methods.count(name)>1}
 if duplicate_methods:issues.append(f'PanelBuilder duplicate methods: {sorted(duplicate_methods)}')
 if methods.count('permissions')!=1 or 'sources' in methods or 'subjects' in methods or 'for' not in methods:
  issues.append('PanelBuilder D71/D73 method contract mismatch')
 if 'permissions(array $definitions)' not in builder[1]:issues.append('PanelBuilder mixed permissions signature missing')
if 'guard(array|string $guarded): static|SubjectAccess' not in api: issues.append('D74 native Eloquent guard adapter missing')
if re.search(r'guard\(string \$panel\): SubjectAccess', api): issues.append('Unsafe string-only Eloquent guard override')
print('D71-D74 renames and unique builder signatures checked')
for f in root.glob('*.md'):
 if f.name=='01-review.md':continue
 content=f.read_text()
 code='\n'.join(re.findall(r'^```[^\n]*\n(.*?)^```',content,re.M|re.S))
 forbidden=[r'roles\(\)->(?:create|update|delete|syncPermissions|renameKey)\(',
            r'->(?:profiles|using)\(\s*[\"\']seller', r'->profiles\(',
            r'make\(options:',r'interface RoleManager\b', r'\{p\}(?:roles|role_permissions|role_contexts)\b',
            r'Models/\{Role,RolePermission',r'\$role->(?:field|model|definition)\(']
 if any(re.search(pattern,code) for pattern in forbidden):issues.append(f'{f.name}: obsolete D80-D83 code contract')
api=(root/'05-php-api.md').read_text()
for signature in ('interface RoleCatalog', 'CodeStateToken|StateToken $state', 'PermissionAuthority $authority', 'GrantDetails $details'):
 if signature not in api:issues.append('Missing current API signature '+signature)
extensions=(root/'06-extension-points.md').read_text()
plugin_base=re.search(r'abstract class BasePlugin.*?\{(.*?)^\}',extensions,re.M|re.S)
if not plugin_base or re.search(r'function (?:make|options|withOptions)\(',plugin_base[1]):issues.append('BasePlugin must not impose generic factory/options')
if 'roleModel' in (root/'18-contexts-and-runtime-inputs.md').read_text().split('## 10.')[1]:issues.append('Runtime input keeps removed roleModel')
print('D80-D83 code roles, typed configuration and authority contracts checked')
if issues: print('\n'.join(issues))
raise SystemExit(bool(issues))

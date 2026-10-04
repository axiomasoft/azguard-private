# Review candidate

Read-only. Do not repair or start another review.

## Specification
### P4.2 — Выдачи из папки: BaseRole, GrantedAutomatically, GrantedToAll, FormerKeys

**Intent:** Сделать `FolderSource` источником назначений папки: автоматические роли (`GrantedAutomatically::appliesTo()`) становятся scoped `RoleContribution`, права с `#[GrantedToAll]` — `Grant` каждому принятому субъекту в валидной области; идентичность роли — ключ, FQCN не хранится; неизвестный, удалённый, прежний (`FormerKeys`) или `#[NotGrantable]`-ключ в назначении даёт ноль прав и диагностику.
**Why:** RAG:— 05 §6 (роли в коде, `GrantedAutomatically`, `#[NotGrantable]`, атрибут = метод), 06 §1.1 (capabilities `FolderSource`), §1.2 (правила выдач), 09 §2 (RoleContribution, неизвестная роль), §5 (`GrantedToAll` только Grants, принятый субъект в валидном scope), 19 §3, §6 (FormerKeys не alias), 20 F06/F16; D14, D19, D52, D62, D80 досье; V09–V11, V66, V74, V79, V117; probe P02; D14 плана п.4, п.7.
**Scope Included:** `FolderSource implements ProvidesRoleGrants, ProvidesGrants` (вдобавок к P2.8): `roleGrants()` — для каждой роли каталога панели, реализующей `GrantedAutomatically`, вызывает `appliesTo($subjectModel, $scope)` на каждый запрошенный scope и отдаёт `RoleContribution::of(role, scope, source: 'folder', origin: 'automatic', expiresAt: null)`; `grants()` — для каждого права из `DiscoverySnapshot::grantedToAll` (только Grants-режим) `Grant` с точным ключом, scope запроса, origin `granted_to_all`; `volatility()` — `Request` (05 §6); диагностика ролей в движке: назначение с ключом вне каталога, с прежним ключом (`former_keys` компилированной роли) или хранимое/полученное от стороннего адаптера назначение с `grantable = false` — ноль прав (исключение — реальное выполнение `GrantedAutomatically` адаптером `FolderSource`, не метки contribution), запись в трассе и `Log::notice` с текущим ключом роли; проверка при компиляции: `#[GrantedToAll]` на PolicyOnly-праве — `DefinitionException` (если P2.5/P2.8 её ещё не делают); регрессия P02.
**Scope Excluded:** Хранимые назначения и их чтение (`DatabaseSource`, P4.4); `RoleNotGrantableException` и миграция FormerKeys `roles:rename-key` (P5.2/P5.3); doctor-показ удалённых ролей (P6.4); `touch()` P4.20 и кэш автоматических ролей P4.8; tenant-политики (P4.6 — здесь scope задаёт запрос).
**Inputs:** `decisions/D16-p4-execution-grouping.md` · `plan.md` · `phases/P4/P4.md` · `decisions/D14-p4-authorization-closure.md` · `decisions/D15-p4-design-repair.md` · `brief/P4-dossier-decisions.md` · `brief/P4-acceptance-matrix.md`
**Files:** `packages/core/src/Sources/Folder/FolderSource.php` · `packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php` (диагностика ключей ролей) · `packages/core/src/Catalog/PanelCatalog.php` (поиск роли по прежнему ключу, если нужен) · `packages/core/api-manifest.json` · `tests/Feature/Sources/Folder/{AutomaticRolesTest,GrantedToAllTest,RoleKeyIdentityTest}.php` · `tests/Fixtures/Guards/Shop/**` (роли `SellerRole` с `GrantedAutomatically`, `RootRole` с `#[SuperAdmin]`/`#[NotGrantable]`, enum с `#[GrantedToAll]`) · `tests/Regression/P02Test.php` · `tests/Regression/specs/P02.md` · `CHANGELOG.md`.
**Required Reads:** 1) `audits/2026-09-29-audit/opus/06-extension-points.md` 2) `audits/2026-09-29-audit/opus/19-oop-and-permission-authority.md` 3) `audits/2026-09-29-audit/opus/20-process-map.md` 4) `audits/2026-09-29-audit/opus/14-verification.md` 5) `packages/core/src/Contracts/Authorization/EvaluationContext.php` 6) `packages/core/src/Catalog/PanelCatalog.php` 7) `packages/core/src/Catalog/RoleCompiler.php` 8) `packages/core/src/Panels/PanelBuilder.php` 9) `decisions/D15-p4-design-repair.md` 10) `brief/P4-acceptance-matrix.md`
**Implementation Rules:** Только публичные контракты (`ProvidesRoleGrants`, `ProvidesGrants`, `EvaluationContext`); источник не читает Auth и не хранит состояние запроса. `appliesTo()` получает модель субъекта из `EvaluationContext::subjectModel()`; `null` модели — роль не применяется (не исключение); исключение `appliesTo()` — исключение источника → `Deny(SourceError)` в движке. Экземпляр роли создаётся контейнером на операцию. Автоматическая роль, выданная ещё и вручную (P4.4), складывается: две contribution одной роли не конфликтуют. `#[GrantedToAll]` даёт ровно своё право, в каждом запрошенном scope, только если панель принимает субъекта (`Panel::accepts`); anonymous/непринятый субъект — ноль. Ключ роли в вкладе — единственная идентичность: переименование класса с тем же `#[Role]`-ключом не меняет решения (V09). Ключ, найденный только среди `former_keys`, не разворачивается в права (D14 п.4); `RoleContribution` с ролью `grantable = false` от адаптера, не являющегося реальным FolderSource при исполнении GrantedAutomatically, — ноль прав и диагностика. Строка source/origin folder/automatic не даёт exemption: provenance реального adapter tracked core. Поддельная DB строка с такими labels даёт ноль прав/диагностику. Метод роли, переопределённый в классе, побеждает атрибут (V79) — проверяется на компилированной роли.
**Code Guidance:** V66 — `SellerRole::appliesTo` по `stores()->exists()`: роль есть у пользователя с магазином и нет у остальных; ручная выдача той же роли через `GeneratedSource` складывается; `RootRole` по `is_root` — суперадмин через Grants-путь P4.1. V09/P02 — класс роли переименован (две фикстурные папки с одинаковым ключом в разных панелях), выдача из `GeneratedSource` по ключу работает. V10 (по D14 п.4) — выдача с прежним ключом: Deny(NotGranted), лог называет текущий ключ. V11/P02 — выдача несуществующего ключа: не бросает, Deny(NotGranted), запись в трассе. V74 (часть) — право с `#[GrantedToAll]` и та же роль, выданная через `GeneratedSource` тем же субъектам, дают одинаковые решения (остаток с `DatabaseSource` — P4.18). V79 — `#[Role('manager', label:, level: 10)]`, подпись через `__()`, метод побеждает атрибут, без `#[Role]` и без `key()` — `DefinitionException`. V117 (часть) — роли только PHP-классы, назначения папки не создают определений.
**Validation:** 1) `vendor/bin/pest tests/Feature/Sources/Folder tests/Regression/P02Test.php tests/Regression/RegressionSpecsTest.php` — GREEN; 2) `vendor/bin/pest tests/Feature/Authorization` — GREEN (локальная семантика P4.1 не сломана); 3) `vendor/bin/pest tests/Arch` — GREEN; 4) `php bin/api-manifest.php --check` — exit 0 после `composer api:manifest`; 5) `composer test`; 6) `vendor/bin/pint --test`; 7) `vendor/bin/phpstan analyse --memory-limit=1G`; 8) `php -d memory_limit=1G vendor/bin/pest --type-coverage --min=98`; 9) `git diff --check`.
**Deliverables:** `FolderSource` с автоматическими ролями и `#[GrantedToAll]`, диагностика ключей ролей, регрессия P02 `covered:`; раздел P4.2 в `findings/P4-execution.md` (V09–V11, V66, V74, V79, V117 → тест; D14 п.4 применён). Полная scoped source integration — P4.18, затем CRM P4.15 до P4.13; локальный source GREEN не подменяет её.

## Bound candidate
{
  "base": "26be26a3782d235eb7bf407b8842f77540558b40",
  "budget_decision": null,
  "candidate_sha256": "2662b06fed27c474b2a84b77a1a9c428d65543c943a1dab93a284c10320a874e",
  "effort": "high",
  "executor_session": "01a1075c-cece-70e0-93d5-e2184c5ee917",
  "item_id": "P4.2",
  "paths": [
    "#[GrantedToAll]",
    "#[NotGrantable]",
    "#[SuperAdmin]",
    "CHANGELOG.md",
    "GrantedAutomatically",
    "RootRole",
    "SellerRole",
    "packages/core/api-manifest.json",
    "packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php",
    "packages/core/src/Catalog/PanelCatalog.php",
    "packages/core/src/Contracts/Sources",
    "packages/core/src/Roles",
    "packages/core/src/Sources/Folder/FolderSource.php",
    "tests/Feature/Authorization/ContributionQualificationTest.php",
    "tests/Feature/Sources/Folder/{AutomaticRolesTest,GrantedToAllTest,RoleKeyIdentityTest}.php",
    "tests/Fixtures/Authorization",
    "tests/Fixtures/Guards/Shop",
    "tests/Fixtures/Guards/Shop/**",
    "tests/Regression/P02Test.php",
    "tests/Regression/specs/P02.md"
  ],
  "plan_id": "2026.10.01-№1-AZGUARD-V1",
  "reviewer": "grok/grok-4.7/500000",
  "risk_class": "local",
  "route": {
    "context_tokens": 500000,
    "model": "grok-4.7",
    "provider": "grok"
  },
  "schema_version": "review-route/v1",
  "snapshot": {
    "diff_sha256": "7f5559d5cf56a724eb7a1949ced7b8cddefbb374f3f8b260220038752c9e8a15",
    "inputs": [
      {
        "mode": 436,
        "path": "audits/2026-09-29-audit/opus/06-extension-points.md",
        "sha256": "c7212b153c9a9e59ea05909eacd3a72eef65ef1530f9474d424849d12de6e193"
      },
      {
        "mode": 436,
        "path": "audits/2026-09-29-audit/opus/14-verification.md",
        "sha256": "9d1b5c2a3e5dd5c9b214e6c03082305c663108d251f4a78ffa05b8bb4dca27c5"
      },
      {
        "mode": 436,
        "path": "audits/2026-09-29-audit/opus/19-oop-and-permission-authority.md",
        "sha256": "db4eedf17c74a55779c64004bfbca0d7ad6ff1c464e0518b4ed859ce366c5146"
      },
      {
        "mode": 436,
        "path": "audits/2026-09-29-audit/opus/20-process-map.md",
        "sha256": "06cc0a62042bab7cc4b138360030d0950825a4ea0483d95f2fd96a611146c17f"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Catalog/PanelCatalog.php",
        "sha256": "66cac3ea42f8ae3ed4137df5176eb7693f43dc3366d24528e739b76e9819378e"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Catalog/RoleCompiler.php",
        "sha256": "943b7fd818789ab442aaee4b4522c2c38a6ff9c1ae9282ab0bb53b0b007c2624"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Authorization/EvaluationContext.php",
        "sha256": "f863436927d91c08fbee340097fdd1bcbed69371edc136b3e91118c249f3c3bc"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Panels/PanelBuilder.php",
        "sha256": "c08d58992b3aff9f7cb532edf38c4cfc1a512d85ba833cd9163ab7974feda206"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/artifacts/B4a-execution/P4.2-full.log",
        "sha256": "60115bde0dd19d08ff4b540977dfdbd4632bcfa6b290befe713dd2bd1b0e5760"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/artifacts/B4a-execution/P4.2-phpstan.log",
        "sha256": "6f530fc159a3905acf47f25edace3714ac60c7ea4b47f6b7689ea59e3deb7367"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/artifacts/B4a-execution/P4.2-targeted.log",
        "sha256": "4104023c5a2f8fc5685cf47a3844c9f3e883557e6f83815c941f399a586aa147"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/brief/P4-acceptance-matrix.md",
        "sha256": "2a989055b52e0587ff016579bf0700e0750bbc9095f74f23614e2796794a7d01"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/brief/P4-dossier-decisions.md",
        "sha256": "ca5b8da2419729c13802ff7060240f06af6b4db22202d84f9f5b1c84a44e6b2c"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/decisions/D14-p4-authorization-closure.md",
        "sha256": "0e83e6afa0a9078625fd643f11ef2de23ce76b7e4c3155ab2d255c9f6d685f74"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/decisions/D15-p4-design-repair.md",
        "sha256": "2aeec56537cd52457d85fee1d7b4ffc1324a2ee97c3ebb6542c15c3b6087f56e"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/decisions/D16-p4-execution-grouping.md",
        "sha256": "f3d9deeb0d691a0104eebdfdf0df75414af38f04c4f15744348ade78badbaa34"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/phases/P4/P4.2.md",
        "sha256": "cd24605ac6aca49ed9d13f002f788d55c6628db3203f2002f06a7f1c04644a33"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/phases/P4/P4.md",
        "sha256": "d1c7871d1b0cc4b01ce0424479dea6bf630551bfb45de1ce6df558be26034d7f"
      },
      {
        "mode": 436,
        "path": "plans/2026.10.01-№1-AZGUARD-V1/plan.md",
        "sha256": "e66efb560728442742b66ffd6dafa3aaffd8a3a60b25ca5f745b385a4b75b660"
      }
    ],
    "product": [
      {
        "mode": null,
        "path": "#[GrantedToAll]",
        "sha256": null
      },
      {
        "mode": null,
        "path": "#[NotGrantable]",
        "sha256": null
      },
      {
        "mode": null,
        "path": "#[SuperAdmin]",
        "sha256": null
      },
      {
        "mode": 436,
        "path": "CHANGELOG.md",
        "sha256": "87f9de6130f1e4506dd1bcff388491c207b21b04c5f0c3b54a138115c725ffa8"
      },
      {
        "mode": null,
        "path": "GrantedAutomatically",
        "sha256": null
      },
      {
        "mode": null,
        "path": "RootRole",
        "sha256": null
      },
      {
        "mode": null,
        "path": "SellerRole",
        "sha256": null
      },
      {
        "mode": 436,
        "path": "packages/core/api-manifest.json",
        "sha256": "28ba7693d7753e6917f4b07e9d4f9f257e26853192679e9a71b4839eb4eb8964"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php",
        "sha256": "518a8d9b72d5b138aaf5bdae41265cf15688c6076b02167cd418ee1ecb0e164b"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Catalog/PanelCatalog.php",
        "sha256": "66cac3ea42f8ae3ed4137df5176eb7693f43dc3366d24528e739b76e9819378e"
      },
      {
        "path": "packages/core/src/Contracts/Sources/",
        "sha256": "directory"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/DescribesSchema.php",
        "sha256": "95226b159a02335d268c10101784981b48aaa00c6b448553c9fefb5550b6aa63"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/ProvidesGrants.php",
        "sha256": "b28d4eca99f2f048f683aae3d28c7ca7f22694dd8957efe500c7010f2c91af46"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/ProvidesPermissions.php",
        "sha256": "c8436bbcff00d4318a41417dff1058fac354eb098688d240cf3dab301b1091fd"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/ProvidesPolicies.php",
        "sha256": "d3faa225be9806d2ca3eff8266f41dc109e76ff2c595f6f7207fcb485a3f0984"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/ProvidesRoleGrants.php",
        "sha256": "225c55ee22e8f29ffe3a34e2432d03a4f5bf24247d45fa4f2a61f3c1c72d7421"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/ProvidesRoles.php",
        "sha256": "86412a14a22bd01b1b4e9293f6e9981970c0d5aa56fbf5600549589a25009057"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/Source.php",
        "sha256": "51bad20aaeb69ea20a271ccef3ef773437f3c4b12db5c09793599639bd6b042e"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/SourceDescription.php",
        "sha256": "6c9f5c67f55be702e908b795763728c9669277d4997235a0c5613ed48ea5ac79"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/StoresGrants.php",
        "sha256": "85b907b5cf8bf64ebc7c3d6ce9fa7ab22889aa3d90b00f8783cb4740fb6cab7b"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Contracts/Sources/Volatility.php",
        "sha256": "6ec709b1b18d2d7727e1fcd21cc27a2f11e92d8684d48b10f016bcc3a4c4986c"
      },
      {
        "path": "packages/core/src/Roles/",
        "sha256": "directory"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Roles/Attributes/FormerKeys.php",
        "sha256": "3f5af95542b29dcebe185f7e51b86527a059a9170f3a0b2c0e25714e7fd2f900"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Roles/Attributes/NotGrantable.php",
        "sha256": "453bfd959a3f7c3f5cca3b6b8e5fada05acefa7c08eb6cb1a71b8fbf5a010d4a"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Roles/Attributes/Role.php",
        "sha256": "b83f102a2e14a340a381ac8e3dd74d962bee0c34371718558baea455bc19b0be"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Roles/Attributes/SuperAdmin.php",
        "sha256": "ac5d712059316e68ba0fd328f7971f8b574e084c0d3296e6621096c1b7edb810"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Roles/BaseRole.php",
        "sha256": "50d818b19d6b5ae1c49df958fd68ad22540c167100bb46a9a6464a267b63734b"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Roles/GrantedAutomatically.php",
        "sha256": "41f3e16a67a263c3de912558b3d434296b78184862abca5f90928338032a1474"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Roles/SuperAdminRole.php",
        "sha256": "4f8f7086dbe1d3767e94d49f0c405888cf4e9a2813441a0107643a3f6a167e4f"
      },
      {
        "mode": 436,
        "path": "packages/core/src/Sources/Folder/FolderSource.php",
        "sha256": "1dcae949dafb77e4858cc9020743229a0b34963d305a5dcfe6124d8e34ecabc4"
      },
      {
        "mode": 436,
        "path": "tests/Feature/Authorization/ContributionQualificationTest.php",
        "sha256": "dee995077aebfa42af513055e46803acb7d080503fecd7e6a7541c0c2a73957f"
      },
      {
        "mode": null,
        "path": "tests/Feature/Sources/Folder/{AutomaticRolesTest,GrantedToAllTest,RoleKeyIdentityTest}.php",
        "sha256": null
      },
      {
        "path": "tests/Fixtures/Authorization/",
        "sha256": "directory"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/AuthorizationWorld.php",
        "sha256": "1afedb13093c55453e4d5393844e7339369878d23f1f6a7830ebafe9604424bb"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/ContinueHook.php",
        "sha256": "b12e035b392ca54043a2d4fe5fb797d88ab051324222aaf300cd48f99685b326"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/DepartmentCondition.php",
        "sha256": "599876c10ed84db49b3c6523b86b3a411df30460ff75efcf6d792f70761b5be9"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/FailingSource.php",
        "sha256": "dc4e3072a53b0ab6400004159ea86e097ad96de5711dc35e09bff2a8aca0c37f"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/GeneratedSource.php",
        "sha256": "0fcd33ea337d5b551d481a1a2ba456be7dab615a18c9f3c78ffb2a8f08b0ead9"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/GrantableRootRole.php",
        "sha256": "21264f24f285635f0bfa7711740862fc11022ef877d5f7d40a9b7a218740dcdd"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/PassRestriction.php",
        "sha256": "84b5b9b50f68ab33de76788b696a722f1fcd768ff0f9abe3677846c2283b8ce6"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/RecordingRestriction.php",
        "sha256": "5fae9ec02e74943684cbe2920c6ec5f26eac35e53b86c742d235808b23f637d3"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/RuntimePolicy.php",
        "sha256": "fb8c8e9d9d7383bba3bfb1a6c4ebc7237ffa84d3fd388e3781e526b1763f66f4"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/WeekdaysCondition.php",
        "sha256": "f6e33824400cdd1deac4188e9fceb82f4e404a38cfa8a029a3c619d60c5c72d9"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Authorization/WriterSource.php",
        "sha256": "d2d38978c6a331c104770a04862cb7550a226795a583aa3eacd5c7f1bb5a1e91"
      },
      {
        "path": "tests/Fixtures/Guards/Shop/",
        "sha256": "directory"
      },
      {
        "mode": null,
        "path": "tests/Fixtures/Guards/Shop/**",
        "sha256": null
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Guards/Shop/Permissions/ShopPermission.php",
        "sha256": "44f87b4dd920887904983bc370f1b74ed06b54aa33df0fabc1a63532a4924265"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Guards/Shop/Roles/RootRole.php",
        "sha256": "35ee9c39f4b7d21534435d4072a81a9acded476b1ad5e86b57892be67463684c"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Guards/Shop/Roles/SellerRole.php",
        "sha256": "b103f8b7f8124fe283b2fce123323db51945b0e1179feecf7dadf481fa128c28"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Guards/Shop/ShopPanel.php",
        "sha256": "d1e50693c1a1bd771ecd7494c238ffb668e948a5720d2fcf374bb54542275465"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Guards/Shop/ShopWorld.php",
        "sha256": "0b2312da82f0759ca6f7bb3658ea758975d87be1241f0308bfc4b1de9d53ac19"
      },
      {
        "mode": 436,
        "path": "tests/Fixtures/Guards/Shop/Store.php",
        "sha256": "1292fb5ec2a5fee98ea0dc09f88b32325a11cf158097e5183ebfbbb12a0c8f39"
      },
      {
        "mode": 436,
        "path": "tests/Regression/P02Test.php",
        "sha256": "3b6d36873317f6998f11a62e4659a22cd7b6d23c702dfc72ebeae760d9811268"
      },
      {
        "mode": 436,
        "path": "tests/Regression/specs/P02.md",
        "sha256": "75e978f6c6866a990e833bff7a994bd20c9c58f8d312704fa3cdcd20ebbc4e56"
      }
    ]
  },
  "supporting": [
    "audits/2026-09-29-audit/opus/06-extension-points.md",
    "audits/2026-09-29-audit/opus/14-verification.md",
    "audits/2026-09-29-audit/opus/19-oop-and-permission-authority.md",
    "audits/2026-09-29-audit/opus/20-process-map.md",
    "packages/core/src/Catalog/PanelCatalog.php",
    "packages/core/src/Catalog/RoleCompiler.php",
    "packages/core/src/Contracts/Authorization/EvaluationContext.php",
    "packages/core/src/Panels/PanelBuilder.php",
    "plans/2026.10.01-№1-AZGUARD-V1/artifacts/B4a-execution/P4.2-full.log",
    "plans/2026.10.01-№1-AZGUARD-V1/artifacts/B4a-execution/P4.2-phpstan.log",
    "plans/2026.10.01-№1-AZGUARD-V1/artifacts/B4a-execution/P4.2-targeted.log",
    "plans/2026.10.01-№1-AZGUARD-V1/brief/P4-acceptance-matrix.md",
    "plans/2026.10.01-№1-AZGUARD-V1/brief/P4-dossier-decisions.md",
    "plans/2026.10.01-№1-AZGUARD-V1/decisions/D14-p4-authorization-closure.md",
    "plans/2026.10.01-№1-AZGUARD-V1/decisions/D15-p4-design-repair.md",
    "plans/2026.10.01-№1-AZGUARD-V1/decisions/D16-p4-execution-grouping.md",
    "plans/2026.10.01-№1-AZGUARD-V1/phases/P4/P4.md",
    "plans/2026.10.01-№1-AZGUARD-V1/plan.md"
  ]
}

## Review question
Check the affected delta; on re-review verify findings and their changed dependency closure.

## Output contract
Return ONLY one JSON object with schema_version=review-verdict/v1, candidate_sha256=2662b06fed27c474b2a84b77a1a9c428d65543c943a1dab93a284c10320a874e, reviewer=grok/grok-4.7/500000, verdict=GREEN|FINDINGS, findings=[{severity: blocker|major|minor|nit, risk_class: local|concurrency|transaction|auth|data-integrity, area: stable subsystem/invariant, file: repository path, line: positive integer, scenario: concrete demonstrated failure, evidence: nonempty evidence reference}]. GREEN requires an empty findings array. Systemic findings use their shared invariant as area.

## Diff
diff --git a/CHANGELOG.md b/CHANGELOG.md
index 1ace3a9..895328a 100644
--- a/CHANGELOG.md
+++ b/CHANGELOG.md
@@ -6,6 +6,8 @@ Versioning: [SemVer](https://semver.org/spec/v2.0.0.html).
 
 ## [Unreleased]
 
+- Ядро 1.0: `FolderSource` выдаёт автоматические роли и точные `GrantedToAll`-права; прежние, удалённые и невыдаваемые ключи ролей дают ноль authority с диагностикой. Исключение для `NotGrantable` требует фактического исполнения автоматической роли встроенным источником.
+
 - Ядро 1.0: добавлен внутренний пайплайн `Authorizer::decide()` с раздельными PolicyOnly/RequiresGrant, условиями одной выдачи, ограничениями, typed хуками, безопасным policy DI и защитой от рекурсии.
 
 ### Fixed
diff --git a/packages/core/api-manifest.json b/packages/core/api-manifest.json
index 7e8dd74..48ddfcb 100644
--- a/packages/core/api-manifest.json
+++ b/packages/core/api-manifest.json
@@ -8427,8 +8427,10 @@
             "parent": null,
             "interfaces": [
                 "AzGuard\\Contracts\\Sources\\DescribesSchema",
+                "AzGuard\\Contracts\\Sources\\ProvidesGrants",
                 "AzGuard\\Contracts\\Sources\\ProvidesPermissions",
                 "AzGuard\\Contracts\\Sources\\ProvidesPolicies",
+                "AzGuard\\Contracts\\Sources\\ProvidesRoleGrants",
                 "AzGuard\\Contracts\\Sources\\ProvidesRoles",
                 "AzGuard\\Contracts\\Sources\\Source"
             ],
@@ -8549,6 +8551,35 @@
                     ],
                     "return": "static"
                 },
+                {
+                    "name": "grants",
+                    "static": false,
+                    "abstract": false,
+                    "parameters": [
+                        {
+                            "name": "subject",
+                            "type": "AzGuard\\Kernel\\Identity\\SubjectRef",
+                            "byRef": false,
+                            "variadic": false,
+                            "hasDefault": false
+                        },
+                        {
+                            "name": "scopes",
+                            "type": "array",
+                            "byRef": false,
+                            "variadic": false,
+                            "hasDefault": false
+                        },
+                        {
+                            "name": "context",
+                            "type": "AzGuard\\Contracts\\Authorization\\EvaluationContext",
+                            "byRef": false,
+                            "variadic": false,
+                            "hasDefault": false
+                        }
+                    ],
+                    "return": "iterable"
+                },
                 {
                     "name": "id",
                     "static": false,
@@ -8608,6 +8639,35 @@
                     ],
                     "return": "iterable"
                 },
+                {
+                    "name": "roleGrants",
+                    "static": false,
+                    "abstract": false,
+                    "parameters": [
+                        {
+                            "name": "subject",
+                            "type": "AzGuard\\Kernel\\Identity\\SubjectRef",
+                            "byRef": false,
+                            "variadic": false,
+                            "hasDefault": false
+                        },
+                        {
+                            "name": "scopes",
+                            "type": "array",
+                            "byRef": false,
+                            "variadic": false,
+                            "hasDefault": false
+                        },
+                        {
+                            "name": "context",
+                            "type": "AzGuard\\Contracts\\Authorization\\EvaluationContext",
+                            "byRef": false,
+                            "variadic": false,
+                            "hasDefault": false
+                        }
+                    ],
+                    "return": "iterable"
+                },
                 {
                     "name": "roles",
                     "static": false,
@@ -8622,6 +8682,13 @@
                         }
                     ],
                     "return": "iterable"
+                },
+                {
+                    "name": "volatility",
+                    "static": false,
+                    "abstract": false,
+                    "parameters": [],
+                    "return": "AzGuard\\Contracts\\Sources\\Volatility"
                 }
             ],
             "stability": "api",
diff --git a/packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php b/packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php
index 99c2529..653ace3 100644
--- a/packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php
+++ b/packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php
@@ -11,7 +11,6 @@
 use AzGuard\Contracts\Authorization\GrantCondition;
 use AzGuard\Contracts\Sources\ProvidesGrants;
 use AzGuard\Contracts\Sources\ProvidesRoleGrants;
-use AzGuard\Contracts\Sources\StoresGrants;
 use AzGuard\Exceptions\InvalidSourceContributionException;
 use AzGuard\Kernel\Decision\AccessRequest;
 use AzGuard\Kernel\Decision\Decision;
@@ -23,6 +22,8 @@
 use AzGuard\Panels\PanelRegistry;
 use AzGuard\Policies\PolicyDecider;
 use AzGuard\Roles\BaseRole;
+use AzGuard\Roles\GrantedAutomatically;
+use AzGuard\Sources\Folder\FolderSource;
 use AzGuard\Sources\PanelSources;
 use Illuminate\Contracts\Container\Container;
 use Illuminate\Support\Facades\Log;
@@ -77,9 +78,21 @@ public function decide(AccessRequest $request, EvaluationFrame $frame, PanelCata
                 }
                 $roleDefinition = $item->role === null ? null : ($catalog->roles()[$item->role->key()] ?? null);
 
-                if ($item->role !== null && ($roleDefinition === null || ($source instanceof StoresGrants && ! $roleDefinition['grantable']))) {
-                    $trace->record('contribution', 'unknown_or_not_grantable_role', $source::class);
-                    Log::warning('AzGuard ignored role contribution.', ['component' => $source::class, 'reason' => 'unknown_or_not_grantable_role', 'role' => $item->role->full()]);
+                $automatic = $item instanceof RoleContribution && $source instanceof FolderSource && $roleDefinition !== null
+                    && is_subclass_of($roleDefinition['class'], GrantedAutomatically::class);
+
+                if ($item->role !== null && ($roleDefinition === null || (! $roleDefinition['grantable'] && ! $automatic))) {
+                    $current = null;
+                    foreach ($catalog->roles() as $candidate) {
+                        if (in_array($item->role->key(), $candidate['former_keys'], true)) {
+                            $current = $candidate['key'];
+
+                            break;
+                        }
+                    }
+                    $reason = $current !== null ? 'former_role_key' : ($roleDefinition === null ? 'unknown_role' : 'not_grantable_role');
+                    $trace->record('contribution', $reason, $source::class);
+                    Log::notice('AzGuard ignored role contribution.', ['component' => $source::class, 'reason' => $reason, 'role' => $item->role->full(), 'current_key' => $current ?? $roleDefinition['key'] ?? null]);
 
                     continue;
                 }
diff --git a/packages/core/src/Sources/Folder/FolderSource.php b/packages/core/src/Sources/Folder/FolderSource.php
index f96ceec..4f8b087 100644
--- a/packages/core/src/Sources/Folder/FolderSource.php
+++ b/packages/core/src/Sources/Folder/FolderSource.php
@@ -5,15 +5,25 @@
 namespace AzGuard\Sources\Folder;
 
 use AzGuard\Catalog\PermissionDefinition;
+use AzGuard\Contracts\Authorization\EvaluationContext;
 use AzGuard\Contracts\Sources\DescribesSchema;
+use AzGuard\Contracts\Sources\ProvidesGrants;
 use AzGuard\Contracts\Sources\ProvidesPermissions;
 use AzGuard\Contracts\Sources\ProvidesPolicies;
+use AzGuard\Contracts\Sources\ProvidesRoleGrants;
 use AzGuard\Contracts\Sources\ProvidesRoles;
 use AzGuard\Contracts\Sources\SourceDescription;
+use AzGuard\Contracts\Sources\Volatility;
 use AzGuard\Exceptions\DefinitionException;
 use AzGuard\Exceptions\DuplicatePermissionException;
 use AzGuard\Exceptions\DuplicatePolicyBindingException;
 use AzGuard\Exceptions\InvalidPolicyStructureException;
+use AzGuard\Kernel\Decision\Grant;
+use AzGuard\Kernel\Decision\PermissionAuthority;
+use AzGuard\Kernel\Decision\RoleContribution;
+use AzGuard\Kernel\Identity\PermissionPattern;
+use AzGuard\Kernel\Identity\RoleKey;
+use AzGuard\Kernel\Identity\SubjectRef;
 use AzGuard\Kernel\Identity\TenantRef;
 use AzGuard\Panels\Panel;
 use AzGuard\Panels\PanelRecipe;
@@ -21,6 +31,7 @@
 use AzGuard\Policies\PolicyBinding;
 use AzGuard\Policies\PolicyFor;
 use AzGuard\Roles\BaseRole;
+use AzGuard\Roles\GrantedAutomatically;
 use AzGuard\Sources\PanelSources;
 use BackedEnum;
 use Illuminate\Contracts\Container\Container;
@@ -37,7 +48,7 @@
  *
  * @api
  */
-final class FolderSource implements DescribesSchema, ProvidesPermissions, ProvidesPolicies, ProvidesRoles
+final class FolderSource implements DescribesSchema, ProvidesGrants, ProvidesPermissions, ProvidesPolicies, ProvidesRoleGrants, ProvidesRoles
 {
     /** @var array{permissions: ?string, policies: ?string, roles: ?string, abilities: ?string} */
     private array $folders = [
@@ -149,6 +160,7 @@ public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
         $known = [];
 
         foreach ($discovery->definitions as $row) {
+            $this->validateAutomaticGrant($row);
             $known[$row['case']['enum']] = true;
             $this->remember($definitions, AttributeReader::definition($row), $panel);
         }
@@ -159,6 +171,7 @@ public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
             }
 
             foreach (AttributeReader::rows($enum, null) as $row) {
+                $this->validateAutomaticGrant($row);
                 $this->remember($definitions, AttributeReader::definition($row), $panel);
             }
         }
@@ -185,6 +198,73 @@ public function roles(Panel $panel): iterable
         return array_values($roles);
     }
 
+    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
+    {
+        $model = $context->subjectModel();
+        $panel = $context->panel();
+
+        if ($model === null || ! $panel->accepts($subject) || ! $panel->accepts($model)) {
+            return;
+        }
+
+        foreach ($this->roles($panel) as $role) {
+            if (! $role instanceof GrantedAutomatically) {
+                continue;
+            }
+
+            foreach ($scopes as $scope) {
+                if ($role->appliesTo($model, $scope)) {
+                    yield RoleContribution::of(RoleKey::of($panel->id(), $role->key()), $scope, 'folder', origin: 'automatic');
+                }
+            }
+        }
+    }
+
+    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
+    {
+        $panel = $context->panel();
+
+        if ($context->subjectModel() === null || ! $panel->accepts($subject)) {
+            return;
+        }
+
+        $discovery = $this->prepared($panel);
+        $permissions = $discovery->grantedToAll;
+
+        foreach ($this->recipe()->enums() as $enum) {
+            if (! is_subclass_of($enum, BackedEnum::class)) {
+                continue;
+            }
+
+            foreach (AttributeReader::rows($enum, null) as $row) {
+                $this->validateAutomaticGrant($row);
+
+                if ($row['granted_to_all']) {
+                    $permissions[] = $row['local'];
+                }
+            }
+        }
+
+        foreach (array_unique($permissions) as $permission) {
+            foreach ($scopes as $scope) {
+                yield Grant::of(PermissionPattern::of($panel->id(), $permission), 'folder', $scope, origin: 'granted_to_all');
+            }
+        }
+    }
+
+    public function volatility(): Volatility
+    {
+        return Volatility::Request;
+    }
+
+    /** @param array{local: string, authority: string, granted_to_all: bool} $row */
+    private function validateAutomaticGrant(array $row): void
+    {
+        if ($row['granted_to_all'] && $row['authority'] !== PermissionAuthority::Grants->value) {
+            throw new DefinitionException('Permission "'.$row['local'].'" declares #[GrantedToAll] in PolicyOnly mode.');
+        }
+    }
+
     public function policies(Panel $panel): iterable
     {
         $discovery = $this->prepared($panel);
diff --git a/tests/Feature/Authorization/ContributionQualificationTest.php b/tests/Feature/Authorization/ContributionQualificationTest.php
index 4903612..f612d53 100644
--- a/tests/Feature/Authorization/ContributionQualificationTest.php
+++ b/tests/Feature/Authorization/ContributionQualificationTest.php
@@ -17,6 +17,7 @@
 use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
 use AzGuard\Tests\Fixtures\Authorization\DepartmentCondition;
 use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
+use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
 use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
 use AzGuard\Tests\Fixtures\Authorization\WeekdaysCondition;
 use AzGuard\Tests\Fixtures\Authorization\WriterSource;
@@ -59,7 +60,7 @@ public function allows(Grant|RoleContribution $grant, AccessRequest $request, Ev
             if ($grant instanceof Grant) {
                 return true;
             }
-            expect($context->grant())->toBe($grant)->and($context->role())->toBeInstanceOf(RootRole::class)->and($context->actor()->id)->toBe('1');
+            expect($context->grant())->toBe($grant)->and($context->role())->toBeInstanceOf(GrantableRootRole::class)->and($context->actor()->id)->toBe('1');
 
             return $grant->fields()['ok'];
         }
@@ -77,7 +78,7 @@ public function allows(Grant|RoleContribution $grant, AccessRequest $request, Ev
 
 it('uses actual writer provenance rather than a forged folder source label for NotGrantable roles', function (): void {
     $role = RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'folder');
-    [$engine,$panel,$request] = AuthorizationWorld::compile(new WriterSource(roles: [$role]));
+    [$engine,$panel,$request] = AuthorizationWorld::compile(new WriterSource(roles: [$role]), rootRole: RootRole::class);
     expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
 });
 it('qualifies each role contribution before expanding its compiled patterns', function (): void {
diff --git a/tests/Fixtures/Authorization/AuthorizationWorld.php b/tests/Fixtures/Authorization/AuthorizationWorld.php
index 290b509..e1551bf 100644
--- a/tests/Fixtures/Authorization/AuthorizationWorld.php
+++ b/tests/Fixtures/Authorization/AuthorizationWorld.php
@@ -19,17 +19,16 @@
 use AzGuard\Tests\Fixtures\Panels\AdminPanel;
 use AzGuard\Tests\Fixtures\Panels\PanelWorld;
 use AzGuard\Tests\Fixtures\Panels\User;
-use AzGuard\Tests\Fixtures\Roles\RootRole;
 use Closure;
 use DateTimeImmutable;
 
 final class AuthorizationWorld
 {
     /** @return array{Authorizer, Panel, AccessRequest} */
-    public static function compile(GeneratedSource $source, ?Closure $configure = null, array $extra = []): array
+    public static function compile(GeneratedSource $source, ?Closure $configure = null, array $extra = [], string $rootRole = GrantableRootRole::class): array
     {
-        [,,$registry] = PanelWorld::compile([AdminPanel::class => static function (PanelBuilder $panel) use ($source, $configure, $extra): void {
-            $panel->for(User::class)->permissions([$source, ...$extra])->roles([RootRole::class])->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]);
+        [,,$registry] = PanelWorld::compile([AdminPanel::class => static function (PanelBuilder $panel) use ($source, $configure, $extra, $rootRole): void {
+            $panel->for(User::class)->permissions([$source, ...$extra])->roles([$rootRole])->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]);
 
             if ($configure !== null) {
                 $configure($panel);
diff --git a/tests/Regression/specs/P02.md b/tests/Regression/specs/P02.md
index 7b0fa37..2e04af7 100644
--- a/tests/Regression/specs/P02.md
+++ b/tests/Regression/specs/P02.md
@@ -6,7 +6,7 @@
 **Scenarios:** V09, V11
 **Defect in 0.3:** Роль с неразрешимым `class_name` (то, что пишет форма `EditRole`, или след переименования класса) вызывала `InvalidRoleClassException` на каждой проверке держателя, включая Gate. Тест: `audits/2026-09-29-audit/opus/evidence/probes/OpusAuditProbesTest.php`, `it('P02: a role class_name that no longer resolves makes every check of its holders throw')`.
 **Required behavior:** Идентичность роли — ключ из `#[Role]`, FQCN в данные не пишется: переименование класса с тем же ключом не ломает выдачи, выдача роли, которой нет в коде, не бросает и не даёт прав, а `azguard:doctor` её показывает.
-**Status:** pending
+**Status:** covered: tests/Regression/P02Test.php::P02 preserves grants after a role class rename and denies removed keys without throwing
 
 ## Сценарий на целевом API
 

## New file: tests/Fixtures/Authorization/GrantableRootRole.php
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Authorization;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;

#[Role('root')]
#[SuperAdmin]
final class GrantableRootRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}

## New file: tests/Fixtures/Guards/Shop/Permissions/ShopPermission.php
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop\Permissions;

use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum ShopPermission: string
{
    #[GrantedToAll]
    case Browse = 'shop.browse';
    case Sell = 'shop.sell';
    case Edit = 'shop.edit';
}

## New file: tests/Fixtures/Guards/Shop/Roles/RootRole.php
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop\Roles;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\Attributes\SuperAdmin;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use Illuminate\Database\Eloquent\Model;

#[Role('root')]
#[SuperAdmin]
#[NotGrantable]
final class RootRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return (bool) $subject->getAttribute('is_root');
    }
}

## New file: tests/Fixtures/Guards/Shop/Roles/SellerRole.php
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop\Roles;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Tests\Fixtures\Guards\Shop\Permissions\ShopPermission;
use AzGuard\Tests\Fixtures\Guards\Shop\Store;
use Illuminate\Database\Eloquent\Model;

#[Role('seller', label: 'Seller')]
#[FormerKeys('old-seller')]
final class SellerRole extends BaseRole implements GrantedAutomatically
{
    public function permissions(): array
    {
        return [ShopPermission::Sell];
    }

    public function appliesTo(Model $subject, AccessScope $scope): bool
    {
        return Store::query()->where('user_id', $subject->getKey())->exists();
    }
}

## New file: tests/Fixtures/Guards/Shop/ShopPanel.php
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tests\Fixtures\Panels\User;

final class ShopPanel extends PanelProvider
{
    public static function getId(): string
    {
        return 'shop';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class)->resourcePrefix(false);
    }
}

## New file: tests/Fixtures/Guards/Shop/ShopWorld.php
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop;

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ShopWorld
{
    public static function seed(): void
    {
        Relation::morphMap(['user' => User::class], false);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_root')->default(false);
        });
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        User::query()->insert([['id' => 1, 'is_root' => false], ['id' => 2, 'is_root' => true]]);
        Store::query()->insert(['id' => 1, 'user_id' => 1]);
    }

    public static function compile(array $sources = []): PanelRegistry
    {
        $registry = new PanelRegistry(app());
        $registry->register(ShopPanel::class);
        $registry->configure('shop', function ($panel) use ($sources): void {
            $panel->permissions($sources);
            foreach ($sources as $source) {
                if ($source instanceof GeneratedSource) {
                    $panel->policies([PolicyBinding::for('orders.policy', RuntimePolicy::class)]);

                    break;
                }
            }
        });
        $registry->freeze();
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);

        return $registry;
    }

    public static function request(string $permission = 'shop.sell', int $id = 1): AccessRequest
    {
        return AccessRequest::for(SubjectRef::of('user', $id), PermissionKey::of('shop', $permission));
    }
}

## New file: tests/Fixtures/Guards/Shop/Store.php
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Guards\Shop;

use Illuminate\Database\Eloquent\Model;

final class Store extends Model
{
    protected $table = 'stores';

    public $timestamps = false;
}

## New file: tests/Regression/P02Test.php
<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Guards\Shop\ShopWorld;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('P02 preserves grants after a role class rename and denies removed keys without throwing', function (): void {
    ShopWorld::seed();
    $before = new #[Role('reader')] class extends BaseRole
    {
        public function permissions(): array
        {
            return ['orders.view'];
        }
    };
    $after = new #[Role('reader')] class extends BaseRole
    {
        public function permissions(): array
        {
            return ['orders.view'];
        }
    };
    expect($before::class)->not->toBe($after::class);
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'reader'), AccessScope::in(TenantRef::global()), 'generated')]);
    foreach ([$before::class, $after::class] as $class) {
        [$engine, $panel, $request] = AuthorizationWorld::compile($source, fn ($panel) => $panel->roles([$class]));
        expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    }
    [$engine, $panel, $request] = AuthorizationWorld::compile($source);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
});

# Task runtime repair — qualification

Owning source: `/home/vostrikov/projects/packages/swissknifeman/packages/task`.
Применяется source-owned `scripts/plan-work.py`, а не старая установленная cache-копия.

- `scripts/validate.sh`: exit 0, `validate: OK`.
- Полный unittest: 1294 tests, OK, 1 existing skip. Лог `/tmp/azguard-task-qualification.log`.
- 18 repeat tests включают отказ чужого/отозванного/устаревшего grant, route undershoot,
  одноразовый grant, фазу с будущим skeleton без design audit, неизменность strict required audit,
  durable witness исторического scoped repeat и историческое чтение после следующего sibling run.
- `render-provider-commands.py --root . --check`: no-op, exit 0.
- `scripts/docs-sync.sh --check`: fresh, exit 0.
- `plan-lint.py --conflicts`: 0 discrepancies, 0 warnings.
- `git diff --check`: exit 0.

Scope fingerprint включает plan/roadmap и выбранную фазу с её specs/design artifacts.
Закрытый schema `repeat-admission/v1` не менялся. Required audit сохраняет finish/GREEN;
historical scoped read аутентифицирует consumed start, gate witness, owner marker и неизменные specs.
Поздний owning repeat sibling не переписывает предшествующую terminal history.

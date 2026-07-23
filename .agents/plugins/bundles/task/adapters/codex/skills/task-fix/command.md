<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/fix.md
Canonical SHA-256: sha256:a7ff7beab7f0f485f8caefeda1d4cf774d9bd310bd20f2407fea1f3dbcff4973
Adapter: task.codex-command/1.0.3
-->
Это МЕЛКАЯ инкрементальная правка. Ввод пользователя: «$ARGUMENTS».
Модель/effort запинены (implementation/medium) — не переключай вручную (frontier тут избыточен).

Профиль `fix` (token-frugal):
1. **Один проход, без fan-out и без субагентов** — задача мелкая, координация не окупится.
2. **Selective-input** (экономия 5–50×): читай slice — функцию/класс/роут ±контекст, не файлы целиком; логи —
   `tail -n`/`grep` по идентификатору, не весь файл; ошибки — последний stack-frame; PR/дифф — `git diff`/
   `gh pr diff`, не файлы. «Point, don't paste»: указывай путь и дочитывай нужное.
3. **Минимальное изменение** под задачу; на изменённое поведение — тест/проверка; не разводи абстракции
   «на вырост».
4. Внешний факт под сомнением → `verify-claims`-лестница (context7 → `perplexity-web`), не утверждай по памяти.
5. Дерево чистое; НЕ git push / PR без явной просьбы.

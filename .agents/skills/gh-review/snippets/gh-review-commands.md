# Шпаргалка gh для ревью и точечного чтения

> Принцип: `gh` отдаёт минимальный срез (дифф, треды, один файл) — это и есть
> экономия токенов против загрузки файлов/репозитория целиком. Локальный VCS —
> за `git`, не дублируется.

## Чтение PR (точечно)

```bash
gh pr view <N>                          # описание, статус, чеклисты
gh pr view <N> --comments               # только треды ревью
gh pr diff <N>                          # только дифф
gh pr diff <N> -- path/to/file          # дифф одного пути
gh pr checks <N>                        # статусы CI (без логов)
gh pr view <N> --json files -q '.files[].path'    # список путей
gh pr view <N> --json url -q .url       # ссылка для handoff
```

## Публикация ревью

```bash
gh pr comment <N> --body "..."
gh pr review <N> --comment        -b "..."
gh pr review <N> --approve        -b "LGTM: ..."
gh pr review <N> --request-changes -b "..."
```

## Точечное чтение чужого репозитория (импорт/сверка)

```bash
# один файл (content — base64)
gh api repos/{owner}/{repo}/contents/{path}?ref={ref} -q '.content' | base64 -d

# листинг каталога без содержимого
gh api repos/{owner}/{repo}/contents/{dir}?ref={ref} -q '.[].path'

# последний коммит, менявший файл (детект upstream-дрейфа)
gh api 'repos/{owner}/{repo}/commits?path={path}&per_page=1' -q '.[0].sha'

# проверить лимиты до массового fetch
gh api rate_limit -q '.resources.core | "\(.remaining)/\(.limit)"'
```

## Issues и релизы (handoff)

```bash
gh issue view <N> --json url -q .url
gh issue comment <N> --body "..."
gh release view <tag>
```

## Что остаётся за git (gh НЕ заменяет)

```bash
git commit / git branch / git switch
git diff            # рабочее дерево (не PR — для PR это gh pr diff)
git log / git rebase / git stash
```

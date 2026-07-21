# Observer — рецепты разбора сессии (jq/grep)

Конкретные команды на каждое измерение. Логи Claude Code: каталог проекта —
путь с `/`→`-` (`~/.claude/projects/-home-...-<project>/`), внутри `<session>.jsonl`
и `<session>/subagents/agent-*.jsonl`. Анонимизировано: пути обобщённые.

## 0. Toggle-гейт (перед всем)

```bash
INI=".claude/observer.env.ini"
EN=$(grep -E '^[[:space:]]*ENABLED' "$INI" 2>/dev/null | sed -E 's/^[^=]*=[[:space:]]*//; s/[[:space:]#].*//' | tr -d "\"'")
[ "$EN" = "true" ] || { echo "Observer выключен (ENABLED!=true). Включить: /observe on"; exit 0; }
```

## Найти лог текущей сессии

```bash
PROJ_DIR=$(printf '%s' "$PWD" | sed 's#/#-#g')
LOGDIR="$HOME/.claude/projects/$PROJ_DIR"
MAIN=$(ls -t "$LOGDIR"/*.jsonl 2>/dev/null | head -1)
SUBS=$(ls -t "$LOGDIR"/*/subagents/agent-*.jsonl 2>/dev/null)
```

## Ось 1 — агенты

```bash
# SKILL_COMPLIANCE: нарушения в реально изменённом коде (пример — Laravel).
git diff --name-only -- 'app/Http/Controllers/*' \
  | xargs -r grep -nE '::(query|create|where|all|find)\(' || echo "controllers clean"

# LOOP_DETECTION: один файл читан N+ раз.
jq -r 'select(.message.content[]?.type=="tool_use" and .message.content[]?.name=="Read")
       | .message.content[] | select(.name=="Read") | .input.file_path' "$MAIN" 2>/dev/null \
  | sort | uniq -c | awk '$1>=3{print "LOOP read x"$1": "$2}'

# INTER_AGENT_ERRORS: tool_result с ошибкой + reviewer needs-work.
jq -rc 'select(.type=="user") | .message.content[]? | select(.is_error==true) | .tool_use_id' "$MAIN" 2>/dev/null | wc -l
grep -l 'needs-work' $SUBS 2>/dev/null
```

## Ось 2 — скиллы

```bash
# Какие скиллы реально грузились (Skill tool calls) — для DEAD/THIN/GAP.
jq -r 'select(.message.content[]?.name=="Skill") | .message.content[] | select(.name=="Skill") | .input.skill' $MAIN $SUBS 2>/dev/null | sort | uniq -c
# THIN_SKILL: скилл грузился, но SKILL_COMPLIANCE-греп выше всё равно нашёл нарушение.
# DEAD_SKILL: объявлен в агенте (skills:), но в выводе jq выше не встречается.
# SKILL_GAP: домен задачи без загруженного скилла.
```

## Ось 3 — настройки

```bash
# HOOK_MISCONFIG: гейт выключен.
grep -E '^[[:space:]]*MODE' .claude/review-gate.env.ini 2>/dev/null
# PERMISSION_FRICTION: повторные запросы прав (Notification-события / отказы).
jq -rc 'select(.type=="user") | .message.content[]? | select(.type=="text") | .text' "$MAIN" 2>/dev/null \
  | grep -iE 'нет|не так|не туда|use |actually|отмен|верни' | head   # CORRECTION_LOG
```

## Запись learnings (через memory-хук, не свой файл)

```bash
~/.claude/hooks/memory/memory.sh remember --type project \
  "<ось>:<находка> — <почему> (session $(date +%F))"
# Промоция (по одобрению человека): .claude/rules/<тема>.md | proposal скилла | memory.
```

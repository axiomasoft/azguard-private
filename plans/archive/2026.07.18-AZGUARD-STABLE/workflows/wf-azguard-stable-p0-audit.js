export const meta = {
  name: 'azguard-stable-p0-audit',
  description: 'P0 read-only аудит azguard: RAG-добор (P0.1) -> 4 параллельные оси (P0.2-P0.5) -> findings-файлы',
  whenToUse: 'Exec фазы P0 плана 2026.07.18-AZGUARD-STABLE из свежей fable/high-сессии (агенты наследуют модель сессии — гейт §3 Routing)',
  phases: [
    { title: 'RAG', detail: 'P0.1 — добор первоисточников (context7 -> perplexity-web -> WebSearch)' },
    { title: 'Оси', detail: 'P0.2–P0.5 — параллельный аудит четырёх осей, каждая пишет свой findings-файл' },
  ],
}

// Оркестрация по D8: стадия RAG -> барьер (оси читают файл P0.1) -> 4 параллельные оси.
// Агенты пишут ТОЛЬКО findings-файлы; git-коммиты и закрытие items по §8 — оркестратор-сессия
// ПОСЛЕ workflow. Код/тесты/CI read-only (Execution Rules плана).

const PLAN = 'plans/2026.07.18-AZGUARD-STABLE'

const COMMON = [
  `Ты — аудитор-исполнитель item'а мастер-плана 2026.07.18-AZGUARD-STABLE (repo azguard).`,
  `Прочитай ${PLAN}/phases/P0.md — ТОЛЬКО секцию своего item'а — и исполни её строго по полям:`,
  `Required Reads по порядку, Scope Included как закрытый чеклист, Implementation Rules и Code Guidance обязательны.`,
  `ЖЁСТКИЕ ПРАВИЛА: код/тесты/docs/CI НЕ менять (read-only аудит); git-команд, меняющих состояние, НЕ выполнять (никаких add/commit);`,
  `писать РОВНО ОДИН файл — указанный в поле Files твоего item'а; прогнать по себе grep-команды из поля Validation ДО возврата ответа.`,
].join('\n')

const RAG_SCHEMA = {
  type: 'object',
  required: ['file', 'preseedVerdicts', 'unverified'],
  properties: {
    file: { type: 'string', description: 'путь записанного файла' },
    preseedVerdicts: {
      type: 'array',
      description: 'статус по каждому из 5 вердиктов preseed',
      items: {
        type: 'object',
        required: ['thesis', 'status'],
        properties: {
          thesis: { type: 'string' },
          status: { type: 'string', enum: ['подтверждён', 'скорректирован', 'опровергнут', 'UNVERIFIED'] },
        },
      },
    },
    unverified: { type: 'array', items: { type: 'string' }, description: 'что осталось [UNVERIFIED] и почему' },
    notes: { type: 'string' },
  },
}

const AXIS_SCHEMA = {
  type: 'object',
  required: ['file', 'checks', 'findings'],
  properties: {
    file: { type: 'string' },
    checks: {
      type: 'object',
      required: ['total', 'pass', 'fail', 'partial', 'na'],
      properties: {
        total: { type: 'integer' }, pass: { type: 'integer' }, fail: { type: 'integer' },
        partial: { type: 'integer' }, na: { type: 'integer' },
      },
    },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        required: ['id', 'severity', 'title'],
        properties: {
          id: { type: 'string' },
          severity: { type: 'string', enum: ['Blocker', 'Major', 'Minor', 'Nit'] },
          title: { type: 'string' },
          anchor: { type: 'string', description: 'file:line' },
        },
      },
    },
    notes: { type: 'string' },
  },
}

phase('RAG')
const rag = await agent(
  [
    COMMON,
    `Твой item: P0.1 — RAG fluent API / DX best practices.`,
    `RAG-лестница: context7 (подключи через ToolSearch) -> perplexity-web -> WebSearch; каждый источник: URL + дата + вердикт.`,
    `Нет первоисточника — честный [UNVERIFIED], не выдуманное подтверждение.`,
  ].join('\n'),
  { label: 'P0.1 RAG-добор', phase: 'RAG', schema: RAG_SCHEMA },
)
if (!rag) throw new Error('P0.1 (RAG) не вернул результат — оси зависят от findings/P0-rag-fluent-dx.md, продолжать нельзя')
log(`P0.1: ${rag.file}; вердиктов preseed: ${rag.preseedVerdicts.length}; UNVERIFIED: ${rag.unverified.length}`)

// Барьер оправдан: P0.2/P0.3 читают findings/P0-rag-fluent-dx.md (deliverable P0.1).
phase('Оси')
const AXES = [
  { id: 'P0.2', name: 'Ось A: интеграционная поверхность / DX потребителя', file: 'findings/P0-axis-a-integration.md' },
  { id: 'P0.3', name: 'Ось B: гибкость / расширяемость / fluent API', file: 'findings/P0-axis-b-fluent.md' },
  { id: 'P0.4', name: 'Ось C: корректность / безопасность', file: 'findings/P0-axis-c-correctness.md' },
  { id: 'P0.5', name: 'Ось D: качество / доменная структура / тестовые дыры', file: 'findings/P0-axis-d-structure.md' },
]

const axisResults = await parallel(AXES.map(a => () =>
  agent(
    [
      COMMON,
      `Твой item: ${a.id} — ${a.name}.`,
      `Формат файла оси, вердиктов и находок — СТРОГО по ${PLAN}/artifacts/P0-finding-template.md (прочитай первым).`,
      `Выход: ${PLAN}/${a.file}.`,
    ].join('\n'),
    { label: `${a.id} ${a.name}`, phase: 'Оси', schema: AXIS_SCHEMA },
  ).then(r => r ? { ...r, id: a.id } : null),
))

const axes = axisResults.filter(Boolean)
for (const r of axes) {
  log(`${r.id}: чеков ${r.checks.total} (pass ${r.checks.pass} / fail ${r.checks.fail} / partial ${r.checks.partial} / n/a ${r.checks.na}); находок ${r.findings.length}`)
}
const missing = AXES.filter(a => !axes.some(r => r.id === a.id)).map(a => a.id)
if (missing.length) log(`ВНИМАНИЕ: оси без результата: ${missing.join(', ')} — их items НЕ закрывать, перезапустить соло`)

return {
  rag,
  axes,
  missing,
  next: 'Оркестратор: прогнать Validation каждого item по phases/P0.md, затем закрыть P0.1–P0.5 по §8 (item-commit findings-файлов -> bookkeeping); P0.6 — ручной solo',
}

# Внешние подтверждения P4 — scoped lifecycle and Gate fallback

Сырой capture: `../artifacts/P4-design-rag/capture.md`. Ответ Perplexity был частичным;
нормативная опора — Laravel 13 container/authorization docs и текущий framework source.

## Проверенные факты

- Scoped binding даёт один instance на Laravel request/job lifecycle и сбрасывается при новом
  Octane request/queue job.
- Laravel не обещает fiber/coroutine-local isolation внутри одного lifecycle; отказ от такой
  гарантии — консервативный inference, а не цитата документации.
- `Gate::before` short-circuits только при non-null result; `null` продолжает обычную policy chain.

## Repo-grounded решения

Сохранение panel registry в singleton, delegated scoped holder, nested `finally`, sync-job
exemption, ownership matcher и route-attribute contract определены F11–F15/D7. Внешние docs не
задают эти AzGuard semantics.


---
name: api-design
bucket: architect
version: 0.1.1
description: Designing REST/GraphQL APIs, endpoints, contracts, versioning, authentication
risk: draft
persona: architect
tags: [api, architecture, rest, contracts]
requires: [data-schema]
produces_for: []
outputs: ["docs/03_Dev/API_Endpoints.md"]
sha256: ""
adapters: [claude, cursor, fable]
---

# Skill: API Design

Apply when: task involves designing API, REST/GraphQL endpoints, API contracts, integrations.

## Principle

- API = contract; hard to change after release.
- Design API as a product: think developer experience, not only functionality.

## API style choice

| Style | Use when |
|:---|:---|
| **REST** | CRUD ops, public API, simple integrations |
| **GraphQL** | Complex nested data, different clients with different needs |
| **WebSocket** | Real-time: chat, notifications, live updates |
| **gRPC** | Internal microservices, high performance |
| **Webhooks** | Async notifications: events → external systems |

## Endpoint structure (REST) — doc format

```markdown
### POST /api/v1/resource

**Описание:** [Что делает]
**Авторизация:** Bearer token / API key / Public

**Request body:**
| Поле | Тип | Обязательно | Описание |
|:---|:---|:---|:---|
| `field_name` | string | ✅ | [Что это] |
| `optional_field` | number | ❌ | [Что это, дефолт: X] |

**Response 200:**
| Поле | Тип | Описание |
|:---|:---|:---|
| `id` | uuid | ID созданного объекта |
| `status` | enum | created / pending / failed |

**Error codes:**
| Код | Описание |
|:---|:---|
| 400 | Невалидные данные: [что именно] |
| 401 | Не авторизован |
| 409 | Конфликт: [условие] |
| 422 | Бизнес-ошибка: [условие] |
```

## Naming rules

- Plural nouns: `/users`, `/orders`, `/products`
- Nesting max 2 levels: `/users/{id}/orders` ok; `/users/{id}/orders/{id}/items/{id}` bad
- Verbs only for non-standard actions: `/orders/{id}/cancel`
- Versioning in URL: `/api/v1/` — mandatory from day one
- Consistency: snake_case for JSON fields, kebab-case in URL

## Mandatory API elements

Pagination:
```json
{
  "data": [...],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 150,
    "total_pages": 8
  }
}
```

Error response:
```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Email is required",
    "field": "email"
  }
}
```

- Idempotency: POST requests with side effects must accept `Idempotency-Key` header.

## Authentication

| Type | When |
|:---|:---|
| Bearer JWT | User requests (stateless) |
| API Key | Server integrations |
| OAuth 2.0 | When access to other users' data needed |

## Document format

Place in `docs/03_Dev/API_Endpoints.md`. File structure:
```markdown
# API: ProjectName

## Base URL
`https://api.example.com/v1`

## Аутентификация
[описание]

## Эндпоинты

### [Модуль 1]
[эндпоинты]

### [Модуль 2]
[эндпоинты]

## Общие коды ошибок
[таблица]

## Версионирование
[политика]
```

## Agent adds itself

- Rate limiting recommendations per endpoint
- Webhooks — if events exist that external systems would want to subscribe to
- Caching headers — for GET requests
- Explicit edge cases: "what if id doesn't exist", "what if body is empty"

## Hard prohibitions

FORBIDDEN:
- API without versioning (even MVP)
- Business logic in URL (verbs instead of nouns)
- Different error formats across endpoints
- Passwords / tokens in URL (only in body or headers)
- GET requests with side effects

## Related skills

- `architect/data-schema` — entities/relations API resources build on (precondition).
- `architect/security-design` — auth scheme per client type, IDOR, rate limiting (auth section here is only top layer).
- `architect/architecture` — broader context where API is one container.
- `frontend-wayfinder/wayfinder` — typesafe routes/actions on frontend for Laravel impl of this contract.
- `laravel-data-layer/laravel-data` — DTO shape of request/response when implementing contract on Laravel.
- `architect/package-contribution-protocol` — shared-package contract evolution: SemVer, "is this breaking?", migration guide, bloat gate.

<!-- ru-source-sha256: 74456f4765b3c8ec0871eadc003be5a1e567fca9249ab83836b2b6ca44aeaffb -->

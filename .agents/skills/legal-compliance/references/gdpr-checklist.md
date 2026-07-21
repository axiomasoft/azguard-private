# Стандартный чеклист GDPR (minimum baseline)

Справочник к скиллу `legal-compliance`.


### 1. Legal basis для обработки
Для **каждой категории данных** выбрать одно из 6 оснований:
- Consent (opt-in, withdrawable)
- Contract (необходимо для выполнения договора с пользователем)
- Legal obligation
- Vital interests
- Public task
- Legitimate interests (требует балансного теста)

### 2. Data inventory (Article 30 records)
Таблица для каждой категории:

| Данные | Цель | Legal basis | Retention | Хранилище | Получатели | Cross-border? |
|:---|:---|:---|:---|:---|:---|:---|

### 3. User rights реализация
- **Доступ** (Article 15) — export user data в machine-readable формате
- **Исправление** (Article 16) — UI или support flow
- **Удаление** (Article 17, «right to be forgotten») — каскад по всем хранилищам, backups, logs, analytics
- **Portability** (Article 20) — JSON/CSV export
- **Возражение** (Article 21) — opt-out из marketing/profiling

Каждое право → конкретный endpoint / UI flow / SLA ответа (1 месяц по GDPR).

### 4. Consent management
- Granular opt-in (не один «принимаю всё»)
- Cookie banner соответствует ePrivacy (нет «нажми X = согласие»)
- Запись consent (когда, версия policy, IP) с retention

### 5. Privacy Policy + ToS
Создаются как draft. Юрист доводит до финала.

### 6. DPA с процессорами
Для каждого external сервиса (AWS, Stripe, OpenAI, аналитика):
- Подписан DPA
- Регион хранения
- SCC если cross-border (EU → US)

### 7. Breach response plan
- Внутренний flow: detection → assessment → containment → notification
- SLA: 72 часа на уведомление DPA при high-risk breach
- Template уведомления готов заранее

---

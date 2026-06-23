# NOTES

## Реализованная функциональность: ручной возврат (refund)

### Backend

**Сущность и миграция:**
- Сущность [`Refund`](backend/src/Entity/Refund.php) с полями: `id`, `transaction_id`, `amount`, `reason`, `status`, `provider_refund_id`, `idempotency_key`, `created_at`.
- Миграция [`Version20260623120000`](backend/migrations/Version20260623120000.php) — таблица `refund` с уникальным индексом на `idempotency_key` и внешним ключом на `transaction`.

**Эндпоинт:**
- `POST /api/admin/transactions/{id}/refund` в [`TransactionController`](backend/src/Controller/Admin/TransactionController.php:42).
- Принимает `{ "amount": "10.00", "reason": "..." }` и заголовок `Idempotency-Key`.
- Возвращает `201 Created` при новом возврате, `200 OK` при повторном запросе с тем же ключом.
- HTTP-ошибки: `400` (нет ключа), `404` (транзакция не найдена), `422` (возврат невозможен).

**Бизнес-логика:**
- [`RefundService`](backend/src/Service/RefundService.php):
  - Проверка идемпотентности по `idempotency_key` (до начала транзакции и через `UniqueConstraintViolationException`).
  - `Pessimistic write lock` на транзакцию для защиты от гонок.
  - Валидация: статус транзакции, наличие `externalId`, сумма не превышает доступный остаток.
  - Создание записи `Refund` в статусе `pending`, вызов `ProviderClient`, обновление статуса на `accepted`.
  - Обновление `refundedAmount` и статуса транзакции (`partially_refunded` / `refunded`).
  - Отправка уведомления мерчанту через `Messenger` после успешного commit.
  - Все денежные расчёты через `bcmath` (`bcadd`, `bcsub`, `bccomp`).

**DTO и валидация:**
- [`RefundRequest`](backend/src/Dto/RefundRequest.php) с валидацией `amount` (positive, not blank) и `reason` (length 3-500).

**Исключения:**
- [`TransactionNotFoundException`](backend/src/Exception/TransactionNotFoundException.php) — 404.
- [`RefundNotAllowedException`](backend/src/Exception/RefundNotAllowedException.php) — 422.

### Frontend

- [`RefundModal`](frontend/src/components/RefundModal.tsx) — модальное окно с формой (сумма, причина), валидацией, loading/error состояниями.
- [`requestRefund()`](frontend/src/api/client.ts:13) — вызов API с генерацией `Idempotency-Key` через `crypto.randomUUID()`.
- [`App.tsx`](frontend/src/App.tsx:7) — обновление строки транзакции после успешного возврата (оптимистичный update).

---

## Исправленные недостатки базового кода

### 1. Float-арифметика → bcmath

**Проблема:** Все денежные расчёты использовали `(float)` и арифметику с плавающей точкой. Для финансовых операций это недопустимо из-за ошибок округления (`0.1 + 0.2 !== 0.3`).

**Исправлено:**
- [`RefundService`](backend/src/Service/RefundService.php) — `bcadd()`, `bcsub()`, `bccomp()`.
- [`BalanceService`](backend/src/Service/BalanceService.php) — `bcsub()`, `bcadd()`.
- [`TransactionController`](backend/src/Controller/Admin/TransactionController.php) — `bcmul()`.
- Добавлена зависимость `ext-bcmath` в [`composer.json`](backend/composer.json).

### 2. Утечка apiKey в логи

**Проблема:** [`ProviderClient`](backend/src/Service/ProviderClient.php) логировал `apiKey` в открытом виде.

**Исправлено:** Убран `apiKey` из строки лога.

### 3. Проглатывание исключений в handler

**Проблема:** [`MerchantNotificationHandler`](backend/src/MessageHandler/MerchantNotificationHandler.php) перехватывал все исключения в пустом `catch`, создавая ложное ощущение успеха.

**Исправлено:** Убран `try/catch`. Исключения пробрасываются — Messenger корректно обрабатывает retry и dead-letter.

### 4. N+1 запрос в листинге транзакций

**Проблема:** [`TransactionRepository::findForListing()`](backend/src/Repository/TransactionRepository.php) не делал `join fetch` для `merchant` → N+1 при обращении к `$tx->getMerchant()` в цикле.

**Исправлено:** Добавлен `->join('t.merchant', 'm')->addSelect('m')`.

---

## Как проверить фичу

1. Поднять инфраструктуру и backend/frontend по шагам из `README.md`.
2. Открыть список транзакций.
3. Выбрать транзакцию со статусом `paid` или `settled`.
4. Нажать "Возврат", ввести сумму и причину, подтвердить.
5. Проверить:
   - Ответ API (`201 Created`, `refundId` и `status`).
   - Изменение `refundedAmount` и статуса строки в таблице.
   - Наличие записи в таблице `refund` в БД.
   - Лог уведомления мерчанта (`messenger:consume`).
6. Повторить запрос с тем же `Idempotency-Key` — должен вернуться `200 OK` без повторной операции.
7. Проверить граничные случаи:
   - Сумма больше остатка → `422`.
   - Транзакция в статусе `failed` → `422`.
   - Несуществующая транзакция → `404`.
   - Отсутствие `Idempotency-Key` → `400`.

---

## Ответы на вопросы

### 1. Doctrine

**Моделирование сущностей и миграции:**
Refund реализован как отдельная сущность, а не просто поле `refundedAmount` в `Transaction`. Поле-агрегат удобно для чтения списка, но история денежных событий должна храниться отдельно — это обеспечивает аудируемость и поддержку множественных частичных возвратов. Миграция создаёт таблицу с внешним ключом, индексом на `transaction_id` и уникальным индексом на `idempotency_key`.

**N+1:**
В проекте была проблема N+1 в `TransactionRepository::findForListing()` — при загрузке транзакций не подгружался `merchant`. Исправлено через `join fetch` (`->join('t.merchant', 'm')->addSelect('m')`). В целом N+1 ищу через SQL profiler, логирование запросов и анализ репозиториев, где возвращаются агрегаты с зависимостями.

**Транзакции БД:**
Транзакция БД в `RefundService` покрывает: проверку доступной суммы, блокировку записи (`pessimistic write lock`), создание `Refund`, обновление `refundedAmount` и статуса, фиксацию idempotency record. Внешний side effect (вызов провайдера) происходит внутри транзакции — если он падает, всё откатывается. Уведомление через Messenger отправляется после `commit`.

**Optimistic vs pessimistic locking:**
Для refund выбрал `pessimistic write lock` на транзакцию. Для денег и admin-action это проще и надёжнее, чем optimistic locking. Optimistic уместен там, где конфликт редок и допустим retry на уровне приложения, но для конкурентных возвратов пессимистическая блокировка предотвращает гонки на уровне БД.

### 2. Messenger / RabbitMQ

**Надёжный handler:**
- Ограниченные retries с backoff (настраивается через `framework.messenger.retry`).
- `failure transport` / dead-letter для сообщений, не обработанных после лимита retry.
- Идемпотентный consumer — повторная доставка не ломает бизнес-инварианты (в refund это обеспечивается проверкой `idempotency_key`).
- Структурированные логи и метрики по retry/failure.

**Проглатывание исключений:**
В исходном `MerchantNotificationHandler` был пустой `catch (\Throwable $e) {}`, который подавлял все ошибки. Это исправлено — теперь исключения пробрасываются, и Messenger может корректно retry-ить или отправить сообщение в dead-letter.

**Порядок сообщений:**
Для уведомления мерчанта порядок не критичен. Но для денежных доменных событий я явно учитываю, где порядок важен — через сериализацию по aggregate key или идемпотентность каждого consumer.

**Падение consumer:**
Сообщение не теряется — оно остаётся в очереди и будет доставлено повторно. Важно, чтобы handler не глотал исключения молча.

### 3. Идемпотентность и атомарность

**Защита от повторного выполнения:**
`Idempotency-Key` сохраняется в БД вместе с результатом. При повторном запросе с тем же ключом `RefundService` возвращает существующую запись без повторной операции. Проверка идёт дважды: до транзакции (быстрый путь) и через `UniqueConstraintViolationException` (защита от гонок).

**Границы транзакции БД:**
Транзакция покрывает: проверку суммы, блокировку записи, создание `Refund`, обновление агрегатных полей, фиксацию статуса. Вызов провайдера — внутри транзакции; если он падает, всё откатывается.

**Частичные сбои:**
Если commit прошёл, а сеть упала на ответе провайдера — `Refund` останется в статусе `pending`. Для production нужен reconciliation path по `idempotency_key` или `provider_refund_id`, либо orchestration через outbox и webhook finalization.

### 4. Интеграции / платёжные пути

**Внешний REST API провайдера:**
- Таймауты (настраиваются в `ProviderClient`).
- Retry только для безопасных transient failures.
- Нормализованные exception types.
- Маскирование секретов в логах (исправлено: `apiKey` больше не логируется).
- Correlation / idempotency identifiers.

**Webhook:**
Должен проходить проверку подписи, защиту от replay, валидацию схемы payload и идемпотентную обработку. Запрос `refund` и последующий webhook — два этапа одного процесса: synchronous acceptance и asynchronous finalization.

**Флоу:**
1. Admin отправляет refund request.
2. Сервис валидирует вход и резервирует бизнес-инвариант локально.
3. Провайдер принимает request и возвращает внешний идентификатор.
4. Система хранит локальную запись refund.
5. Финальный статус подтверждается webhook'ом или reconciliation job.

### 5. Security / KYC

**API-ключи и секреты:**
Должны жить в env/secret storage, не в git, не в argv, не в логах. Нужны ротация, минимизация доступа, redaction в логах. Утечка `apiKey` в логи была исправлена.

**AuthZ на эндпоинтах:**
Admin endpoint требует authn/authz слой: кто может инициировать refund, по каким мерчантам, с каким audit trail. В текущем проекте auth не реализован — это out of scope для тестового задания.

**PII и KYC:**
Принцип минимизации: не хранить лишнее, ограничивать поля в ответах API, контролировать доступ и сроки хранения. KYC/идентификацию встраивал бы отдельным bounded context или интеграцией с внешним провайдером, не смешивая с платёжной транзакцией.

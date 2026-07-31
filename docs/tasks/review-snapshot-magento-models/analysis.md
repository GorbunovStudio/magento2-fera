# Перевод Fera review snapshots на модели и коллекции Magento — анализ

## Part I: Refining Requirements

### Context

Сейчас webhook-обработчики `review_created` и `review_updated`, а также команда исторического backfill обращаются к `fera_review_snapshots` через `Services/ReviewSnapshot/SnapshotRepository`. Репозиторий получает `ResourceConnection`, строит запросы через DB adapter и выполняет чтение, upsert и подсчёт неполных записей напрямую. В модуле уже применяется стандартный Magento-подход для сущностей `FeraProduct` и `FeraOrder`: model, resource model, collection и сервис, который инкапсулирует работу с ними.

Цель — перевести хранение review snapshot на этот стандартный подход, не меняя внешний контракт вебхуков, backfill и отчётности.

### Functional Requirements

- **FR-1** (Magento persistence for snapshots): система должна получать, создавать и обновлять review snapshot через Magento model, resource model и collection вместо прямого использования `ResourceConnection` в прикладном репозитории.
- **FR-2** (Stable snapshot data contract): текущий набор полей snapshot и преобразование нормализованного `media` между массивом приложения и JSON в таблице должны сохраниться.
- **FR-3** (Version-aware persistence): повторная доставка и данные со старым `fera_updated_at` не должны перезаписывать более новую локальную версию; равная или новая версия должна сохраняться.
- **FR-4** (Unchanged producers and consumers): обработчики `review_created`, `review_updated` и консольный backfill должны продолжить использовать единый persistence-сервис; логика уведомлений, сравнения snapshot и SQL-отчёт не меняются.
- **FR-5** (Incomplete snapshot reporting): backfill должен по-прежнему возвращать число неполных snapshot без загрузки и обработки несвязанного доменного поведения.

### Business Rules & Constraints

- Таблица `fera_review_snapshots`, её первичный ключ `id`, уникальность `review_id` и существующие индексы остаются без миграции схемы.
- `fera_created_at` нельзя заменять более поздним значением: его допускается заполнить только при ранее пустом поле.
- Защита от устаревшей source-версии должна действовать для каждого источника записи: обоих webhook и backfill.
- Переход не должен расширять состав snapshot-данных и не должен менять версионный SQL-файл отчёта.
- В прикладном коде persistence-сервиса не должны остаться `ResourceConnection`, `Zend_Db_Expr`, `fetchRow`, `fetchOne` или `insertOnDuplicate`.

---

## Part II: Analysis & Strategy

### Proposed Subject Areas

1. **Snapshot persistence entity** — определяет Magento model, resource model, collection и границу ответственности persistence-сервиса; покрывает FR-1 и FR-2.
2. **Version-aware saving and concurrency** — определяет сохранение правил source-version и согласованность одновременных `review_created`, `review_updated` и backfill; покрывает FR-3.
3. **Producer migration and validation** — определяет неизменность контрактов webhook/backfill, подсчёта неполных snapshot и проверок поведения; покрывает FR-4 и FR-5.

### Snapshot persistence entity

Проверка существующих `FeraProduct` и `FeraOrder` подтвердила единый модульный паттерн: API data interface с константами полей и типизированными методами, `AbstractModel`, `AbstractDb` resource model, `AbstractCollection` и сервис, владеющий доступом к сущности. Для `fera_review_snapshots` уже есть пригодная структура: технический ключ `id` и уникальный бизнес-ключ `review_id`; миграция схемы не нужна. Это покрывает FR-1 (Magento persistence for snapshots) и FR-2 (Stable snapshot data contract).

`SnapshotBuilder` и `SnapshotComparator` работают с прикладным массивом, в котором `media` — нормализованный список. Magento-модель, напротив, должна отражать значение столбца и хранить `media` как JSON-строку. Поэтому преобразование массива в JSON и обратно остаётся в едином persistence-сервисе.

**Decision:** добавить `ReviewSnapshotInterface`, `ReviewSnapshot` model, `ReviewSnapshot` resource model и её collection. Сохранить `SnapshotRepository` как фасад для webhook и backfill, но перевести его на factory, resource model и collection. Методы репозитория продолжают принимать и возвращать существующий прикладной snapshot-массив; модель не становится внешним контрактом webhook. Обработка source-version и блокировок не включается в это решение и рассматривается отдельно.

### Version-aware saving and concurrency

Текущий `insertOnDuplicate` делает запись по уникальному `review_id` атомарной и применяет mutable-поля лишь при допустимой source-версии. `fera_created_at` заполняется отдельно — только при пустом сохранённом поле. `ReviewUpdatedWebhook` уже удерживает mutex от чтения предыдущего snapshot до публикации уведомления, однако ключ включает store ID и не используется created/backfill. Значит обычная последовательность collection lookup и `resource->save()` без общей координации создаст duplicate-key и lost-update гонки. Это покрывает FR-3 (Version-aware persistence).

**Options:**

1. **Сохранить действующее поведение уведомлений:** общий mutex защищает persistence всех трёх источников, а устаревший update по-прежнему может быть сравнён и опубликован, как сейчас, хотя его snapshot не станет текущим.
2. **Не публиковать устаревший update:** repository возвращает результат применения source-версии, а `review_updated` пропускает publish для rejected stale snapshot. Это усиливает version-aware семантику, но меняет наблюдаемое поведение уведомлений.

**Decision:** использовать общий application-level mutex по глобальному `review_id` и обычные model/resource model/collection операции внутри него. Добавить внутренний `ReviewSnapshotLock` поверх `LockManagerInterface`: ключ состоит из стабильного префикса и SHA-256 review ID, а время ожидания остаётся десять секунд. `review_created`, `review_updated` и backfill используют один ключ; update удерживает lock на текущей границе «загрузка предыдущего snapshot → сравнение → version-aware save → решение и публикация уведомления».

`SnapshotRepository` применяет существующее правило source-version к загруженной модели: mutable-поля и `fera_updated_at` меняются только при пустом сохранённом timestamp либо равной/новой входящей версии; `fera_created_at` заполняется только при ранее пустом поле. Сохранение вызывается лишь для изменённой модели. При невозможности получить lock webhook возвращает retryable 503, а backfill завершает обработку текущего account через существующую обработку ошибки. Устаревший update сохраняет действующее поведение уведомлений: его сохранение может быть отклонено, но это не добавляет нового запрета на сравнение или publish.

### Producer migration and validation

У `SnapshotRepository` только три production consumer: created сохраняет построенный snapshot, updated загружает предыдущий snapshot для сравнения и затем сохраняет, а backfill сохраняет каждую валидную запись и в конце запрашивает число неполных. REST endpoints, payload и CLI-интерфейс не требуют изменения. Backfill уже изолирует ошибку одного Fera account и должен сохранить это свойство. Это покрывает FR-4 (Unchanged producers and consumers) и FR-5 (Incomplete snapshot reporting).

Сложный критерий неполноты включает вложенное условие для product review. Его нельзя выразить набором независимых `addFieldToFilter()` без изменения логики, поэтому этот predicate должен принадлежать collection, а repository должен получать результат через `getSize()`. Это сохраняет SQL внутри Magento collection и исключает построение запроса в прикладном persistence-сервисе.

**Decision:** сохранить интерфейсы webhook, их payload, параметры backfill, dry-run, account-level recovery и формат summary без изменения. Created выполняет persistence в `ReviewSnapshotLock`, после чего продолжает текущую notification-логику; при timeout отвечает retryable 503. Updated заменяет собственный store-scoped lock на общий `ReviewSnapshotLock`, удерживая существующую область «load → compare → save → publish». Backfill берёт lock только перед фактической записью каждой review; при timeout existing per-account error handling отмечает только этот account failed, а `--dry-run` не берёт lock.

`SnapshotRepository::getByReviewId(): ?array` и `countIncomplete(): int` сохраняются. `save()` становится внутренней void-операцией: ни один production consumer не использует нынешнее число затронутых строк, а stale snapshot остаётся учтённым в backfill как обработанная попытка. Collection получает `addIncompleteReportingDataFilter(): self`. SQL-ориентированный unit test заменяется поведенческой проверкой model/collection persistence; дополняются unit-тесты блокировки и producer-ов и Magento integration test для уникальности, NULL и source-version сценариев.

---

## Part III: Implementation Plan

### Proposed Implementation Steps

1. **Introduce the ReviewSnapshot Magento entity** — add the data interface, model, resource model and collection, including the reusable incomplete-snapshot filter.
2. **Move snapshot persistence to entity operations** — replace adapter calls in `SnapshotRepository` with model/resource/collection operations while preserving mapping, media conversion and source-version rules.
3. **Coordinate all snapshot writers** — add `ReviewSnapshotLock` and migrate created, updated and backfill flows without changing webhook or CLI contracts.
4. **Verify persistence behavior and integration contracts** — replace SQL-implementation assertions with behavior coverage, integration checks and static analysis.

### Step 1. Introduce the ReviewSnapshot Magento entity

- Add the internal `ReviewSnapshotInterface` with persisted-field constants and typed accessors; keep it an entity contract rather than a new Web API contract.
- Add `ReviewSnapshot` model, resource model and collection bound to `fera_review_snapshots` and technical key `id`; retain `review_id` as the globally unique lookup key.
- Represent persisted `media` on the model as a non-null JSON string, while leaving array normalization at the persistence boundary.
- Add `ReviewSnapshot\Collection::addIncompleteReportingDataFilter(): self` to encapsulate the existing incomplete-snapshot predicate for `getSize()`.

### Step 2. Move snapshot persistence to entity operations

- Replace `ResourceConnection` and DB-adapter dependencies in `SnapshotRepository` with `ReviewSnapshotFactory`, `ReviewSnapshot` resource model and collection factory; remove adapter fetch, upsert and expression calls.
- Resolve an existing snapshot through a `review_id`-filtered, single-item collection; map the model to the unchanged `ReviewSnapshot` array returned by `getByReviewId()`.
- Serialize normalized `media` before setting the entity and deserialize/normalize it while reading, retaining an empty list for invalid persisted JSON.
- Apply source-version policy before saving the model: update mutable fields and `fera_updated_at` only for a missing stored version or an equal/newer incoming version; populate `fera_created_at` only when its stored value is absent.
- Save only modified models, make `save()` an internal void operation, and implement `countIncomplete()` through `addIncompleteReportingDataFilter()->getSize()`.

### Step 3. Coordinate all snapshot writers

- Add internal `ReviewSnapshotLock` over `LockManagerInterface` with a ten-second wait, a global key made from a stable prefix and SHA-256 of `review_id`, and guaranteed unlock in `finally`.
- Run `ReviewCreatedWebhook` persistence under `ReviewSnapshotLock`; retain its existing notification flow after the save and translate lock timeout to retryable HTTP 503.
- Replace `ReviewUpdatedWebhook`’s direct, store-scoped lock with `ReviewSnapshotLock`; keep its complete critical section: previous snapshot load, comparison, version-aware save, notification decision and publish.
- Run each non-dry-run backfill persistence attempt under the same lock; let a timeout enter the existing per-account error path, while `--dry-run` neither acquires a lock nor touches persistence.

### Step 4. Verify persistence behavior and integration contracts

- Replace the repository test that asserts `insertOnDuplicate` SQL fragments with behavior tests for collection lookup, entity mapping, media JSON conversion, first insert, duplicate delivery, stale/equal/new versions, NULL timestamps and `fera_created_at` backfill.
- Add coverage for `ReviewSnapshotLock`: stable global key, release in `finally` and timeout behavior.
- Extend created, updated and backfill tests to verify common-lock usage while retaining current webhook payloads, notification behavior, dry-run semantics, account recovery and backfill summary counters.
- Add a Magento integration test against `fera_review_snapshots` for the unique key, persisted version rules and `addIncompleteReportingDataFilter()->getSize()`; run affected PHPUnit tests from the consuming Magento root and PHPStan for the package.

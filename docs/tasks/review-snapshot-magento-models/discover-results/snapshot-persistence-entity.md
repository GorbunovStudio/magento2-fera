# Snapshot persistence entity — discovery

## Inspected sources

- `Api/Data/FeraProductInterface.php`, `Model/FeraProduct.php`, `Model/ResourceModel/FeraProduct.php`, `Model/ResourceModel/FeraProduct/Collection.php`
- `Api/Data/FeraOrderInterface.php`, `Model/FeraOrder.php`, `Model/ResourceModel/FeraOrder.php`, `Model/ResourceModel/FeraOrder/Collection.php`, `Model/OrderExportManager.php`
- `Services/ReviewSnapshot/SnapshotBuilder.php`, `Services/ReviewSnapshot/SnapshotRepository.php`
- `etc/db_schema.xml`
- `Test/Unit/Services/ReviewSnapshot/SnapshotRepositoryTest.php`

## Evidence and local constraints

- Existing module entities use an API data interface with constants and typed accessors, an `AbstractModel`, an `AbstractDb` resource model, an `AbstractCollection`, and a service that injects generated model/collection factories plus a resource model.
- `OrderExportManager` demonstrates the expected lookup and persistence flow: filtered collection, page size one, `getFirstItem()`, then `resource->save($model)`.
- `fera_review_snapshots` already has an `id` primary key, a unique `review_id`, and all required fields; no schema change is needed.
- `SnapshotBuilder` provides the existing application contract: a typed array with normalized `media` as `list<array{id,url}>`.
- The current repository owns DB-to-array mapping and media JSON conversion. Its only repository unit test asserts direct `insertOnDuplicate` SQL expressions.

## Recommended entity boundary

Add `Api/Data/ReviewSnapshotInterface`, `Model/ReviewSnapshot`, `Model/ResourceModel/ReviewSnapshot`, and `Model/ResourceModel/ReviewSnapshot/Collection`, following the existing Fera product/order pattern. The interface is an internal entity contract, not a new Web API contract. It centralizes field names and type conversion; `id`, `created_at`, and `updated_at` stay outside the existing builder array contract.

Keep `SnapshotRepository` as the facade used by both webhooks and backfill. It should inject the resource model, model factory, collection factory, `Json`, and `MediaNormalizer`; it should no longer inject `ResourceConnection`, use `Zend_Db_Expr`, or call adapter methods.

Store `media` in the model as its database representation, a non-null JSON string. `SnapshotRepository` must normalize and serialize the builder's media list before saving and deserialize/normalize it when returning the existing application array. This preserves comparator inputs and defensive handling of malformed JSON.

## Risks and dependencies

- `review_id` is the global business key; `id` stays the technical key.
- Fera timestamps remain source data and must remain distinct from local lifecycle timestamps.
- A collection lookup followed by `resource->save()` does not itself retain the atomic source-version guarantee or protect concurrent writers. Version policy and shared locking require a separate decision.
- Replace the SQL-expression test with behavior tests for entity mapping, media conversion, create/update selection and version outcomes.

## Confidence

High for entity shape and repository boundary. Medium for exact typed-accessor conversion because Magento returns decimal and boolean database fields as strings; test those conversions explicitly.

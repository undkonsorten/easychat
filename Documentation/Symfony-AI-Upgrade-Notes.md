# Notes for upgrading symfony/ai from 0.1

EasyChat is on **symfony/ai-* 0.1.0** (agent, chat, platform, generic-platform, store, qdrant-store,
similarity-search-tool). The latest release at the time of writing is **0.13.0** (2026-08-30). All
symfony/ai packages are experimental and outside Symfony's BC promise, so every minor release may break
things. Upgrade them together.

Sources: the store [CHANGELOG](https://github.com/symfony/ai/blob/main/src/store/CHANGELOG.md) and
[UPGRADE.md](https://github.com/symfony/ai/blob/main/UPGRADE.md). They disagree on a few version numbers
(e.g. whether `remove()` came in 0.2 or 0.4, and `count()` in 0.9 or 0.14). Check against the tag you
actually upgrade to.

## Why upgrade: generic deletion

`StoreInterface::remove(string|array $ids, array $options = [])` removes documents by id, and every store
bridge implements it (Qdrant, MariaDb, Postgres, Redis, Weaviate, Pinecone, ChromaDb, Elasticsearch, …).
`clear()` (0.11) empties a store without dropping it.

There is still **no generic delete or listing by metadata**. Filters are store-specific query options.
That is why EasyChat tracks point ids itself in `tx_easychat_index_point` (`IndexPointRegistry`) and only
needs deletion by id.

## What to change in EasyChat

**Indexing**

* Replace `QdrantPointRemover` with the store's own `remove()`: have `VectorTarget` call
  `$store->remove($ids)`, then delete `PointRemoverInterface` and `QdrantPointRemover`.
* In `StoreFactory` / `VectorTargetFactory`, add the bridges you want to support. Nothing else in the
  indexing code is store-specific; `IndexEventListener` only looks up configurations with
  `vector_db NOT IN ('none', '')`.
* Ids: `Uuid` is no longer accepted as a document id, and bridges no longer cast ids to uuids. Pass
  `DeterministicUuid::generate(...)->toRfc4122()` as the (string) id. Keep it a UUID string, because
  Qdrant only accepts UUIDs or unsigned ints as point ids.
* `StoreInterface::add()` takes a document or an array instead of being variadic:
  `add($vectorizer->vectorize($chunks))` instead of `add(...)`.
* `VectorDocument` / `TextDocument` properties became private with getters (`getId()`, `getMetadata()`,
  …). Stores, vectorizers and retrievers are typed against `VectorDocumentInterface` / `VectorInterface`.
* Test fixtures `Tests/Fixtures/Indexing/InMemoryPointStore.php` and `FakeVectorizer.php` implement those
  interfaces and must follow: `query(QueryInterface)`, `remove()`, `clear()`, `count()`.

**Chat / retrieval** (`Classes/Reaction/ChatReaction.php`)

* `StoreInterface::query()` takes a `QueryInterface` (`VectorQuery`, `TextQuery`, `HybridQuery`)
  instead of a `Vector`.
* `SimilaritySearch` takes a `RetrieverInterface` instead of vectorizer + store:
  `new SimilaritySearch(new Retriever($store, $vectorizer))`. Its `$usedDocuments` became
  `getUsedDocuments()`.
* `AgentProcessor` was removed: pass the toolbox to the `Agent` constructor. `Agent::call()` returns a lazy
  `Execution`; read results or deltas from it. `StreamListener` is gone.
* `ToolCallMessage` holds content parts (`new Text(...)`), and its text is read with `asText()`.

**Platform** (`Classes/Factories/AiPlatformFactory.php`)

* Bridge factories were unified into a `Factory` with `createProvider()` / `createPlatform()`; check how
  the generic bridge is built now.
* `PlatformInterface::invoke()` accepts `string|Model`.
* The generic bridge no longer waits on async results: `JobResult` + `JobRunner`.

**Chat history** (`Classes/Domain/Repository/SessionRepository.php`)

* `MessageNormalizer` emits an ordered `parts` field for assistant messages. Existing sessions stored in
  the old shape may need a migration or a tolerant denormalizer.

## Known 0.1 issue the upgrade may fix

When the embeddings API rate-limits, `Bridge\Generic\Embeddings\ResultConverter` passes the
`Retry-After` header as a string to `RateLimitExceededException(?int $retryAfter)`, which throws a
`TypeError` instead of the rate-limit exception. Check whether this is fixed upstream.

## After upgrading

Run both test suites. `QdrantReindexTest` checks the real store end to end. Then run `index:queue`: the
point count in the store must match the row count in `tx_easychat_index_point`.

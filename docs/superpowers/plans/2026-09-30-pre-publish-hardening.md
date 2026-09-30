# Pre-publish Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix every blocker, high and medium finding from the 2026-09-30 pre-publish review so `drupal/meilisearch` can be released as 1.0.

**Architecture:** Each Search API server owns its own Meilisearch client (no shared mutable singleton). The backend talks to Meilisearch through a thin API service that converts every SDK failure (API, network, timeout, failed task) into `MeilisearchApiException`, which the backend rethrows as `SearchApiException`. Search API's own `BackendTestBase` conformance suite, run against a real Meilisearch, is the acceptance test.

**Tech Stack:** Drupal 10.3/11, Search API ≥ 1.39, meilisearch-php ^1.16, PHPUnit via drupal/core-dev, Docker Compose (watch) for local tests.

**Spec:** the review in this conversation (summarised in "Findings addressed" below).

## Global Constraints

- `core_version_requirement: ^10.3 || ^11`; `drupal/search_api: ^1.39` (first release with plugin attributes); PHP ≥ 8.1.
- Plugins use PHP attributes (`#[SearchApiBackend]`, `#[SearchApiProcessor]`), not annotations.
- No Drupal UI for server-level Meilisearch settings (synonyms, stop words, ranking rules, embedders): link to Cloud instead.
- Primary key of every Meilisearch document: `search_api_document_id` (the `search_api_` prefix is reserved by Search API, so no indexed field can collide).
- Every stored document carries `search_api_id`, `search_api_datasource`, `search_api_language`; all three are filterable and sortable.
- Never swallow write errors: `addIndex`, `updateIndex`, `removeIndex`, `indexItems`, `deleteItems`, `deleteAllIndexItems`, `search` throw `SearchApiException`.
- No `Co-Authored-By` lines in commits.

## Review Focus

1. Two servers pointing at different Meilisearch instances: queries and writes must never cross. (Task 2 kernel test `testServersAreIsolated`.)
2. Meilisearch down or slow: search page gets a `SearchApiException` (Views shows "no results"/error), never a PHP fatal. (Task 2 unit test with a throwing client.)
3. Multilingual index with `setLanguages()`: only requested languages return. (Conformance suite `searchSuccess`.)
4. An indexed field literally named `id`: must not break document identity. (Conformance suite: example content has an `id` field.)
5. Conditions the builder cannot express: exception, never silent widening. (Task 4 unit test.)

---

### Task 1: Test infrastructure and conformance suite (red)

**Files:**
- Create: `compose.yaml`, `Dockerfile.dev`, `scripts/test.sh`
- Create: `tests/modules/meilisearch_test/meilisearch_test.info.yml` + `config/install/search_api.server.search_server.yml`, `search_api.index.search_index.yml` (copied from `search_api_test_db`, backend switched to `meilisearch`)
- Create: `tests/src/Kernel/MeilisearchBackendConformanceTest.php` (extends `\Drupal\Tests\search_api\Kernel\BackendTestBase`)
- Modify: `composer.json`, `meilisearch.info.yml`, `phpunit.xml.dist`, `.github/workflows/test.yml`; create `.gitlab-ci.yml` (drupal.org template)

- [ ] Build `Dockerfile.dev` (Drupal 11 + search_api + facets + meilisearch-php + core-dev), `compose.yaml` with `meilisearch` (healthcheck) and `tests` service (`develop.watch` syncs module into `web/modules/custom/meilisearch`).
- [ ] Conformance test reads `MEILISEARCH_TEST_URL`/`MEILISEARCH_TEST_KEY`, skips when absent, rewrites the server config, deletes leftover indexes in `setUp`/`tearDown`.
- [ ] Run: `scripts/test.sh tests/src/Kernel` → Expected: conformance test FAILS (current backend can't handle special fields).
- [ ] Commit.

### Task 2: Per-server API client and total error mapping

**Files:** `src/Api/*`, `src/Client/*`, `meilisearch.services.yml`, tests `tests/src/Unit/Api/MeilisearchApiServiceTest.php`

**Interfaces:**
- `MeilisearchApiFactory::create(string $url, string $apiKey, array $headers = []): MeilisearchApiServiceInterface` (service `meilisearch.api_factory`).
- `MeilisearchApiServiceInterface`: `ping()`, `version()`, `createIndex(uid, primaryKey)`, `deleteIndex(uid)`, `updateSettings(uid, settings)`, `addDocuments(uid, docs)`, `deleteDocuments(uid, ids)`, `deleteDocumentsByFilter(uid, filter)`, `deleteAllDocuments(uid)`, `search(uid, q, params): array` (raw response), `multiSearch(queries): array`, `sendEvent(array $event): void`, `waitForTask(int $uid, int $timeoutMs = 60000): array`.
- Every method converts `Meilisearch\Exceptions\ExceptionInterface` (API, communication, timeout, JSON) into `MeilisearchApiException`. `waitForTask` throws when the task status is `failed`, with the task's error message and code.

- [ ] Unit tests: failed task throws; timeout throws `MeilisearchApiException`; communication error throws; headers decorator adds headers.
- [ ] Implement; delete the shared `meilisearch.api` service.
- [ ] Commit.

### Task 3: Document conversion

**Files:** `src/Converter/DocumentConverter.php`, `src/Utility/MeilisearchUtils.php`, unit tests.

- [ ] `MeilisearchUtils::encodeDocumentId()`: injective, output `[A-Za-z0-9_-]` ≤ 511 bytes (`_` → `__`, other bytes → `_XX`, fallback `h_` + sha256 when too long).
- [ ] Documents: `search_api_document_id`, `search_api_id`, `search_api_datasource`, `search_api_language`, then fields. Fields whose ID starts with `_` are skipped. `location` values (`"lat,lon"`) → `_geo` (first location field only).
- [ ] Unit tests for encoding uniqueness, special fields, `id` field preserved, location.
- [ ] Commit.

### Task 4: Filter builder

**Files:** `src/Filter/*`, unit tests.

- [ ] Negated groups → `NOT (...)`. `= NULL` → `(f NOT EXISTS OR f IS NULL)`; `<> NULL` → `(f EXISTS AND NOT f IS NULL)`.
- [ ] Unknown field or unsupported operator/value → `MeilisearchFilterException` (extends `SearchApiException`).
- [ ] `build($group, $index, array $excludeTags = [])` skips groups carrying any excluded tag (for OR facets).
- [ ] One value formatter shared by all parsers (numbers bare, strings quoted+escaped, booleans `true/false`); BETWEEN quotes strings.
- [ ] Remove `GeoFilterParser` (geo goes through the `search_api_location` option).
- [ ] Commit.

### Task 5: Backend lifecycle and configuration

**Files:** `src/Plugin/search_api/backend/MeilisearchBackend.php`, `config/schema/meilisearch.schema.yml`.

- [ ] Config: `url`, `api_key`, `index_prefix`, `search_mode`, `semantic_ratio`, `embedder`, `matching_strategy`, `max_total_hits`. API key: password field, blank keeps the existing key; description points at settings.php override.
- [ ] Lazy `getApi()` per backend instance; reset on `setConfiguration()`; not serialized.
- [ ] `addIndex` (tolerates `index_already_exists`), `updateIndex` (settings incl. `pagination.maxTotalHits`, reindex when fields change), `removeIndex` (respects read-only), `deleteAllIndexItems($datasource)` by filter, all throw `SearchApiException`.
- [ ] `supportsDataType('location')`, `getDiscouragedProcessors()`, attributes, no `final`, docblocks.
- [ ] Commit.

### Task 6: Search

- [ ] Keys: original string when available, else flatten parsed keys (negated terms → `-term`); matching strategy from config.
- [ ] Languages → `search_api_language IN [...]`; `setLanguages([])` aborts.
- [ ] Pagination: page mode when offset is a multiple of limit (exact `totalHits`), else offset/limit.
- [ ] `showRankingScore` → `setScore()`; `attributesToRetrieve: [search_api_id]`.
- [ ] Sorts incl. magic fields; location sort → `_geoPoint`; `search_api_location` option → `_geoRadius`.
- [ ] Facets natively (`search_api_facets` option → `search_api_facets` extra data) with `limit`, `min_count`, `missing`, and OR facets via one multi-search; delete `modules/meilisearch_facets`.
- [ ] Conformance suite green (backend-specific overrides only where Meilisearch semantics differ: complex boolean keys, fulltext-field conditions, word-level facets on fulltext fields).
- [ ] Commit.

### Task 7: Highlighting → excerpts

- [ ] Processor requests highlight/crop; `postprocessSearchResults` sets `$item->setExcerpt()` from `_formatted`, HTML-escaped with only the configured tags restored.
- [ ] Kernel test asserts `<script>` in content is escaped in the excerpt.
- [ ] Commit.

### Task 8: Analytics (Cloud only)

- [ ] Searches send `Meili-Include-Metadata: true` + `X-MS-USER-ID`; backend stores `queryUid` on results.
- [ ] Views rows for Meilisearch indexes get `data-meilisearch-*` attributes; library attached automatically; JS uses `Drupal.url()`.
- [ ] Controller takes the Drupal index ID (not a Meilisearch UID), validates it, rate-limits with flood control, sends through that index's server.
- [ ] Commit.

### Task 9: Docs and packaging

- [ ] README (requirements, settings.php key override, limitations), docs as Mintlify (`docs/mint.json` + `.mdx`), version floors.
- [ ] phpcs (Drupal, DrupalPractice) clean; phpstan level 1 clean.
- [ ] Run full suite against minimum and latest Meilisearch.
- [ ] Commit.

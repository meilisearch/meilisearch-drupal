# Meilisearch Drupal Plugin — Design Spec

**Date:** 2026-04-14
**Status:** Approved
**Linear:** SID-16

## Overview

Official Meilisearch Search API backend module for Drupal. Brand-new module (`drupal/meilisearch`) built from scratch, not a fork of the existing community module (`drupal/search_api_meilisearch`).

## Requirements

- Drupal 9.3, 10, 11 compatibility
- PHP ^8.1 (via `meilisearch-php ^1.16`)
- Search API framework integration
- Meilisearch Cloud and self-hosted support
- Semantic/hybrid search
- Facets, geo search, highlighting
- Analytics (click/conversion event tracking)
- Deployment examples: Docker Compose, DDEV, Meilisearch Cloud

## Architecture: Feature-Rich Core + Minimal Submodules

Core module includes all features that only depend on the Meilisearch PHP SDK. Submodules exist only when there is an external Drupal module dependency or a distinct optional concern.

> **Scope note:** Server-level settings (synonyms, stop words, ranking rules, embedders) are managed in Meilisearch Cloud or via the Meilisearch HTTP API / `meilisearch` CLI — not duplicated in Drupal admin. The Drupal module's responsibility is field-level attribute sync (searchable, filterable, sortable, displayed) derived from the Search API index configuration.

### Module Naming

| Field | Value |
|-------|-------|
| Machine name | `meilisearch` |
| Composer package | `drupal/meilisearch` |
| PHP namespace | `Drupal\meilisearch` |
| Backend plugin ID | `meilisearch` |

## Module Structure

```
meilisearch/
├── composer.json
├── meilisearch.info.yml
├── meilisearch.services.yml
├── meilisearch.module
├── meilisearch.install
├── config/
│   └── schema/
│       └── meilisearch.schema.yml
├── src/
│   ├── Api/
│   │   ├── MeilisearchApiServiceInterface.php
│   │   ├── MeilisearchApiService.php
│   │   └── MeilisearchApiException.php
│   ├── Client/
│   │   ├── MeilisearchClientFactoryInterface.php
│   │   └── MeilisearchClientFactory.php
│   ├── Plugin/
│   │   └── search_api/
│   │       └── backend/
│   │           └── MeilisearchBackend.php
│   ├── Processor/
│   │   └── MeilisearchHighlighting.php
│   ├── Converter/
│   │   ├── DocumentConverterInterface.php
│   │   └── DocumentConverter.php
│   ├── Filter/
│   │   ├── FilterBuilderInterface.php
│   │   ├── FilterBuilder.php
│   │   ├── ScalarValueParser.php
│   │   ├── BooleanValueParser.php
│   │   ├── NullValueParser.php
│   │   ├── BetweenOperatorParser.php
│   │   ├── NotBetweenOperatorParser.php
│   │   ├── InOperatorParser.php
│   │   ├── NotInOperatorParser.php
│   │   └── GeoFilterParser.php
│   └── Utility/
│       └── MeilisearchUtils.php
├── modules/
│   ├── meilisearch_facets/
│   │   ├── meilisearch_facets.info.yml
│   │   ├── meilisearch_facets.services.yml
│   │   └── src/EventSubscriber/
│   │       ├── DeterminingServerFeaturesSubscriber.php
│   │       └── ProcessingResultsSubscriber.php
│   └── meilisearch_analytics/
│       ├── meilisearch_analytics.info.yml
│       ├── meilisearch_analytics.services.yml
│       ├── meilisearch_analytics.libraries.yml
│       ├── js/
│       │   └── click-tracking.js
│       └── src/
│           ├── EventSubscriber/
│           │   └── SearchMetadataSubscriber.php
│           ├── Analytics/
│           │   └── AnalyticsService.php
│           └── Controller/
│               └── ClickTrackingController.php
├── tests/
│   ├── src/
│   │   ├── Unit/
│   │   │   ├── FilterBuilderTest.php
│   │   │   └── DocumentConverterTest.php
│   │   ├── Kernel/
│   │   │   ├── MeilisearchBackendTest.php
│   │   │   └── IndexLifecycleTest.php
│   │   └── Functional/
│   │       ├── SearchIntegrationTest.php
│   │       ├── FacetsIntegrationTest.php
│   │       ├── GeoSearchTest.php
│   │       └── SemanticSearchTest.php
│   └── modules/
│       └── meilisearch_test/
├── docs/
│   ├── docker-compose.example.yml
│   ├── ddev-setup.md
│   └── cloud-setup.md
└── README.md
```

## Core Backend Plugin — MeilisearchBackend

Extends `BackendPluginBase`, implements `PluginFormInterface`.

### Connection Configuration

The backend form provides:

- **Connection mode toggle**: "Self-hosted" vs "Meilisearch Cloud"
  - Self-hosted: Host URL + port (default `http://127.0.0.1:7700`)
  - Cloud: Project URL (e.g., `https://ms-xxx.meilisearch.io`) — no port field
- **API key**: single field, works for both master key (self-hosted) and admin API key (Cloud)
- **Test connection** AJAX button — pings server, displays Meilisearch version
- **Cloud detection**: stored as boolean in config. Determined by URL pattern (`*.meilisearch.io`) or `/version` response. Used by analytics submodule.
- **Cloud dashboard link**: when connection mode is "cloud", the backend form displays a link — "Manage synonyms, stop words, ranking rules, and embedders in your Meilisearch Cloud dashboard →" — pointing to `https://cloud.meilisearch.com/projects`.

### Search Mode Configuration

- **Keyword** (default) — standard full-text search
- **Semantic** — vector-based similarity search (`semanticRatio: 1.0`)
- **Hybrid** — blended keyword + semantic (configurable `semanticRatio`, default `0.5`)
- **Embedder name** — text field, the embedder configured on the Meilisearch instance

When hybrid/semantic mode is enabled, the backend adds `hybrid: { semanticRatio, embedder }` to all search queries.

### Index Lifecycle

| Method | Behavior |
|--------|----------|
| `addIndex()` | Creates Meilisearch index with UID = Search API index machine name |
| `updateIndex()` | Computes full desired settings state and pushes via bulk `updateSettings()` |
| `removeIndex()` | Deletes the Meilisearch index |

All operations are async (Meilisearch tasks) — the backend waits for task completion.

### Settings Sync (on `updateIndex()`)

Rather than comparing and updating each attribute type individually, the backend computes the full desired state and sends it in one `updateSettings()` call:

- Searchable attributes (ordered by field boost weight)
- Filterable attributes (all indexed fields + `_geo` if any field has Search API type `location`)
- Sortable attributes (all indexed fields + `_geo` if any field has Search API type `location`)
- Displayed attributes (all fields)

### Indexing

- `indexItems()` — converts Drupal items to Meilisearch documents via `DocumentConverter`, sends via `addDocuments()`
- `deleteItems()` / `deleteAllIndexItems()` — standard delete operations
- Document IDs sanitized for Meilisearch (no `:` or `/`)

### Searching

Translates Search API `QueryInterface` into Meilisearch search params:

| Search API | Meilisearch |
|------------|-------------|
| Keywords | `q` parameter |
| Conditions | Filter string via `FilterBuilder` |
| Sorts | `sort` array (e.g., `["field:asc"]`) |
| Pagination | `offset` / `limit` |
| Facets | `facets` array (when facets submodule active) |
| Hybrid/Semantic | `hybrid: { semanticRatio, embedder }` |

Results mapped back to Search API `ResultSet` items using the stored `search_api_id` field.

## API Service Layer

### Client Factory

`MeilisearchClientFactory` creates `Meilisearch\Client` instances. Accepts URL + API key. Uses PSR-18 HTTP client discovery (via `php-http/discovery`). No opinion on Guzzle vs Symfony HttpClient.

### API Service Interface

Methods grouped by domain:

```
Connection:   ping(), version(), isCloud()
Indexes:      createIndex(), getIndex(), listIndexes(), deleteIndex()
Documents:    addDocuments(), getDocuments(), deleteDocuments(), deleteAllDocuments()
Search:       search(), multiSearch(), searchFacets()
Settings:     getSettings(), updateSettings()
Tasks:        waitForTask(), getTask()
```

Key design choice: prefer bulk `updateSettings()` over individual getters/setters for each attribute type. Individual methods only where needed (e.g., `searchFacets()`).

### Service Container

- API service is `shared: false` — each backend instance gets its own configured client (supports multiple Search API servers pointing to different Meilisearch instances)
- Error handling: all API calls wrap exceptions into `MeilisearchApiException`. Backend decides how to surface (log, messenger, throw `SearchApiException`).

## Processors

### MeilisearchHighlighting

`@SearchApiProcessor` plugin.

- Config: which fields to highlight, pre/post tags (default `<em>`/`</em>`), crop length, crop marker
- On search: adds `attributesToHighlight`, `highlightPreTag`, `highlightPostTag`, `attributesToCrop`, `cropLength`, `cropMarker` to search query
- Injects highlighted snippets into result items as extra data for themes

## Document Converter

Transforms Drupal Search API items into Meilisearch documents.

### Field Type Mapping

| Search API Type | Meilisearch Type |
|----------------|-----------------|
| `text` / `string` | string |
| `integer` / `decimal` | number |
| `boolean` | boolean |
| `date` | Unix timestamp (number) |
| `geopoint` | `_geo: { lat, lng }` |

- Multi-value fields become arrays
- Document ID: sanitized from Search API item ID (replace `/` and `:` with `-`)
- `search_api_id` field stores original unsanitized ID for result mapping
- Extensible via tagged services — other modules can register field converters for custom types

## Filter Builder

Converts Search API `ConditionGroup` into Meilisearch filter syntax strings.

### Supported Operations

| Search API | Meilisearch Filter |
|-----------|-------------------|
| `=` | `field = "value"` |
| `!=` / `<>` | `field != "value"` |
| `<`, `<=`, `>`, `>=` | `field < value` |
| `IN` | `field IN ["a", "b"]` |
| `NOT IN` | `field NOT IN ["a", "b"]` |
| `BETWEEN` | `field min TO max` |
| `NOT BETWEEN` | `NOT field min TO max` |
| `IS NULL` | `field IS NULL` |
| `IS NOT NULL` | `field IS NOT NULL` |
| AND/OR groups | Parenthesized: `(a AND b) OR c` |
| Geo radius | `_geoRadius(lat, lng, meters)` |
| Geo bounding box | `_geoBoundingBox([lat1, lng1], [lat2, lng2])` |

### Implementation

- Recursive tree walker for `ConditionGroup`
- Tagged service pattern: individual condition parsers (scalar, boolean, null, between, in, geo) collected via service tags
- Boolean values: converts `1`/`0` to `true`/`false`

## Submodule: meilisearch_facets

**Depends on:** `drupal/facets`

Uses event subscriber pattern (not query type plugins) — Meilisearch faceting is pass `facets: [...]` at search time, get `facetDistribution` + `facetStats` back.

### Event Subscribers

- `DeterminingServerFeaturesSubscriber` — advertises facet support to Search API
- `ProcessingResultsSubscriber` — parses `facetDistribution` (string facet counts) and `facetStats` (min/max for numeric) from Meilisearch response into Facets module format
- Facet search via dedicated `searchFacets()` endpoint for type-ahead in facet widgets

## Submodule: meilisearch_analytics

**Depends on:** `meilisearch` (no external Drupal dependency)

Based on Meilisearch analytics API (`POST /events`).

### How It Works

1. **Search metadata injection**: adds `Meili-Include-Metadata: true` header to all search requests. Stores returned `queryUid` in result set extra data.
2. **Click tracking**: lightweight JS snippet attached to search result pages. When user clicks a result, fires `POST /events` with:
   - `eventType: "click"`
   - `queryUid` (from search metadata)
   - `objectId` (document ID)
   - `position` (result position)
   - `userId` (configurable: Drupal user ID, hashed session ID, or anonymous hash)
3. **Conversion tracking**: service method for other modules to record conversion events tied to a prior search
4. **Custom fields**: supports `analyticsCustomFields` on search requests for custom metadata

### Configuration

- Enable/disable click tracking
- User identification method: Drupal user ID, hashed session ID, anonymous hash
- Drupal route (`ClickTrackingController`) as a proxy endpoint for click events (avoids exposing Meilisearch API key to the browser)

## Deployment Examples

### Docker Compose

Full local dev: Drupal + Meilisearch containers with healthchecks.

```yaml
services:
  drupal:
    image: drupal:11-php8.3-apache
    ports:
      - "8080:80"
    volumes:
      - drupal_modules:/var/www/html/modules
      - drupal_sites:/var/www/html/sites
    depends_on:
      meilisearch:
        condition: service_healthy

  meilisearch:
    image: getmeili/meilisearch:latest
    ports:
      - "7700:7700"
    environment:
      MEILI_MASTER_KEY: "masterKey123"
      MEILI_ENV: "development"
    volumes:
      - meili_data:/meili_data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:7700/health"]
      interval: 5s
      timeout: 5s
      retries: 5

volumes:
  drupal_modules:
  drupal_sites:
  meili_data:
```

### DDEV

- `.ddev/docker-compose.meilisearch.yaml` adds Meilisearch as a DDEV service
- Connection URL inside DDEV: `http://meilisearch:7700`
- Custom `ddev meilisearch` command for status checks
- Step-by-step: `ddev start`, `ddev composer require drupal/meilisearch`, configure in admin

### Meilisearch Cloud

- Create project at `https://cloud.meilisearch.com`
- Copy project URL + admin API key
- Configure in Drupal: Search API server → "Meilisearch Cloud" mode → paste URL + key
- Configure embedders for semantic search via Cloud dashboard or API
- Use search-only API key for frontend, admin key for indexing

## Testing Strategy

### Unit Tests (no Drupal, no Meilisearch)

- `FilterBuilderTest` — all operator mappings, AND/OR groups, geo filters, null handling, boolean conversion
- `DocumentConverterTest` — field type mapping, multi-value fields, geo field conversion, ID sanitization

### Kernel Tests (Drupal bootstrap, mocked API)

- `MeilisearchBackendTest` — plugin discovery, config form validation, default settings
- `IndexLifecycleTest` — create/update/remove index produce correct API requests

### Functional Tests (full Drupal, real Meilisearch)

- `SearchIntegrationTest` — index content, search, verify results
- `FacetsIntegrationTest` — faceted search with counts
- `GeoSearchTest` — geo filtering and sorting
- `SemanticSearchTest` — hybrid search (requires configured embedder)

### CI Infrastructure

- Unit and kernel tests run without Meilisearch
- Functional tests use Meilisearch Docker container as GitHub Actions service
- `meilisearch_test` helper module provides test content types and sample data

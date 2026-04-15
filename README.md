# Meilisearch for Drupal

Official Meilisearch backend for the Drupal Search API.

## Requirements

- Drupal 9.3, 10, or 11
- PHP 8.1+
- A Meilisearch instance (self-hosted or Cloud) — v1.3.3+

## Installation

```
composer require drupal/meilisearch
drush en meilisearch -y
```

## Features

- Full-text search via Drupal Search API
- Self-hosted and Meilisearch Cloud support (auto-detected)
- Semantic and hybrid (keyword + vector) search with embedders
- Geo search (`_geoRadius`, `_geoBoundingBox`)
- Highlighting and snippet cropping (per-query)

> **Server-level settings** (synonyms, stop words, ranking rules, embedders) are
> managed in the **Meilisearch Cloud dashboard** or via the Meilisearch CLI/HTTP
> API. The Drupal admin UI intentionally does not duplicate that surface — when
> you connect a Cloud server, the configuration form links straight to the
> dashboard for those settings.

### Optional submodules

- **`meilisearch_facets`** — faceted search via `drupal/facets`
- **`meilisearch_analytics`** — click & conversion event tracking

## Quick start

1. Enable the module.
2. Go to **Configuration → Search API** and add a server with Meilisearch as the backend.
3. Fill in the connection details (URL, port or Cloud URL, API key).
4. Create an index, pick fields to index, and run indexing.

## Deployment guides

- [Docker Compose](docs/docker-compose-setup.md)
- [DDEV](docs/ddev-setup.md)
- [Meilisearch Cloud](docs/cloud-setup.md)

## Architecture

| Layer | Class |
|-------|-------|
| Search API backend | `MeilisearchBackend` |
| API wrapper | `MeilisearchApiService` |
| Document conversion | `DocumentConverter` |
| Filter translation | `FilterBuilder` + condition parsers |

## License

GPL-2.0-or-later

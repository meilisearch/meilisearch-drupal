# Drupal Meilisearch Demo — Design

**Date:** 2026-04-15
**Location of demo repo:** `_demos/drupal-meilisearch-demo/` (sibling of this plugin)
**Status:** Approved for implementation planning

## Goal

A zero-friction showcase of the `drupal/meilisearch` module. One command (`docker compose up`) boots a Drupal 11.1 site with the module pre-installed, a recipe content type configured, 300 curated recipes seeded and indexed, and a working faceted search page. Audience: Drupal developers evaluating the module.

## Scope

**In scope (core feature tier):**
- Full-text search with typo tolerance
- Per-query highlighting (via the plugin's `MeilisearchHighlighting` processor)
- Faceted search on two dimensions (cuisine + meal type) via `meilisearch_facets` + `drupal/facets`
- Range filter on cook time (plain Views exposed filter)
- Sort by rating or cook time
- Admin walkthrough of the Search API backend configuration screens

**Out of scope (explicitly documented as such in the demo README):**
- Semantic / hybrid search (would need an embedder — deferred to a future "Cloud edition")
- Analytics submodule (shipped by the plugin but not exercised here)
- Geo search (recipes don't have coordinates)
- Multilingual
- Production hardening

## Architecture

Four Docker services in a single `compose.yaml`:

| Service | Image | Purpose |
|---------|-------|---------|
| `drupal` | Custom `Dockerfile`, extends `drupal:11.1-apache` | Drupal 11.1 on PHP 8.3, serves the site at `localhost:8080`. Plugin source bind-mounted from `../../_sdk/meilisearch-drupal` as a Composer path repository so the demo always uses local plugin code. |
| `db` | `mariadb:11` | Drupal database. Named volume persists between restarts. |
| `meilisearch` | `getmeili/meilisearch:v1.16` | The search engine. Master key set via `MEILI_MASTER_KEY`. Health-checked. |
| `drush` (one-shot) | Same image as `drupal` | On first boot, runs `drush site:install meilisearch_demo_profile -y` + seed; exits. Idempotent — detects already-installed state via `drush status --field=bootstrap` and exits cleanly. |

**First-boot flow:**

1. `db` and `meilisearch` start and reach healthy state
2. `drupal` container comes up, Apache serves empty Drupal
3. `drush` one-shot runs the install profile, which:
   a. Installs Drupal core + required contrib + the plugin
   b. Imports shipped config (content type, fields, taxonomies, server, index, views, facets)
   c. Runs the custom install task that reads `fixtures/recipes.json` and creates ~300 nodes
   d. Synchronously indexes all nodes into Meilisearch
   e. Exits 0
4. Evaluator hits `http://localhost:8080/recipes` and searches

**Reset:** `docker compose down -v` wipes DB + Meilisearch volumes. Next `up` repeats step 1–3.

## Content model

**Content type:** `recipe` (single bundle).

| Field | Type | Purpose | Meilisearch role |
|---|---|---|---|
| `title` | node title | Recipe name | Searchable + highlighted |
| `body` | text_long with summary | Short description | Searchable + highlighted |
| `field_ingredients` | text_long | Ingredients list | Searchable |
| `field_directions` | text_long | Cooking steps | Not indexed; visible on node page only |
| `field_cuisine` | entity_reference → `cuisine` taxonomy | Italian, Mexican, etc. | Filterable / facet #1 |
| `field_meal_type` | entity_reference multi → `meal_type` taxonomy | Dinner, Dessert, etc. | Filterable / facet #2 |
| `field_total_time` | integer (minutes) | Prep + cook time | Filterable (range) + sortable |
| `field_rating` | decimal(3,1) | User rating 0.0–5.0 | Sortable + displayed |
| `field_image_url` | string | URL of recipe image | Not indexed; rendered as `<img>` in teaser |

**Two taxonomies:** `cuisine` (~10 terms, single-select on nodes) and `meal_type` (~6 terms, multi-select). Terms are created on demand during seed — never manually curated.

**Image handling:** stored as a string URL, rendered with `<img src>` in the Views teaser template, with broken-image fallback. We do not use Drupal's `image` field because downloading ~300 images during install adds container boot time and repo weight for no plugin-feature benefit.

**Index settings** (configured via shipped `search_api.index.recipes.yml`):
- Searchable: `title` (rank 1), `body` (rank 2), `field_ingredients` (rank 3)
- Filterable: `field_cuisine`, `field_meal_type`, `field_total_time`, `field_rating`
- Sortable: `field_total_time`, `field_rating`
- Highlighting processor: enabled, `<mark>` / `</mark>` pre/post tags, 15-word crop

**Ranking rules:** Meilisearch defaults (keeps the demo honest — no hand-tuning of relevance).

## Search experience

**Public search page — `http://localhost:8080/recipes`**

A Search API view (`recipes_search`) attached to the Meilisearch index:
- **Search box** (exposed fulltext filter) at top
- **Results grid** (3 cols desktop, 1 col mobile) — teaser shows image, title with `<mark>` highlights, cropped+highlighted body snippet, cuisine badge, meal-type chips, rating stars, total time
- **Left sidebar** with three filters:
  - Cuisine facet (checkbox list, counts shown)
  - Meal type facet (checkbox list, counts shown)
  - Total time — Views exposed filter with buckets: 0–30, 30–60, 60+ mins (plain Views, not `drupal/facets` — their range UX isn't great)
- **Sort dropdown:** Relevance (default) / Rating desc / Total time asc
- **"X results" count** + pagination (25 per page)

**Theme:** Olivero (Drupal 11 default) + thin `meilisearch_demo_theme` subtheme that overrides the `recipe` teaser template and adds ~30 lines of CSS for the card grid and `<mark>` styling.

**Admin experience** — `admin`/`admin` credentials:
- `/admin/config/search/search-api` — server shown as "Available" with Meilisearch version
- `/admin/config/search/search-api/server/meilisearch_demo` — full backend config form pre-filled
- `/admin/config/search/search-api/index/recipes` — "300 of 300 indexed"
- Fields tab shows which fields are searchable/filterable/sortable
- Processors tab shows highlighting enabled

**Anonymous access:** `/recipes` is public. Admin UI requires login.

## Install profile internals

**Profile path:** `web/profiles/meilisearch_demo_profile/`

```
meilisearch_demo_profile/
├── meilisearch_demo_profile.info.yml
├── meilisearch_demo_profile.install
├── meilisearch_demo_profile.profile
├── config/install/          ← ~20 YAML files
└── fixtures/recipes.json    ← ~300 curated recipes
```

**`.info.yml` dependencies:**
- Core: `node`, `taxonomy`, `views`, `views_ui`, `user`, `field`, `text`, `options`
- Contrib: `search_api`, `facets`
- Our plugin: `meilisearch`, `meilisearch_facets`

**`config/install/` contents:**
- `node.type.recipe.yml`
- `field.storage.node.field_*.yml` + `field.field.node.recipe.field_*.yml` (one pair per field)
- `taxonomy.vocabulary.cuisine.yml`, `taxonomy.vocabulary.meal_type.yml`
- `search_api.server.meilisearch_demo.yml` + `search_api.index.recipes.yml`
- `views.view.recipes_search.yml`
- `facets.facet.recipe_cuisine.yml`, `facets.facet.recipe_meal_type.yml`
- `block.block.*.yml` placing facet blocks in Olivero's sidebar region

**Seed task** (declared via `hook_install_tasks`):
- Loads `fixtures/recipes.json`
- For each record: ensures taxonomy terms exist (create on demand), creates a `recipe` node with all fields populated
- After all nodes are created, synchronously indexes remaining items:
  `$index->indexItems($index->getTrackerInstance()->getRemainingItems())`
- Logs progress every 50 records

**Fixture schema** (`fixtures/recipes.json` is a JSON array of flat objects):
```json
{
  "title": "Classic Margherita Pizza",
  "body": "A simple, traditional pizza...",
  "ingredients": "- 500g flour\n- 1 tsp salt\n...",
  "directions": "1. Preheat oven...",
  "cuisine": "Italian",
  "meal_types": ["Dinner"],
  "total_time": 45,
  "rating": 4.6,
  "image_url": "https://..."
}
```

**`scripts/build-fixture.py`** — committed for reproducibility, run manually (not in Docker):
- Reads a Kaggle CSV path from argv
- Normalizes cuisine/meal-type strings against a hardcoded allowlist (~10 cuisines, ~6 meal types)
- Drops rows missing title, image, or with `total_time > 360`
- Stratified sample: ~30 recipes per cuisine to guarantee facet diversity
- Writes `web/profiles/meilisearch_demo_profile/fixtures/recipes.json`

**Error handling in the seed task:**
- If Meilisearch unreachable during indexing → task fails with clear message ("ensure `meilisearch` service is healthy"). Compose healthcheck + one-shot wait normally prevent this.
- If fixture file missing → task logs a warning and skips; site still installs cleanly empty.
- Per-recipe errors (malformed row) are logged and skipped; install task continues.

**Idempotency:** `scripts/entrypoint-drush.sh` starts with:
```bash
if drush status --field=bootstrap 2>/dev/null | grep -q Successful; then
  echo "Drupal already installed — skipping."
  exit 0
fi
```

## Repo layout

The Drupal docroot lives at `web/`. Everything Drupal looks for (modules, profiles, themes) goes under `web/`. The profile lives at `web/profiles/meilisearch_demo_profile/` — no symlinks, no out-of-tree tricks.

```
_demos/drupal-meilisearch-demo/
├── compose.yaml
├── Dockerfile
├── .env.example
├── .gitignore
├── README.md
├── composer.json
├── composer.lock
├── scripts/
│   ├── build-fixture.py                ← one-time offline data prep
│   └── entrypoint-drush.sh             ← idempotent install runner
└── web/                                ← Drupal docroot
    ├── modules/custom/                 ← empty (all custom code is in the profile)
    ├── themes/custom/meilisearch_demo_theme/
    │   ├── meilisearch_demo_theme.info.yml
    │   ├── meilisearch_demo_theme.libraries.yml
    │   ├── css/recipes.css
    │   └── templates/node--recipe--teaser.html.twig
    └── profiles/meilisearch_demo_profile/
        ├── meilisearch_demo_profile.info.yml
        ├── meilisearch_demo_profile.install
        ├── meilisearch_demo_profile.profile
        ├── config/install/             ← ~20 YAML files listed above
        └── fixtures/recipes.json       ← ~300 curated recipes
```

**Gitignored:** `web/core/`, `web/modules/contrib/`, `web/sites/default/files/`, `web/sites/default/settings.php`, `vendor/`, `.env`. These are produced by `composer install` or at runtime and should not be committed.

## Verification checklist

Acceptance criteria for the demo (tested manually on a clean clone):

1. `docker compose up` on a clean checkout completes within ~3 minutes, no errors in any container log.
2. `http://localhost:8080/recipes` returns a list of recipes — no 500, no "Meilisearch unreachable" errors.
3. Searching "pasta" in the search box returns Italian recipes with `<mark>` tags visible around `pasta` substrings in title or body teaser.
4. Clicking a cuisine facet filters results and the URL updates with a facet query parameter (e.g. `?f[0]=cuisine:Italian`).
5. Clicking the "0–30 min" total-time bucket filters to fast recipes only.
6. Sorting by "Rating desc" reorders results by descending rating.
7. Logging in as `admin`/`admin` and visiting `/admin/config/search/search-api/server/meilisearch_demo` shows a green "Available" badge and the Meilisearch version string.
8. `docker compose down -v && docker compose up` repeats cleanly from empty.

## README outline

- One-sentence intro + link to the plugin repo
- **Quickstart** (clone, copy `.env.example`, `docker compose up`, open URL)
- **What you'll see** — bullet list matching the verification checklist
- **Guided tour** — short paragraphs: public search page → admin search API screen → adding a recipe → reindexing
- **How the demo is built** — install profile + fixture + Docker Compose; link to `profiles/meilisearch_demo_profile/`
- **Resetting** — `docker compose down -v`
- **Troubleshooting** — port 8080 already in use, Meilisearch healthcheck failing, fixture import errors
- **Out of scope** — semantic/hybrid, analytics, geo (pointers to the plugin's own docs for those)

## Success criteria

The demo is "done" when:
- All 8 verification checklist items pass on a fresh clone on macOS (OrbStack) and Linux (Docker Engine).
- README walkthrough can be followed by someone unfamiliar with Drupal and they reach a working search page within 5 minutes.
- The plugin source is pulled from `../../_sdk/meilisearch-drupal` via Composer path repo, so plugin changes flow into the demo without publishing.

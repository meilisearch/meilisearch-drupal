# Meilisearch Cloud Setup

## Prerequisites

- A Meilisearch Cloud account at <https://cloud.meilisearch.com>
- A Drupal site with the `meilisearch` module installed

## Steps

1. **Create a project** in the Cloud dashboard. After provisioning, copy:
   - The project URL (e.g., `https://ms-abc123.fra.meilisearch.io`)
   - An **admin API key** for indexing operations
   - A **search-only API key** for frontend queries (optional, recommended)

2. **Configure the Drupal server:**
   - Go to **Configuration → Search API → Servers → Add server**
   - Name: e.g. "Meilisearch Cloud"
   - Backend: **Meilisearch**
   - Connection mode: **Meilisearch Cloud**
   - Host URL: paste the project URL (no port needed)
   - API key: admin API key
   - Click **Save**. The "Is Cloud" flag is auto-detected from the `.meilisearch.io` domain.

3. **Verify connectivity:** the server edit screen shows the Meilisearch version and "Cloud: Yes" when connected.

4. **Create an index** and attach it to the Cloud server. Add fields, then index content. The module will create the matching Meilisearch index via the Cloud API.

5. **(Optional) Enable semantic search:**
   - In the Cloud dashboard, go to **Settings → Embedders** and configure an embedder (e.g., OpenAI `text-embedding-3-small`).
   - In Drupal, edit the server, set **Search mode** to **Hybrid** or **Semantic**, and enter the embedder name.

6. **(Optional) Enable analytics:**
   - Install the `meilisearch_analytics` submodule.
   - Add the `click-tracking` library to your theme:

     ```yaml
     # theme.libraries.yml
     search-results:
       dependencies:
         - meilisearch_analytics/click_tracking
     ```

   - Render result links with `data-meilisearch-*` attributes (see submodule README).

## Security tips

- **Use a search-only key for frontend queries** via Meilisearch's tenant token mechanism. Do not expose admin keys.
- Rotate the admin key periodically via the Cloud dashboard.

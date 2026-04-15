# Docker Compose Setup

## Prerequisites

- Docker and Docker Compose v2.22+
- `curl` for healthchecks

## Steps

1. Copy the example compose file:

   ```
   cp docs/docker-compose.example.yml compose.yaml
   ```

2. Start the stack:

   ```
   docker compose up -d
   ```

3. Install Drupal (browse to <http://localhost:8080>).

4. Add the module via Composer (on the Drupal container):

   ```
   docker compose exec drupal composer require drupal/meilisearch
   ```

5. Enable the module:

   ```
   docker compose exec drupal drush en meilisearch -y
   ```

6. In the Drupal admin UI, go to **Configuration → Search API** and create a new server:
   - Backend: **Meilisearch**
   - Connection mode: **Self-hosted**
   - Host URL: `http://meilisearch` (Docker service DNS)
   - Port: `7700`
   - API key: `masterKey123`

7. Create an index and attach it to the server, then add fields and index content.

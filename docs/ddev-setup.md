# DDEV Setup

## Prerequisites

- DDEV v1.22+
- An existing DDEV project (or `ddev config` to create one)

## Steps

1. Copy the Meilisearch compose addon into your project:

   ```
   mkdir -p .ddev
   cp docs/ddev/docker-compose.meilisearch.yaml .ddev/
   ```

2. Restart DDEV:

   ```
   ddev restart
   ```

3. Install the module via Composer:

   ```
   ddev composer require drupal/meilisearch
   ```

4. Enable it:

   ```
   ddev drush en meilisearch -y
   ```

5. Configure the server in Drupal admin:
   - Host URL: `http://meilisearch`
   - Port: `7700`
   - API key: `masterKey123`

6. Access the Meilisearch dashboard (if exposed) at <https://your-project.ddev.site:7701>.

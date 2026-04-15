# Drupal Meilisearch Demo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A one-command Drupal 11.1 demo (`docker compose up`) that boots a site with the `drupal/meilisearch` plugin pre-installed, a Recipe content type, ~300 seeded recipes from the Kaggle "Better Recipes for a Better Life" dataset, and a working faceted search page at `http://localhost:8080/recipes`.

**Architecture:** Docker Compose stack with three services (drupal, db, meilisearch) plus a one-shot `drush` service that runs `site:install meilisearch_demo_profile` and seeds content on first boot. The install profile ships all config as YAML under `config/install/` and runs a custom install task to create nodes from a JSON fixture. Plugin source comes from a Composer path repository pointing at the sibling plugin checkout so plugin changes flow into the demo without publishing.

**Tech Stack:** Drupal 11.1 + PHP 8.3 + MariaDB 11 + Meilisearch 1.16 + Docker Compose. Module dependencies: `drupal/search_api`, `drupal/facets`, `drupal/meilisearch` (local, via path repo). Offline data-prep script in Python 3.

**Spec:** `docs/superpowers/specs/2026-04-15-drupal-demo-design.md`

---

## Working directory

All tasks in this plan operate under **`/Users/quentindequelen/Projects/Meilisearch/_demos/drupal-meilisearch-demo/`** unless stated otherwise. This directory does not yet exist — Task 1 creates it.

The plugin source being referenced is at `/Users/quentindequelen/Projects/Meilisearch/_sdk/meilisearch-drupal/`, which is two levels up (`../../_sdk/meilisearch-drupal/`).

**Commit convention:** every task ends with a `git commit` inside the demo repo (we'll `git init` it in Task 1). Commit messages use Conventional Commits (`feat:`, `docs:`, `fix:`, `chore:`).

---

## Phase 1 — Repo scaffold

Goal of phase: `docker compose up` builds all images and the `drupal` service starts (even though no site is installed yet).

### Task 1: Initialize demo repo

**Files:**
- Create: `_demos/drupal-meilisearch-demo/.gitignore`
- Create: `_demos/drupal-meilisearch-demo/README.md` (placeholder)

- [ ] **Step 1: Create directory and initialize git**

Run:
```bash
mkdir -p /Users/quentindequelen/Projects/Meilisearch/_demos/drupal-meilisearch-demo
cd /Users/quentindequelen/Projects/Meilisearch/_demos/drupal-meilisearch-demo
git init
```

- [ ] **Step 2: Write `.gitignore`**

Create `.gitignore` with:
```
# Environment
.env

# Composer
vendor/

# Drupal - installed artifacts
web/core/
web/modules/contrib/
web/themes/contrib/
web/profiles/contrib/
web/libraries/

# Drupal - runtime
web/sites/default/files/
web/sites/default/private/
web/sites/default/settings.php
web/sites/default/settings.local.php
web/sites/default/services.yml
web/.ht.router.php

# Editor
.DS_Store
.idea/
.vscode/
*.swp
```

- [ ] **Step 3: Write placeholder `README.md`**

```markdown
# Drupal Meilisearch Demo

A one-command showcase of the [drupal/meilisearch](../../_sdk/meilisearch-drupal) module.

Full README coming in the final task of the implementation plan.
```

- [ ] **Step 4: Commit**

```bash
git add .gitignore README.md
git commit -m "chore: initialize demo repo with .gitignore and placeholder README"
```

---

### Task 2: Create `composer.json`

**Files:**
- Create: `composer.json`

- [ ] **Step 1: Write `composer.json`**

```json
{
  "name": "meilisearch/drupal-demo",
  "description": "One-command Drupal + Meilisearch demo.",
  "type": "project",
  "license": "GPL-2.0-or-later",
  "repositories": [
    {
      "type": "composer",
      "url": "https://packages.drupal.org/8"
    },
    {
      "type": "path",
      "url": "../../_sdk/meilisearch-drupal",
      "options": {
        "symlink": false
      }
    }
  ],
  "require": {
    "php": "^8.3",
    "composer/installers": "^2.3",
    "drupal/core-composer-scaffold": "^11.1",
    "drupal/core-recommended": "^11.1",
    "drupal/core-project-message": "^11.1",
    "drupal/search_api": "^1.30",
    "drupal/facets": "^3.0",
    "drupal/meilisearch": "*",
    "drush/drush": "^13.0"
  },
  "conflict": {
    "drupal/drupal": "*"
  },
  "minimum-stability": "dev",
  "prefer-stable": true,
  "config": {
    "allow-plugins": {
      "composer/installers": true,
      "drupal/core-composer-scaffold": true,
      "drupal/core-project-message": true,
      "cweagans/composer-patches": true,
      "php-http/discovery": true
    },
    "sort-packages": true
  },
  "extra": {
    "drupal-scaffold": {
      "locations": {
        "web-root": "web/"
      }
    },
    "installer-paths": {
      "web/core": ["type:drupal-core"],
      "web/libraries/{$name}": ["type:drupal-library"],
      "web/modules/contrib/{$name}": ["type:drupal-module"],
      "web/profiles/contrib/{$name}": ["type:drupal-profile"],
      "web/themes/contrib/{$name}": ["type:drupal-theme"],
      "drush/Commands/contrib/{$name}": ["type:drupal-drush"]
    }
  }
}
```

> Note: `drupal/meilisearch` resolves via the path repository to `../../_sdk/meilisearch-drupal/`. The `"symlink": false` option forces a copy so changes require rebuild — this avoids Composer write-back issues when tests run inside Docker.

- [ ] **Step 2: Commit**

```bash
git add composer.json
git commit -m "feat: add composer.json with Drupal 11.1 + contrib + plugin path repo"
```

---

### Task 3: Create `Dockerfile`

**Files:**
- Create: `Dockerfile`

- [ ] **Step 1: Write `Dockerfile`**

```dockerfile
FROM php:8.3-apache

# System deps for Drupal + Composer
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by Drupal 11
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo_mysql \
        mysqli \
        opcache \
        zip \
        intl \
        mbstring \
        xml

# Opcache recommended settings
RUN { \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.validate_timestamps=1'; \
    echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Enable Apache rewrite for Drupal clean URLs
RUN a2enmod rewrite

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer files and install vendor + Drupal scaffold
WORKDIR /var/www/html
COPY composer.json composer.lock* ./
COPY ../../_sdk/meilisearch-drupal /opt/meilisearch-drupal

# Make the plugin path repo available to composer at the relative path it expects
RUN mkdir -p /var/www/_sdk && ln -sf /opt/meilisearch-drupal /var/www/_sdk/meilisearch-drupal

# Install Drupal + contrib + plugin
RUN composer install --no-interaction --prefer-dist --no-progress

# Apache docroot points to web/
RUN sed -i 's!/var/www/html!/var/www/html/web!g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's!/var/www/html!/var/www/html/web!g' /etc/apache2/apache2.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html/web

# Drush on PATH
RUN ln -sf /var/www/html/vendor/bin/drush /usr/local/bin/drush

# Add scripts
COPY scripts/entrypoint-drush.sh /scripts/entrypoint-drush.sh
RUN chmod +x /scripts/entrypoint-drush.sh

EXPOSE 80
```

> Note: the `COPY ../../_sdk/meilisearch-drupal /opt/meilisearch-drupal` step requires a build context wider than the demo directory. We handle that in `compose.yaml` by setting `build.context` to the parent. If that's awkward, alternative: mount the plugin at runtime and run `composer install` at entrypoint. We'll start with the simpler build-time copy.

- [ ] **Step 2: Commit**

```bash
git add Dockerfile
git commit -m "feat: add Dockerfile for Drupal 11.1 on PHP 8.3"
```

---

### Task 4: Create entrypoint script

**Files:**
- Create: `scripts/entrypoint-drush.sh`

- [ ] **Step 1: Write `scripts/entrypoint-drush.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

echo "[entrypoint-drush] Waiting for database..."
until mysql -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASS}" -e 'SELECT 1' "${DB_NAME}" >/dev/null 2>&1; do
  sleep 1
done
echo "[entrypoint-drush] Database is up."

echo "[entrypoint-drush] Waiting for Meilisearch..."
until curl -sf "${MEILI_URL}/health" >/dev/null 2>&1; do
  sleep 1
done
echo "[entrypoint-drush] Meilisearch is up."

if drush status --field=bootstrap 2>/dev/null | grep -q Successful; then
  echo "[entrypoint-drush] Drupal already installed — skipping."
  exit 0
fi

echo "[entrypoint-drush] Installing Drupal with meilisearch_demo_profile..."
drush site:install meilisearch_demo_profile \
  --db-url="mysql://${DB_USER}:${DB_PASS}@${DB_HOST}/${DB_NAME}" \
  --account-name=admin \
  --account-pass="${DRUPAL_ADMIN_PASS:-admin}" \
  --site-name="Meilisearch Demo" \
  --yes

echo "[entrypoint-drush] Install complete."
```

- [ ] **Step 2: Make executable and commit**

```bash
mkdir -p scripts
# (file written at scripts/entrypoint-drush.sh above)
chmod +x scripts/entrypoint-drush.sh
git add scripts/entrypoint-drush.sh
git commit -m "feat: add idempotent entrypoint script for drush install"
```

---

### Task 5: Create `compose.yaml`

**Files:**
- Create: `compose.yaml`

- [ ] **Step 1: Write `compose.yaml`**

```yaml
name: drupal-meilisearch-demo

services:
  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: drupal
      MARIADB_USER: drupal
      MARIADB_PASSWORD: drupal
      MARIADB_ROOT_PASSWORD: root
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD-SHELL", "mariadb -udrupal -pdrupal -e 'SELECT 1' drupal"]
      interval: 5s
      timeout: 5s
      retries: 20

  meilisearch:
    image: getmeili/meilisearch:v1.16
    environment:
      MEILI_MASTER_KEY: "${MEILI_MASTER_KEY:-masterKey123}"
      MEILI_NO_ANALYTICS: "true"
      MEILI_ENV: "development"
    ports:
      - "7700:7700"
    volumes:
      - meilisearch_data:/meili_data
    healthcheck:
      test: ["CMD-SHELL", "wget -q --spider http://localhost:7700/health || exit 1"]
      interval: 5s
      timeout: 5s
      retries: 20

  drush:
    build:
      context: ../..
      dockerfile: _demos/drupal-meilisearch-demo/Dockerfile
    restart: "no"
    depends_on:
      db:
        condition: service_healthy
      meilisearch:
        condition: service_healthy
    environment:
      DB_HOST: db
      DB_NAME: drupal
      DB_USER: drupal
      DB_PASS: drupal
      MEILI_URL: http://meilisearch:7700
      MEILI_MASTER_KEY: "${MEILI_MASTER_KEY:-masterKey123}"
      DRUPAL_ADMIN_PASS: "${DRUPAL_ADMIN_PASS:-admin}"
    volumes:
      - ./web/profiles/meilisearch_demo_profile:/var/www/html/web/profiles/meilisearch_demo_profile
      - ./web/themes/custom:/var/www/html/web/themes/custom
      - drupal_sites:/var/www/html/web/sites/default
    entrypoint: ["/scripts/entrypoint-drush.sh"]

  drupal:
    build:
      context: ../..
      dockerfile: _demos/drupal-meilisearch-demo/Dockerfile
    ports:
      - "8080:80"
    depends_on:
      drush:
        condition: service_completed_successfully
    environment:
      DB_HOST: db
      DB_NAME: drupal
      DB_USER: drupal
      DB_PASS: drupal
      MEILI_URL: http://meilisearch:7700
      MEILI_MASTER_KEY: "${MEILI_MASTER_KEY:-masterKey123}"
    volumes:
      - ./web/profiles/meilisearch_demo_profile:/var/www/html/web/profiles/meilisearch_demo_profile
      - ./web/themes/custom:/var/www/html/web/themes/custom
      - drupal_sites:/var/www/html/web/sites/default

volumes:
  db_data:
  meilisearch_data:
  drupal_sites:
```

Key points:
- `build.context: ../..` puts the plugin source within Docker's build context so the `COPY ../../_sdk/meilisearch-drupal` in the Dockerfile works.
- `drush` has `restart: "no"` because it's one-shot.
- `drupal` waits for `drush` to finish (`service_completed_successfully`), guaranteeing Apache only serves once install is done.
- `drupal_sites` is a shared named volume for `web/sites/default/` (settings.php + files). Both drush and drupal mount it.

- [ ] **Step 2: Commit**

```bash
git add compose.yaml
git commit -m "feat: add compose.yaml with drupal, db, meilisearch, and drush one-shot"
```

---

### Task 6: Create `.env.example`

**Files:**
- Create: `.env.example`

- [ ] **Step 1: Write `.env.example`**

```
# Meilisearch master key (used by both Meilisearch server and Drupal backend config).
# For local demo only; never reuse in production.
MEILI_MASTER_KEY=masterKey123

# Password for the Drupal admin user created at install time.
DRUPAL_ADMIN_PASS=admin
```

- [ ] **Step 2: Commit**

```bash
git add .env.example
git commit -m "docs: add .env.example with demo-safe defaults"
```

---

### Task 7: Phase 1 smoke test

- [ ] **Step 1: Build and start, verify services reach healthy**

Run:
```bash
cd /Users/quentindequelen/Projects/Meilisearch/_demos/drupal-meilisearch-demo
cp .env.example .env
docker compose build
```

Expected: build completes without errors. The `drush` service will likely fail on first `up` (there's no install profile yet) but `db` and `meilisearch` should come up healthy.

- [ ] **Step 2: Start only db + meilisearch**

Run:
```bash
docker compose up -d db meilisearch
docker compose ps
```

Expected: both services show `healthy` status within ~30s.

- [ ] **Step 3: Tear down**

```bash
docker compose down -v
```

- [ ] **Step 4: No commit (smoke test only)**

If build failed or services didn't reach healthy, fix the underlying issue and retry. Do not proceed to Phase 2 with a broken build.

---

## Phase 2 — Install profile skeleton

Goal of phase: `docker compose up` installs Drupal with the custom profile. Admin login works at `/user/login`. No content yet, but the site is alive.

### Task 8: Create profile `.info.yml`

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.info.yml`

- [ ] **Step 1: Write `.info.yml`**

```yaml
name: Meilisearch Demo
type: profile
description: 'Showcase install profile for the drupal/meilisearch module. Creates a Recipe content type, seeds ~300 recipes, and wires up a faceted search page.'
core_version_requirement: ^11
install:
  - node
  - taxonomy
  - views
  - views_ui
  - user
  - field
  - field_ui
  - text
  - options
  - block
  - search_api
  - facets
  - meilisearch
  - meilisearch_facets
themes:
  - olivero
  - claro
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.info.yml
git commit -m "feat: add install profile .info.yml with required modules"
```

---

### Task 9: Create profile `.profile`

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.profile`

- [ ] **Step 1: Write the `.profile` file**

```php
<?php

/**
 * @file
 * Install profile callbacks for Meilisearch Demo.
 */

declare(strict_types=1);

/**
 * Implements hook_install_tasks().
 */
function meilisearch_demo_profile_install_tasks(array &$install_state): array {
  return [
    'meilisearch_demo_profile_seed_recipes' => [
      'display_name' => t('Seed demo recipes'),
      'type' => 'normal',
    ],
  ];
}

/**
 * Install task: seed ~300 recipes from the bundled fixture.
 *
 * Populated in Task 26. For now this is a no-op so that install succeeds.
 */
function meilisearch_demo_profile_seed_recipes(array &$install_state): void {
  // Will be implemented in Task 26.
}
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.profile
git commit -m "feat: add profile .profile with seed install task stub"
```

---

### Task 10: Create empty `.install`

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.install`

- [ ] **Step 1: Write `.install`**

```php
<?php

/**
 * @file
 * Install hooks for Meilisearch Demo profile.
 */

declare(strict_types=1);

/**
 * Implements hook_install().
 *
 * All static configuration ships in config/install/ and is imported
 * automatically. This hook is reserved for anything that cannot be expressed
 * declaratively (currently nothing).
 */
function meilisearch_demo_profile_install(): void {
  // Intentionally empty.
}
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.install
git commit -m "feat: add empty hook_install for profile"
```

---

### Task 11: Phase 2 smoke test

- [ ] **Step 1: Build and bring the full stack up**

Run:
```bash
docker compose down -v
docker compose up --build -d
```

- [ ] **Step 2: Watch the drush logs**

Run:
```bash
docker compose logs -f drush
```

Expected: see `[entrypoint-drush] Database is up.`, `Meilisearch is up.`, `Installing Drupal with meilisearch_demo_profile...`, `Install complete.`, and then the container exits 0.

- [ ] **Step 3: Verify Drupal serves**

Open `http://localhost:8080` in a browser. Expected: Drupal's Olivero front page (empty of content, just site name "Meilisearch Demo"). `/user/login` accepts `admin` / `admin`.

- [ ] **Step 4: Tear down**

```bash
docker compose down -v
```

If anything above fails, fix and retry before continuing.

---

## Phase 3 — Content model config

Goal of phase: after install, `/admin/structure/types/manage/recipe` shows the Recipe content type with all 7 custom fields (beyond `title`/`body`). Two taxonomies visible at `/admin/structure/taxonomy`.

### Task 12: Create taxonomy vocabulary configs

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/taxonomy.vocabulary.cuisine.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/taxonomy.vocabulary.meal_type.yml`

- [ ] **Step 1: Write `taxonomy.vocabulary.cuisine.yml`**

```yaml
langcode: en
status: true
dependencies: {  }
name: Cuisine
vid: cuisine
description: 'Recipe cuisine (e.g., Italian, Mexican).'
weight: 0
new_revision: false
```

- [ ] **Step 2: Write `taxonomy.vocabulary.meal_type.yml`**

```yaml
langcode: en
status: true
dependencies: {  }
name: 'Meal type'
vid: meal_type
description: 'Type of meal (e.g., Dinner, Dessert).'
weight: 0
new_revision: false
```

- [ ] **Step 3: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/taxonomy.vocabulary.cuisine.yml \
        web/profiles/meilisearch_demo_profile/config/install/taxonomy.vocabulary.meal_type.yml
git commit -m "feat: add cuisine and meal_type taxonomy configs"
```

---

### Task 13: Create `recipe` content type config

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/node.type.recipe.yml`

- [ ] **Step 1: Write the content type config**

```yaml
langcode: en
status: true
dependencies:
  enforced:
    module:
      - node
name: Recipe
type: recipe
description: 'A single recipe with ingredients, directions, cuisine, meal type, and rating.'
help: ''
new_revision: true
preview_mode: 1
display_submitted: false
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/node.type.recipe.yml
git commit -m "feat: add recipe content type config"
```

---

### Task 14: Create field storage configs

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/field.storage.node.field_ingredients.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/field.storage.node.field_directions.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/field.storage.node.field_cuisine.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/field.storage.node.field_meal_type.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/field.storage.node.field_total_time.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/field.storage.node.field_rating.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/field.storage.node.field_image_url.yml`

- [ ] **Step 1: Write all 7 storage configs**

`field.storage.node.field_ingredients.yml`:
```yaml
langcode: en
status: true
dependencies:
  module:
    - node
    - text
id: node.field_ingredients
field_name: field_ingredients
entity_type: node
type: text_long
settings: {  }
module: text
locked: false
cardinality: 1
translatable: true
indexes: {  }
persist_with_no_fields: false
custom_storage: false
```

`field.storage.node.field_directions.yml`:
```yaml
langcode: en
status: true
dependencies:
  module:
    - node
    - text
id: node.field_directions
field_name: field_directions
entity_type: node
type: text_long
settings: {  }
module: text
locked: false
cardinality: 1
translatable: true
indexes: {  }
persist_with_no_fields: false
custom_storage: false
```

`field.storage.node.field_cuisine.yml`:
```yaml
langcode: en
status: true
dependencies:
  module:
    - node
    - taxonomy
id: node.field_cuisine
field_name: field_cuisine
entity_type: node
type: entity_reference
settings:
  target_type: taxonomy_term
module: core
locked: false
cardinality: 1
translatable: true
indexes: {  }
persist_with_no_fields: false
custom_storage: false
```

`field.storage.node.field_meal_type.yml`:
```yaml
langcode: en
status: true
dependencies:
  module:
    - node
    - taxonomy
id: node.field_meal_type
field_name: field_meal_type
entity_type: node
type: entity_reference
settings:
  target_type: taxonomy_term
module: core
locked: false
cardinality: -1
translatable: true
indexes: {  }
persist_with_no_fields: false
custom_storage: false
```

`field.storage.node.field_total_time.yml`:
```yaml
langcode: en
status: true
dependencies:
  module:
    - node
id: node.field_total_time
field_name: field_total_time
entity_type: node
type: integer
settings:
  unsigned: true
  size: normal
module: core
locked: false
cardinality: 1
translatable: true
indexes: {  }
persist_with_no_fields: false
custom_storage: false
```

`field.storage.node.field_rating.yml`:
```yaml
langcode: en
status: true
dependencies:
  module:
    - node
id: node.field_rating
field_name: field_rating
entity_type: node
type: decimal
settings:
  precision: 3
  scale: 1
module: core
locked: false
cardinality: 1
translatable: true
indexes: {  }
persist_with_no_fields: false
custom_storage: false
```

`field.storage.node.field_image_url.yml`:
```yaml
langcode: en
status: true
dependencies:
  module:
    - node
id: node.field_image_url
field_name: field_image_url
entity_type: node
type: string
settings:
  max_length: 1024
  is_ascii: false
  case_sensitive: false
module: core
locked: false
cardinality: 1
translatable: true
indexes: {  }
persist_with_no_fields: false
custom_storage: false
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/field.storage.node.field_*.yml
git commit -m "feat: add field storage configs for recipe custom fields"
```

---

### Task 15: Create field instance configs

**Files:**
- Create one file per field under `web/profiles/meilisearch_demo_profile/config/install/field.field.node.recipe.field_*.yml`

- [ ] **Step 1: Write all 7 instance configs**

`field.field.node.recipe.field_ingredients.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.field_ingredients
    - node.type.recipe
id: node.recipe.field_ingredients
field_name: field_ingredients
entity_type: node
bundle: recipe
label: Ingredients
description: 'Line-separated list of ingredients.'
required: false
translatable: true
default_value: {  }
default_value_callback: ''
settings: {  }
field_type: text_long
```

`field.field.node.recipe.field_directions.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.field_directions
    - node.type.recipe
id: node.recipe.field_directions
field_name: field_directions
entity_type: node
bundle: recipe
label: Directions
description: 'Cooking steps.'
required: false
translatable: true
default_value: {  }
default_value_callback: ''
settings: {  }
field_type: text_long
```

`field.field.node.recipe.field_cuisine.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.field_cuisine
    - node.type.recipe
    - taxonomy.vocabulary.cuisine
id: node.recipe.field_cuisine
field_name: field_cuisine
entity_type: node
bundle: recipe
label: Cuisine
description: ''
required: false
translatable: false
default_value: {  }
default_value_callback: ''
settings:
  handler: 'default:taxonomy_term'
  handler_settings:
    target_bundles:
      cuisine: cuisine
    sort:
      field: name
      direction: asc
    auto_create: true
    auto_create_bundle: cuisine
field_type: entity_reference
```

`field.field.node.recipe.field_meal_type.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.field_meal_type
    - node.type.recipe
    - taxonomy.vocabulary.meal_type
id: node.recipe.field_meal_type
field_name: field_meal_type
entity_type: node
bundle: recipe
label: 'Meal type'
description: ''
required: false
translatable: false
default_value: {  }
default_value_callback: ''
settings:
  handler: 'default:taxonomy_term'
  handler_settings:
    target_bundles:
      meal_type: meal_type
    sort:
      field: name
      direction: asc
    auto_create: true
    auto_create_bundle: meal_type
field_type: entity_reference
```

`field.field.node.recipe.field_total_time.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.field_total_time
    - node.type.recipe
id: node.recipe.field_total_time
field_name: field_total_time
entity_type: node
bundle: recipe
label: 'Total time (minutes)'
description: 'Prep + cook time, in minutes.'
required: false
translatable: false
default_value: {  }
default_value_callback: ''
settings:
  min: ''
  max: ''
  prefix: ''
  suffix: ' min'
field_type: integer
```

`field.field.node.recipe.field_rating.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.field_rating
    - node.type.recipe
id: node.recipe.field_rating
field_name: field_rating
entity_type: node
bundle: recipe
label: Rating
description: '0.0 to 5.0'
required: false
translatable: false
default_value: {  }
default_value_callback: ''
settings:
  min: 0
  max: 5
  prefix: ''
  suffix: ''
field_type: decimal
```

`field.field.node.recipe.field_image_url.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.field_image_url
    - node.type.recipe
id: node.recipe.field_image_url
field_name: field_image_url
entity_type: node
bundle: recipe
label: 'Image URL'
description: 'Absolute URL of the recipe image.'
required: false
translatable: true
default_value: {  }
default_value_callback: ''
settings:
  case_sensitive: false
field_type: string
```

Note: `max_length` lives on the storage config only; string field instance schema does not include it.

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/field.field.node.recipe.field_*.yml
git commit -m "feat: add field instance configs for recipe fields"
```

---

### Task 16: Phase 3 smoke test

- [ ] **Step 1: Clean install**

```bash
docker compose down -v
docker compose up --build -d
docker compose logs -f drush
```

Expected: install completes.

- [ ] **Step 2: Inspect admin**

Log in as `admin`/`admin` at `http://localhost:8080/user/login`. Visit:
- `/admin/structure/types/manage/recipe` → "Recipe" content type exists with description
- `/admin/structure/types/manage/recipe/fields` → all 7 custom fields listed
- `/admin/structure/taxonomy` → "Cuisine" and "Meal type" vocabularies exist

If any are missing, inspect `docker compose logs drush` for config import errors and fix the underlying YAML.

- [ ] **Step 3: Tear down**

```bash
docker compose down -v
```

---

## Phase 4 — Search API config

Goal of phase: after install, `/admin/config/search/search-api` shows the Meilisearch server as "Available" and the Recipes index with 0/0 indexed (no content yet).

### Task 17: Create `search_api.server.meilisearch_demo.yml`

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/search_api.server.meilisearch_demo.yml`

- [ ] **Step 1: Write the server config**

```yaml
langcode: en
status: true
dependencies:
  module:
    - meilisearch
    - search_api
id: meilisearch_demo
name: 'Meilisearch (demo)'
description: 'Self-hosted Meilisearch running at http://meilisearch:7700 inside Docker.'
backend: meilisearch
backend_config:
  connection_mode: self_hosted
  host: 'http://meilisearch'
  port: 7700
  cloud_host: ''
  api_key: 'masterKey123'
  search_mode: keyword
  embedder_name: ''
  semantic_ratio: 0.5
```

Key points:
- `host` matches the compose service name.
- `api_key` matches the `MEILI_MASTER_KEY` default in `.env.example`. (Production demos would read this from env, but for a local-only demo the literal is clearer.)
- `search_mode: keyword` — the core-tier demo does not use semantic/hybrid.

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/search_api.server.meilisearch_demo.yml
git commit -m "feat: add Meilisearch search API server config"
```

---

### Task 18: Create `search_api.index.recipes.yml`

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/search_api.index.recipes.yml`

- [ ] **Step 1: Write the index config**

```yaml
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.field_cuisine
    - field.storage.node.field_ingredients
    - field.storage.node.field_meal_type
    - field.storage.node.field_rating
    - field.storage.node.field_total_time
    - search_api.server.meilisearch_demo
  module:
    - meilisearch
    - node
    - search_api
id: recipes
name: Recipes
description: 'Searchable index of recipe nodes.'
read_only: false
field_settings:
  title:
    label: Title
    datasource_id: 'entity:node'
    property_path: title
    type: text
    boost: 3.0
  body:
    label: Body
    datasource_id: 'entity:node'
    property_path: 'body:value'
    type: text
    boost: 2.0
  field_ingredients:
    label: Ingredients
    datasource_id: 'entity:node'
    property_path: 'field_ingredients:value'
    type: text
    boost: 1.0
  field_cuisine:
    label: Cuisine
    datasource_id: 'entity:node'
    property_path: 'field_cuisine:entity:name'
    type: string
  field_meal_type:
    label: 'Meal type'
    datasource_id: 'entity:node'
    property_path: 'field_meal_type:entity:name'
    type: string
  field_total_time:
    label: 'Total time'
    datasource_id: 'entity:node'
    property_path: field_total_time
    type: integer
  field_rating:
    label: Rating
    datasource_id: 'entity:node'
    property_path: field_rating
    type: decimal
datasource_settings:
  'entity:node':
    bundles:
      default: false
      selected:
        - recipe
    languages:
      default: true
      selected: []
processor_settings:
  add_url: { }
  aggregated_field: { }
  rendered_item: { }
  meilisearch_highlighting:
    weights:
      preprocess_query: 0
    highlight_fields:
      - title
      - body
    pre_tag: '<mark>'
    post_tag: '</mark>'
    crop_length: 15
    crop_marker: '…'
tracker_settings:
  default:
    indexing_order: fifo
options:
  index_directly: true
  cron_limit: 50
server: meilisearch_demo
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/search_api.index.recipes.yml
git commit -m "feat: add recipes search API index config with highlighting processor"
```

---

### Task 19: Phase 4 smoke test

- [ ] **Step 1: Clean install**

```bash
docker compose down -v
docker compose up --build -d
docker compose logs -f drush
```

- [ ] **Step 2: Inspect Search API admin**

Visit `/admin/config/search/search-api`. Expected:
- Server "Meilisearch (demo)" listed, status "Available", shows Meilisearch version
- Index "Recipes" listed, "0 of 0 indexed"

- [ ] **Step 3: Check the Fields tab**

`/admin/config/search/search-api/index/recipes/fields` shows title, body, field_ingredients as fulltext; field_cuisine, field_meal_type as string; field_total_time as integer; field_rating as decimal.

- [ ] **Step 4: Check the Processors tab**

`/admin/config/search/search-api/index/recipes/processors` shows "Meilisearch highlighting" enabled with `<mark>` pre-tag.

- [ ] **Step 5: Tear down**

```bash
docker compose down -v
```

---

## Phase 5 — Views + facets

Goal of phase: `/recipes` page loads (empty grid, no content yet), left sidebar shows facet blocks for Cuisine and Meal type (empty).

### Task 20: Create `views.view.recipes_search.yml`

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/views.view.recipes_search.yml`

- [ ] **Step 1: Write the view config**

```yaml
langcode: en
status: true
dependencies:
  config:
    - search_api.index.recipes
  module:
    - search_api
    - views
id: recipes_search
label: 'Recipes search'
module: views
description: 'Public search page for recipes, powered by Meilisearch.'
tag: ''
base_table: search_api_index_recipes
base_field: search_api_id
display:
  default:
    id: default
    display_title: Default
    display_plugin: default
    position: 0
    display_options:
      access:
        type: none
      cache:
        type: none
      query:
        type: views_query
        options:
          bypass_access: false
          skip_access: false
      exposed_form:
        type: basic
        options:
          reset_button: true
          reset_button_label: Reset
      pager:
        type: full
        options:
          items_per_page: 25
          offset: 0
          id: 0
          total_pages: null
          tags:
            previous: '‹ Previous'
            next: 'Next ›'
      style:
        type: default
      row:
        type: 'entity:node'
        options:
          view_mode: teaser
      fields:
        title:
          id: title
          table: search_api_index_recipes
          field: title
          relationship: none
          plugin_id: search_api
          label: ''
          type: string
      filters:
        search_api_fulltext:
          id: search_api_fulltext
          table: search_api_index_recipes
          field: search_api_fulltext
          plugin_id: search_api_fulltext
          operator: and
          exposed: true
          expose:
            operator_id: search_api_fulltext_op
            label: Search
            placeholder: 'Search recipes…'
            identifier: q
            remember: false
          is_grouped: false
        field_total_time:
          id: field_total_time
          table: search_api_index_recipes
          field: field_total_time
          plugin_id: search_api_range_filter
          operator: between
          value:
            min: ''
            max: ''
          exposed: true
          expose:
            operator_id: field_total_time_op
            label: 'Total time (minutes)'
            identifier: total_time
            remember: false
          is_grouped: false
      sorts:
        search_api_relevance:
          id: search_api_relevance
          table: search_api_index_recipes
          field: search_api_relevance
          plugin_id: search_api
          order: DESC
          exposed: true
          expose:
            label: Sort
          is_grouped: false
      title: Recipes
      empty:
        area:
          id: area
          table: views
          field: area
          plugin_id: text_custom
          content: 'No recipes match your search.'
      header: {  }
      footer: {  }
  page_1:
    id: page_1
    display_title: Page
    display_plugin: page
    position: 1
    display_options:
      display_extenders: {  }
      path: recipes
      menu:
        type: normal
        title: Recipes
        description: ''
        weight: 0
```

Key points:
- Path is `/recipes`, also added to the main navigation (menu type `normal`).
- Teaser view mode renders each result (theme overrides the template in Phase 8).
- Exposed `search_api_fulltext` filter is the search box.
- Exposed range filter on `field_total_time`.
- `items_per_page: 25`.

> **Implementer note on range filter plugin:** the `search_api_range_filter` plugin_id above assumes the contrib `search_api_range_filter` module. If you prefer to stay core-only, swap for the plain `numeric` filter (`plugin_id: numeric`, operator `between`, expose `min` and `max` inputs). Either works; pick one and adjust the view YAML accordingly. If you add the contrib module, update `composer.json` (`drupal/search_api_range_filter`) and the profile's `.info.yml` dependencies.

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/views.view.recipes_search.yml
git commit -m "feat: add Recipes search view with fulltext and total-time filters"
```

---

### Task 21: Create facet configs

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/facets.facet.recipe_cuisine.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/facets.facet.recipe_meal_type.yml`

- [ ] **Step 1: Write `facets.facet.recipe_cuisine.yml`**

```yaml
langcode: en
status: true
dependencies:
  config:
    - search_api.index.recipes
    - views.view.recipes_search
  module:
    - facets
    - search_api
id: recipe_cuisine
name: Cuisine
url_alias: cuisine
weight: 0
min_count: 1
show_only_one_result: false
field_identifier: field_cuisine
facet_source_id: 'search_api:views_page__recipes_search__page_1'
widget:
  type: links
  config:
    show_numbers: true
    soft_limit: 0
    soft_limit_settings:
      show_less_label: 'Show less'
      show_more_label: 'Show more'
query_operator: or
use_hierarchy: false
expand_hierarchy: false
enable_parent_when_child_gets_disabled: true
hard_limit: 0
exclude: false
only_visible_when_facet_source_is_visible: true
processor_configs:
  url_processor_handler:
    processor_id: url_processor_handler
    weights:
      pre_query: -10
      build: -10
    settings: {  }
```

- [ ] **Step 2: Write `facets.facet.recipe_meal_type.yml`**

Same shape as above, but for meal_type:

```yaml
langcode: en
status: true
dependencies:
  config:
    - search_api.index.recipes
    - views.view.recipes_search
  module:
    - facets
    - search_api
id: recipe_meal_type
name: 'Meal type'
url_alias: meal_type
weight: 1
min_count: 1
show_only_one_result: false
field_identifier: field_meal_type
facet_source_id: 'search_api:views_page__recipes_search__page_1'
widget:
  type: links
  config:
    show_numbers: true
    soft_limit: 0
    soft_limit_settings:
      show_less_label: 'Show less'
      show_more_label: 'Show more'
query_operator: or
use_hierarchy: false
expand_hierarchy: false
enable_parent_when_child_gets_disabled: true
hard_limit: 0
exclude: false
only_visible_when_facet_source_is_visible: true
processor_configs:
  url_processor_handler:
    processor_id: url_processor_handler
    weights:
      pre_query: -10
      build: -10
    settings: {  }
```

- [ ] **Step 3: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/facets.facet.recipe_*.yml
git commit -m "feat: add cuisine and meal_type facet configs"
```

---

### Task 22: Create facet block placements

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/block.block.olivero_recipe_cuisine.yml`
- Create: `web/profiles/meilisearch_demo_profile/config/install/block.block.olivero_recipe_meal_type.yml`

- [ ] **Step 1: Write `block.block.olivero_recipe_cuisine.yml`**

```yaml
langcode: en
status: true
dependencies:
  config:
    - facets.facet.recipe_cuisine
  module:
    - facets
  theme:
    - olivero
id: olivero_recipe_cuisine
theme: olivero
region: sidebar
weight: 0
provider: null
plugin: 'facet_block:recipe_cuisine'
settings:
  id: 'facet_block:recipe_cuisine'
  label: Cuisine
  label_display: visible
  provider: facets
visibility:
  request_path:
    id: request_path
    pages: /recipes
    negate: false
    context_mapping: {  }
```

- [ ] **Step 2: Write `block.block.olivero_recipe_meal_type.yml`**

```yaml
langcode: en
status: true
dependencies:
  config:
    - facets.facet.recipe_meal_type
  module:
    - facets
  theme:
    - olivero
id: olivero_recipe_meal_type
theme: olivero
region: sidebar
weight: 1
provider: null
plugin: 'facet_block:recipe_meal_type'
settings:
  id: 'facet_block:recipe_meal_type'
  label: 'Meal type'
  label_display: visible
  provider: facets
visibility:
  request_path:
    id: request_path
    pages: /recipes
    negate: false
    context_mapping: {  }
```

- [ ] **Step 3: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/block.block.olivero_recipe_*.yml
git commit -m "feat: place facet blocks in Olivero sidebar on /recipes"
```

---

### Task 23: Phase 5 smoke test

- [ ] **Step 1: Clean install**

```bash
docker compose down -v
docker compose up --build -d
docker compose logs -f drush
```

- [ ] **Step 2: Visit `/recipes`**

Expected:
- Page loads without errors
- Empty result state: "No recipes match your search."
- Left sidebar shows "Cuisine" and "Meal type" block headers (empty because no content yet)
- Top exposed filter shows the search box and the total-time range inputs

- [ ] **Step 3: Visit `/admin/structure/views/view/recipes_search`**

Expected: view exists, page display at `/recipes`.

- [ ] **Step 4: Tear down**

```bash
docker compose down -v
```

---

## Phase 6 — Seed task (stub fixture)

Goal of phase: 5 recipes appear on `/recipes` after install. Indexing runs synchronously during install. This proves the seed mechanism works end-to-end before we build the real fixture.

### Task 24: Create stub fixture

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/fixtures/recipes.json`

- [ ] **Step 1: Write 5 hand-written recipes**

```json
[
  {
    "title": "Classic Margherita Pizza",
    "body": "A simple, traditional Neapolitan pizza with tomato, mozzarella, and fresh basil.",
    "ingredients": "- 500g 00 flour\n- 325ml water\n- 10g salt\n- 3g dry yeast\n- 200g San Marzano tomatoes\n- 250g fresh mozzarella\n- Fresh basil leaves\n- Extra-virgin olive oil",
    "directions": "1. Mix flour, water, salt, yeast; knead 10 min.\n2. Rise 8 hours at room temperature.\n3. Shape into balls, rest 2 hours.\n4. Stretch into 12in rounds, top with crushed tomatoes, torn mozzarella.\n5. Bake at 500°F for 7 minutes.\n6. Finish with basil and olive oil.",
    "cuisine": "Italian",
    "meal_types": ["Dinner"],
    "total_time": 60,
    "rating": 4.6,
    "image_url": "https://images.unsplash.com/photo-1604068549290-dea0e4a305ca?w=800"
  },
  {
    "title": "Chicken Tikka Masala",
    "body": "Creamy, tomato-based curry with marinated grilled chicken and aromatic spices.",
    "ingredients": "- 1kg chicken thighs\n- 250g yogurt\n- 4 tbsp tikka masala paste\n- 400g canned tomatoes\n- 200ml heavy cream\n- 2 onions\n- 4 garlic cloves\n- Fresh ginger\n- Garam masala",
    "directions": "1. Marinate chicken in yogurt + paste for 4 hours.\n2. Grill chicken until charred.\n3. Sauté onions, garlic, ginger.\n4. Add tomatoes, simmer 20 min.\n5. Stir in cream and chicken.\n6. Simmer 10 more minutes, finish with garam masala.",
    "cuisine": "Indian",
    "meal_types": ["Dinner"],
    "total_time": 90,
    "rating": 4.8,
    "image_url": "https://images.unsplash.com/photo-1565557623262-b51c2513a641?w=800"
  },
  {
    "title": "Tacos al Pastor",
    "body": "Mexico City street tacos with marinated pork, pineapple, onion, and cilantro on corn tortillas.",
    "ingredients": "- 1kg pork shoulder\n- 3 dried guajillo chiles\n- 2 dried ancho chiles\n- 1 small pineapple\n- 4 garlic cloves\n- 1 tbsp achiote paste\n- White onion\n- Fresh cilantro\n- Lime\n- Corn tortillas",
    "directions": "1. Rehydrate chiles, blend with pineapple juice, garlic, achiote.\n2. Marinate sliced pork 8 hours.\n3. Stack on skewer, roast or grill.\n4. Slice thin, serve on warm tortillas with pineapple chunks, onion, cilantro, lime.",
    "cuisine": "Mexican",
    "meal_types": ["Dinner", "Lunch"],
    "total_time": 75,
    "rating": 4.7,
    "image_url": "https://images.unsplash.com/photo-1565299585323-38d6b0865b47?w=800"
  },
  {
    "title": "Overnight Oats with Berries",
    "body": "No-cook breakfast with rolled oats, yogurt, and mixed berries, prepared the night before.",
    "ingredients": "- 50g rolled oats\n- 120ml milk\n- 80g Greek yogurt\n- 1 tbsp maple syrup\n- 1 tsp chia seeds\n- 100g mixed berries\n- 1 tbsp almond butter",
    "directions": "1. Combine oats, milk, yogurt, syrup, chia in a jar.\n2. Stir, cover, refrigerate overnight.\n3. Top with berries and almond butter in the morning.",
    "cuisine": "American",
    "meal_types": ["Breakfast"],
    "total_time": 5,
    "rating": 4.3,
    "image_url": "https://images.unsplash.com/photo-1517674089241-f3b22ec9d28f?w=800"
  },
  {
    "title": "Chocolate Lava Cake",
    "body": "Individual molten chocolate cakes with a flowing center, ready in 20 minutes.",
    "ingredients": "- 100g dark chocolate (70%)\n- 100g butter\n- 2 eggs\n- 2 egg yolks\n- 60g sugar\n- 40g flour\n- Pinch of salt\n- Butter and cocoa for ramekins",
    "directions": "1. Preheat oven to 425°F.\n2. Butter and cocoa-dust 4 ramekins.\n3. Melt chocolate and butter together.\n4. Whisk eggs, yolks, sugar until pale.\n5. Fold in chocolate, then flour.\n6. Divide into ramekins; bake 10 min.\n7. Invert onto plates immediately.",
    "cuisine": "French",
    "meal_types": ["Dessert"],
    "total_time": 20,
    "rating": 4.9,
    "image_url": "https://images.unsplash.com/photo-1624353365286-3f8d62daad51?w=800"
  }
]
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/fixtures/recipes.json
git commit -m "feat: add 5-recipe stub fixture for seed-task development"
```

---

### Task 25: Implement seed helper class

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/src/RecipeSeeder.php`

- [ ] **Step 1: Write the seeder class**

```php
<?php

declare(strict_types=1);

namespace Drupal\meilisearch_demo_profile;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\search_api\Entity\Index;
use Psr\Log\LoggerInterface;

/**
 * Creates recipe nodes and taxonomy terms from the bundled JSON fixture.
 */
final class RecipeSeeder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ExtensionPathResolver $pathResolver,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Seeds recipes from the fixture file and triggers indexing.
   */
  public function seed(): void {
    $path = $this->pathResolver->getPath('profile', 'meilisearch_demo_profile')
      . '/fixtures/recipes.json';

    if (!is_readable($path)) {
      $this->logger->warning('Fixture file missing at @path — skipping seed.', ['@path' => $path]);
      return;
    }

    $raw = file_get_contents($path);
    $records = json_decode($raw, TRUE, flags: JSON_THROW_ON_ERROR);

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $cuisineCache = [];
    $mealTypeCache = [];
    $created = 0;

    foreach ($records as $record) {
      try {
        $cuisineTid = $this->termId($termStorage, $cuisineCache, 'cuisine', $record['cuisine']);
        $mealTypeTids = array_map(
          fn(string $name) => $this->termId($termStorage, $mealTypeCache, 'meal_type', $name),
          $record['meal_types'] ?? []
        );

        $node = $nodeStorage->create([
          'type' => 'recipe',
          'title' => $record['title'],
          'body' => [
            'value' => $record['body'],
            'format' => 'basic_html',
          ],
          'field_ingredients' => [
            'value' => $record['ingredients'],
            'format' => 'basic_html',
          ],
          'field_directions' => [
            'value' => $record['directions'],
            'format' => 'basic_html',
          ],
          'field_cuisine' => $cuisineTid,
          'field_meal_type' => $mealTypeTids,
          'field_total_time' => (int) $record['total_time'],
          'field_rating' => (float) $record['rating'],
          'field_image_url' => $record['image_url'],
          'status' => 1,
        ]);
        $node->save();
        $created++;

        if ($created % 50 === 0) {
          $this->logger->info('Seeded @count recipes so far...', ['@count' => $created]);
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Failed to seed recipe "@title": @msg', [
          '@title' => $record['title'] ?? '(unknown)',
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    $this->logger->info('Seeded @count recipes total.', ['@count' => $created]);
    $this->indexAll();
  }

  /**
   * Indexes all remaining items on the recipes index synchronously.
   */
  private function indexAll(): void {
    $index = Index::load('recipes');
    if (!$index) {
      $this->logger->error('Recipes index not found — cannot trigger indexing.');
      return;
    }

    $tracker = $index->getTrackerInstance();
    $remaining = $tracker->getRemainingItems();
    $this->logger->info('Indexing @count items into Meilisearch...', ['@count' => $remaining]);

    // Index in batches of 50 to avoid memory pressure.
    while ($tracker->getRemainingItems() > 0) {
      $indexed = $index->indexItems(50);
      if ($indexed === 0) {
        break;
      }
    }

    $this->logger->info('Indexing complete.');
  }

  /**
   * Loads a term by name from a vocabulary, creating it if missing.
   */
  private function termId(
    object $termStorage,
    array &$cache,
    string $vid,
    string $name,
  ): int {
    if (isset($cache[$name])) {
      return $cache[$name];
    }

    $existing = $termStorage->loadByProperties(['vid' => $vid, 'name' => $name]);
    if ($existing) {
      $term = reset($existing);
      $cache[$name] = (int) $term->id();
      return $cache[$name];
    }

    $term = $termStorage->create(['vid' => $vid, 'name' => $name]);
    $term->save();
    $cache[$name] = (int) $term->id();
    return $cache[$name];
  }

}
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/src/RecipeSeeder.php
git commit -m "feat: add RecipeSeeder service to create nodes from fixture JSON"
```

---

### Task 26: Wire seeder into install task

**Files:**
- Modify: `web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.profile`

- [ ] **Step 1: Replace the stub seed function body**

Open `web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.profile` and replace the `meilisearch_demo_profile_seed_recipes` function with:

```php
/**
 * Install task: seed recipes from the bundled fixture.
 */
function meilisearch_demo_profile_seed_recipes(array &$install_state): void {
  $seeder = new \Drupal\meilisearch_demo_profile\RecipeSeeder(
    \Drupal::entityTypeManager(),
    \Drupal::service('extension.path.resolver'),
    \Drupal::logger('meilisearch_demo_profile'),
  );
  $seeder->seed();
}
```

- [ ] **Step 2: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.profile
git commit -m "feat: wire RecipeSeeder into install task"
```

---

### Task 27: Phase 6 smoke test

- [ ] **Step 1: Clean install**

```bash
docker compose down -v
docker compose up --build -d
docker compose logs -f drush
```

Expected in drush logs: `Seeded 5 recipes total.` and `Indexing 5 items into Meilisearch...` `Indexing complete.`

- [ ] **Step 2: Visit `/recipes`**

Expected: 5 recipe teasers visible. Facets "Cuisine" and "Meal type" now show terms with counts (Italian 1, Indian 1, Mexican 1, American 1, French 1 for cuisines).

- [ ] **Step 3: Try a full-text search**

Type "chicken" in the search box and submit. Expected: only the Tikka Masala result. The matched term is rendered inside `<mark>` tags in the title or body snippet.

- [ ] **Step 4: Try a facet**

Click "Italian" in the cuisine facet. Expected: only the Margherita Pizza. URL updates to `/recipes?f[0]=cuisine:Italian` (or similar).

- [ ] **Step 5: Tear down**

```bash
docker compose down -v
```

If any of the above fails, inspect logs and fix before moving on.

---

## Phase 7 — Real dataset

Goal of phase: ~300 curated recipes from the Kaggle "Better Recipes for a Better Life" dataset replace the 5-recipe stub. Full-text search, facets, and filters all have meaningful data to work with.

### Task 28: Create `scripts/build-fixture.py`

**Files:**
- Create: `scripts/build-fixture.py`

- [ ] **Step 1: Write the script**

```python
#!/usr/bin/env python3
"""
Transform the Kaggle "Better Recipes for a Better Life" CSV into the
demo fixture JSON.

Usage:
    python3 scripts/build-fixture.py path/to/kaggle/recipes.csv

Writes to:
    web/profiles/meilisearch_demo_profile/fixtures/recipes.json
"""

from __future__ import annotations

import csv
import json
import random
import re
import sys
from pathlib import Path

# Hardcoded allowlist for cuisine (Kaggle dataset is messy; we normalize).
CUISINE_KEYWORDS: dict[str, list[str]] = {
    "Italian": ["italian", "pizza", "pasta", "lasagna", "risotto"],
    "Mexican": ["mexican", "taco", "burrito", "enchilada", "quesadilla"],
    "American": ["american", "bbq", "burger", "meatloaf"],
    "French": ["french", "ratatouille", "coq au vin", "crème", "croissant"],
    "Indian": ["indian", "curry", "tikka", "masala", "biryani", "naan"],
    "Chinese": ["chinese", "stir fry", "stir-fry", "dumpling", "fried rice", "lo mein"],
    "Japanese": ["japanese", "sushi", "ramen", "tempura", "teriyaki", "miso"],
    "Thai": ["thai", "pad thai", "green curry", "tom yum"],
    "Mediterranean": ["mediterranean", "hummus", "tabbouleh", "falafel", "greek"],
    "Southern": ["southern", "grits", "fried chicken", "biscuits"],
}

MEAL_TYPE_KEYWORDS: dict[str, list[str]] = {
    "Breakfast": ["breakfast", "pancake", "waffle", "omelette", "muffin"],
    "Lunch": ["lunch", "sandwich", "wrap"],
    "Dinner": ["dinner", "roast", "steak", "casserole"],
    "Dessert": ["dessert", "cake", "cookie", "pie", "pudding", "ice cream", "brownie"],
    "Appetizer": ["appetizer", "starter", "dip", "bruschetta"],
    "Snack": ["snack", "chips", "popcorn"],
}


def classify(text: str, keywords: dict[str, list[str]]) -> list[str]:
    """Return label list that matches any keyword in text."""
    text = text.lower()
    return [label for label, words in keywords.items() if any(w in text for w in words)]


def parse_time(value: str) -> int | None:
    """Parse strings like '1 hr 30 min', '45 min', '2 hours' to minutes."""
    if not value:
        return None
    hours = re.search(r"(\d+)\s*(?:h|hr|hour)", value, re.I)
    mins = re.search(r"(\d+)\s*(?:m|min|minute)", value, re.I)
    total = 0
    if hours:
        total += int(hours.group(1)) * 60
    if mins:
        total += int(mins.group(1))
    if total == 0:
        # Maybe a bare integer.
        match = re.search(r"\d+", value)
        if match:
            total = int(match.group(0))
    return total or None


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        print("usage: build-fixture.py <kaggle-csv>", file=sys.stderr)
        return 2

    csv_path = Path(argv[1])
    if not csv_path.is_file():
        print(f"not found: {csv_path}", file=sys.stderr)
        return 1

    recipes: list[dict] = []
    with csv_path.open(newline="", encoding="utf-8") as fh:
        reader = csv.DictReader(fh)
        for row in reader:
            title = (row.get("recipe_name") or row.get("title") or "").strip()
            description = (row.get("description") or row.get("summary") or "").strip()
            ingredients = (row.get("ingredients") or "").strip()
            directions = (row.get("directions") or row.get("instructions") or "").strip()
            image = (row.get("image_url") or row.get("image") or "").strip()
            rating_raw = (row.get("rating") or row.get("stars") or "").strip()
            time_raw = (row.get("total_time") or row.get("time") or "").strip()

            # Skip unusable rows.
            if not title or not image or not ingredients:
                continue

            # Rating: accept floats 0–5.
            try:
                rating = float(rating_raw) if rating_raw else 4.0
            except ValueError:
                rating = 4.0
            rating = max(0.0, min(5.0, rating))

            total_time = parse_time(time_raw) or 30
            if total_time > 360:
                continue

            haystack = f"{title} {description} {ingredients}"
            cuisines = classify(haystack, CUISINE_KEYWORDS)
            meal_types = classify(haystack, MEAL_TYPE_KEYWORDS)
            if not cuisines or not meal_types:
                # Recipes without a detectable cuisine/meal type don't help
                # showcase the facets; drop them.
                continue

            recipes.append({
                "title": title,
                "body": description or title,
                "ingredients": ingredients,
                "directions": directions or "Directions unavailable.",
                "cuisine": cuisines[0],
                "meal_types": meal_types[:2],  # Cap at 2 labels.
                "total_time": total_time,
                "rating": round(rating, 1),
                "image_url": image,
            })

    # Stratified sample: ~30 per cuisine to guarantee facet diversity.
    random.seed(42)
    by_cuisine: dict[str, list[dict]] = {}
    for recipe in recipes:
        by_cuisine.setdefault(recipe["cuisine"], []).append(recipe)

    sampled: list[dict] = []
    for cuisine, items in by_cuisine.items():
        random.shuffle(items)
        sampled.extend(items[:30])

    # Cap overall at 300.
    random.shuffle(sampled)
    sampled = sampled[:300]

    out_path = Path(__file__).resolve().parent.parent / "web/profiles/meilisearch_demo_profile/fixtures/recipes.json"
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(sampled, indent=2), encoding="utf-8")
    print(f"wrote {len(sampled)} recipes to {out_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
```

- [ ] **Step 2: Make executable and commit**

```bash
chmod +x scripts/build-fixture.py
git add scripts/build-fixture.py
git commit -m "feat: add offline fixture builder for Kaggle recipes dataset"
```

---

### Task 29: Run the fixture builder and commit real data

**Manual step with clear instructions:**

- [ ] **Step 1: Download the Kaggle CSV**

Go to https://www.kaggle.com/datasets/thedevastator/better-recipes-for-a-better-life and download the CSV. You need a Kaggle account. Save the file as `/tmp/kaggle-recipes.csv`.

- [ ] **Step 2: Inspect columns to confirm the script's assumptions**

```bash
head -1 /tmp/kaggle-recipes.csv
```

If the column names differ from the script's assumptions (`recipe_name`, `description`, `ingredients`, `directions`, `image_url`, `rating`, `total_time`), update the lookups in `main()` accordingly.

- [ ] **Step 3: Run the builder**

```bash
python3 scripts/build-fixture.py /tmp/kaggle-recipes.csv
```

Expected: `wrote 300 recipes to .../fixtures/recipes.json` (or fewer if the dataset is smaller after filtering).

- [ ] **Step 4: Verify the output**

```bash
jq 'length' web/profiles/meilisearch_demo_profile/fixtures/recipes.json
jq '.[0]' web/profiles/meilisearch_demo_profile/fixtures/recipes.json
jq 'group_by(.cuisine) | map({cuisine: .[0].cuisine, count: length})' web/profiles/meilisearch_demo_profile/fixtures/recipes.json
```

Expected: ~300 total, well-formed first record, ~10 cuisines with ~30 each.

- [ ] **Step 5: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/fixtures/recipes.json
git commit -m "feat: replace stub fixture with 300 curated recipes from Kaggle"
```

---

### Task 30: Phase 7 smoke test

- [ ] **Step 1: Clean install with real data**

```bash
docker compose down -v
docker compose up --build -d
docker compose logs -f drush
```

Expected: `Seeded 300 recipes total.` and `Indexing 300 items into Meilisearch...` `Indexing complete.` Install takes ~1–2 minutes total.

- [ ] **Step 2: Visit `/recipes`**

Expected:
- 25 results (first page), pagination at bottom
- Cuisine facet shows ~10 terms with counts summing to 300
- Meal type facet shows ~6 terms with overlapping counts

- [ ] **Step 3: Searches**

- Search "pasta" → Italian recipes dominate, with `<mark>` highlights
- Search "chicken" → mixed cuisines, all mentioning chicken
- Facet filter on "Mexican" + search "taco" → narrow result set

- [ ] **Step 4: Sort**

Change sort to "Rating (desc)" → top results have highest ratings.

- [ ] **Step 5: Tear down**

```bash
docker compose down -v
```

---

## Phase 8 — Theme

Goal of phase: `/recipes` looks like a proper recipe grid (cards with image, title, cuisine badge, meal-type chips, rating). `<mark>` tags are visually distinct.

### Task 31: Create theme `.info.yml` and libraries

**Files:**
- Create: `web/themes/custom/meilisearch_demo_theme/meilisearch_demo_theme.info.yml`
- Create: `web/themes/custom/meilisearch_demo_theme/meilisearch_demo_theme.libraries.yml`

- [ ] **Step 1: Write `.info.yml`**

```yaml
name: 'Meilisearch Demo'
type: theme
description: 'Thin subtheme of Olivero for the Meilisearch Drupal demo.'
core_version_requirement: ^11
base theme: olivero
libraries:
  - meilisearch_demo_theme/global
regions:
  header: Header
  primary_menu: 'Primary menu'
  secondary_menu: 'Secondary menu'
  highlighted: Highlighted
  breadcrumb: Breadcrumb
  hero: Hero
  content_above: 'Content above'
  sidebar: 'Sidebar'
  content: Content
  content_below: 'Content below'
  footer_top: 'Footer top'
  footer_bottom: 'Footer bottom'
```

- [ ] **Step 2: Write `.libraries.yml`**

```yaml
global:
  css:
    theme:
      css/recipes.css: {}
```

- [ ] **Step 3: Commit**

```bash
git add web/themes/custom/meilisearch_demo_theme/meilisearch_demo_theme.info.yml \
        web/themes/custom/meilisearch_demo_theme/meilisearch_demo_theme.libraries.yml
git commit -m "feat: add meilisearch_demo_theme subtheme of Olivero"
```

---

### Task 32: Add CSS

**Files:**
- Create: `web/themes/custom/meilisearch_demo_theme/css/recipes.css`

- [ ] **Step 1: Write `recipes.css`**

```css
/* Search results grid. */
.view-recipes-search .views-view-unformatted {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1.5rem;
}

@media (max-width: 768px) {
  .view-recipes-search .views-view-unformatted {
    grid-template-columns: 1fr;
  }
}

/* Recipe teaser card. */
.node--type-recipe.node--view-mode-teaser {
  background: var(--color--white, #fff);
  border: 1px solid var(--color--gray-95, #e5e7eb);
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.node--type-recipe.node--view-mode-teaser .recipe-image {
  width: 100%;
  height: 180px;
  object-fit: cover;
  background: #f3f4f6;
}

.node--type-recipe.node--view-mode-teaser .recipe-body {
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  flex: 1;
}

.node--type-recipe.node--view-mode-teaser h2 {
  font-size: 1.1rem;
  line-height: 1.3;
  margin: 0;
}

.node--type-recipe.node--view-mode-teaser h2 a {
  text-decoration: none;
  color: inherit;
}

.node--type-recipe.node--view-mode-teaser .recipe-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  font-size: 0.85rem;
}

.recipe-badge {
  display: inline-block;
  padding: 0.2rem 0.6rem;
  border-radius: 999px;
  font-size: 0.75rem;
  background: var(--color--blue-95, #eff6ff);
  color: var(--color--blue-40, #1e40af);
}

.recipe-rating {
  color: #d97706;
  font-weight: 600;
}

/* Highlighting */
.view-recipes-search mark {
  background: #fef08a;
  color: inherit;
  padding: 0 2px;
  border-radius: 2px;
}
```

- [ ] **Step 2: Commit**

```bash
git add web/themes/custom/meilisearch_demo_theme/css/recipes.css
git commit -m "feat: add recipe card grid and highlight styles"
```

---

### Task 33: Override recipe teaser template

**Files:**
- Create: `web/themes/custom/meilisearch_demo_theme/templates/node--recipe--teaser.html.twig`

- [ ] **Step 1: Write the template**

```twig
{#
/**
 * @file
 * Theme override for recipe teaser in the demo.
 */
#}
<article{{ attributes.addClass('node--type-recipe node--view-mode-teaser').removeClass('node--view-mode-teaser--default') }}>
  {% if content.field_image_url %}
    <img class="recipe-image"
         src="{{ node.field_image_url.value }}"
         alt="{{ label|render|striptags }}"
         loading="lazy"
         onerror="this.style.display='none'">
  {% endif %}

  <div class="recipe-body">
    <h2{{ title_attributes }}>
      <a href="{{ url }}">{{ label }}</a>
    </h2>

    {% if content.body %}
      <div class="recipe-summary">
        {{ content.body }}
      </div>
    {% endif %}

    <div class="recipe-meta">
      {% if content.field_cuisine %}
        <span class="recipe-badge recipe-badge--cuisine">
          {{ content.field_cuisine|render|striptags|trim }}
        </span>
      {% endif %}
      {% if content.field_meal_type %}
        <span class="recipe-badge recipe-badge--meal-type">
          {{ content.field_meal_type|render|striptags|trim }}
        </span>
      {% endif %}
      {% if content.field_total_time %}
        <span class="recipe-time">
          {{ content.field_total_time|render|striptags|trim }}
        </span>
      {% endif %}
      {% if content.field_rating %}
        <span class="recipe-rating">
          ★ {{ content.field_rating|render|striptags|trim }}
        </span>
      {% endif %}
    </div>
  </div>
</article>
```

- [ ] **Step 2: Commit**

```bash
git add web/themes/custom/meilisearch_demo_theme/templates/node--recipe--teaser.html.twig
git commit -m "feat: override recipe teaser template for card layout"
```

---

### Task 34: Set theme as default in install profile

**Files:**
- Create: `web/profiles/meilisearch_demo_profile/config/install/system.theme.yml`
- Modify: `web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.info.yml`
- Rename block placements from `olivero_` to `meilisearch_demo_theme_` (or leave — Olivero is the base, block placements work).

- [ ] **Step 1: Write `system.theme.yml`**

```yaml
langcode: en
_core:
  default_config_hash: ''
admin: claro
default: meilisearch_demo_theme
```

- [ ] **Step 2: Add theme to the profile's themes list**

Modify `web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.info.yml` to add `meilisearch_demo_theme` to the `themes` list:

```yaml
themes:
  - olivero
  - claro
  - meilisearch_demo_theme
```

- [ ] **Step 3: Create theme block placements for facets**

Because blocks are per-theme, we need copies of the block.block configs for the new default theme. Create:

`web/profiles/meilisearch_demo_profile/config/install/block.block.meilisearch_demo_theme_recipe_cuisine.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - facets.facet.recipe_cuisine
  module:
    - facets
  theme:
    - meilisearch_demo_theme
id: meilisearch_demo_theme_recipe_cuisine
theme: meilisearch_demo_theme
region: sidebar
weight: 0
provider: null
plugin: 'facet_block:recipe_cuisine'
settings:
  id: 'facet_block:recipe_cuisine'
  label: Cuisine
  label_display: visible
  provider: facets
visibility:
  request_path:
    id: request_path
    pages: /recipes
    negate: false
    context_mapping: {  }
```

`web/profiles/meilisearch_demo_profile/config/install/block.block.meilisearch_demo_theme_recipe_meal_type.yml`:
```yaml
langcode: en
status: true
dependencies:
  config:
    - facets.facet.recipe_meal_type
  module:
    - facets
  theme:
    - meilisearch_demo_theme
id: meilisearch_demo_theme_recipe_meal_type
theme: meilisearch_demo_theme
region: sidebar
weight: 1
provider: null
plugin: 'facet_block:recipe_meal_type'
settings:
  id: 'facet_block:recipe_meal_type'
  label: 'Meal type'
  label_display: visible
  provider: facets
visibility:
  request_path:
    id: request_path
    pages: /recipes
    negate: false
    context_mapping: {  }
```

- [ ] **Step 4: Commit**

```bash
git add web/profiles/meilisearch_demo_profile/config/install/system.theme.yml \
        web/profiles/meilisearch_demo_profile/meilisearch_demo_profile.info.yml \
        web/profiles/meilisearch_demo_profile/config/install/block.block.meilisearch_demo_theme_*.yml
git commit -m "feat: set meilisearch_demo_theme as default and replicate facet blocks"
```

---

### Task 35: Phase 8 smoke test

- [ ] **Step 1: Clean install**

```bash
docker compose down -v
docker compose up --build -d
```

- [ ] **Step 2: Visit `/recipes`**

Expected:
- Results are in a 3-column card grid
- Each card shows image at top, title, description, cuisine badge (blue pill), meal type badge, total time, rating stars
- `<mark>` highlights on search terms have a yellow background
- On mobile width (< 768px), grid collapses to 1 column

- [ ] **Step 3: Admin still uses Claro**

Visit `/admin` — should still be styled with Claro (admin theme), not the custom theme.

- [ ] **Step 4: Tear down**

```bash
docker compose down -v
```

---

## Phase 9 — Docs + final verification

### Task 36: Write final `README.md`

**Files:**
- Replace: `README.md`

- [ ] **Step 1: Replace the placeholder README**

```markdown
# Drupal Meilisearch Demo

One-command showcase of [drupal/meilisearch](../../_sdk/meilisearch-drupal) — the official Meilisearch backend for Drupal Search API.

## Quickstart

```bash
git clone <this-repo>
cd drupal-meilisearch-demo
cp .env.example .env
docker compose up
```

Wait ~2 minutes for images to build and the install profile to seed 300 recipes.

Open **http://localhost:8080/recipes** in your browser.

Log in at **http://localhost:8080/user/login** with `admin` / `admin` to see the admin side.

## What you'll see

- Full-text search with typo tolerance over 300 recipes (try "chicken" or "pasta").
- `<mark>` highlighting on matched terms in title and body snippet.
- Facets for Cuisine and Meal type in the left sidebar — click to filter, counts update live.
- Range filter for total cook time (0–30, 30–60, 60+ min) at the top of the page.
- Sort by relevance (default), rating desc, or total time asc.
- Admin screens at `/admin/config/search/search-api` show the Meilisearch server is "Available" with the live version string, and the Recipes index reporting "300 of 300 indexed".

## Guided tour

1. **Search like a user** — at `http://localhost:8080/recipes`, try searches, facets, and the total-time filter.
2. **See the admin UX** — log in, visit `/admin/config/search/search-api/server/meilisearch_demo`. This is the backend configuration form shipped by the module.
3. **Inspect the index** — `/admin/config/search/search-api/index/recipes` shows every field and which Meilisearch role it fills (searchable / filterable / sortable).
4. **Add your own recipe** — `/node/add/recipe`, fill in the fields, save. It auto-indexes (index has `index_directly: true`).
5. **Reindex manually** — on the index admin page, "Queue all items for reindexing" then "Index now".

## How the demo is built

- **Install profile** at `web/profiles/meilisearch_demo_profile/` creates all Drupal configuration from `config/install/` YAML files (content type, fields, taxonomies, search server, index, view, facets, theme).
- **Seed task** (`meilisearch_demo_profile.profile`, function `meilisearch_demo_profile_seed_recipes`) reads `fixtures/recipes.json` and creates nodes + triggers synchronous indexing.
- **Fixture** comes from the Kaggle "Better Recipes for a Better Life" dataset, transformed by `scripts/build-fixture.py` (stratified sample of ~30 recipes per cuisine).
- **Plugin source** is pulled from `../../_sdk/meilisearch-drupal` via a Composer path repository — any local plugin change rebuilds into the demo on `docker compose build`.

## Resetting

```bash
docker compose down -v   # wipes database and Meilisearch volumes
docker compose up        # reinstalls from scratch
```

## Troubleshooting

- **Port 8080 already in use** — change the mapping in `compose.yaml` (`"8080:80"` → `"8090:80"`, for example).
- **Drush container stuck** — check `docker compose logs meilisearch` for the healthcheck status. If Meilisearch didn't come up, make sure the image pulled correctly.
- **Install fails with a config import error** — check the drush logs for the specific YAML file; Drupal's config schema is strict. Usually a missing `langcode: en` or a misspelled dependency.
- **Search returns zero results** — visit `/admin/config/search/search-api/index/recipes` and confirm "300 of 300 indexed". If it says 0, click "Index now".

## Out of scope

This demo intentionally covers only the "core tier" features of the plugin:
- Full-text search, highlighting, facets, range filter, sort.

The following are **not** demonstrated here (but the plugin supports them):
- **Semantic / hybrid search** — needs an embedder. See the plugin's [Cloud setup guide](../../_sdk/meilisearch-drupal/docs/cloud-setup.md).
- **Analytics** — click tracking and conversion events. See the plugin's `meilisearch_analytics` submodule README.
- **Geo search** — recipes don't have coordinates.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: write full README with quickstart, tour, and troubleshooting"
```

---

### Task 37: Full verification pass

- [ ] **Step 1: Fresh clone simulation**

```bash
cd /Users/quentindequelen/Projects/Meilisearch/_demos/drupal-meilisearch-demo
docker compose down -v
rm -rf /tmp/drupal-demo-verification
cp -r . /tmp/drupal-demo-verification
cd /tmp/drupal-demo-verification
cp .env.example .env
```

(We copy instead of cloning because the repo is local. This simulates a fresh clone except for git history.)

- [ ] **Step 2: Build and boot**

```bash
time docker compose up --build -d
```

Expected: completes within 3 minutes (target per spec).

- [ ] **Step 3: Run the 8-item checklist from the spec**

Walk through each of the 8 items in the spec's "Verification checklist" section. Check off each as you go:

1. [ ] `docker compose up` on a clean checkout completes within ~3 minutes; no errors in any container's log.
2. [ ] `http://localhost:8080/recipes` returns a list of recipes — no 500, no "No Meilisearch server reachable".
3. [ ] Searching "pasta" returns Italian recipes with `<mark>` highlights visible on `pasta` substring.
4. [ ] Clicking a cuisine facet filters results; URL updates with `?f[0]=cuisine:Italian`.
5. [ ] Selecting the "0–30 min" total-time range filters to fast recipes.
6. [ ] Sorting by "Rating desc" reorders results.
7. [ ] Logging in as `admin`/`admin`, `/admin/config/search/search-api/server/meilisearch_demo` shows "Available" + Meilisearch version.
8. [ ] `docker compose down -v && docker compose up` repeats cleanly from empty.

- [ ] **Step 4: Tear down the verification copy**

```bash
cd /Users/quentindequelen/Projects/Meilisearch/_demos/drupal-meilisearch-demo
rm -rf /tmp/drupal-demo-verification
docker compose down -v
```

- [ ] **Step 5: Tag**

Once all 8 items pass:
```bash
git tag -a v0.1.0 -m "Initial public release of drupal-meilisearch-demo"
```

- [ ] **Step 6: Commit** — no commit needed; tag only.

If any of the 8 items fail, file the failure as a follow-up task and iterate. Do not tag until all pass.

---

## Summary of tasks

| # | Phase | Task |
|---|---|---|
| 1 | Scaffold | Initialize demo repo |
| 2 | Scaffold | Create composer.json |
| 3 | Scaffold | Create Dockerfile |
| 4 | Scaffold | Create entrypoint-drush.sh |
| 5 | Scaffold | Create compose.yaml |
| 6 | Scaffold | Create .env.example |
| 7 | Scaffold | **Smoke test** |
| 8 | Profile | Create .info.yml |
| 9 | Profile | Create .profile |
| 10 | Profile | Create .install |
| 11 | Profile | **Smoke test** (install profile boots) |
| 12 | Content | Taxonomy vocabulary configs |
| 13 | Content | Recipe content type config |
| 14 | Content | Field storage configs (7) |
| 15 | Content | Field instance configs (7) |
| 16 | Content | **Smoke test** |
| 17 | Search | Meilisearch server config |
| 18 | Search | Recipes index config |
| 19 | Search | **Smoke test** |
| 20 | UI | Views config (recipes_search) |
| 21 | UI | Facets config |
| 22 | UI | Facet block placements |
| 23 | UI | **Smoke test** |
| 24 | Seed | Stub fixture (5 recipes) |
| 25 | Seed | RecipeSeeder class |
| 26 | Seed | Wire seeder into install task |
| 27 | Seed | **Smoke test** (5 recipes visible) |
| 28 | Data | Fixture builder script |
| 29 | Data | Run builder + commit real fixture |
| 30 | Data | **Smoke test** (300 recipes) |
| 31 | Theme | Theme .info.yml + libraries |
| 32 | Theme | CSS |
| 33 | Theme | Teaser template |
| 34 | Theme | Set as default theme |
| 35 | Theme | **Smoke test** |
| 36 | Docs | Final README |
| 37 | Docs | **Full verification** + tag |

Total: 37 tasks across 9 phases. Seven of them are smoke-test checkpoints that produce no commits but gate progress.

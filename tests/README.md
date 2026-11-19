# Tests

Two suites, both run against a local copy of the site and a **fresh** test database each time.

| File | What it checks |
| ---- | -------------- |
| `api.test.mjs` | 75 HTTP tests: sign-in, app access, every permission rule (ARCHITECTURE.md §3), cookbook sharing, the recipe editor (in-place rows, conflicts), history and restore, recipe sharing, linking, search, favorites, delete/restore, saved scale, shopping lists (combining rules, serving/batch scale, "have it", remove/rebuild, stores, compare-and-set edits, live changes, sharing, copies), and the Senior Family import. |
| `ui-lists.mjs` | 12 browser steps: scaling and remembered amounts, the add-to-list panel, adding several recipes from a cookbook, edit mode (stepper, department, store, notes, hand-added items), zero = not needed, shopping mode and Checked Off, by-store view, a second phone seeing a check within seconds, typing never overwritten by another device's change, rebuild, mark as done. |
| `ui-walkthrough.mjs` | 16 browser steps in headless Edge: sign-in, cookbooks, filtering, favorites, recipe page, editor (save, field errors, two-person conflict), history restore, new recipe with a cooking method, adding to a second cookbook, sharing, a viewer's view, search, and phone layout (no sideways scrolling). Fails on any console error or missing file. |

## Setup

1. Start a local MariaDB/MySQL and create an empty database (e.g. `sft_test`).
2. Load, in order: `my-apps-hub/api/schema.sql`, `senior-family-cookbook/api/schema.sql`,
   this repo's `api/schema.sql`.
3. Add users `owner@test` (password `pw`, `is_admin = 1`), `elaine@test`, `michelle@test`,
   `outsider@test` with sessions `tok-owner`, `tok-elaine`, `tok-michelle`, `tok-outsider`. Give
   `senior-family-table` app access to owner, elaine and outsider (not michelle).
4. Load the Senior Family recipes into `cookbook_recipes`/`cookbook_tags` (an export of
   `getAllRecipes&collection=senior-family` works), then run
   `php api/tools/import-senior-family.php --user=owner@test`.
5. Copy the whole repo somewhere else (never test inside the repo — `api/config.php` would hold
   test credentials), add a test `api/config.php` (set `DB_PORT` if needed and
   `ALLOWED_ORIGIN` to `http://127.0.0.1:8100`), and serve the copy: `php -S 127.0.0.1:8100`.
   Served from localhost, the pages call `api/api.php` next to them automatically.

## Run

```
API_URL=http://127.0.0.1:8100/api/api.php SQL="mariadb -h127.0.0.1 -P3307 -uroot -proot sft_test" node tests/api.test.mjs
```

Reset the database (steps 1–4), then:

```
npm install playwright-core      # once, outside the repo
BASE_URL=http://127.0.0.1:8100 node tests/ui-walkthrough.mjs ./screenshots
```

Each walkthrough needs its own fresh database.

Last run 2026-11-19 (MariaDB 11.4, PHP 8.3, Edge): API 75/75; recipe walkthrough 16/16;
lists walkthrough 12/12; no console errors.

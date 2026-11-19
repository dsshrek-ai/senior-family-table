# Tests

`api.test.mjs` checks sign-in, the permission rules (ARCHITECTURE.md §3), and the Senior Family
import against a local copy of the API. It needs a **fresh** test database each run.

1. Start a local MariaDB/MySQL and create an empty database (e.g. `sft_test`).
2. Load, in order: `my-apps-hub/api/schema.sql`, `senior-family-cookbook/api/schema.sql`,
   this repo's `api/schema.sql`.
3. Add users `owner@test` (with `is_admin = 1`), `elaine@test`, `michelle@test`, `outsider@test`
   with sessions `tok-owner`, `tok-elaine`, `tok-michelle`, `tok-outsider`. Give
   `senior-family-table` app access to owner, elaine and outsider (not michelle).
4. Load the Senior Family recipes into `cookbook_recipes`/`cookbook_tags` (an export of
   `getAllRecipes&collection=senior-family` works), then run
   `php api/tools/import-senior-family.php --user=owner@test`.
5. Copy `api/` somewhere with a test `config.php` (set `DB_PORT` if needed) and serve it:
   `php -S 127.0.0.1:8099`.
6. Run:
   `API_URL=http://127.0.0.1:8099/api.php SQL="mariadb -h127.0.0.1 -P3307 -uroot -proot sft_test" node tests/api.test.mjs`

Last run 2026-11-19: 28 passed, 0 failed (MariaDB 11.4, PHP 8.3).

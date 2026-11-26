# Tests

Seven suites. `parser.test.mjs` needs nothing but Node; the rest run against a local copy of the site and a **fresh** test database each time.

| File | What it checks |
| ---- | -------------- |
| `parser.test.mjs` | 17 tests of the paste-a-recipe reader: headings, sections, two cooking methods, wrapped and titled steps, no-heading recipes, website copies, package sizes, ranges, fractions, "to taste", optional, flags instead of guesses. Run: `node tests/parser.test.mjs`. |
| `api.test.mjs` | 104 HTTP tests: sign-in, app access, every permission rule (ARCHITECTURE.md §3), cookbook sharing, the recipe editor (in-place rows, conflicts), history and restore, recipe sharing, linking, search, favorites, delete/restore, saved scale, shopping lists (combining rules, serving/batch scale, "have it", remove/rebuild, stores, compare-and-set edits, live changes, sharing, copies), family notes (private never leaks, search visibility, moderation), tags (who may set each kind, tidy names, no duplicates, rename/delete), paste import (duplicate suggestions, saved text, linking instead of copying), photos (who may add or change them, shrinking, sideways phone photos turned upright, fake images refused, signed links that can't be altered, links that stop when a photo, note or recipe goes, note photos never leaking from a private note), copying a recipe to another cookbook (exact wording, separate edits, tags, photos with their own files, who may copy), and the Senior Family import. |
| `ui-photos.mjs` | 8 browser steps: adding two photos (shrunk before upload, first is the cover), the full-screen viewer, captions / make cover / remove, the cover in the cookbook list, a note with a photo and removing it, a viewer's view, the cover in Send as PDF, phone layout. Test images are made by `png.mjs`. |
| `ui-import.mjs` | 8 browser steps: paste survives a reload, the filled-in review screen with flags and original text, clearing a flag, saving (both methods, original text in History), "you may already have this" → add that one instead, "Type it in myself", phone layout. |
| `ui-notes.mjs` | 9 browser steps: shared and private notes across two people, editing and removing notes, built-in/cookbook/personal tags with find-or-add, a viewer's limits, filtering a cookbook by several tags, renaming a tag, Send as PDF (checks the PDF's text, and that private notes are absent), phone layout. |
| `ui-lists.mjs` | 12 browser steps: scaling and remembered amounts, the add-to-list panel, adding several recipes from a cookbook, edit mode (stepper, department, store, notes, hand-added items), zero = not needed, shopping mode and Checked Off, by-store view, a second phone seeing a check within seconds, typing never overwritten by another device's change, rebuild, mark as done. |
| `ui-walkthrough.mjs` | 17 browser steps in headless Edge: sign-in, cookbooks, filtering, favorites, recipe page, editor (save, field errors, two-person conflict), history restore, new recipe with a cooking method, adding to a second cookbook, making a separate copy, sharing, a viewer's view, search, and phone layout (no sideways scrolling). Fails on any console error or missing file. |

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
   `ALLOWED_ORIGIN` to `http://127.0.0.1:8100`, plus `PHOTO_DIR` (any empty folder) and
   `PHOTO_URL_KEY`), and serve the copy with GD and EXIF enabled and a 16 MB upload limit:
   `php -d extension=gd -d extension=exif -d upload_max_filesize=16M -d post_max_size=17M -S 127.0.0.1:8100`.
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

Last run 2026-11-19 (MariaDB 11.4, PHP 8.3, Edge): parser 17/17; API 104/104; recipe walkthrough 17/17;
lists 12/12; notes 9/9; import 8/8; photos 8/8; no console errors.

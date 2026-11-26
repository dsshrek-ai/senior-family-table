# The Senior Family Table — Architecture (Foundation phase)

**Version:** 0.1 (draft)
**Date:** 2026-11-19
**Implements:** [REQUIREMENTS.md](REQUIREMENTS.md) v1.0
**Companion:** [../api/schema.sql](../api/schema.sql)

This document records the foundation-phase decisions: stack and hosting, authentication,
the permission model, and how each major feature is designed. The biggest addition to the
requirements is §6: **shopping lists work the way they do in the Senior Family Cookbook**,
extended to be server-side, shared, and purpose-based.

No meal planner — not in Version 1 and not on the roadmap (REQUIREMENTS.md §1).

---

## 1. Stack and hosting

| Decision        | Choice                                                                        |
| --------------- | ----------------------------------------------------------------------------- |
| Front end       | Multi-page static HTML + vanilla JS, no build step, served from GitHub Pages |
| API             | Single-file PHP 8 API (`api/api.php`, action router, mysqli) on Turbify      |
| API location    | `https://seniorfamily.org/senior-family-table-api/api.php`                           |
| Database        | The **MyDataWorld** MySQL database, tables prefixed `sft_`                    |
| Sign-in         | **My Apps Hub SSO** — shared `users`/`sessions`, `app_access` grant for app key `senior-family-table` |
| Photos          | Files outside `public_html`, served only through the API (§8)                |
| Live updates    | Short-interval polling (§6.7) — shared hosting can't hold open connections   |

This is the same stack as Quill Clock, Temple Time and the other MyDataWorld apps, so the
deployment steps, local test setup and Hub tile are all known quantities.

**Why polling, not WebSockets/SSE:** PHP on shared cPanel hosting can't run a WebSocket
server, and server-sent events would tie up one PHP worker per open phone for as long as the
list is open. A cheap "anything new since version N?" call every few seconds meets acceptance
test 10 ("within the documented fallback refresh interval") without risking the host's worker
limits. The client is written so polling could later be swapped for SSE without other changes.

---

## 2. Accounts and invitations

- Everyone signs in through the Hub (`users`, `sessions`). The Senior Family Table stores no passwords.
- `requireUser()` checks the session and the `senior-family-table` `app_access` grant, the same way
  Quill Clock's API does.
- **Invitations are by Hub username.** The invitee must already have a Hub account (decision
  from REQUIREMENTS.md §16: *recipients must register first*). Inviting someone to a cookbook,
  recipe or shopping list also grants them `senior-family-table` access if they don't have it.
- Account recovery is the Hub's existing password reset, not something this app builds.
- Display names come from `users.display_name`; never show another user's username/email
  to people who don't already share something with them.

---

## 3. Permission model

### 3.1 Roles

| Resource      | Roles                          | Table                     |
| ------------- | ------------------------------ | ------------------------- |
| Cookbook      | `owner`, `editor`, `viewer`    | `sft_cookbook_members`     |
| Recipe        | `co_owner`, `editor`, `viewer` | `sft_recipe_members`       |
| Shopping list | `owner`, `editor`, `viewer`    | `sft_shopping_list_members`|

### 3.2 The rules (implemented once, as SQL helpers in `api.php`, used by every action)

```
canViewCookbook(u, c)   = u is a member of c (any role) and c is not deleted
canEditCookbook(u, c)   = role in (owner, editor)
canManageCookbook(u, c) = role = owner

canViewRecipe(u, r)     = u is a recipe member of r
                          OR r is linked to a non-deleted cookbook that u is a member of
canEditRecipe(u, r)     = recipe role in (co_owner, editor)          -- cookbook roles never grant this
canManageRecipe(u, r)   = recipe role = co_owner

visibleCookbooksFor(u, r) = cookbooks linked to r WHERE u is a member  -- never the others
visibleNotes(u, r)        = notes on r WHERE visibility = 'shared' OR author = u
```

- **Intersection rule:** a cookbook membership only ever grants *viewing* of the recipes linked
  into it. Editing a master recipe always needs a recipe role.
- **Linking a recipe into a cookbook** needs `canEditCookbook` *and* `canViewRecipe`. Linking
  makes the recipe visible to that cookbook's members — the UI says so when the cookbook has
  other members.
- Every list/search query joins through these rules; nothing is filtered in the browser.
  Guessed IDs return "not found", never "forbidden", so existence isn't leaked.

### 3.3 Shared-ownership policy (decided)

- The creator of a recipe is its first `co_owner`.
- Any co-owner can invite co-owners, editors and viewers, and change their roles.
- Removing another co-owner needs a confirmation dialog and writes an audit event.
- The **last co-owner can't be removed or leave**; they must first promote someone, or delete
  the recipe.
- Only co-owners can delete (soft-delete) a recipe. It is restorable for **30 days**, then purged.
- Disagreements are handled by revision history: any editor can see who changed what and
  restore an earlier version (§7.3).
- Private notes belong to their author forever. Losing access to a recipe hides it, but the
  author's own private notes stay in their export (§9).

### 3.4 Personal data defaults (decided)

- Favorites, 1–5 ratings, cooked-on history and **Tested & Approved** are **personal**.
  Nobody else sees them. (A shared "Tested & Approved" tag is available via §5 if a cookbook
  wants a visible stamp.)

---

## 4. Recipes and cookbooks

- `sft_recipes` is the master; `sft_cookbook_recipes` links it into cookbooks with a unique
  `(cookbook_id, recipe_id)` pair. Removing a link never touches the recipe.
- **Two ways to put a recipe in another cookbook** (recipe page): *Also add to* links the same
  recipe (one master — an edit shows in both). *Make a copy in* (`copyRecipe`, added
  2026-11-26) makes a separate recipe: the copier is its only co-owner; ingredient lines are
  copied row for row (original wording and catalog links kept); methods, steps and photos
  (with their own files) come along; tags only if they're built-in, the copier's own, or the
  target cookbook's. Family notes, stars, ratings, sharing and history stay with the
  original — the copy starts at version 1, "Copied from …". Needs view rights on the recipe and
  owner/editor on the target cookbook. A same-titled recipe already in that cookbook makes it
  "… (copy)".
- Ingredients (`sft_recipe_ingredients`), methods (`sft_recipe_methods`) and steps
  (`sft_recipe_steps`) are child rows. A step may belong to a method ("Instant Pot",
  "Slow Cooker"), or to no method (shared steps).
- Ingredient sections ("For serving", "For the sauce") are a `section` text on the
  ingredient row.
- **Saving a recipe** is one transaction that updates child rows **in place by ID** (inserting
  new lines, deleting only lines the user removed) and writes a revision snapshot (§7.3).
  Ingredient row IDs must stay stable because shopping lists point at them (§6.4). `sft_recipes.version` gives optimistic locking: a save based on an old
  version is refused and the editor shows what changed.
- **Batch definition:** `yield_quantity` + `yield_unit` (e.g. 1 pan) alongside `servings`
  (e.g. 12). Either can drive scaling (§6.1).
- **Saved scale** ("make 2 pans"): stored per user in `sft_recipe_user_state.saved_scale`,
  never on the master.
- **Search:** titles, ingredient names/lines, tags, and notes the user can see — all through
  §3.2. MySQL `LIKE` over normalized columns is enough at family scale; FULLTEXT can be added
  later without API changes.

---

## 5. Categories and tags

- One table, `sft_categories`, with `kind` (`category`, `method`, `descriptor`) and `scope`
  (`system`, `user`, `cookbook`).
- System entries seeded from REQUIREMENTS.md §7 (Main Dishes … Tested & Approved).
- A unique key on `(scope, owner, normalized_name)` prevents "Soup"/"soup " duplicates.
  Names are Title-Cased on save, preserving acronyms (as the Senior Family Cookbook does).
- Cookbook-scoped tags are visible to that cookbook's members; user-scoped tags only to
  their owner. Filters combine with AND (all selected tags must match).

---

### 5.1 Notes, tags and sharing outside the family (decided 2026-11-19)

- **Notes:** anyone who can see a recipe can add a note — **shared** (everyone who can see the
  recipe) or **private** (only the author; never returned to anyone else, including co-owners,
  and never in search results, PDFs or exports for others). Authors edit and delete their own
  notes; co-owners may also remove shared notes (audited). Search covers notes the person can see.
- **Who can tag:** built-in tags — people who can edit the recipe; cookbook tags — that
  cookbook's owners and editors; "My tags" — anyone, seen only by them. New tag names are tidied
  (spaces collapsed, words capitalised unless already capitalised) and never duplicated in the
  same place. Cookbook pages filter by several tags at once (all must match).
- **Send as PDF:** a recipe can go to someone without an account as a PDF built in the browser
  (vendored jsPDF, `js/vendor/`), at the amount currently shown. Phones and tablets open the
  share sheet (Messages, Mail); computers download it. Never includes private notes. No public
  links are created, so nothing new is exposed on the server.

## 6. Shopping lists — the Senior Family Cookbook way, extended

### 6.1 How the Senior Family Cookbook does it today (reference)

From `senior-family-cookbook/app.js`:

1. **Favorite** recipes (♥), each with its own saved servings scale.
2. On a recipe, **check off ingredients you already have** — they're left out of shopping.
3. **Shopping List** builds one auto-combined list from all favorites: scaled, same-name
   ingredients merged, volume units added together (tsp→cup), weights added together (oz→lb),
   and each item guessed into a grocery **department** by keyword.
4. **Edit mode** lists items grouped by department. Each row has a quantity stepper (− / +),
   unit, name, and a department dropdown. Picking a department by hand sticks (renaming the
   item won't re-guess it). Rows can be removed, and blank items added.
5. Setting a quantity to **0 or blank** means "don't need it": the row stays in edit mode but is
   hidden from shopping mode and the printout.
6. **Start Shopping** switches to a big tap-to-check view; checked items drop to a
   **Checked Off** section at the bottom.
7. **Reset** (with confirmation) throws away edits and rebuilds from the current favorites.
8. **Print** gives a checkbox list by department, with the recipes and servings at the top.
9. It is a single list, stored only in that device's browser.

### 6.2 What carries over unchanged

Steps 2, 4, 5, 6 and 8 carry over as-is, with the same look and the same department list
(Produce, Meat & Seafood, Dairy & Eggs, Bakery & Bread, Baking, Dry Goods & Pasta,
Canned & Jarred, Spices & Seasonings, Oils & Condiments, Frozen, Other) and the same keyword
guesser — that settles REQUIREMENTS.md §16's grocery-department taxonomy. The unit synonym
table is ported too.

Also carried over: the lesson in the Senior Family code comment about the department dropdown
— never rebuild a row's DOM while the user may be tapping it. That matters even more here,
because live updates arrive in the background (§6.7).

### 6.3 What changes

| Senior Family Cookbook                         | The Senior Family Table                                                    |
| ---------------------------------------------- | -------------------------------------------------------------- |
| One list per device, built from **favorites**  | Many lists, one per **purpose**; each has its own **recipes on this list** with their own scale |
| Stored in the browser                          | Stored on the server; shared lists sync between people (§6.7)  |
| Excluded ("have it") ingredients are global per recipe | Per recipe *per list* — "have it" for Thanksgiving isn't "have it" for Weekly Groceries |
| Merges by exact name                           | Merges by **catalog ingredient** when matched, else by exact normalized name — never fuzzy |
| Mixed volume + weight merged approximately (1 pint ≈ 1 lb) | **Not merged** — shown as two lines (REQUIREMENTS.md §6 forbids conversion without density) |
| Department only                                | Department, plus optional **store**, **notes**, **assigned to** (behind a "More" toggle so rows stay compact) |
| Shopping mode groups by department             | Toggle **By department / By store** (store view sub-groups by department) |
| Reset discards everything                      | **Rebuild from recipes** discards edits to recipe items, but **keeps items you added by hand** |
| No history                                     | **Mark complete**, keep, reopen or copy a past list            |
| First keyword match picks the department ("cornstarch" → Produce) | **Longest** keyword match wins ("cornstarch" → Baking, "garlic powder" → Spices) |
| Combined amounts shown to 0.01 ("9.92 tbsp")   | Rounded **up** to a kitchen fraction ("10 tbsp", "5⅓ tbsp"); counts up to whole |

Favorites still exist (personal ♥ on recipes), but they no longer drive the list.

### 6.4 Adding recipes to a list

- On a recipe, **Add to shopping list** opens a sheet: choose a list (last used is
  pre-selected, or **New list…**), confirm the scale (pre-filled from the recipe screen's
  servings or batches), and untick the ingredients you already have — the Senior Family
  "check off" step, now in the same sheet.
- That recipe is stored as a row in `sft_shopping_list_recipes` (scale + excluded ingredient IDs)
  and its ingredients are **merged into the existing items** (§6.5). Edits already made to
  the list are kept, not rebuilt.
- **Merging rule into edited items:** a new contribution merges into an existing item only
  if the item is unchecked and has a compatible unit (same dimension, §6.5). The quantity is
  added in the item's own unit and rounded to a friendly fraction. Otherwise it becomes its
  own row, so nothing is silently lost.
- **Removing a recipe from a list** removes its contribution: items it alone produced are
  deleted, merged items have its share subtracted (recorded in `sft_shopping_item_sources`).
  If someone hand-edited that item since, it is left alone and flagged "check quantity".
- Several recipes can be added at once from a cookbook's recipe cards (multi-select →
  **Add to shopping list**).

### 6.5 Consolidation rules

Two contributions merge into one item only when **all** of these hold:

1. **Same ingredient:** same `ingredient_id` when both are matched to the catalog; when neither
   is matched, the same normalized name (lowercase, trimmed, simple plural removed). A matched
   and an unmatched line never merge.
2. **Same dimension:** both volume (added in tsp, shown in the friendliest of tsp/tbsp/cup/quart),
   both mass (added in oz, shown in oz/lb), or both the same count/package unit with the same
   package qualifier ("28-oz can" + "28-oz can", not "15-oz can").
3. **No quantity on one side** ("salt to taste") → one item with no quantity, keeping the note.

Anything else becomes a separate item, with all source recipes listed under it. Users can
**merge** two items (keeps both sources, adds if compatible, otherwise asks for the result) or
**split** a merged item back into its sources.

Quantities are stored as `DECIMAL(12,4)` and displayed as friendly fractions (⅓, ½, 1¼).
On the list, combined measured amounts round **up** to the nearest kitchen fraction
(⅛ ¼ ⅓ ½ ⅔ ¾ ⅞) and count items up to whole numbers ("2.5 onions" → 3); the exact
contribution is kept in the source rows.

### 6.6 Department and store

- **Department:** the catalog ingredient's default department if it has one, otherwise the
  ported keyword guesser. A manual pick sets `department_manual = 1` and is never overwritten.
- **Store:** optional per item, from `sft_stores` (system-seeded: Costco, Sam's Club, Walmart,
  plus user-added stores). Blank = **Unassigned**. Changing it never touches recipes.
- **Assigned to:** optional, any member of the list.

### 6.7 Sync between devices

- Every change to a list bumps `sft_shopping_lists.change_seq`; each changed item records the
  seq it was changed at (`changed_seq`). Deleted items are soft-deleted so the deletion syncs.
- The open list calls `listChanges&listId=…&since=<seq>`, which returns only items changed
  after that seq (usually none — a tiny response).
  - **Shopping mode:** every 4 seconds. **Edit mode:** every 15 seconds.
  - Paused while the tab/app is hidden, polled immediately when it comes back.
- **Check/uncheck sends the desired state**, not a toggle, so two people tapping eggs at once
  both end with eggs checked.
- **Field edits** send the item's `row_version`. If someone else changed the item first, the
  server applies the change only if it touched different fields, otherwise it returns the
  current item and the app shows "Changed by Elaine — your edit wasn't saved" on that row.
  Nothing is silently lost.
- Incoming updates are applied **row by row** and never to the row whose input currently has
  focus; that row is refreshed when focus leaves it. Rows that change group (department or
  store) wait for a full redraw until you stop typing.
- Only list members can call `listChanges`; it is checked on every poll.

---

## 7. Import, catalog, and revisions

### 7.1 Paste-text import

- The parser runs **in the browser** (`js/parser.js`), so the review screen appears
  instantly, and is versioned (`parser_version` stored with each import).
- Deterministic rules, no AI service in Version 1. It recognizes: title (first line),
  servings/yield/time lines, `Ingredients`/`Directions`/`Instructions`/`Notes` headings,
  sub-section headings ending in `:` ("For serving:"), method headings ("Instant Pot",
  "Slow Cooker", "Air Fryer", "Oven", "Stovetop"), numbered or bulleted steps.
- Ingredient lines: whole numbers, decimals, mixed and Unicode fractions (`1 1/2`, `1½`),
  ranges (`2-3`, `2 to 3` → `quantity` + `quantity_max`), the unit synonym table, package
  qualifiers (`1 (28 oz) can crushed tomatoes`), parenthetical notes, `optional`,
  "to taste"/"as needed" (no quantity — never invented).
- Every line it isn't sure about is flagged on the review screen. Nothing saves without the
  user pressing **Save**. The full pasted text, the parser's output and the user's corrections
  go to `sft_recipe_imports`.
- **Duplicate check** before save: recipes the user can already see with a similar title
  (normalized title match, or most words shared) or heavily overlapping ingredients. Each
  match offers **Add this existing recipe to the cookbook instead**.
- If parsing fails, the text is kept and the user lands in the manual editor with it alongside.

**As built (2026-11-19):** `js/parser.js` (version `paste-1`), tested in `tests/parser.test.mjs`
and against all 190 Senior Family recipes turned back into text (188 read perfectly; the other two
were a "Serves 4–6" range in the description and notes mixed into the steps — both shown for review).
The review screen is the normal recipe editor in "check the pasted recipe" mode: flagged lines
carry a "Check this" mark that clears once the line is edited, the original text sits alongside,
and "You may already have this" offers recipes with the same or a similar title, or 70%+ of the
same ingredients. The paste is kept on the device until saved; "Type it in myself" keeps the text
beside an empty editor. The original pasted text is shown on the recipe's History page.

### 7.2 Ingredient catalog

- `sft_ingredients` (canonical names, default department, dimension) and
  `sft_ingredient_aliases`.
- Scopes: `system` (shared by everyone), `user` (only you). Matching only uses **exact
  normalized alias matches** — no fuzzy matching — so "garlic powder" can never land on
  "garlic".
- Anyone can create a user-scoped ingredient or alias, which only affects their own matching.
  Proposing one for everyone sets its status to `pending`; an **app admin**
  (`sft_admins`) approves it into the `system` scope.
- The catalog starts with the common ingredients from the Senior Family department keyword
  list, with obvious aliases (ground beef ← hamburger, hamburger meat, beef, ground).

### 7.3 Revisions

- Every recipe save writes a JSON snapshot (all recipe fields, ingredients, methods, steps,
  tags) to `sft_recipe_revisions` with author and time.
- **Restore** = save that snapshot as a new revision. History is never rewritten.
- Notes, photos and personal data are not in revisions (they have their own rows).

---

## 8. Photos

- Upload: JPEG, PNG or WebP, up to 15 MB. The API checks the real file type (not the
  extension), then uses GD to apply the EXIF rotation and **re-encode to JPEG**, which strips
  location and other metadata. Stored as a 1600 px "full" and a 400 px "thumb".
- Files live in a directory **outside `public_html`**, named by random ID.
- `<img>` tags can't send the bearer token, so the API hands out **signed, expiring URLs**
  (`photo&id=…&size=thumb&exp=…&sig=HMAC`) valid for 1 hour. A link copied elsewhere stops
  working; nothing is publicly guessable.
- Each recipe has one cover (`is_cover`) and any number of others, ordered by `position`.
  Note photos use the same storage and the same visibility as their note.

### 8.1 As built (2026-11-19)

- **Who:** anyone who can edit a recipe adds, captions, reorders and removes its photos (up to
  30); viewers see them. Note photos (up to 4 per note) are added and removed only by the
  note's author; a private note's photos are never linked for anyone else.
- **The first photo is the cover.** "Make cover" moves a photo to the front. The cover shows
  at the top of the recipe, beside the recipe in cookbook lists, and at the top of Send as PDF.
- **Before upload** the browser shrinks the photo to 2000 px and turns it upright
  (`js/photos.js`), so a 10 MB phone photo goes up as ~0.5 MB. If the browser can't read the
  file it sends the original and the server decides.
- **On the server** (`api/lib/photos.php`): real type checked with `getimagesize` (JPEG, PNG,
  WebP), at most 30 megapixels, EXIF rotation applied, re-encoded to JPEG at 1600 px and a
  480 px thumbnail, transparent areas on white. Files are written to a `.part` name and
  renamed, so a half-written file is never served.
- **Links:** `?action=photo&t=r|n&id=…&s=full|thumb&e=…&g=…`, an HMAC over type, id, size and
  expiry with `PHOTO_URL_KEY`. Expiry is rounded to the hour (valid 1–2 hours), so a page's
  links stay the same — and cached — for at least an hour. Any change to the link gives
  "not found"; an expired one gives 410. A removed photo, deleted note or deleted recipe stops
  serving at once.
- **Removing** a recipe photo marks it deleted (kept 30 days for the purge job, audited); a
  note photo is deleted outright with its files.
- Photos are not in recipe revisions.

---

## 9. Export, backup, retention

- **Export (per user):** a ZIP from **Settings → Export** with a documented JSON file (every
  cookbook, recipe, list and tag the user can see, and their own private notes — never anyone
  else's) plus the photos. Format documented in `docs/EXPORT-FORMAT.md` (Phase 6).
- **Backup (whole app):** a cPanel cron job dumps the `sft_` tables nightly and copies the photo
  folder, keeping 14 daily + 12 monthly copies. A restore procedure is written and tested
  before launch (Phase 6).
- **Retention (decided):** soft-deleted recipes, cookbooks and list items purged after 30 days;
  completed shopping lists kept 1 year; revisions kept indefinitely (they're small text);
  import records kept indefinitely.

---

## 10. Security

- Every action calls `requireUser()` and then the §3.2 helper for the resource. There is no
  action that takes a user ID from the client.
- Prepared statements everywhere. All user text is HTML-escaped when rendered; notes and
  descriptions support only the plain-text formatting the other apps use (bold, lists, links),
  never raw HTML.
- Pasted recipe text is just data: parsed, displayed escaped, never evaluated.
- CORS limited to the GitHub Pages origin; rate limits on uploads and invites.
- Audit events (`sft_audit_events`) for sharing changes, role changes, removals, deletions,
  restores and revision restores.
- **Required tests (Phase 6):** for every read action, a second user guessing IDs gets
  "not found" — recipes, cookbooks, notes, photos (including signed URLs with a forged sig),
  shopping lists and `listChanges`.

---

## 11. Pages

| Page                  | File                 |
| --------------------- | -------------------- |
| My Cookbooks          | `index.html`         |
| Cookbook Detail       | `cookbook.html`      |
| Recipe Detail         | `recipe.html`        |
| Add / Import Recipe   | `import.html`        |
| Recipe Editor         | `recipe-edit.html`   |
| Sharing               | `share.html` (cookbook, recipe or list, by `type` + `id`) |
| Shopping Lists        | `lists.html`         |
| Shopping List Detail  | `list.html` (edit mode / shopping mode) |
| Ingredient Catalog    | `catalog.html`       |
| Activity / History    | `activity.html`      |
| Settings / Data       | `settings.html`      |
| Help                  | `help.html`          |

The bottom nav on phones has **Cookbooks**, **Lists** and **Search**, so checking items off is
one tap from anywhere.

---

## 12. Hosting checklist (Turbify)

- [ ] GD extension available (photo resize/re-encode) — Quill Clock already uses it.
- [ ] `ZipArchive` available (export).
- [ ] `upload_max_filesize` / `post_max_size` ≥ 16 MB.
- [ ] A writable photo directory outside `public_html`.
- [ ] cPanel cron for nightly backup and the 30-day purge.
- [ ] Register `senior-family-table` in the Hub's `apps` table (tile, SSO on).

---

## 13. REQUIREMENTS.md §16 — status

| Decision                                         | Status                                                    |
| ------------------------------------------------ | --------------------------------------------------------- |
| Framework / is Turbify suitable                  | **Decided:** PHP 8 + vanilla JS on the MyDataWorld stack; yes, with polling (§1) |
| Authentication, invitations, recovery            | **Decided:** Hub SSO; invite by Hub username; must register first; Hub password reset (§2) |
| Co-owner removal / ownership transfer            | **Decided** (§3.3)                                         |
| Sharing categories / Tested & Approved / cooking history | **Decided:** personal by default; cookbook-scoped tags for shared labels (§3.4, §5) |
| Units, regional preferences                      | **Proposed:** US customary; metric units recognized and kept as written, no auto-conversion between systems |
| Stores and department taxonomy                   | **Decided:** Senior Family department list; seeded + custom stores (§6.2, §6.6) |
| Offline behavior                                 | **Proposed:** none in V1 — the app needs a connection; a failed save shows clearly and retries |
| Conflict handling, polling targets               | **Decided** (§6.7): 4 s shopping mode, 15 s edit mode     |
| Backup format, retention, restore                | **Decided** (§9); export format doc in Phase 6            |

---

## 14. Delivery sequence

Follows REQUIREMENTS.md §15:

1. **Foundation:** Hub registration, `sft_` schema, `requireUser` + permission helpers with
   tests, cookbook CRUD, and the **Senior Family Cookbook import**
   ([IMPORT-SENIOR-FAMILY.md](IMPORT-SENIOR-FAMILY.md)) so real recipes are there from the start.
2. **Recipe library:** cookbook/recipe screens, linking, recipe editor, sharing.
3. **Importer and organization:** parser + review, catalog, tags, photos, notes, revisions.
4. **Scaling and shopping:** scaling, lists, add-from-recipe, Senior Family edit/shopping modes,
   department/store views, print.
5. **Collaboration:** list sharing and polling sync, conflict handling, audit.
6. **Quality and deployment:** cross-user ID tests, export, backup/restore test, mobile pass,
   `help.html`.

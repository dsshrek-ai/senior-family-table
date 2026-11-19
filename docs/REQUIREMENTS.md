# Recipe Book — Application Specification

**Status:** Requirements agreed; implementation not yet started  
**Version:** 1.0  
**Date:** November 18, 2026  
**Purpose:** Working product and technical specification for a multi-user recipe and shopping-list web application.

## 1. Product vision

Build a responsive recipe-book web application usable on desktop, tablet, and phone. Users can organize a single recipe into multiple cookbooks without duplication, collaborate on recipes, paste complete recipe text for structured import, attach photographs, scale quantities, and maintain synchronized private or shared shopping lists.

The initial deployment may use the owner's existing MySQL-compatible hosting environment, subject to verification of server-side language, storage, authentication, HTTPS, background jobs, and real-time connection support. **MySQL is the intended relational database.** Implementation framework and hosting topology remain open decisions.

### Explicit exclusion

**No meal planner.** Do not include calendars, scheduled meals, or meal-plan-driven grocery generation in Version 1 or the presently planned roadmap. Users can add ingredients from several recipes to a shopping list without assigning dates.

## 2. Confirmed core requirements

1. Multiple cookbooks per user, with switching, organizing, and searching.
2. The **same recipe record can appear in multiple cookbooks** through a many-to-many association; no duplicated master record.
3. **Shared recipe ownership:** multiple users can co-own and edit a recipe. Master edits propagate wherever the recipe appears.
4. Independent cookbook membership and recipe permissions; membership in one cookbook must not expose another cookbook.
5. Complete recipe **text paste/import** with parsing, user review, and original text retention. Image OCR and URL imports are deferred.
6. Manual recipe photo uploads in Version 1, including a cover image and additional images.
7. Predefined and user-created categories/tags, with multiple tags per recipe.
8. Serving-based and batch-based scaling, retaining the unmodified master recipe.
9. A canonical master ingredient catalog with aliases, structured measurements, and conservative matching.
10. Shared and private notes per recipe; private notes have server-side access restrictions.
11. Shopping lists organized **by purpose**, with optional store assignment on each item, not a mandatory store per list.
12. Private and shared shopping lists, with near-real-time changes and checked-off status across connected users.
13. Ingredients can be added from one or many recipes; scaled amounts are used, with safe consolidation where dimensions and ingredient identity match.

## 3. Users and permissions

### Accounts

- Each user signs in to an account and has personal/private data.
- Sessions, passwords, and invitations are managed server-side. Store password hashes using a modern password hashing algorithm; never plaintext passwords.
- All private reads, writes, uploads, and subscriptions require authorization checks on the server.

### Roles

| Resource | Role | Capabilities |
|---|---|---|
| Cookbook | Owner | Rename/delete cookbook, manage members, add/remove recipe links |
| Cookbook | Editor | Organize contents, add/remove recipe links; cannot delete cookbook or manage owners |
| Cookbook | Viewer | Read cookbook and accessible recipes |
| Recipe | Co-owner | Edit master recipe, photos, ingredients, categories, notes permitted by visibility; manage recipe collaborators |
| Recipe | Editor | Edit master content, without transferring ownership or deleting the recipe |
| Recipe | Viewer | Read master content; add own private notes if permitted by policy |
| Shopping list | Owner | Manage list, membership and deletion |
| Shopping list | Editor | Add/update/check items and optional store assignments |
| Shopping list | Viewer | Read list without changes |

**Permission intersection rule:** A cookbook membership can grant visibility to a linked recipe within that cookbook, but never editing rights to its master unless an explicit recipe permission grants editing. A user navigating a recipe through one shared cookbook cannot discover other cookbooks containing it unless separately authorized. The same user's private notes must never be returned to anyone else, including a co-owner.

**Shared ownership governance:** Establish a policy before coding for inviting/removing co-owners, preventing removal of the last owner, and handling disagreements. Proposed default: owners can invite others; removing another co-owner requires confirmation and is recorded in an audit log. A user must not lose access to their private notes when a cookbook association changes; private-note retention/export policy is to be finalized.

## 4. Recipes and cookbooks

### Cookbook capabilities

- Create, rename, describe, archive, and delete a cookbook with confirmation.
- Switch active cookbook; optionally browse/search recipes across all cookbooks the user may access.
- Add one recipe to several cookbooks without copying it.
- Remove a recipe from a cookbook without deleting its master record or other associations.
- Avoid duplicate recipe/cookbook links through a unique database constraint.
- Invite registered users to one cookbook as owner/editor/viewer; changing cookbook membership does not grant access to other cookbooks.

### Recipe record

- Title, description, servings, yield/batch definition, prep/cook/total time where available, ingredient rows, ordered instructions, alternate cooking methods, notes, source attribution, categories/tags, and photos.
- Save the original imported text along with structured fields and parse provenance.
- Revision/audit history identifies author and timestamp; authorized collaborators can restore a prior version.
- Support favorites, personal rating, cooked-on history, and **Tested & Approved** state (initial implementation may be lightweight).
- Search titles, ingredients, categories/tags, and accessible notes.
- Deleting a master recipe is an exceptional operation with confirmation and a recoverable soft-delete period. It should never happen merely because a cookbook link is removed.

### Example

One Meatball Soup recipe may appear in Family Favorites, Freezer Meals, and Soups & Stews. Editing the master soup instructions changes the recipe in all three locations. Removing it from Freezer Meals leaves the other associations untouched.

## 5. Text recipe import — Version 1

1. Choose a cookbook and select **Add Recipe → Paste Text**.
2. Paste the complete recipe (including possible headings, ingredients, cooking methods, storage, garnishes, and notes).
3. Click **Parse Recipe**; extract title, description, serving count, times, ingredients, quantities/units, steps, cooking methods, and supplementary notes.
4. Show an **editable review screen** with confidence/ambiguity prompts, especially for ingredient quantities, cooking methods, and yield.
5. Show possible existing recipe matches. Users may link an existing recipe to the cookbook instead of creating a new recipe.
6. Save only on user confirmation. Preserve the entire original text and what was corrected.

The parser must handle fractional quantities, ranges, common abbreviations, parenthetical descriptions, optional items, sections (e.g., “For serving”), and alternative Instant Pot/slow-cooker instructions. Do not silently invent missing quantities. Parsing may start with a deterministic rules approach or an assisted parser, but review remains mandatory. Import failure should preserve the pasted text and allow manual entry.

**Not in Version 1:** extracting recipes from photos/OCR or importing a website URL. **Still in Version 1:** attaching image files to an already created recipe.

## 6. Ingredients, measurement, and scaling

### Master ingredient catalog

- Canonical entry (e.g., **ground beef**) and many aliases (e.g., **hamburger meat**, **beef, ground**).
- Different products are not merged merely because their wording is similar: fresh garlic ≠ garlic powder; tomato paste ≠ crushed tomatoes.
- Preserve the original display line, structured quantity and unit, preparation note, optional flag, and link to a canonical ingredient when confidently matched.
- Users can create custom ingredients and propose/manage aliases. Shared/global catalog edits need administrator review or other controlled scope to prevent one user from altering everyone's matching unexpectedly.

### Quantity model

Store numerical quantities with a precise decimal or rational-compatible representation, unit dimension (volume, mass, count, package, or unknown), and original text. Keep package qualifiers such as “one 28-ounce can” separately when practical. Conversion across weight and volume requires ingredient-specific density; otherwise do not automatically convert.

### Scaling

**Serving scaling:** factor = requested servings ÷ original servings.  
**Batch scaling:** factor = requested batches ÷ base batches; define original batch yield (e.g., one pan, twelve servings).  
Use either factor for the displayed ingredient list and when adding ingredients to a shopping list. Show friendly fractions, support sensible whole-item rounding, and preserve original stored quantities. Warn that oven times, pan dimensions, and equipment capacity may not scale linearly. A scaled cooking plan may be saved without duplicating the recipe.

### Shopping-list consolidation

Combine only the same canonical ingredient and compatible unit/variant. For example, 1 onion + 2 onions = 3 onions, but “1 bunch green onions” and “2 onions” should not merge. Keep source recipe links and separate items when uncertainty remains; let users manually merge/split/edit.

## 7. Categories and tags

Include predefined categories such as Main Dishes, Side Dishes, Soup, Salad, Breakfast, Appetizer, and Dessert. Also support cooking-method tags (Instant Pot, Slow Cooker, Air Fryer, Oven, Stovetop), and descriptors such as Freezer Friendly, High Protein, Gluten Free, Family Favorite, and Tested & Approved.

- Users may create custom categories/tags, rename them, and optionally share them within a cookbook.
- A recipe can have unlimited applicable tags.
- Search and filtering can combine several tag conditions.
- The data model should distinguish system tags from user-scoped or cookbook-scoped tags and avoid accidental duplicate labels.

## 8. Recipe notes, history, and photos

### Notes

- **Shared:** readable by authorized recipe viewers, editable by note author and authorized editors under the agreed policy.
- **Private:** readable and editable only by their author, enforced by server queries and permissions.
- Store author/time, text, and optional attachments. Search results must honor visibility.

### Personal activity

Track optional cooked-on dates, personal 1–5 ratings, and an optional Tested & Approved marker. Whether that marker is personal or shared is a policy decision; proposed default is personal unless explicitly marked shared.

### Photos

Allow cover and secondary photos per recipe, and optionally photo attachments to notes. Validate file types/sizes; generate safe thumbnails; strip unnecessary metadata where practical. Store photos in protected object/file storage and store paths/metadata in MySQL. Authorize access to each private/shared photo; avoid publicly guessable permanent links.

## 9. Shopping lists

### Organization

A list represents a **purpose**, e.g., Weekly Groceries, Relief Society Christmas Social, Thanksgiving Dinner, or Freezer Meal Preparation. Users can create multiple lists and invite others to collaborate. Each individual item may optionally specify a store such as Costco, Sam's Club, Walmart, or Unassigned.

### Features

- Add ingredients from one or multiple recipes with the currently chosen serving/batch scale.
- Add manual items unrelated to a recipe; edit item label, unit, quantity, notes, department, store, and checked status.
- View the same list grouped **by department** or **by store**, switching views without making new lists.
- Allow store assignment changes without modifying source recipes.
- Check/uncheck items from different devices and show near-real-time state changes to all collaborators.
- Optional **Assigned To** user for dividing errands.
- Keep completed lists/history, reopen/copy a previous list, and confirm before deleting list data.
- Use simple conflict handling: persist authoritative server updates, broadcast changes and timestamps, and avoid silently losing concurrent edits.

### Real-time implementation requirement

Prefer WebSockets or server-sent events if hosting allows them, with authenticated subscriptions scoped to each list. If existing hosting cannot support persistent connections, use secure short-interval polling as an initial compatible fallback. No client may subscribe to another user's private list. “Real-time” does not imply guaranteed offline synchronization; offline behavior requires a separate design decision.

## 10. Main screens

| Screen | Key functions |
|---|---|
| Sign in / account | Log in, manage basic account and sessions |
| My Cookbooks | View/create/switch cookbooks and shared access |
| Cookbook Detail | Recipe cards, search, filters, add/link/remove recipe |
| Recipe Detail | Ingredients, scale controls, methods, steps, photos, notes, add to shopping list |
| Add / Import Recipe | Paste raw text, parse, review, correct, detect duplicates, save |
| Recipe Editor | Manage structured recipe, ingredient matching, tags, methods, photos |
| Sharing / Permissions | Cookbook membership and recipe co-owner/editor/viewer access |
| Shopping Lists | Purpose-based list index, private/shared lists |
| Shopping List Detail | Add/check/edit items, choose store, group by department/store, collaborators |
| Ingredient Catalog | View/search ingredient names and aliases; create custom entries |
| Activity / History | Recipe revisions, cooked history, recently completed shopping lists |
| Settings / Data | Profile, preferred units, export, backup, account management |

Mobile navigation should make checking shopping items and adding an ingredient possible with minimal taps. Desktop layouts can display ingredient rows and instructions side by side.

## 11. Proposed MySQL relational design

All tables should have primary keys, appropriate foreign keys, timestamps, and indexes. The names below are conceptual; exact columns and SQL migrations will be finalized during implementation.

| Table | Principal fields / relationship |
|---|---|
| `Users` | `UserID`, email (unique), password hash or identity-provider ID, status |
| `Cookbooks` | `CookbookID`, title, description, created_by, archived/deleted timestamps |
| `CookbookMembers` | `CookbookID`, `UserID`, role; unique pair |
| `Recipes` | `RecipeID`, title, description, original servings, yield, times, imported_text, soft-delete status |
| `RecipeMembers` | `RecipeID`, `UserID`, role; unique pair; multiple co-owners allowed |
| `CookbookRecipes` | `CookbookID`, `RecipeID`, date_added, display_order; **unique pair** |
| `RecipeIngredients` | `RecipeIngredientID`, `RecipeID`, position, original_text, quantity, unit, `IngredientID`, preparation, optional |
| `Ingredients` | `IngredientID`, canonical_name, scope/owner, unit/dimension metadata |
| `IngredientAliases` | `AliasID`, `IngredientID`, alias_text, scope, matching status |
| `RecipeMethods` | `MethodID`, `RecipeID`, method_name, sequence |
| `RecipeSteps` | `StepID`, `RecipeID`, optional `MethodID`, step_number, instruction |
| `RecipePhotos` | `PhotoID`, `RecipeID`, storage_key, caption, cover_flag, position, uploaded_by |
| `Categories` | `CategoryID`, name, type, scope, owner_id/cookbook_id nullable |
| `RecipeCategories` | `RecipeID`, `CategoryID`; unique pair |
| `RecipeNotes` | `NoteID`, `RecipeID`, `UserID`, note_text, visibility, timestamps |
| `RecipeNotePhotos` | `NotePhotoID`, `NoteID`, protected storage_key |
| `RecipeRatings` | `RecipeID`, `UserID`, rating, tested_approved, unique pair |
| `RecipeCookingHistory` | `HistoryID`, `RecipeID`, `UserID`, cooked_on, remarks |
| `RecipeRevisions` | `RevisionID`, `RecipeID`, changed_by, changed_at, revision snapshot/diff |
| `RecipeImports` | `ImportID`, `RecipeID`, raw_text, parser_version, review corrections, imported_by |
| `ShoppingLists` | `ShoppingListID`, purpose/title, created_by, status, timestamps |
| `ShoppingListMembers` | `ShoppingListID`, `UserID`, role; unique pair |
| `ShoppingListItems` | `ItemID`, `ShoppingListID`, `IngredientID` nullable, label, quantity, unit, store_id nullable, department, assigned_user_id nullable, checked, position, version/timestamps |
| `ShoppingItemSources` | `ItemID`, `RecipeID`, `RecipeIngredientID`, source scale/quantity; preserves provenance when consolidated |
| `Stores` | `StoreID`, name, scope/owner, optional details |
| `AuditEvents` | Actor, resource, action, timestamp, minimal relevant detail |

### Core relationships

- `Cookbooks` ↔ `Recipes` via `CookbookRecipes` (many-to-many).
- `Recipes` ↔ `Users` via `RecipeMembers` (many-to-many; shared ownership).
- `ShoppingLists` ↔ `Users` via `ShoppingListMembers` (many-to-many).
- `Recipes` → `RecipeIngredients` → `Ingredients` (recipe-to-rows-to-canonical catalog).
- `ShoppingListItems` ↔ recipe ingredient sources through `ShoppingItemSources` (many-to-many as items consolidate).

### Database safeguards

Use composite uniqueness constraints, transactional writes for recipes/imports/list consolidation, strict foreign keys, indexed membership checks, and optimistic versioning for concurrent shopping edits. Implement soft deletion for valuable user content. Every API query must enforce resource-level authorization. Tests must explicitly verify that cross-user ID guessing cannot retrieve data, files, notes, or live updates.

## 12. Key user workflows / acceptance tests

1. **Reuse recipe:** Add Meatball Soup to Family Favorites and Freezer Meals; verify one `Recipes` row and two `CookbookRecipes` rows.
2. **Shared edits:** Two co-owners edit a recipe; committed changes appear from both cookbook views with revision author/time.
3. **Bounded visibility:** A user invited only to Family Favorites can view Meatball Soup there, but cannot list private Freezer Meals or see another member's private notes.
4. **Text import:** Paste the full Meatball Soup example, review extracted sections and fix an ingredient, then save. Original pasted text remains recoverable.
5. **Photo:** Attach a cover photo and a second photo to a recipe; authorized collaborators can view them and an unrelated user cannot.
6. **Serving scale:** Six-serving soup doubled to twelve updates ingredient display and shopping quantities but leaves source quantities untouched.
7. **Batch scale:** Two lasagna pans use double the one-pan quantities; warn that cooking directions may require manual adjustment.
8. **Catalog match:** “Hamburger meat” and “ground beef” resolve to one approved canonical entry; fresh garlic and garlic powder remain distinct.
9. **Purpose and store:** Create Christmas Social list, assign one item to Costco and another to Sam's Club, then switch department/store view without altering item ownership or quantities.
10. **Shared checkoff:** Two authorized devices open a list; checking a carton of eggs on one displays checked on the other without manual reload (or within the documented fallback refresh interval).
11. **Permissions:** Viewer cannot modify master recipe, list items, or collaborators; a collaborator on a shopping list cannot see unrelated lists.
12. **No meal planner:** UI and API expose no meal-date scheduling or planned-meal calendar.

## 13. Version 1 scope versus future enhancements

### Version 1 — target

- Responsive multi-user accounts and login.
- Multiple private/shared cookbooks; independent membership roles.
- Many-to-many recipe placement; multi-owner master recipes with secure permissions.
- Paste-and-review text import, manual recipe creation/editing, duplicate suggestions.
- Structured ingredients and master catalog with aliases; initial categories and custom tags.
- Multiple photo attachments and protected access.
- Shared and private notes, basic revisions.
- Serving/batch scaling and ingredient-to-list workflow.
- Purpose-based shopping lists, per-item store, department/store view, manual entries, sharing, near-real-time checkoff.
- Search/filter, secure backup/export minimum, basic tested/approved or rating support.

### Possible later enhancements (subject to separate approval)

- OCR/image-to-recipe extraction and website URL recipe imports.
- PDF cookbook printing/export with curated layouts.
- Detailed nutrition estimates and advanced ingredient conversion/density tables.
- Pantry inventory and purchasing suggestions.
- Offline-capable mobile/PWA caching, more advanced conflicts, push notifications.
- Native iOS/Android packaging if useful.

**Do not add a meal planner to this roadmap.**

## 14. Security, privacy, backup, and operations

- Enforce HTTPS, secure session cookies, CSRF protection where relevant, input validation, rate limits, and least-privilege database accounts.
- Protect photos by authorization rather than open URLs; validate uploads and sanitize displayed rich text.
- Treat imported recipe content as untrusted text, never executable instructions.
- Keep user access boundaries consistent in database/API/search/storage/real-time channels.
- Record significant ownership, sharing, deletion and revision changes in audit logs.
- Provide export of cookbook and recipe data in a documented portable format, including associations, notes according to permissions, and media. Provide documented restore procedure and recurring backup strategy.
- Define retention periods for soft-deleted recipes, completed lists, and old revisions prior to production release.
- Verify host's ability to run secure API code, scheduled backups, file uploads, and push-based updates; use polling fallback where needed.

## 15. Proposed delivery sequence

1. **Foundation:** select implementation stack/hosting, authentication, user and access model, initial schema and migrations.
2. **Recipe library:** cookbook navigation, shared recipe links, master editor and permissions.
3. **Importer and organization:** paste/import review, catalog aliases, tags, photo uploads, notes, revision history.
4. **Scaling and shopping:** quantity logic, purpose lists, recipe additions, store and department views.
5. **Collaboration:** list sharing and synchronized checkoff, collaboration/audit edge cases.
6. **Quality and deployment:** permission/security tests, backups/restore validation, mobile usability, deployment.

## 16. Decisions still to finalize before implementation

- Programming framework and whether existing Turbify hosting is suitable for the full app.
- Authentication method, invitation flows, account recovery, and whether invitation recipients must register first.
- Exact co-owner removal/approval policy and ownership transfer handling.
- Defaults for sharing categories and Tested & Approved flags; whether private cooking history is visible to others.
- Unit conventions, regional measurement preferences, custom stores and grocery department taxonomy.
- Offline behavior, conflict resolution details, and real-time/polling performance targets.
- Backup format, retention, and supported restore procedure.

These are implementation decisions, **not** permission to introduce a meal planner or expand the committed Version 1 import modes.

---

*This document records the requirements agreed in the conversation through November 18, 2026. Update its version and change log as decisions change.*

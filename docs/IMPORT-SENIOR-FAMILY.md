# Importing the Senior Family Cookbook

**Status:** Plan, written 2026-11-19 from a read of the live data. Runs during the foundation
phase, once the `sft_` tables exist.

**Scope:** only recipes with `collection = 'senior-family'`. **Living Lean recipes
(`collection = 'living-lean'`) are not imported.**

## 1. Source

The Senior Family Cookbook's tables are already in the MyDataWorld database, so the import is
a one-time server-side PHP script (`api/tools/import-senior-family.php`). It reads
`cookbook_recipes`, `cookbook_tags` and `cookbook_recipe_tags` and writes `sft_` rows. Nothing
is downloaded or uploaded, and the source tables are only read, never changed.

Live data as of 2026-11-19:

| Item                         | Count |
| ---------------------------- | ----- |
| Recipes                      | 190   |
| Ingredient lines             | 1,838 |
| Lines with no quantity       | 92 ("to taste", "as needed", …) |
| Lines with a note            | 884 ("chopped", "75% lean", …) |
| Recipes with a story         | 170   |
| Recipes with nutrition text  | 7     |
| Recipes with prep/cook times | 2     |
| Distinct tags                | ~260  |
| Photos                       | none (the old app has no photos) |

## 2. What gets created

- One cookbook, **Senior Family Cookbook**, owned by the person who runs the import.
- Each recipe: one `sft_recipes` row, linked into that cookbook, with the importer as its
  `co_owner`.
- Each recipe gets a first revision ("Imported from Senior Family Cookbook #93") and an
  `sft_recipe_imports` row holding the **original source row as JSON**, so nothing from the old
  data is lost even where mapping had to make a choice.
- Other family members are added afterwards through normal sharing (cookbook viewers or
  editors, recipe co-owners or editors) — the import doesn't guess who should have what.

## 3. Field mapping

| Senior Family field   | The Senior Family Table                                             |
| --------------------- | ------------------------------------------------------------------- |
| `name`                | `title`                                                             |
| `baseServings`        | `servings`                                                          |
| `story`               | `description` (it's the "About" text: who made it, where it's from) |
| `notes`               | `general_notes`                                                     |
| `nutrition`           | appended to `general_notes` under a "Nutrition:" line              |
| `prepTime`/`cookTime`/`totalTime` | parsed to minutes ("2 hours 35 minutes" → 155); kept in notes if unparseable |
| `tested`              | not used (no recipe is marked tested)                               |
| `category` + `tags`   | categories/tags (§5)                                                |
| `steps[]`             | `sft_recipe_steps`, in order, no cooking method                     |
| `ingredients[]`       | `sft_recipe_ingredients` (§4)                                       |

## 4. Ingredient lines

Each source line is `{ingredient, quantity, unit, notes}`. Mapping:

- `original_text` is rebuilt as the line reads in the old app (`1 cup flour (sifted)`).
- `item_name` ← `ingredient`; `preparation` ← `notes`; `quantity` ← `quantity`.
- `unit` goes through the same synonym table as the shopping list (`cups`/`Cups`/`cup` → cup,
  `Tbsp`/`tablespoons` → tbsp, `pounds`/`lb` → lb, `qt` → quart, `gal` → gallon …).
- **Package units:** `can (15 oz)`, `pkg (8 oz)`, `cans (14.5 oz)` → unit `can`/`package` with
  `package_qualifier` "15 oz", so two 15-oz cans combine on a list but a 15-oz and a 28-oz don't.
- **Size words used as units** (`medium`, `large`, `small`, `whole`, `each`): the line becomes a
  plain count ("1 medium onion" → 1 × onion), and the size word is kept at the front of
  `preparation` ("medium, chopped"), so onions from several recipes combine on a list.
- **Words that aren't units** (`to taste`, `as needed`, `for serving`, `for topping`,
  `for slurry`, `drizzle`, `a splash` …): no quantity, unit cleared, the phrase kept in
  `preparation`. Quantities are never invented.
- `optional` as the unit → `is_optional = 1`.
- `1–2` → `quantity` 1, `quantity_max` 2.
- Catalog matching uses the same exact-alias rule as everywhere else; unmatched lines import
  fine and simply aren't linked to a catalog ingredient.

## 5. Tags

- **Mapped to built-in categories/tags** (so filters work across all cookbooks):
  Main Dish, Main Course → **Main Dishes** · Side, Side Dish → **Side Dishes** · Soup · Salad ·
  Breakfast · Appetizer · Dessert · Instant Pot · Crock Pot, Slow Cooker → **Slow Cooker** ·
  Stovetop, Stove Top → **Stovetop** · Freezer-Friendly → **Freezer Friendly** ·
  High-Protein → **High Protein** · Gluten Free · Family Favorite.
- **Near-duplicates merged:** Make-Ahead + Make Ahead; Crowd Size + Crowd Sized;
  Bean + Beans; Potato + Potatoes; Cupcake + Cupcakes.
- **Everything else** (Beef, Italian, Holiday, Hamburger Helper, Venezuelan, …) becomes a
  tag scoped to the Senior Family Cookbook, so everyone it's shared with can see and filter by it.
- The one-off `category` values (Salad, Side Dish) are treated like tags.

## 6. Rows that need a decision (found in the live data)

| Recipe                                 | Problem                                               | Plan                     |
| -------------------------------------- | ----------------------------------------------------- | ------------------------ |
| Refried Beans with Lard (Instant Pot)  | Chili powder and cumin quantities are stored as the date `2026-01-02` — a spreadsheet turned "1/2" into January 2 | Import with **no quantity** and flag; almost certainly ½ Tbsp each — **confirm** |
| (lemon juice line)                     | `4` · unit `the` · note `tsp` — unit and note swapped | Import as 4 tsp fresh lemon juice |
| Rice Pilaf (DUP 2021)                  | Title and a `DUP` tag mark it as a duplicate          | **Skip it?** — confirm   |
| Cilantro "a lot!"                      | Unit is a comment                                     | No quantity; note kept   |
| 46 recipes tagged **Nourish Forward**  | Tag from the retired app                              | Keep as a tag (useful filter) unless you'd rather drop it |

Every flagged line also gets `needs_review` noted in the import record, and the script prints a
report of all of them.

## 7. Running it

1. Dry run: `php import-senior-family.php --dry-run` (or the admin-only browser URL if Turbify
   has no SSH) — prints counts and the flagged-line report, writes nothing.
2. Real run: everything in **one transaction** — either all 190 recipes import or none do.
3. **Re-runnable safely:** each import record stores `source_ref = senior-family-cookbook:<id>`
   (unique), so a second run skips recipes already imported instead of duplicating them.
4. Verify: 190 (or 189) recipes in the new cookbook, ingredient and step counts match the
   source, spot-check five recipes side by side with the old app.

## 8. After the import

The old Senior Family Cookbook app keeps working, untouched. Edits made there afterwards do
**not** flow into The Senior Family Table. Once you're happy with the new app, decide whether
to retire the old one or keep it as a public read-only view.

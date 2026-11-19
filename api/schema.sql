-- Recipe Book — schema (DRAFT, foundation phase)
--
-- Lives in the MyDataWorld database alongside the Hub's `users`, `sessions`, `apps`,
-- `app_access` tables. All Recipe Book tables are prefixed rb_. Design: docs/ARCHITECTURE.md.
-- Requires the Hub schema (users.id INT) to exist first.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Admins (approve system-wide catalog entries)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_admins (
  user_id     INT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_rb_admins_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Cookbooks
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_cookbooks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(150) NOT NULL,
  description  TEXT NULL,
  created_by   INT NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  archived_at  DATETIME NULL,
  deleted_at   DATETIME NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_rb_cookbooks_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_cookbook_members (
  cookbook_id  INT UNSIGNED NOT NULL,
  user_id      INT NOT NULL,
  role         ENUM('owner','editor','viewer') NOT NULL,
  added_by     INT NULL,
  added_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cookbook_id, user_id),
  KEY ix_rb_cbm_user (user_id),
  CONSTRAINT fk_rb_cbm_cookbook FOREIGN KEY (cookbook_id) REFERENCES rb_cookbooks (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_cbm_user     FOREIGN KEY (user_id)     REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Recipes
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_recipes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title            VARCHAR(200) NOT NULL,
  title_normalized VARCHAR(200) NOT NULL,   -- lowercase, punctuation stripped; duplicate check
  description      TEXT NULL,
  servings         DECIMAL(8,2) NULL,
  yield_quantity   DECIMAL(8,2) NULL,       -- e.g. 1
  yield_unit       VARCHAR(50)  NULL,       -- e.g. "pan", "loaf", "dozen cookies"
  prep_minutes     INT UNSIGNED NULL,
  cook_minutes     INT UNSIGNED NULL,
  total_minutes    INT UNSIGNED NULL,
  source_text      VARCHAR(500) NULL,       -- attribution ("Grandma Senior", a book, a site)
  general_notes    TEXT NULL,               -- storage, garnish, etc. from the recipe itself
  version          INT UNSIGNED NOT NULL DEFAULT 1,   -- optimistic locking for saves
  created_by       INT NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by       INT NOT NULL,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at       DATETIME NULL,           -- 30-day restore window
  deleted_by       INT NULL,
  PRIMARY KEY (id),
  KEY ix_rb_recipes_title (title_normalized),
  CONSTRAINT fk_rb_recipes_creator FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_rb_recipes_updater FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_members (
  recipe_id  INT UNSIGNED NOT NULL,
  user_id    INT NOT NULL,
  role       ENUM('co_owner','editor','viewer') NOT NULL,
  added_by   INT NULL,
  added_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (recipe_id, user_id),
  KEY ix_rb_rm_user (user_id),
  CONSTRAINT fk_rb_rm_recipe FOREIGN KEY (recipe_id) REFERENCES rb_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_rm_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_cookbook_recipes (
  cookbook_id    INT UNSIGNED NOT NULL,
  recipe_id      INT UNSIGNED NOT NULL,
  display_order  INT NOT NULL DEFAULT 0,
  added_by       INT NOT NULL,
  added_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cookbook_id, recipe_id),         -- the unique pair
  KEY ix_rb_cbr_recipe (recipe_id),
  CONSTRAINT fk_rb_cbr_cookbook FOREIGN KEY (cookbook_id) REFERENCES rb_cookbooks (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_cbr_recipe   FOREIGN KEY (recipe_id)   REFERENCES rb_recipes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Ingredient catalog
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_ingredients (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  canonical_name     VARCHAR(150) NOT NULL,
  normalized_name    VARCHAR(150) NOT NULL,
  scope              ENUM('system','user') NOT NULL,
  owner_user_id      INT NULL,                 -- set when scope = 'user'
  status             ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  default_department VARCHAR(50) NULL,         -- one of the Senior Family department names
  dimension          ENUM('volume','mass','count','package','unknown') NOT NULL DEFAULT 'unknown',
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rb_ingredients_name (scope, owner_user_id, normalized_name),
  CONSTRAINT fk_rb_ingredients_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_ingredient_aliases (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ingredient_id    INT UNSIGNED NOT NULL,
  alias_text       VARCHAR(150) NOT NULL,
  normalized_alias VARCHAR(150) NOT NULL,     -- exact-match key; no fuzzy matching
  scope            ENUM('system','user') NOT NULL,
  owner_user_id    INT NULL,
  status           ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rb_alias (scope, owner_user_id, normalized_alias),
  KEY ix_rb_alias_lookup (normalized_alias),
  CONSTRAINT fk_rb_alias_ingredient FOREIGN KEY (ingredient_id) REFERENCES rb_ingredients (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_alias_owner      FOREIGN KEY (owner_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Recipe contents
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_recipe_ingredients (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id          INT UNSIGNED NOT NULL,
  position           INT NOT NULL,
  section            VARCHAR(100) NULL,       -- "For serving", "For the sauce"
  original_text      VARCHAR(500) NOT NULL,   -- the line as written
  quantity           DECIMAL(12,4) NULL,      -- NULL = no quantity ("to taste"); never invented
  quantity_max       DECIMAL(12,4) NULL,      -- upper end of a range ("2-3")
  unit               VARCHAR(30) NULL,        -- normalized unit ("tbsp", "cup", "can")
  unit_dimension     ENUM('volume','mass','count','package','unknown') NOT NULL DEFAULT 'unknown',
  package_qualifier  VARCHAR(50) NULL,        -- "28 oz" in "1 (28 oz) can"
  item_name          VARCHAR(200) NOT NULL,   -- "crushed tomatoes"
  ingredient_id      INT UNSIGNED NULL,       -- set only on a confident catalog match
  preparation        VARCHAR(200) NULL,       -- "diced", "room temperature"
  is_optional        TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_rb_ri_recipe (recipe_id, position),
  KEY ix_rb_ri_ingredient (ingredient_id),
  CONSTRAINT fk_rb_ri_recipe     FOREIGN KEY (recipe_id)     REFERENCES rb_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_ri_ingredient FOREIGN KEY (ingredient_id) REFERENCES rb_ingredients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_methods (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id    INT UNSIGNED NOT NULL,
  method_name  VARCHAR(100) NOT NULL,   -- "Instant Pot", "Slow Cooker", "Stovetop"
  sequence     INT NOT NULL,
  PRIMARY KEY (id),
  KEY ix_rb_methods_recipe (recipe_id),
  CONSTRAINT fk_rb_methods_recipe FOREIGN KEY (recipe_id) REFERENCES rb_recipes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_steps (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id    INT UNSIGNED NOT NULL,
  method_id    INT UNSIGNED NULL,       -- NULL = applies to every method
  step_number  INT NOT NULL,
  instruction  TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY ix_rb_steps_recipe (recipe_id, method_id, step_number),
  CONSTRAINT fk_rb_steps_recipe FOREIGN KEY (recipe_id) REFERENCES rb_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_steps_method FOREIGN KEY (method_id) REFERENCES rb_recipe_methods (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_photos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id    INT UNSIGNED NOT NULL,
  storage_key  CHAR(32) NOT NULL,       -- random hex; files <key>_full.jpg / <key>_thumb.jpg
  caption      VARCHAR(255) NULL,
  is_cover     TINYINT(1) NOT NULL DEFAULT 0,
  position     INT NOT NULL DEFAULT 0,
  width        INT UNSIGNED NULL,
  height       INT UNSIGNED NULL,
  uploaded_by  INT NOT NULL,
  uploaded_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rb_photos_key (storage_key),
  KEY ix_rb_photos_recipe (recipe_id, position),
  CONSTRAINT fk_rb_photos_recipe   FOREIGN KEY (recipe_id)   REFERENCES rb_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_photos_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Categories and tags
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_categories (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(80) NOT NULL,
  normalized_name  VARCHAR(80) NOT NULL,
  kind             ENUM('category','method','descriptor') NOT NULL,
  scope            ENUM('system','user','cookbook') NOT NULL,
  owner_user_id    INT NULL,                   -- scope = 'user'
  cookbook_id      INT UNSIGNED NULL,          -- scope = 'cookbook'
  -- One generated key so the unique index works for every scope (NULLs don't collide in MySQL).
  scope_key        VARCHAR(30) AS (CONCAT(scope, ':', COALESCE(owner_user_id, cookbook_id, 0))) STORED,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rb_categories_name (scope_key, normalized_name),
  CONSTRAINT fk_rb_categories_owner    FOREIGN KEY (owner_user_id) REFERENCES users (id),
  -- No ON DELETE CASCADE: MySQL forbids it on a column used by a generated column.
  -- Cookbooks are soft-deleted; the purge job removes their tags first.
  CONSTRAINT fk_rb_categories_cookbook FOREIGN KEY (cookbook_id)   REFERENCES rb_cookbooks (id)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_categories (
  recipe_id    INT UNSIGNED NOT NULL,
  category_id  INT UNSIGNED NOT NULL,
  added_by     INT NOT NULL,
  PRIMARY KEY (recipe_id, category_id),
  KEY ix_rb_rc_category (category_id),
  CONSTRAINT fk_rb_rc_recipe   FOREIGN KEY (recipe_id)   REFERENCES rb_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_rc_category FOREIGN KEY (category_id) REFERENCES rb_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Notes, personal data, history
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_recipe_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id   INT UNSIGNED NOT NULL,
  user_id     INT NOT NULL,                       -- author
  visibility  ENUM('shared','private') NOT NULL,  -- private: returned ONLY to user_id
  note_text   TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  DATETIME NULL,
  PRIMARY KEY (id),
  KEY ix_rb_notes_recipe (recipe_id, visibility),
  KEY ix_rb_notes_user (user_id),
  CONSTRAINT fk_rb_notes_recipe FOREIGN KEY (recipe_id) REFERENCES rb_recipes (id),
  CONSTRAINT fk_rb_notes_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_note_photos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  note_id      INT UNSIGNED NOT NULL,
  storage_key  CHAR(32) NOT NULL,
  uploaded_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rb_note_photos_key (storage_key),
  CONSTRAINT fk_rb_note_photos_note FOREIGN KEY (note_id) REFERENCES rb_recipe_notes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal, never shown to others: favorite, rating, Tested & Approved, saved scale.
CREATE TABLE IF NOT EXISTS rb_recipe_user_state (
  recipe_id        INT UNSIGNED NOT NULL,
  user_id          INT NOT NULL,
  is_favorite      TINYINT(1) NOT NULL DEFAULT 0,
  rating           TINYINT UNSIGNED NULL,          -- 1–5
  tested_approved  TINYINT(1) NOT NULL DEFAULT 0,
  saved_scale_mode ENUM('servings','batches') NULL,
  saved_scale      DECIMAL(8,2) NULL,              -- servings or batches wanted
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (recipe_id, user_id),
  KEY ix_rb_rus_user (user_id, is_favorite),
  CONSTRAINT fk_rb_rus_recipe FOREIGN KEY (recipe_id) REFERENCES rb_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_rus_user   FOREIGN KEY (user_id)   REFERENCES users (id),
  CONSTRAINT ck_rb_rus_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_cooking_history (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id  INT UNSIGNED NOT NULL,
  user_id    INT NOT NULL,
  cooked_on  DATE NOT NULL,
  remarks    VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rb_cooked_user (user_id, recipe_id, cooked_on),
  CONSTRAINT fk_rb_cooked_recipe FOREIGN KEY (recipe_id) REFERENCES rb_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_cooked_user   FOREIGN KEY (user_id)   REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_revisions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id    INT UNSIGNED NOT NULL,
  version      INT UNSIGNED NOT NULL,      -- rb_recipes.version this snapshot represents
  snapshot     MEDIUMTEXT NOT NULL,        -- JSON: fields, ingredients, methods, steps, tags
  change_note  VARCHAR(255) NULL,          -- "Imported", "Restored version 4", …
  changed_by   INT NOT NULL,
  changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rb_revisions (recipe_id, version),
  CONSTRAINT fk_rb_revisions_recipe FOREIGN KEY (recipe_id)  REFERENCES rb_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_revisions_user   FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_recipe_imports (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id       INT UNSIGNED NULL,       -- NULL if the import was abandoned or linked to an existing recipe
  raw_text        MEDIUMTEXT NOT NULL,
  parser_version  VARCHAR(20) NOT NULL,
  parsed_json     MEDIUMTEXT NULL,         -- what the parser produced
  corrections     MEDIUMTEXT NULL,         -- what the user changed on the review screen
  outcome         ENUM('created','linked_existing','abandoned') NOT NULL,
  imported_by     INT NOT NULL,
  imported_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rb_imports_recipe (recipe_id),
  CONSTRAINT fk_rb_imports_recipe FOREIGN KEY (recipe_id)   REFERENCES rb_recipes (id) ON DELETE SET NULL,
  CONSTRAINT fk_rb_imports_user   FOREIGN KEY (imported_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Shopping lists (ARCHITECTURE.md §6 — the Senior Family Cookbook model, server-side)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_stores (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(80) NOT NULL,
  scope          ENUM('system','user') NOT NULL,
  owner_user_id  INT NULL,
  sort_order     INT NOT NULL DEFAULT 0,
  scope_key      VARCHAR(30) AS (CONCAT(scope, ':', COALESCE(owner_user_id, 0))) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rb_stores_name (scope_key, name),
  CONSTRAINT fk_rb_stores_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_shopping_lists (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title         VARCHAR(150) NOT NULL,     -- the purpose: "Weekly Groceries", "Christmas Social"
  status        ENUM('active','completed') NOT NULL DEFAULT 'active',
  change_seq    BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- bumped on every item change (sync)
  created_by    INT NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at  DATETIME NULL,
  deleted_at    DATETIME NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_rb_lists_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_shopping_list_members (
  list_id   INT UNSIGNED NOT NULL,
  user_id   INT NOT NULL,
  role      ENUM('owner','editor','viewer') NOT NULL,
  added_by  INT NULL,
  added_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (list_id, user_id),
  KEY ix_rb_slm_user (user_id),
  CONSTRAINT fk_rb_slm_list FOREIGN KEY (list_id) REFERENCES rb_shopping_lists (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_slm_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Recipes on this list": the Senior Family favorites + per-favorite scale + "have it"
-- exclusions, but per list.
CREATE TABLE IF NOT EXISTS rb_shopping_list_recipes (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  list_id                INT UNSIGNED NOT NULL,
  recipe_id              INT UNSIGNED NOT NULL,
  scale_mode             ENUM('servings','batches') NOT NULL,
  scale_value            DECIMAL(8,2) NOT NULL,     -- servings or batches requested
  scale_factor           DECIMAL(10,4) NOT NULL,    -- value ÷ the recipe's base, at time of adding
  excluded_ingredient_ids TEXT NULL,                -- JSON array of rb_recipe_ingredients.id ("have it")
  added_by               INT NOT NULL,
  added_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rb_slr (list_id, recipe_id),
  CONSTRAINT fk_rb_slr_list   FOREIGN KEY (list_id)   REFERENCES rb_shopping_lists (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_slr_recipe FOREIGN KEY (recipe_id) REFERENCES rb_recipes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rb_shopping_items (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  list_id            INT UNSIGNED NOT NULL,
  ingredient_id      INT UNSIGNED NULL,
  merge_key          VARCHAR(200) NULL,        -- "ing:42|volume" or "name:onion|count"; NULL = never auto-merge
  label              VARCHAR(200) NOT NULL,
  quantity           DECIMAL(12,4) NULL,       -- NULL or 0 = "don't need" (hidden in shopping mode/print)
  unit               VARCHAR(30) NULL,
  notes              VARCHAR(255) NULL,
  department         VARCHAR(50) NOT NULL DEFAULT 'Other',
  department_manual  TINYINT(1) NOT NULL DEFAULT 0,   -- a hand-picked department is never re-guessed
  store_id           INT UNSIGNED NULL,               -- NULL = Unassigned
  assigned_user_id   INT NULL,
  is_manual          TINYINT(1) NOT NULL DEFAULT 0,   -- added by hand; survives "Rebuild from recipes"
  is_edited          TINYINT(1) NOT NULL DEFAULT 0,   -- hand-edited since generated
  needs_review       TINYINT(1) NOT NULL DEFAULT 0,   -- "check quantity" after a source recipe was removed
  checked            TINYINT(1) NOT NULL DEFAULT 0,
  checked_by         INT NULL,
  checked_at         DATETIME NULL,
  position           INT NOT NULL DEFAULT 0,
  row_version        INT UNSIGNED NOT NULL DEFAULT 1,   -- optimistic locking for field edits
  changed_seq        BIGINT UNSIGNED NOT NULL DEFAULT 0, -- list change_seq at last change (sync)
  updated_by         INT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at         DATETIME NULL,                     -- soft delete so deletions sync
  PRIMARY KEY (id),
  KEY ix_rb_items_list_seq (list_id, changed_seq),
  KEY ix_rb_items_merge (list_id, merge_key),
  CONSTRAINT fk_rb_items_list       FOREIGN KEY (list_id)          REFERENCES rb_shopping_lists (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_items_ingredient FOREIGN KEY (ingredient_id)    REFERENCES rb_ingredients (id),
  CONSTRAINT fk_rb_items_store      FOREIGN KEY (store_id)         REFERENCES rb_stores (id) ON DELETE SET NULL,
  CONSTRAINT fk_rb_items_assignee   FOREIGN KEY (assigned_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which recipe lines fed each item, and how much each contributed (in the item's unit),
-- so a recipe can be removed from a list and items split back apart.
CREATE TABLE IF NOT EXISTS rb_shopping_item_sources (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id               INT UNSIGNED NOT NULL,
  list_recipe_id        INT UNSIGNED NOT NULL,
  recipe_ingredient_id  INT UNSIGNED NOT NULL,
  contributed_quantity  DECIMAL(12,4) NULL,
  contributed_unit      VARCHAR(30) NULL,
  PRIMARY KEY (id),
  KEY ix_rb_sources_item (item_id),
  KEY ix_rb_sources_list_recipe (list_recipe_id),
  CONSTRAINT fk_rb_sources_item        FOREIGN KEY (item_id)              REFERENCES rb_shopping_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_sources_list_recipe FOREIGN KEY (list_recipe_id)       REFERENCES rb_shopping_list_recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rb_sources_ingredient  FOREIGN KEY (recipe_ingredient_id) REFERENCES rb_recipe_ingredients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Audit
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rb_audit_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id  INT NULL,
  resource_type  ENUM('cookbook','recipe','shopping_list','note','photo','catalog') NOT NULL,
  resource_id    INT UNSIGNED NOT NULL,
  action         VARCHAR(50) NOT NULL,    -- member_added, role_changed, member_removed, deleted, restored, revision_restored…
  detail         VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rb_audit_resource (resource_type, resource_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO rb_categories (name, normalized_name, kind, scope) VALUES
  ('Main Dishes','main dishes','category','system'),
  ('Side Dishes','side dishes','category','system'),
  ('Soup','soup','category','system'),
  ('Salad','salad','category','system'),
  ('Breakfast','breakfast','category','system'),
  ('Appetizer','appetizer','category','system'),
  ('Dessert','dessert','category','system'),
  ('Instant Pot','instant pot','method','system'),
  ('Slow Cooker','slow cooker','method','system'),
  ('Air Fryer','air fryer','method','system'),
  ('Oven','oven','method','system'),
  ('Stovetop','stovetop','method','system'),
  ('Freezer Friendly','freezer friendly','descriptor','system'),
  ('High Protein','high protein','descriptor','system'),
  ('Gluten Free','gluten free','descriptor','system'),
  ('Family Favorite','family favorite','descriptor','system'),
  ('Tested & Approved','tested & approved','descriptor','system');

INSERT IGNORE INTO rb_stores (name, scope, sort_order) VALUES
  ('Costco','system',1),
  ('Sam''s Club','system',2),
  ('Walmart','system',3);

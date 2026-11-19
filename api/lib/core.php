<?php
// Database helpers, auth, and the permission rules (docs/ARCHITECTURE.md §3.2).
// Shared by api.php and the command-line tools. Never called directly over the web
// (api/lib/.htaccess denies it).

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const APP_KEY = 'senior-family-table';

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
    $conn->set_charset('utf8mb4');
  }
  return $conn;
}

// Runs a prepared statement and returns all rows (empty array for non-SELECT).
function q(string $sql, string $types = '', array $params = []): array {
  $stmt = db()->prepare($sql);
  if ($types !== '') { $stmt->bind_param($types, ...$params); }
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}

// Runs a prepared statement and returns the insert id (0 if none).
function exec_(string $sql, string $types = '', array $params = []): int {
  $stmt = db()->prepare($sql);
  if ($types !== '') { $stmt->bind_param($types, ...$params); }
  $stmt->execute();
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

function q1(string $sql, string $types = '', array $params = []): ?array {
  $rows = q($sql, $types, $params);
  return $rows ? $rows[0] : null;
}

// Thrown for anything the caller should see as a normal error (not a 500).
final class AppError extends Exception {
  public int $status;
  public ?string $field;
  public function __construct(string $message, int $status = 400, ?string $field = null) {
    parent::__construct($message);
    $this->status = $status;
    $this->field = $field;
  }
}

function fail(string $message, int $status = 400, ?string $field = null): void {
  throw new AppError($message, $status, $field);
}

// Guessed or forbidden IDs both look like "not found", so existence isn't leaked.
function notFound(string $what): void {
  fail($what . ' not found', 404);
}

// ---------------------------------------------------------------------------
// Auth: shared MyDataWorld (Hub) login + an app_access grant
// ---------------------------------------------------------------------------

function userFromToken(string $token): ?array {
  return q1(
    'SELECT u.id, u.username, u.display_name, u.is_admin FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()', 's', [$token]
  );
}

function hasAppAccess(int $userId): bool {
  return (bool)q(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id WHERE aa.user_id = ? AND a.app_key = ?',
    'is', [$userId, APP_KEY]
  );
}

// Inviting someone to a cookbook/recipe/list also lets them into the app.
function grantAppAccess(int $userId): void {
  exec_(
    'INSERT INTO app_access (user_id, app_id)
     SELECT ?, a.id FROM apps a WHERE a.app_key = ?
     ON DUPLICATE KEY UPDATE user_id = app_access.user_id',
    'is', [$userId, APP_KEY]
  );
}

function userByUsername(string $username): ?array {
  return q1('SELECT id, username, display_name FROM users WHERE username = ?', 's', [trim($username)]);
}

function audit(?int $actorId, string $resourceType, int $resourceId, string $action, ?string $detail = null): void {
  exec_(
    'INSERT INTO sft_audit_events (actor_user_id, resource_type, resource_id, action, detail) VALUES (?, ?, ?, ?, ?)',
    'isiss', [$actorId, $resourceType, $resourceId, $action, $detail === null ? null : mb_substr($detail, 0, 500)]
  );
}

// ---------------------------------------------------------------------------
// Permissions (ARCHITECTURE.md §3.2). Every action goes through these.
// ---------------------------------------------------------------------------

function cookbookRole(int $userId, int $cookbookId): ?string {
  $row = q1(
    'SELECT m.role FROM sft_cookbook_members m JOIN sft_cookbooks c ON c.id = m.cookbook_id
     WHERE m.cookbook_id = ? AND m.user_id = ? AND c.deleted_at IS NULL',
    'ii', [$cookbookId, $userId]
  );
  return $row ? $row['role'] : null;
}

function requireCookbookRole(int $userId, int $cookbookId, array $roles): string {
  $role = cookbookRole($userId, $cookbookId);
  if ($role === null) { notFound('Cookbook'); }
  if (!in_array($role, $roles, true)) { fail('You don\'t have permission to do that in this cookbook', 403); }
  return $role;
}

function recipeRole(int $userId, int $recipeId): ?string {
  $row = q1(
    'SELECT m.role FROM sft_recipe_members m JOIN sft_recipes r ON r.id = m.recipe_id
     WHERE m.recipe_id = ? AND m.user_id = ? AND r.deleted_at IS NULL',
    'ii', [$recipeId, $userId]
  );
  return $row ? $row['role'] : null;
}

// Recipe members, or anyone in a live cookbook the recipe is linked into.
function canViewRecipe(int $userId, int $recipeId): bool {
  if (recipeRole($userId, $recipeId) !== null) { return true; }
  return (bool)q(
    'SELECT 1 FROM sft_cookbook_recipes cr
       JOIN sft_cookbooks c ON c.id = cr.cookbook_id AND c.deleted_at IS NULL
       JOIN sft_cookbook_members m ON m.cookbook_id = c.id AND m.user_id = ?
       JOIN sft_recipes r ON r.id = cr.recipe_id AND r.deleted_at IS NULL
     WHERE cr.recipe_id = ? LIMIT 1',
    'ii', [$userId, $recipeId]
  );
}

// Cookbook roles never grant editing of a master recipe.
function canEditRecipe(int $userId, int $recipeId): bool {
  return in_array(recipeRole($userId, $recipeId), ['co_owner', 'editor'], true);
}

function requireViewRecipe(int $userId, int $recipeId): void {
  if (!canViewRecipe($userId, $recipeId)) { notFound('Recipe'); }
}

// The SQL fragment used by every recipe listing/search, so listings can never
// show a recipe that canViewRecipe() would refuse. Bind the user id twice.
const VISIBLE_RECIPE_SQL = '(r.deleted_at IS NULL AND (
    EXISTS (SELECT 1 FROM sft_recipe_members rm WHERE rm.recipe_id = r.id AND rm.user_id = ?)
    OR EXISTS (SELECT 1 FROM sft_cookbook_recipes cr
                 JOIN sft_cookbooks c ON c.id = cr.cookbook_id AND c.deleted_at IS NULL
                 JOIN sft_cookbook_members cm ON cm.cookbook_id = c.id AND cm.user_id = ?
               WHERE cr.recipe_id = r.id)))';

// ---------------------------------------------------------------------------
// Text helpers
// ---------------------------------------------------------------------------

function normalizeName(string $s): string {
  $s = mb_strtolower(trim($s));
  return preg_replace('/\s+/u', ' ', $s);
}

// For duplicate-title checks: lowercase, punctuation stripped, spaces collapsed.
function normalizeTitle(string $s): string {
  $s = mb_strtolower($s);
  $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
  return trim(preg_replace('/\s+/u', ' ', $s));
}

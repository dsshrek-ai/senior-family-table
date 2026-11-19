# Putting The Senior Family Table online

Same pattern as Quill Clock and the other MyDataWorld apps: the pages are on GitHub Pages,
the API is on seniorfamily.org, and the data is in the MyDataWorld database.

## 1. Database (phpMyAdmin, MyDataWorld database)

1. Run `api/schema.sql` in the SQL tab. It creates the `sft_` tables, the built-in categories
   and stores, and the My Apps Hub tile.
2. Give yourself access (edit the email):
   ```sql
   INSERT INTO app_access (user_id, app_id)
   SELECT u.id, a.id FROM users u JOIN apps a ON a.app_key = 'senior-family-table'
   WHERE u.username = 'you@example.com'
   ON DUPLICATE KEY UPDATE user_id = app_access.user_id;
   ```
   Everyone else gets access automatically when you share a cookbook or recipe with them.

## 2. API (cPanel File Manager or FTP)

1. Create the folder `public_html/senior-family-table-api/`.
2. Upload `api/api.php` and the `api/lib/` and `api/tools/` folders, **including the
   `.htaccess` files** in `lib/` and `tools/` (they keep those folders off the web).
3. Copy `api/config.example.php` to `config.php` **on the server**, fill in the MyDataWorld
   database details (the same ones the other apps use), and leave `ALLOWED_ORIGIN` as
   `https://dsshrek-ai.github.io`.
4. Check it: opening `https://seniorfamily.org/senior-family-table-api/api.php?action=whoAmI`
   should show `{"ok":false,"error":"Please sign in"}`.

Never upload a `config.php` from your own computer's copy of the repo.

## 3. Import the Senior Family Cookbook (once)

Recipes come from the old app's tables in the same database (Living Lean is never touched).
Sign in to the app first, then in the browser's developer console on any app page run:

```js
await apiPost('adminImportSeniorFamily', { dryRun: true })   // checks everything, saves nothing
await apiPost('adminImportSeniorFamily', { dryRun: false })  // the real import
```

The first shows the report (190 recipes, the flagged lines). Running the real import twice
is harmless — the second run imports nothing. Only a Hub admin can run it. Details:
[docs/IMPORT-SENIOR-FAMILY.md](docs/IMPORT-SENIOR-FAMILY.md).

## 4. Pages

In the GitHub repo: **Settings → Pages → Deploy from a branch → main / (root)**. The app is
then at https://dsshrek-ai.github.io/senior-family-table/ and opens from its Hub tile.

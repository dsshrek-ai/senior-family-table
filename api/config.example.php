<?php
// Copy to config.php, fill in, and upload to seniorfamily.org/senior-family-table-api/.
// config.php is gitignored. Uses the shared MyDataWorld database (Hub users/sessions + sft_ tables).

define('DB_HOST', 'localhost');
define('DB_NAME', 'PUT_YOUR_ACCOUNT_PREFIX_HERE_MyDataWorld');
define('DB_USER', 'PUT_YOUR_DB_USERNAME_HERE');
define('DB_PASS', 'PUT_YOUR_DB_PASSWORD_HERE');

// The only browser origin allowed to call the API (the GitHub Pages site).
define('ALLOWED_ORIGIN', 'https://dsshrek-ai.github.io');

// Recipe and note photos. Must be OUTSIDE public_html and writable by PHP.
define('PHOTO_DIR', '/home/PUT_ACCOUNT_HERE/senior-family-table-photos');
define('PHOTO_MAX_UPLOAD_MB', 15);

// Signs the expiring photo URLs. Generate with:
//   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
define('PHOTO_URL_KEY', 'PUT_A_RANDOM_BASE64_SECRET_HERE');
define('PHOTO_URL_TTL_SECONDS', 3600);

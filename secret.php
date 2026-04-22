<?php
// secrets.php - store securely, chmod 600, do NOT commit to VCS.
// Generate a key on the server, e.g.:
//   openssl rand -base64 32
// Paste that string below as ENCRYPTION_KEY.

if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', 'replace_with_your_base64_or_random_secret_here');
}
<?php
/**
 * Loads SMTP credentials from the .env file at the project root.
 * The .env file is gitignored — credentials are never committed to source control.
 *
 * To set up:
 *   1. Copy .env.example → .env
 *   2. Generate a Yahoo App Password at https://login.yahoo.com/security/app-passwords
 *   3. Set SMTP_PASSWORD in your .env file
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$dotenv->required([
    'SMTP_HOST', 'SMTP_USERNAME', 'SMTP_PASSWORD',
    'SMTP_PORT', 'SMTP_FROM', 'SMTP_FROM_NAME',
    'MAIL_TO',   'MAIL_TO_NAME',
    'SITE_URL',  'SLOT_SECRET',
]);
$dotenv->ifPresent('MAIL_DEBUG')->isInteger();

define('SMTP_HOST',      $_ENV['SMTP_HOST']);
define('SMTP_USERNAME',  $_ENV['SMTP_USERNAME']);
define('SMTP_PASSWORD',  $_ENV['SMTP_PASSWORD']);
define('SMTP_PORT',      (int) $_ENV['SMTP_PORT']);
define('SMTP_FROM',      $_ENV['SMTP_FROM']);
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME']);
define('MAIL_TO',        $_ENV['MAIL_TO']);
define('MAIL_TO_NAME',   $_ENV['MAIL_TO_NAME']);
define('MAIL_DEBUG',     (int) ($_ENV['MAIL_DEBUG'] ?? 0));
define('SITE_URL',       rtrim($_ENV['SITE_URL'], '/'));
define('SLOT_SECRET',    $_ENV['SLOT_SECRET']);

<?php
// ---- Basic app settings ----
define('APP_NAME', 'Ava D Creates');
define('APP_URL',  'https://avadcreates.com'); // <-- set to your domain
define('APP_ENV',  'production');// 'development' or 'production'

define('PROJECT_ROOT', __DIR__);
define('PUBLIC_PATH', PROJECT_ROOT . '/public');   // your webroot
define('UPLOAD_PATH', PUBLIC_PATH . '/uploads');   // filesystem folder where files are written
define('UPLOAD_URL',  '/uploads');

// ---- Database (match your MySQL creds / DB name) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'ava_d_creates');
define('DB_USER', '');
define('DB_PASS', ''); // change in production

// ---- Stripe ----

define('STRIPE_SECRET_KEY',      ');
define('STRIPE_PUBLISHABLE_KEY', '');
define('STRIPE_WEBHOOK_SECRET',  '');

// Email (fill these in)
define('MAIL_FROM',      'ava@avadcreates.com');
define('MAIL_FROM_NAME', 'Ava D Creates');
define('MAIL_BCC',       'ava@avadcreates.com');

// Optional SMTP (recommended). If you don’t set these, code will fallback to mail()
define('SMTP_HOST',  'mail.privateemail.com');   // e.g., smtp.gmail.com or your provider
define('SMTP_PORT',  587);
define('SMTP_USER',  'ava@avadcreates.com');
define('SMTP_PASS',  '');
define('SMTP_SECURE','tls');                 // 'tls' or 'ssl' or ''


// ---- Security ----
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
session_start();

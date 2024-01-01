<?php

/**
 * Config File
 * 
 * Loads environment variables and sets up application config
 */

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }
}

// Required config
define('SHOPIFY_SHOP_DOMAIN', getenv('SHOPIFY_SHOP_DOMAIN') ?: $_ENV['SHOPIFY_SHOP_DOMAIN'] ?? '');
define('SHOPIFY_ACCESS_TOKEN', getenv('SHOPIFY_ACCESS_TOKEN') ?: $_ENV['SHOPIFY_ACCESS_TOKEN'] ?? '');
define('SHOPIFY_WEBHOOK_SECRET', getenv('SHOPIFY_WEBHOOK_SECRET') ?: $_ENV['SHOPIFY_WEBHOOK_SECRET'] ?? '');

// Optional config
define('LOG_FILE', getenv('LOG_FILE') ?: $_ENV['LOG_FILE'] ?? __DIR__ . '/../logs/app.log');
define('LOG_LEVEL', (int)(getenv('LOG_LEVEL') ?: $_ENV['LOG_LEVEL'] ?? 1));

// Validate required config
if (empty(SHOPIFY_SHOP_DOMAIN)) {
    throw new Exception('SHOPIFY_SHOP_DOMAIN is required');
}

if (empty(SHOPIFY_ACCESS_TOKEN)) {
    throw new Exception('SHOPIFY_ACCESS_TOKEN is required');
}

if (empty(SHOPIFY_WEBHOOK_SECRET)) {
    throw new Exception('SHOPIFY_WEBHOOK_SECRET is required');
}

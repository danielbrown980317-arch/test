<?php

/**
 * Webhook Handler
 * 
 * Handles orders/create webhook events
 */

// Load configuration
require_once __DIR__ . '/../config/config.php';

// Load classes
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/ShopifyAPI.php';
require_once __DIR__ . '/OrderEnricher.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Initialize logger
$logger = new Logger(LOG_FILE, Logger::LEVEL_INFO);

/**
 * Send HTTP response
 */
function sendResponse(int $statusCode, string $message = ''): void
{
    http_response_code($statusCode);
    if (!empty($message)) {
        echo json_encode(['message' => $message]);
    }
    exit;
}

/**
 * Main webhook processing
 */
try {
    // Get raw POST data (required for HMAC validation)
    $rawData = file_get_contents('php://input');
    
    if (empty($rawData)) {
        $logger->error("Empty webhook payload received");
        sendResponse(400, 'Empty payload');
    }

    // Get HMAC header
    $hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
    
    if (empty($hmacHeader)) {
        $logger->error("Missing HMAC header");
        sendResponse(401, 'Unauthorized');
    }

    // Validate HMAC signature
    if (!Validator::verifyWebhook($rawData, $hmacHeader, SHOPIFY_WEBHOOK_SECRET)) {
        $logger->error("Invalid HMAC signature");
        sendResponse(401, 'Unauthorized');
    }

    // Parse webhook data
    $webhookData = json_decode($rawData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $logger->error("Invalid JSON payload: " . json_last_error_msg());
        sendResponse(400, 'Invalid JSON');
    }

    // Check webhook topic
    $topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';
    
    if ($topic !== 'orders/create') {
        $logger->info("Ignoring webhook topic: {$topic}");
        sendResponse(200, 'Webhook received but not processed');
    }

    // Extract order ID
    $orderId = $webhookData['id'] ?? null;
    
    if (empty($orderId)) {
        $logger->error("Missing order ID in webhook payload");
        sendResponse(400, 'Missing order ID');
    }

    // Convert numeric ID to GID format if needed
    if (is_numeric($orderId)) {
        $orderId = "gid://shopify/Order/{$orderId}";
    }

    // Validate order ID format
    if (!Validator::validateOrderId($orderId)) {
        $logger->error("Invalid order ID format: {$orderId}");
        sendResponse(400, 'Invalid order ID format');
    }

    $logger->info("Processing webhook for order: {$orderId}");

    // Initialize Shopify API client
    $shopifyAPI = new ShopifyAPI(
        SHOPIFY_SHOP_DOMAIN,
        SHOPIFY_ACCESS_TOKEN,
        $logger
    );

    // Initialize order enricher
    $orderEnricher = new OrderEnricher($shopifyAPI, $logger);

    // Process order enrichment
    $success = $orderEnricher->enrichOrder($orderId);

    if ($success) {
        $logger->info("Successfully processed order: {$orderId}");
        sendResponse(200, 'Order processed successfully');
    } else {
        $logger->error("Failed to process order: {$orderId}");
        sendResponse(500, 'Order processing failed');
    }

} catch (Exception $e) {
    if (isset($logger)) {
        $logger->error("Exception in webhook handler: " . $e->getMessage());
        $logger->error("Stack trace: " . $e->getTraceAsString());
    }
    sendResponse(500, 'Internal server error');
}

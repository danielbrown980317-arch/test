<?php

/**
 * Validator Class
 * 
 * Handles HMAC validation and input sanitization for webhook security
 */
class Validator
{
    /**
     * Verify Shopify webhook HMAC signature
     * 
     * @param string $data Raw POST body data
     * @param string $hmacHeader HMAC signature from X-Shopify-Hmac-Sha256 header
     * @param string $secret Webhook secret from Shopify
     * @return bool True if valid, false otherwise
     */
    public static function verifyWebhook(string $data, string $hmacHeader, string $secret): bool
    {
        if (empty($data) || empty($hmacHeader) || empty($secret)) {
            return false;
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $secret, true));
        
        // Use hash_equals for timing-safe comparison
        return hash_equals($hmacHeader, $calculatedHmac);
    }

    /**
     * Validate externalId format
     * Examples: "Ackermans-EXPRESS-1569", "Ackermans-COLLECT-2014"
     * 
     * @param string $externalId The external ID to validate
     * @return bool True if valid format
     */
    public static function validateExternalIdFormat(string $externalId): bool
    {
        $parts = explode('-', $externalId);
        
        if (count($parts) !== 3) {
            return false;
        }

        // All parts should be non-empty
        foreach ($parts as $part) {
            if (empty(trim($part))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize input string
     * 
     * @param string $input Input to sanitize
     * @return string Sanitized string
     */
    public static function sanitize(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate order ID format (Shopify GID)
     * 
     * @param string $orderId Order ID in format gid://shopify/Order/123456
     * @return bool True if valid format
     */
    public static function validateOrderId(string $orderId): bool
    {
        return preg_match('/^gid:\/\/shopify\/Order\/\d+$/', $orderId) === 1;
    }
}

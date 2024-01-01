<?php

/**
 * OrderEnricher Class
 * 
 * Main business logic for enriching orders with pickup point information
 */
class OrderEnricher
{
    private $shopifyAPI;
    private $logger;

    public function __construct(ShopifyAPI $shopifyAPI, $logger = null)
    {
        $this->shopifyAPI = $shopifyAPI;
        $this->logger = $logger;
    }

    /**
     * Enrich order with pickup point information
     * 
     * @param string $orderId Shopify order GID (format: gid://shopify/Order/123456)
     * @return bool True on success, false on failure
     */
    public function enrichOrder(string $orderId): bool
    {
        try {
            if ($this->logger) {
                $this->logger->info("Starting order enrichment for: {$orderId}");
            }

            // Step 1: Fetch fulfillment data (unstable API)
            $fulfillmentData = $this->shopifyAPI->getFulfillmentOrders($orderId);

            // Step 2: Extract pickup point
            $pickupPoint = $this->extractPickupPoint($fulfillmentData);

            if (!$pickupPoint) {
                if ($this->logger) {
                    $this->logger->info("No pickup point found for order {$orderId}");
                }
                return true; // Not an error - just skip enrichment
            }

            // Step 3: Parse externalId
            $externalId = $pickupPoint['externalId'] ?? null;
            
            if (empty($externalId)) {
                if ($this->logger) {
                    $this->logger->warning("Pickup point found but externalId is missing for order {$orderId}");
                }
                return false;
            }

            $parsed = $this->parseExternalId($externalId);

            if (!$parsed) {
                if ($this->logger) {
                    $this->logger->warning("Invalid externalId format: {$externalId} for order {$orderId}");
                }
                return false;
            }

            if ($this->logger) {
                $this->logger->info("Parsed externalId: " . json_encode($parsed));
            }

            // Step 4: Update shipping line title
            $shippingUpdated = $this->updateShippingLineTitle($orderId, $parsed);
            
            if (!$shippingUpdated) {
                if ($this->logger) {
                    $this->logger->error("Failed to update shipping line for order {$orderId}");
                }
                return false;
            }

            // Step 5: Add metafields
            $metafieldsAdded = $this->addMetafields($orderId, $parsed);
            
            if (!$metafieldsAdded) {
                if ($this->logger) {
                    $this->logger->error("Failed to add metafields for order {$orderId}");
                }
                return false;
            }

            // Step 6: Add tags
            $tagsAdded = $this->addTags($orderId, $parsed);
            
            if (!$tagsAdded) {
                if ($this->logger) {
                    $this->logger->error("Failed to add tags for order {$orderId}");
                }
                return false;
            }

            if ($this->logger) {
                $this->logger->info("Successfully enriched order {$orderId}");
            }

            return true;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Error enriching order {$orderId}: " . $e->getMessage());
                $this->logger->error("Exception type: " . get_class($e));
                $this->logger->error("Stack trace: " . $e->getTraceAsString());
            }
            return false;
        }
    }

    /**
     * Extract pickup point from fulfillment data
     * 
     * @param array $fulfillmentData GraphQL response data
     * @return array|null Pickup point data or null if not found
     */
    private function extractPickupPoint(array $fulfillmentData): ?array
    {
        $fulfillmentOrders = $fulfillmentData['data']['order']['fulfillmentOrders']['edges'] ?? [];

        if ($this->logger) {
            $this->logger->info("Checking " . count($fulfillmentOrders) . " fulfillment order(s) for pickup point");
        }

        foreach ($fulfillmentOrders as $edge) {
            $deliveryMethod = $edge['node']['deliveryMethod'] ?? null;

            if ($this->logger && $deliveryMethod) {
                $methodType = $deliveryMethod['methodType'] ?? 'unknown';
                $this->logger->info("Delivery method type: {$methodType}");
                $this->logger->info("Delivery method keys: " . implode(', ', array_keys($deliveryMethod)));
            }

            if (isset($deliveryMethod['pickupPoint'])) {
                if ($this->logger) {
                    $this->logger->info("Found pickup point with externalId: " . ($deliveryMethod['pickupPoint']['externalId'] ?? 'missing'));
                }
                return $deliveryMethod['pickupPoint'];
            }
        }

        if ($this->logger) {
            $this->logger->info("No pickupPoint field found in any fulfillment order");
        }

        return null;
    }

    /**
     * Parse externalId into components
     * Examples: "Ackermans-EXPRESS-1569", "Ackermans-COLLECT-2014"
     * 
     * @param string $externalId The external ID to parse
     * @return array|null Parsed data with keys: courier, method, branchCode
     */
    private function parseExternalId(string $externalId): ?array
    {
        if (!Validator::validateExternalIdFormat($externalId)) {
            return null;
        }

        $parts = explode('-', $externalId);
        
        if (count($parts) !== 3) {
            return null;
        }

        return [
            'courier' => $parts[0],
            'method' => $parts[1],
            'branchCode' => $parts[2]
        ];
    }

    /**
     * Update shipping line title with pickup point information
     * Format: "Courier - METHOD - BranchCode"
     * 
     * @param string $orderId Shopify order GID
     * @param array $parsed Parsed externalId data
     * @return bool Success status
     */
    private function updateShippingLineTitle(string $orderId, array $parsed): bool
    {
        try {
            // Get current shipping lines to preserve code and price
            $orderData = $this->shopifyAPI->getOrderShippingLines($orderId);
            $shippingLines = $orderData['data']['order']['shippingLines']['edges'] ?? [];

            if (empty($shippingLines)) {
                if ($this->logger) {
                    $this->logger->warning("No shipping lines found for order {$orderId}");
                }
                return false;
            }

            $shippingLine = $shippingLines[0]['node'];
            $originalCode = $shippingLine['code'] ?? 'PickUp';
            $originalPrice = $shippingLine['originalPriceSet']['shopMoney']['amount'] ?? '0.00';
            $currencyCode = $shippingLine['originalPriceSet']['shopMoney']['currencyCode'] ?? 'ZAR';
            $shippingLineId = $shippingLines[0]['node']['id'];

            // Format new title: "Courier - METHOD - BranchCode"
            $newTitle = sprintf(
                "%s - %s - %s",
                $parsed['courier'],
                $parsed['method'],
                $parsed['branchCode']
            );

            // Begin order edit
            $calculatedOrderId = $this->shopifyAPI->orderEditBegin($orderId);

            // Remove old shipping line
            $this->shopifyAPI->orderEditRemoveShippingLine($calculatedOrderId, $shippingLineId);

            // Add new shipping line with updated title, preserved code and price
            $this->shopifyAPI->orderEditAddShippingLine(
                $calculatedOrderId,
                $newTitle,
                $originalCode,
                (float)$originalPrice,
                $currencyCode
            );

            // Commit changes
            $this->shopifyAPI->orderEditCommit($calculatedOrderId);

            if ($this->logger) {
                $this->logger->info("Updated shipping line title to: {$newTitle}");
            }

            return true;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Error updating shipping line: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Add metafields to order
     * 
     * @param string $orderId Shopify order GID
     * @param array $parsed Parsed externalId data
     * @return bool Success status
     */
    private function addMetafields(string $orderId, array $parsed): bool
    {
        try {
            // Add delivery.method metafield
            $this->shopifyAPI->addOrderMetafield(
                $orderId,
                'delivery',
                'method',
                $parsed['method']
            );

            // Add delivery.branch_code metafield
            $this->shopifyAPI->addOrderMetafield(
                $orderId,
                'delivery',
                'branch_code',
                $parsed['branchCode']
            );

            if ($this->logger) {
                $this->logger->info("Added metafields: method={$parsed['method']}, branch_code={$parsed['branchCode']}");
            }

            return true;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Error adding metafields: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Add tags to order
     * 
     * @param string $orderId Shopify order GID
     * @param array $parsed Parsed externalId data
     * @return bool Success status
     */
    private function addTags(string $orderId, array $parsed): bool
    {
        try {
            $method = strtolower($parsed['method']);
            $branchCode = $parsed['branchCode'];

            // Tag 1: delivery:method (machine-readable)
            $tag1 = "delivery:{$method}";
            
            // Tag 2: branch:code (branch identifier)
            $tag2 = "branch:{$branchCode}";
            
            // Tag 3: click-and-collect-method (human-readable)
            $tag3 = "click-and-collect-{$method}";

            $tags = [$tag1, $tag2, $tag3];

            $this->shopifyAPI->addOrderTags($orderId, $tags);

            if ($this->logger) {
                $this->logger->info("Added tags: " . implode(', ', $tags));
            }

            return true;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Error adding tags: " . $e->getMessage());
            }
            return false;
        }
    }
}

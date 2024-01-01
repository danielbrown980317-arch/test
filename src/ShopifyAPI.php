<?php

/**
 * ShopifyAPI Class
 * 
 * Supports both stable and unstable API versions
 */
class ShopifyAPI
{
    private $shopDomain;
    private $accessToken;
    private $unstableApiVersion;
    private $stableApiVersion;
    private $logger;

    public function __construct(string $shopDomain, string $accessToken, $logger = null)
    {
        $this->shopDomain = rtrim($shopDomain, '/');
        $this->accessToken = $accessToken;
        $this->unstableApiVersion = '2026-01'; // For pickup point data
        $this->stableApiVersion = '2025-01';   // For order updates
        $this->logger = $logger;
    }

    /**
     * Execute GraphQL query/mutation
     * 
     * @param string $query GraphQL query string
     * @param string $apiVersion API version to use
     * @param array $variables Optional variables for the query
     * @return array Decoded JSON response
     * @throws Exception On API errors
     */
    private function executeGraphQL(string $query, string $apiVersion, array $variables = []): array
    {
        $url = "https://{$this->shopDomain}/admin/api/{$apiVersion}/graphql.json";

        $payload = ['query' => $query];
        if (!empty($variables)) {
            $payload['variables'] = $variables;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $this->accessToken
        ]);

        // SSL certificate handling - disable verification for Windows development
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        // curl_close() is deprecated in PHP 8.5+, but still works
        // Resources are automatically closed when they go out of scope
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($curlError) {
            throw new Exception("cURL error: {$curlError}");
        }

        if ($httpCode !== 200) {
            $errorDetails = substr($response, 0, 500); // Limit response length
            if ($this->logger) {
                $this->logger->error("API HTTP error {$httpCode} for URL: {$url}");
                $this->logger->error("Response: {$errorDetails}");
            }
            throw new Exception("HTTP error {$httpCode}: {$errorDetails}");
        }

        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }

        // Check for GraphQL errors
        if (isset($decoded['errors']) && !empty($decoded['errors'])) {
            $errorMsg = json_encode($decoded['errors']);
            if ($this->logger) {
                $this->logger->error("GraphQL errors for URL: {$url}");
                $this->logger->error("Errors: {$errorMsg}");
            }
            throw new Exception("GraphQL errors: {$errorMsg}");
        }

        return $decoded;
    }

    /**
     * Get fulfillment orders with pickup point data (unstable API)
     * 
     * @param string $orderId Shopify order GID
     * @return array Fulfillment data
     */
    public function getFulfillmentOrders(string $orderId): array
    {
        $query = <<<GRAPHQL
query GetFulfillmentOrders(\$orderId: ID!) {
  order(id: \$orderId) {
    id
    fulfillmentOrders(first: 10, displayable: true) {
      edges {
        node {
          id
          deliveryMethod {
            methodType
            deliveryOptionGeneratorPickupPoint {
              externalId
              functionId
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

        try {
            $result = $this->executeGraphQL($query, $this->unstableApiVersion, [
                'orderId' => $orderId
            ]);

            if ($this->logger) {
                $this->logger->info("Fetched fulfillment orders for order: {$orderId}");
                // Log the structure to help debug what fields are available
                $fulfillmentOrders = $result['data']['order']['fulfillmentOrders']['edges'] ?? [];
                
                if (!empty($fulfillmentOrders)) {
                    $firstOrder = $fulfillmentOrders[0]['node'] ?? [];
                    $deliveryMethod = $firstOrder['deliveryMethod'] ?? null;
                    if ($deliveryMethod) {
                        $this->logger->info("Delivery method structure: " . json_encode($deliveryMethod, JSON_PRETTY_PRINT));
                    }
                }
            }

            return $result;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Error fetching fulfillment orders: " . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Get order details including shipping lines (stable API)
     * 
     * @param string $orderId Shopify order GID
     * @return array Order data with shipping lines
     */
    public function getOrderShippingLines(string $orderId): array
    {
        $query = <<<GRAPHQL
query GetOrderShippingLines(\$orderId: ID!) {
  order(id: \$orderId) {
    id
    shippingLines(first: 5) {
      edges {
        node {
          id
          code
          title
          originalPriceSet {
            shopMoney {
              amount
              currencyCode
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

        try {
            $result = $this->executeGraphQL($query, $this->stableApiVersion, [
                'orderId' => $orderId
            ]);

            return $result;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Error fetching order shipping lines: " . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Begin order edit session
     * 
     * @param string $orderId Shopify order GID
     * @return string Calculated order ID
     */
    public function orderEditBegin(string $orderId): string
    {
        $mutation = <<<GRAPHQL
mutation OrderEditBegin(\$orderId: ID!) {
  orderEditBegin(id: \$orderId) {
    calculatedOrder {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $result = $this->executeGraphQL($mutation, $this->stableApiVersion, [
            'orderId' => $orderId
        ]);

        if (!empty($result['data']['orderEditBegin']['userErrors'])) {
            $errors = json_encode($result['data']['orderEditBegin']['userErrors']);
            throw new Exception("Order edit begin errors: {$errors}");
        }

        return $result['data']['orderEditBegin']['calculatedOrder']['id'];
    }

    /**
     * Remove shipping line from order edit
     * 
     * @param string $calculatedOrderId Calculated order ID
     * @param string $shippingLineId Shipping line ID to remove
     * @return bool Success status
     */
    public function orderEditRemoveShippingLine(string $calculatedOrderId, string $shippingLineId): bool
    {
        $mutation = <<<GRAPHQL
mutation RemoveShippingLine(\$calculatedOrderId: ID!, \$shippingLineId: ID!) {
  orderEditRemoveShippingLine(
    id: \$calculatedOrderId
    shippingLineId: \$shippingLineId
  ) {
    calculatedOrder {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $result = $this->executeGraphQL($mutation, $this->stableApiVersion, [
            'calculatedOrderId' => $calculatedOrderId,
            'shippingLineId' => $shippingLineId
        ]);

        if (!empty($result['data']['orderEditRemoveShippingLine']['userErrors'])) {
            $errors = json_encode($result['data']['orderEditRemoveShippingLine']['userErrors']);
            throw new Exception("Remove shipping line errors: {$errors}");
        }

        return true;
    }

    /**
     * Add shipping line to order edit
     * 
     * @param string $calculatedOrderId Calculated order ID
     * @param string $title Shipping line title
     * @param string $code Shipping line code (must preserve original)
     * @param float $price Shipping price
     * @param string $currencyCode Currency code
     * @return bool Success status
     */
    public function orderEditAddShippingLine(
        string $calculatedOrderId,
        string $title,
        string $code,
        float $price,
        string $currencyCode
    ): bool {
        $mutation = <<<GRAPHQL
mutation AddShippingLine(
  \$calculatedOrderId: ID!
  \$title: String!
  \$code: String!
  \$price: MoneyInput!
) {
  orderEditAddShippingLine(
    id: \$calculatedOrderId
    shippingLine: {
      title: \$title
      code: \$code
      price: \$price
    }
  ) {
    calculatedOrder {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $result = $this->executeGraphQL($mutation, $this->stableApiVersion, [
            'calculatedOrderId' => $calculatedOrderId,
            'title' => $title,
            'code' => $code,
            'price' => [
                'amount' => (string)$price,
                'currencyCode' => $currencyCode
            ]
        ]);

        if (!empty($result['data']['orderEditAddShippingLine']['userErrors'])) {
            $errors = json_encode($result['data']['orderEditAddShippingLine']['userErrors']);
            throw new Exception("Add shipping line errors: {$errors}");
        }

        return true;
    }

    /**
     * Commit order edit
     * 
     * @param string $calculatedOrderId Calculated order ID
     * @return string Final order ID
     */
    public function orderEditCommit(string $calculatedOrderId): string
    {
        $mutation = <<<GRAPHQL
mutation OrderEditCommit(\$calculatedOrderId: ID!) {
  orderEditCommit(id: \$calculatedOrderId, notifyCustomer: false) {
    order {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $result = $this->executeGraphQL($mutation, $this->stableApiVersion, [
            'calculatedOrderId' => $calculatedOrderId
        ]);

        if (!empty($result['data']['orderEditCommit']['userErrors'])) {
            $errors = json_encode($result['data']['orderEditCommit']['userErrors']);
            throw new Exception("Order edit commit errors: {$errors}");
        }

        return $result['data']['orderEditCommit']['order']['id'];
    }

    /**
     * Add metafields to order
     * 
     * @param string $orderId Shopify order GID
     * @param string $namespace Metafield namespace
     * @param string $key Metafield key
     * @param string $value Metafield value
     * @return bool Success status
     */
    public function addOrderMetafield(string $orderId, string $namespace, string $key, string $value): bool
    {
        $mutation = <<<GRAPHQL
mutation AddOrderMetafield(
  \$orderId: ID!
  \$namespace: String!
  \$key: String!
  \$value: String!
) {
  metafieldsSet(metafields: [{
    ownerId: \$orderId
    namespace: \$namespace
    key: \$key
    value: \$value
    type: "single_line_text_field"
  }]) {
    metafields {
      id
      namespace
      key
      value
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $result = $this->executeGraphQL($mutation, $this->stableApiVersion, [
            'orderId' => $orderId,
            'namespace' => $namespace,
            'key' => $key,
            'value' => $value
        ]);

        if (!empty($result['data']['metafieldsSet']['userErrors'])) {
            $errors = json_encode($result['data']['metafieldsSet']['userErrors']);
            throw new Exception("Metafield errors: {$errors}");
        }

        return true;
    }

    /**
     * Add tags to order
     * 
     * @param string $orderId Shopify order GID
     * @param array $tags Array of tags to add
     * @return bool Success status
     */
    public function addOrderTags(string $orderId, array $tags): bool
    {
        // First, get existing tags
        $query = <<<GRAPHQL
query GetOrderTags(\$orderId: ID!) {
  order(id: \$orderId) {
    tags
  }
}
GRAPHQL;

        $result = $this->executeGraphQL($query, $this->stableApiVersion, [
            'orderId' => $orderId
        ]);

        $existingTags = $result['data']['order']['tags'] ?? [];
        
        // Merge with new tags, remove duplicates
        $allTags = array_unique(array_merge($existingTags, $tags));

        $mutation = <<<GRAPHQL
mutation UpdateOrderTags(\$orderId: ID!, \$tags: [String!]!) {
  tagsAdd(id: \$orderId, tags: \$tags) {
    node {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

        $result = $this->executeGraphQL($mutation, $this->stableApiVersion, [
            'orderId' => $orderId,
            'tags' => $allTags
        ]);

        if (!empty($result['data']['tagsAdd']['userErrors'])) {
            $errors = json_encode($result['data']['tagsAdd']['userErrors']);
            throw new Exception("Tag errors: {$errors}");
        }

        return true;
    }
}

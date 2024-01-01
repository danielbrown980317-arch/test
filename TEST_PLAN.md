# Test Plan

This document describes how to test the Shopify Pickup Point Webhook Handler.

## Prerequisites

1. **Shopify Development Store** with:
   - Pickup Point Generator enabled
   - Custom app created with Admin API access
   - Webhook subscription configured

2. **Webhook Endpoint** accessible from the internet:
   - Use a service like ngrok for local testing: `ngrok http 80`
   - Or deploy to a staging server

3. **Test Environment**:
   - PHP 7.4+ installed
   - Configuration file (`config/.env`) properly set up
   - Log directory writable

## Test Setup

### 1. Configure Webhook in Shopify

1. Go to Shopify Admin → Settings → Notifications → Webhooks
2. Click "Create webhook"
3. Configure:
   - **Event**: Order creation
   - **Format**: JSON
   - **URL**: Your webhook endpoint (e.g., `https://yourdomain.com/src/webhook_handler.php`)
4. Copy the webhook secret and add to `config/.env`

### 2. Verify Configuration

Check that your `.env` file has:
```env
SHOPIFY_SHOP_DOMAIN=yourstore.myshopify.com
SHOPIFY_ACCESS_TOKEN=shpat_xxxxxxxxxxxxx
SHOPIFY_WEBHOOK_SECRET=your_webhook_secret_here
```

### 3. Test Webhook Endpoint Accessibility

```bash
curl -X POST https://yourdomain.com/src/webhook_handler.php \
  -H "Content-Type: application/json" \
  -d '{"test": "data"}'
```

Should return a 401 (Unauthorized) since no HMAC is provided, but confirms endpoint is accessible.

## Test Cases

### Test Case 1: Valid Order with Pickup Point

**Objective**: Verify complete order enrichment workflow

**Steps**:
1. Create a test order in Shopify with:
   - At least one product
   - Pickup point selected (use Pickup Point Generator)
   - Complete payment (use test gateway)

2. Monitor webhook delivery:
   - Check Shopify Admin → Settings → Notifications → Webhooks
   - Verify webhook was sent successfully

3. Check application logs:
   ```bash
   tail -f logs/app.log
   ```

4. Verify order updates in Shopify Admin:
   - Go to Orders → Select the test order
   - Check shipping line title (should be formatted: "Courier - METHOD - BranchCode")
   - Check metafields (delivery.method and delivery.branch_code)
   - Check tags (delivery:method, branch:code, click-and-collect-method)

**Expected Results**:
- ✅ Webhook received and processed successfully
- ✅ Shipping line title updated with pickup point info
- ✅ Original shipping code preserved (e.g., "PickUp")
- ✅ Original shipping price preserved
- ✅ Two metafields added (delivery.method, delivery.branch_code)
- ✅ Three tags added
- ✅ Log file shows successful processing

**Verification Query** (use Shopify GraphiQL):
```graphql
query {
  order(id: "gid://shopify/Order/YOUR_ORDER_ID") {
    shippingLines(first: 1) {
      code
      title
      originalPriceSet {
        shopMoney {
          amount
          currencyCode
        }
      }
    }
    metafields(first: 10, namespace: "delivery") {
      edges {
        node {
          namespace
          key
          value
        }
      }
    }
    tags
  }
}
```

### Test Case 2: Order Without Pickup Point

**Objective**: Verify graceful handling of orders without pickup points

**Steps**:
1. Create a test order with regular shipping (no pickup point)
2. Monitor logs

**Expected Results**:
- ✅ Webhook received successfully
- ✅ Log shows: "No pickup point found for order {orderId}"
- ✅ Order remains unchanged (no errors)
- ✅ Returns HTTP 200 (success)

### Test Case 3: Invalid HMAC Signature

**Objective**: Verify security validation

**Steps**:
1. Send a test webhook with invalid HMAC:
   ```bash
   curl -X POST https://yourdomain.com/src/webhook_handler.php \
     -H "Content-Type: application/json" \
     -H "X-Shopify-Hmac-Sha256: invalid_signature" \
     -d '{"id": "123456"}'
   ```

**Expected Results**:
- ✅ Returns HTTP 401 (Unauthorized)
- ✅ Log shows: "Invalid HMAC signature"
- ✅ Order is not processed

### Test Case 4: Malformed ExternalId

**Objective**: Verify error handling for invalid data

**Steps**:
1. Manually create an order with pickup point that has malformed externalId
   (This may require API manipulation or test data)

**Expected Results**:
- ✅ Webhook received
- ✅ Log shows warning: "Invalid externalId format"
- ✅ Returns HTTP 500 (processing failed)
- ✅ Order not updated (graceful failure)

### Test Case 5: Missing Webhook Data

**Objective**: Verify input validation

**Steps**:
1. Send webhook with missing order ID:
   ```bash
   curl -X POST https://yourdomain.com/src/webhook_handler.php \
     -H "Content-Type: application/json" \
     -d '{}'
   ```

**Expected Results**:
- ✅ Returns HTTP 400 (Bad Request)
- ✅ Log shows: "Missing order ID in webhook payload"

### Test Case 6: API Rate Limiting

**Objective**: Verify behavior under rate limits

**Steps**:
1. Send multiple webhooks rapidly (if possible)
2. Monitor for rate limit errors

**Expected Results**:
- ✅ Errors logged appropriately
- ✅ System doesn't crash
- ✅ Consider implementing retry logic (bonus feature)

## Sample Test Data

### Sample Webhook Payload

```json
{
  "id": 1234567890,
  "email": "customer@example.com",
  "created_at": "2024-01-15T10:30:00Z",
  "updated_at": "2024-01-15T10:30:00Z",
  "number": 1001,
  "note": null,
  "token": "abc123def456",
  "gateway": "test",
  "test": true,
  "total_price": "150.00",
  "subtotal_price": "100.00",
  "total_weight": 500,
  "total_tax": "15.00",
  "currency": "ZAR",
  "financial_status": "paid",
  "confirmed": true,
  "total_discounts": "0.00",
  "buyer_accepts_marketing": false,
  "name": "#1001",
  "referring_site": "",
  "landing_site": "",
  "cancelled_at": null,
  "cancel_reason": null,
  "total_line_items_price": "100.00",
  "cart_token": null,
  "reference": null,
  "user_id": null,
  "location_id": null,
  "source_identifier": null,
  "source_url": null,
  "processed_at": "2024-01-15T10:30:00Z",
  "device_id": null,
  "phone": null,
  "customer_locale": null,
  "app_id": null,
  "browser_ip": "192.168.1.1",
  "landing_site_ref": null,
  "order_number": 1001,
  "discount_codes": [],
  "note_attributes": [],
  "payment_gateway_names": ["test"],
  "processing_method": "",
  "checkout_id": null,
  "source_name": "web",
  "fulfillment_status": null,
  "order_status_url": "https://yourstore.myshopify.com/1234567890/orders/abc123/authenticate?key=def456",
  "tags": "",
  "contact_email": "customer@example.com",
  "order_adjustments": [],
  "line_items": [
    {
      "id": 9876543210,
      "variant_id": 111222333,
      "title": "Test Product",
      "quantity": 1,
      "sku": "TEST-SKU-001",
      "variant_title": null,
      "vendor": null,
      "fulfillment_service": "manual",
      "product_id": 555666777,
      "requires_shipping": true,
      "taxable": true,
      "gift_card": false,
      "name": "Test Product",
      "variant_inventory_management": null,
      "properties": [],
      "product_exists": true,
      "fulfillable_quantity": 1,
      "grams": 500,
      "price": "100.00",
      "total_discount": "0.00",
      "fulfillment_status": null,
      "tax_lines": []
    }
  ],
  "shipping_address": {
    "first_name": "John",
    "last_name": "Doe",
    "address1": "123 Test St",
    "phone": null,
    "city": "Cape Town",
    "zip": "8001",
    "province": "Western Cape",
    "country": "South Africa",
    "last_name": "Doe",
    "address2": null,
    "company": null,
    "latitude": null,
    "longitude": null,
    "name": "John Doe",
    "country_code": "ZA",
    "province_code": "WC"
  },
  "billing_address": {
    "first_name": "John",
    "last_name": "Doe",
    "address1": "123 Test St",
    "phone": null,
    "city": "Cape Town",
    "zip": "8001",
    "province": "Western Cape",
    "country": "South Africa",
    "last_name": "Doe",
    "address2": null,
    "company": null,
    "latitude": null,
    "longitude": null,
    "name": "John Doe",
    "country_code": "ZA",
    "province_code": "WC"
  },
  "shipping_lines": [
    {
      "id": 444555666,
      "title": "Pickup",
      "price": "50.00",
      "code": "PickUp",
      "source": "shopify",
      "phone": null,
      "requested_fulfillment_service_id": null,
      "delivery_category": null,
      "carrier_identifier": null,
      "discounted_price": "50.00",
      "price_set": {
        "shop_money": {
          "amount": "50.00",
          "currency_code": "ZAR"
        },
        "presentment_money": {
          "amount": "50.00",
          "currency_code": "ZAR"
        }
      },
      "discounted_price_set": {
        "shop_money": {
          "amount": "50.00",
          "currency_code": "ZAR"
        },
        "presentment_money": {
          "amount": "50.00",
          "currency_code": "ZAR"
        }
      },
      "discount_allocations": []
    }
  ],
  "fulfillments": [],
  "refunds": [],
  "customer": {
    "id": 999888777,
    "email": "customer@example.com",
    "accepts_marketing": false,
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-01-15T10:30:00Z",
    "first_name": "John",
    "last_name": "Doe",
    "orders_count": 1,
    "state": "enabled",
    "total_spent": "150.00",
    "last_order_id": 1234567890,
    "note": null,
    "verified_email": true,
    "multipass_identifier": null,
    "tax_exempt": false,
    "phone": null,
    "tags": "",
    "last_order_name": "#1001",
    "currency": "ZAR",
    "accepts_marketing_updated_at": "2024-01-15T10:30:00Z",
    "marketing_opt_in_level": null,
    "tax_exemptions": [],
    "admin_graphql_api_id": "gid://shopify/Customer/999888777",
    "default_address": {
      "id": 777666555,
      "customer_id": 999888777,
      "first_name": "John",
      "last_name": "Doe",
      "company": null,
      "address1": "123 Test St",
      "address2": null,
      "city": "Cape Town",
      "province": "Western Cape",
      "country": "South Africa",
      "zip": "8001",
      "phone": null,
      "name": "John Doe",
      "province_code": "WC",
      "country_code": "ZA",
      "country_name": "South Africa",
      "default": true
    }
  }
}
```

### Expected ExternalId Formats

- `Ackermans-EXPRESS-1569`
- `Ackermans-COLLECT-2014`
- `CourierName-DELIVERY-0519`

### Expected Output

After processing, the order should have:

**Shipping Line**:
- `code`: "PickUp" (unchanged)
- `title`: "Ackermans - EXPRESS - 1569"` (updated)
- `price`: "50.00 ZAR" (unchanged)

**Metafields**:
- `delivery.method`: "EXPRESS"
- `delivery.branch_code`: "1569"

**Tags**:
- `delivery:express`
- `branch:1569`
- `click-and-collect-express`

## Verification Checklist

After each test, verify:

- [ ] Webhook was received (check Shopify Admin)
- [ ] Log file shows processing attempt
- [ ] No PHP errors in logs
- [ ] Order updated correctly (if applicable)
- [ ] HTTP response code is appropriate
- [ ] All three update locations are correct (shipping, metafields, tags)

## Troubleshooting Tests

### Webhook Not Received
- Check webhook URL is publicly accessible
- Verify webhook is active in Shopify Admin
- Check webhook delivery logs in Shopify

### Processing Fails
- Check `logs/app.log` for error messages
- Verify API credentials are correct
- Ensure API access token has required scopes
- Check if pickup point data exists in order

### Updates Not Appearing
- Wait a few seconds (API may be delayed)
- Refresh order page in Shopify Admin
- Use GraphQL query to verify updates
- Check logs for any errors during update process
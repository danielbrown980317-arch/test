# Shopify Pickup Point Webhook Handler

A PHP webhook handler that processes Shopify orders created via the Pickup Point Generator, extracting pickup point information and enriching orders with structured data for downstream fulfillment systems.

## Overview

This application receives `orders/create` webhooks from Shopify, extracts pickup point data from the fulfillment API, and updates orders with:

- **Shipping Line Title**: Formatted pickup point information (e.g., "Ackermans - EXPRESS - 1569")
- **Metafields**: Delivery method and branch code for reporting
- **Tags**: Searchable tags for filtering in Shopify Admin

## Requirements

- PHP 7.4 or higher
- cURL extension enabled
- Composer (optional, for dependency management)
- Access to a Shopify development store with:
  - Pickup Point Generator enabled
  - Custom app with Admin API access
  - Webhook subscription for `orders/create`

## Installation

1. **Clone or download this repository**

2. **Copy the environment configuration file:**
   ```bash
   cp config/.env.example config/.env
   ```

3. **Edit `config/.env` with your Shopify credentials:**
   ```env
   SHOPIFY_SHOP_DOMAIN=yourstore.myshopify.com
   SHOPIFY_ACCESS_TOKEN=shpat_xxxxxxxxxxxxx
   SHOPIFY_WEBHOOK_SECRET=your_webhook_secret
   ```

4. **Set up webhook endpoint:**
   - Point your webhook URL to: `https://yourdomain.com/src/webhook_handler.php`
   - Ensure the endpoint is publicly accessible
   - Configure in Shopify Admin → Settings → Notifications → Webhooks

5. **Set proper permissions:**
   ```bash
   chmod 755 src/webhook_handler.php
   chmod 777 logs/  # For log file writing
   ```

## Configuration

### Required Environment Variables

- `SHOPIFY_SHOP_DOMAIN`: Your Shopify shop domain (e.g., `yourstore.myshopify.com`)
- `SHOPIFY_ACCESS_TOKEN`: Admin API access token (starts with `shpat_`)
- `SHOPIFY_WEBHOOK_SECRET`: Webhook secret from Shopify

### Optional Environment Variables

- `LOG_FILE`: Path to log file (default: `logs/app.log`)
- `LOG_LEVEL`: Logging level (0=DEBUG, 1=INFO, 2=WARNING, 3=ERROR, default: 1)

## Project Structure

```
.
├── src/
│   ├── webhook_handler.php    # Entry point for webhook requests
│   ├── ShopifyAPI.php         # GraphQL API client
│   ├── OrderEnricher.php      # Business logic for order enrichment
│   ├── Validator.php          # HMAC validation and input sanitization
│   └── Logger.php              # Simple file-based logger
├── config/
│   ├── config.php             # Configuration loader
│   └── .env.example           # Environment variables template
├── tests/                      # Unit tests (to be implemented)
├── logs/                       # Application logs
├── README.md                  # This file
├── TEST_PLAN.md              # Testing instructions
├── ARCHITECTURE.md           # Design decisions
└── composer.json             # PHP dependencies

```

## How It Works

1. **Webhook Reception**: Shopify sends `orders/create` webhook to `webhook_handler.php`
2. **HMAC Validation**: Request is validated using HMAC signature
3. **Fulfillment Query**: System queries Shopify's unstable API for pickup point data
4. **Data Extraction**: Extracts `externalId` from pickup point (format: `COURIER-METHOD-BRANCHCODE`)
5. **Order Updates**: Updates order in three locations:
   - Shipping line title (via Order Editing API)
   - Metafields (delivery.method, delivery.branch_code)
   - Tags (delivery:method, branch:code, click-and-collect-method)

## API Versions

- **Unstable API (2026-01)**: Used for querying pickup point data (only available in unstable)
- **Stable API (2025-01)**: Used for order updates and mutations

## Usage

Once configured, the webhook handler will automatically process orders when:

1. A customer creates an order with a pickup point selected
2. Shopify sends the `orders/create` webhook
3. The handler validates, processes, and enriches the order

Check `logs/app.log` for processing details and any errors.

## Testing

See [TEST_PLAN.md](TEST_PLAN.md) for detailed testing instructions.

## Troubleshooting

### Webhook not being received
- Verify webhook URL is publicly accessible
- Check Shopify webhook delivery logs in Admin
- Ensure HMAC secret matches in both places

### Orders not updating
- Check logs for error messages
- Verify API access token has required scopes:
  - `read_orders`
  - `write_orders`
  - `read_fulfillments`
- Ensure pickup point data exists in the order

### HMAC validation failing
- Ensure you're using the raw POST body for HMAC calculation
- Verify webhook secret matches exactly
- Check for any URL rewriting or request modification

## Security

- All webhook requests are validated using HMAC signatures
- Input is sanitized before processing
- Sensitive credentials are stored in environment variables
- Never commit `.env` file to version control

## License

This is a solution for a developer entrance exam. Use as needed.

## Support

For issues or questions, refer to:
- [Shopify GraphQL Admin API Documentation](https://shopify.dev/docs/api/admin-graphql)
- [Shopify Webhooks Documentation](https://shopify.dev/docs/apps/build/webhooks)
- [Order Editing API Documentation](https://shopify.dev/docs/apps/build/orders/edit-orders)


---
Last updated: 2026-02-09 15:59:33

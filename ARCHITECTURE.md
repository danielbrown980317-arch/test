# Architecture Documentation

This document explains the design decisions and architecture of the Shopify Pickup Point Webhook Handler.

## System Overview

The application follows a **layered architecture** with clear separation of concerns:

```
┌─────────────────────────────────────────────────┐
│  Shopify Webhook (orders/create)                │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  webhook_handler.php                            │
│  - HMAC validation                              │
│  - Request routing                              │
│  - Error handling                               │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  OrderEnricher.php                              │
│  - Business logic                               │
│  - Data parsing                                 │
│  - Orchestration                                │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  ShopifyAPI.php                                 │
│  - GraphQL client                               │
│  - API version management                       │
│  - Request/response handling                    │
└────────────────┬────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────┐
│  Validator.php                                  │
│  - Security validation                          │
│  - Input sanitization                           │
└─────────────────────────────────────────────────┘
```

## Component Design

### 1. webhook_handler.php (Entry Point)

**Responsibilities**:
- Receive and validate webhook requests
- Extract order information
- Coordinate order enrichment
- Return appropriate HTTP responses

**Design Decisions**:
- **Single entry point**: All webhooks route through one file for simplicity
- **Early validation**: HMAC checked before any processing
- **Error handling**: Try-catch blocks with proper HTTP status codes
- **Logging**: All operations logged for debugging

**Key Features**:
- HMAC signature validation using `Validator::verifyWebhook()`
- Order ID extraction and format conversion (numeric to GID)
- Dependency injection of logger and API client

### 2. OrderEnricher.php (Business Logic)

**Responsibilities**:
- Orchestrate the order enrichment workflow
- Extract and parse pickup point data
- Coordinate updates to shipping, metafields, and tags

**Design Decisions**:
- **Single Responsibility**: Each method handles one aspect of enrichment
- **Graceful degradation**: Returns `true` if no pickup point found (not an error)
- **Error isolation**: Failures in one update don't prevent others from attempting
- **Data transformation**: Parses externalId into structured components

**Workflow**:
1. Fetch fulfillment data (unstable API)
2. Extract pickup point
3. Parse externalId
4. Update shipping line title
5. Add metafields
6. Add tags

### 3. ShopifyAPI.php (API Client)

**Responsibilities**:
- Execute GraphQL queries and mutations
- Manage API version selection
- Handle API errors and responses

**Design Decisions**:
- **Dual API version support**: Separate clients for stable and unstable APIs
  - Unstable (2026-01): For pickup point data queries
  - Stable (2025-01): For order updates and mutations
- **Error handling**: Throws exceptions with descriptive messages
- **Response validation**: Checks for GraphQL errors in responses
- **Method organization**: Separate methods for each operation

**Key Methods**:
- `getFulfillmentOrders()`: Queries pickup point data (unstable)
- `getOrderShippingLines()`: Gets current shipping info (stable)
- `orderEditBegin()`: Starts order editing session
- `orderEditRemoveShippingLine()`: Removes shipping line
- `orderEditAddShippingLine()`: Adds shipping line with new title
- `orderEditCommit()`: Commits changes
- `addOrderMetafield()`: Adds metafields
- `addOrderTags()`: Adds tags

### 4. Validator.php (Security & Validation)

**Responsibilities**:
- HMAC signature verification
- Input format validation
- Data sanitization

**Design Decisions**:
- **Static methods**: No state needed, utility functions
- **Security first**: Uses `hash_equals()` for timing-safe comparison
- **Flexible validation**: ExternalId validation allows various formats

**Security Considerations**:
- HMAC uses raw POST body (not parsed JSON)
- Timing-safe comparison prevents timing attacks
- Input sanitization prevents injection attacks

### 5. Logger.php (Logging)

**Responsibilities**:
- File-based logging
- Log level management
- Timestamp formatting

**Design Decisions**:
- **Simple implementation**: No external dependencies
- **Configurable levels**: DEBUG, INFO, WARNING, ERROR
- **File-based**: Easy to monitor and debug

## Data Flow

### Webhook Processing Flow

```
1. Shopify sends webhook
   ↓
2. webhook_handler.php receives request
   ↓
3. Validator::verifyWebhook() validates HMAC
   ↓
4. Extract order ID from payload
   ↓
5. OrderEnricher::enrichOrder() called
   ↓
6. ShopifyAPI::getFulfillmentOrders() (unstable API)
   ↓
7. Extract pickup point externalId
   ↓
8. Parse externalId (COURIER-METHOD-BRANCHCODE)
   ↓
9. Update shipping line (Order Editing API)
   ↓
10. Add metafields
   ↓
11. Add tags
   ↓
12. Return success response
```

### Order Editing API Flow

The shipping line update uses a complex workflow:

```
1. Get current shipping line (code, price, title)
   ↓
2. orderEditBegin() - Start edit session
   ↓
3. orderEditRemoveShippingLine() - Remove old line
   ↓
4. orderEditAddShippingLine() - Add new line with:
   - Updated title: "Courier - METHOD - BranchCode"
   - Preserved code: "PickUp" (read-only, must preserve)
   - Preserved price: Original amount
   ↓
5. orderEditCommit() - Save changes
```

**Why this approach?**
- `shipping_lines.code` is read-only (configured in Shopify admin)
- `shipping_lines.title` cannot be directly updated
- Order Editing API allows removing and re-adding shipping lines
- Must preserve original code and price

## API Version Strategy

The application uses **two different API versions**:

### Unstable API (2026-01)
- **Used for**: Querying pickup point data
- **Why**: `externalId` field only exists in unstable API
- **Operations**: `getFulfillmentOrders()`

### Stable API (2025-01)
- **Used for**: All mutations and order updates
- **Why**: Production stability, better error handling
- **Operations**: All update operations (shipping, metafields, tags)

**Design Rationale**:
- Pickup point data is only available in unstable API
- Order updates should use stable API for reliability
- Separate API clients prevent version conflicts

## Error Handling Strategy

### Levels of Error Handling

1. **Webhook Level** (webhook_handler.php):
   - Invalid HMAC → 401 Unauthorized
   - Missing data → 400 Bad Request
   - Processing errors → 500 Internal Server Error

2. **Business Logic Level** (OrderEnricher.php):
   - No pickup point → Return true (not an error)
   - Invalid externalId → Return false, log warning
   - API errors → Return false, log error

3. **API Level** (ShopifyAPI.php):
   - Network errors → Exception with message
   - GraphQL errors → Exception with error details
   - HTTP errors → Exception with status code

### Logging Strategy

- **INFO**: Normal operations, successful processing
- **WARNING**: Non-critical issues (missing pickup point, invalid format)
- **ERROR**: Failures, exceptions, API failures
- **DEBUG**: Detailed debugging information (if enabled)

## Security Considerations

### HMAC Validation
- Validates every webhook request
- Uses raw POST body (not parsed JSON)
- Timing-safe comparison (`hash_equals()`)
- Prevents webhook spoofing

### Input Sanitization
- All user input sanitized
- Order IDs validated for format
- ExternalId validated before parsing

### Credential Management
- Credentials stored in environment variables
- `.env` file excluded from version control
- No hardcoded secrets

## Scalability Considerations

### Current Limitations
- Single-threaded processing
- No queue system
- No retry mechanism
- Synchronous processing

### Potential Improvements
- **Queue System**: Process webhooks asynchronously
- **Retry Logic**: Handle transient API failures
- **Rate Limiting**: Respect Shopify API limits
- **Idempotency**: Prevent duplicate processing
- **Caching**: Cache API responses if needed

## Testing Strategy

### Unit Tests (To Be Implemented)
- `ValidatorTest.php`: Test HMAC validation, format validation
- `OrderEnricherTest.php`: Test parsing, enrichment logic

### Integration Tests
- End-to-end webhook processing
- API interaction testing
- Error scenario testing

### Manual Testing
- Real webhook delivery
- Shopify Admin verification
- Log file analysis

## Configuration Management

### Environment Variables
- Centralized in `config/config.php`
- Loaded from `.env` file
- Validated on startup
- Clear error messages for missing config

### Benefits
- Easy deployment across environments
- No code changes for different stores
- Secure credential management

## Future Enhancements

### Potential Features
1. **Retry Logic**: Automatic retry for failed API calls
2. **Queue System**: Process webhooks asynchronously
3. **Monitoring**: Health checks, metrics collection
4. **Unit Tests**: Comprehensive test coverage
5. **Docker Support**: Containerized deployment
6. **Multiple Store Support**: Handle multiple Shopify stores

### Code Quality Improvements
- PSR-4 autoloading
- Dependency injection container
- Interface-based design
- Comprehensive error handling
- API response caching

## Conclusion

This architecture prioritizes:
- **Simplicity**: Easy to understand and maintain
- **Security**: Proper validation and error handling
- **Reliability**: Graceful degradation
- **Extensibility**: Easy to add new features

The design follows SOLID principles where possible and maintains clear separation of concerns for maintainability and testability.

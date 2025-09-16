# Fera.ai Magento 2 Extension
This extension makes it easy to use Fera.ai to offer customers a live shopping experience in your product pages. To learn more about Fera go to https://www.fera.ai


## Installation
### 1. Create An Account
Go to https://app.fera.ai/signup?platform=magento2 and create a new account.

### 2. Install with Composer
Install the extension using Composer:
```bash
composer require gorbunovstudio/magento2-fera
php bin/magento setup:upgrade
```

*That's it!*. If you follow the instructions described then your store will automatically be configured to connected to the Fera.ai servers.

### 3. Migrate to v3
If you are using old version v2 we highly recommend to migrate to v3 by contacting our support team via email at help a-t fera dot ai.

## How It Works

This extension integrates your Magento 2 store with Fera.ai by synchronizing products, orders, and customer data. It uses a robust queue-based system to handle data exports asynchronously, ensuring no impact on your store's performance.

### Queue-Based Data Sync

All data synchronization with Fera.ai is handled in the background by Magento's message queue system. This includes:
- **Product Exports**: Products are automatically queued for export when an order is placed. You can also export them manually via the console.
- **Order Exports**: New orders, updates, and fulfillments are queued and sent to Fera.ai.
- **Customer Updates**: Customer data changes are synced to keep Fera.ai up-to-date.

This ensures that data synchronization is reliable and does not slow down the customer experience or admin operations.

## Usage
Go to https://app.fera.ai/widgets to customize your experience!

## Console Commands

This module includes console commands for managing product data with Fera.ai.

### Export Products

Use this command to export products from Magento 2 to Fera.ai. It's useful for initial setup or for syncing a large number of products.

**Command:**
```bash
php bin/magento fera:products:export [options]
```

**Options:**
- `--limit (-l)`: Limit the number of products to export.
- `--store-id (-s)`: Specify the store ID to export products from. If not provided, it will process all stores where Fera.ai is configured.

**Examples:**

Export all products from all configured stores:
```bash
php bin/magento fera:products:export
```

Export the first 100 products:
```bash
php bin/magento fera:products:export --limit 100
```

Export products from a specific store:
```bash
php bin/magento fera:products:export --store-id 1
```

### Backfill Product Mappings

This command synchronizes product mappings between your local database and Fera.ai. It fetches product data from Fera, compares it with your local records, and creates, updates, or deletes mappings as needed. This is useful for ensuring data consistency.

**Command:**
```bash
php bin/magento fera:products:backfill-mappings [options]
```

**Options:**
- `--store-id`: The store ID to process. If not provided, it will process all stores where Fera.ai is configured.
- `--page-size`: The number of products to fetch per API request (default: 100).
- `--max-pages`: The maximum number of pages to fetch from the Fera.ai API (default: 0, for no limit).

**Example:**

Backfill mappings for store with ID 1:
```bash
php bin/magento fera:products:backfill-mappings --store-id 1
```

## Configuration

All configuration options for the Fera.ai extension are located under `Stores > Configuration > Fera Commerce > Fera.ai`. These settings are available at the store view scope, allowing for different configurations per store.

### General Settings

**Admin Path:** `... > Fera.ai > General`

-   **Enabled (Default: No)**
    -   **Description:** Master switch to enable or disable the Fera.ai integration for the selected store view.
    -   **Use Cases:**
        -   **Enable** to activate all Fera.ai features, including data synchronization and widget display.
        -   **Disable** to completely turn off the integration on a specific store.

-   **Debug Mode (Default: No)**
    -   **Description:** When enabled, detailed logs of API requests, queue messages, and other processes are written to `var/log/fera_ai.log`.
    -   **Use Cases:**
        -   **Enable** during development or troubleshooting to get more insight into the extension's behavior.
        -   **Disable** in a production environment to avoid generating large log files.

### API Keys

**Admin Path:** `... > Fera.ai > API Keys`

This section is for configuring your Fera.ai account credentials. You can find these keys in your Fera.ai dashboard under **Store > Settings > API**.

-   **Public Key:** Your public Fera.ai API key (pk_...).
-   **Secret Key:** Your secret Fera.ai API key (sk_...).
-   **App Url:** The base URL for the Fera.ai application (e.g., `https://app.fera.ai/`).
-   **Api Url:** The base URL for the Fera.ai API (e.g., `https://api.fera.ai/`).
-   **Js Url:** The URL for the Fera.ai JavaScript library (e.g., `https://cdn.fera.ai/js/v3/fera.js`).

### Sync Settings

**Admin Path:** `... > Fera.ai > Sync Settings`

-   **Export orders on creation (Default: Yes)**
    -   **Description:** Controls when orders are exported to Fera.ai.
        -   **Yes (Default):** Orders are exported immediately after creation.
        -   **No:** Orders are only exported when they are marked as "Complete".
    -   **Use Cases:**
        -   **Enable** for instant review collection.
        -   **Disable** for stores with high cancellation rates or long order processing times.

-   **Minimize data sharing (Default: No)**
    -   **Description:** When enabled, sensitive customer and order data (addresses, phone numbers, monetary values) is excluded from exports. Basic data required for reviews (customer name/email, product details) is still sent.
    -   **Use Cases:**
        -   **Enable** if you have strict data privacy requirements and want to limit the information shared with Fera.ai.
        -   **Disable** to send complete order and customer data for a richer integration.

## Help
If you're seeing this repo you're probably a trusted developer - so just feel free to email help a-t fera dot ai with any questions.

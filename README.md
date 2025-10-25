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

### Export Past Orders

Use this command to export historical fulfilled orders to Fera.ai so you can run one-time review campaigns without impacting the automatic review request quotas. The command skips orders that were already exported and works in batches to limit memory usage.

**Command:**
```bash
php bin/magento fera:orders:export [options]
```

**Options:**
- `--from`: *required* lower bound for the order fulfillment date (`YYYY-MM-DD` or full datetime).
- `--to`: optional upper bound for the order fulfillment date.
- `--store-id (-s)`: export orders only from the provided store. By default, all stores configured for Fera.ai are processed.
- `--batch-size (-b)`: number of orders to process per batch (default: 100).
- `--max (-m)`: maximum number of orders to export during this run.
- `--exclude-emails-csv`: path to a CSV file containing customer emails to exclude from export (useful for avoiding duplicate review requests from other platforms like TrustPilot).
- `--dry-run (-d)`: list matching orders without exporting them (shows sample IDs for one batch).

**Examples:**

Export orders fulfillment since Jan 1, 2024:
```bash
php bin/magento fera:orders:export --from 2024-01-01
```

Dry run for store ID 3 between specific dates:
```bash
php bin/magento fera:orders:export --from "2024-01-01 00:00:00" --to "2024-06-30 23:59:59" --store-id 3 --dry-run
```

Export orders while excluding customers who already reviewed on TrustPilot:
```bash
php bin/magento fera:orders:export --from 2024-01-01 --exclude-emails-csv /path/to/trustpilot_emails.csv
```

### Export Specific Orders

Use this command to export specific orders by providing a CSV file of order entity IDs. This is useful for targeted exports, re-exporting failed orders, or manual backfills. The command preserves the order of IDs from the CSV file.

**Command:**
```bash
php bin/magento fera:orders:export:specific [options]
```

**Options:**
- `--ids-csv`: *required* path to the CSV file containing order entity IDs.
- `--batch-size (-b)`: number of orders to process per batch (default: 100).
- `--max (-m)`: maximum number of orders to process from the CSV.
- `--dry-run (-d)`: list orders that would be exported without actually sending them to Fera.ai.

**Examples:**

Export orders from a CSV file:
```bash
php bin/magento fera:orders:export:specific --ids-csv var/import/order_ids.csv
```

Dry run for the first 50 orders from a CSV:
```bash
php bin/magento fera:orders:export:specific --ids-csv orders.csv --max 50 --dry-run
```

#### Order ID CSV Format

The `--ids-csv` option accepts a simple, single-column CSV file.

**CSV Format:**
- Single column containing `sales_order.entity_id` values.
- Optional header row (e.g., `order_id`, `id`).
- Delimiter can be a comma (`,`) or semicolon (`;`); it is auto-detected.
- Empty lines, invalid IDs, and duplicates are automatically skipped.

**Example CSV:**
```csv
order_id
1001
1002
1005
```

#### Email Exclusion CSV Format

The `--exclude-emails-csv` option accepts a CSV file with customer emails to skip during export. This is useful to avoid sending duplicate review requests to customers who have already reviewed your products on other platforms.

**CSV Format:**
- Single column containing email addresses
- Optional header row with "email" (case-insensitive)
- Empty lines and invalid email formats are automatically skipped
- Email matching is case-insensitive

**Example CSV:**
```csv
email
customer1@example.com
CUSTOMER2@EXAMPLE.COM
customer3@domain.org
```

### Import Reviews from CSV

This command allows you to import historical reviews from a CSV file directly into Fera.ai. It is useful for migrating reviews from another platform or for bulk-adding reviews that were collected offline.

**Command:**
```bash
php bin/magento fera:reviews:import [options]
```

**Options:**
- `--csv`: *required* path to the CSV file containing the reviews to import.
- `--store-id (-s)`: The store ID to associate the reviews with. This is required if you have multiple stores configured with different Fera.ai accounts.
- `--batch-size (-b)`: The number of reviews to process in each batch (default: 100).
- `--max (-m)`: The maximum total number of reviews to process from the CSV file.

**Examples:**

Import reviews for store ID 1 from a CSV file:
```bash
php bin/magento fera:reviews:import --csv var/import/reviews.csv --store-id 1
```

Import a maximum of 500 reviews with a smaller batch size:
```bash
php bin/magento fera:reviews:import --csv path/to/your/reviews.csv --store-id 1 --max 500 --batch-size 50
```

#### Review Import CSV Format

The `--csv` option requires a specifically formatted CSV file with a header row. The command validates the headers and data types for each column.

**CSV Headers (must be in this order):**
1.  `External Order ID`
2.  `External Customer ID`
3.  `Product ID`
4.  `Heading`
5.  `Body`
6.  `Rating`
7.  `State`
8.  `Is Verified`
9.  `Created At`
10. `Updated At` (Note: This column is expected in the header but its value is currently ignored by the import process).
11. `Store Reply`
12. `Store Replied At`
13. `Customer Media 1`
14. `Customer Media 2`

**Column Descriptions:**
-   **External Order ID** (string, required): Your internal order identifier.
-   **External Customer ID** (string, required): Your internal customer identifier.
-   **Product ID** (string, required): The Magento Product ID the review is for.
-   **Heading** (string): The title of the review.
-   **Body** (string, required): The main content of the review.
-   **Rating** (integer, required): A rating from `1` to `5`.
-   **State** (string, required): The moderation state of the review. Must be one of: `approved`, `pending_approval`, `pending_update`, `declined_approval`.
-   **Is Verified** (boolean, required): Indicates if the review is from a verified buyer. Accepts `true`, `1`, `yes`, `y`, `on` or `false`, `0`, `no`, `n`, `off`.
-   **Created At** (string, required): The date the review was submitted. The format should be parsable by PHP's `strtotime` (e.g., `YYYY-MM-DD HH:MM:SS`).
-   **Store Reply** (string): The text of your reply to the customer's review.
-   **Store Replied At** (string): The date your reply was submitted.
-   **Customer Media 1** (string): A public URL to an image or video submitted with the review.
-   **Customer Media 2** (string): A public URL to a second media file.

**Example CSV:**
```csv
External Order ID,External Customer ID,Product ID,Heading,Body,Rating,State,Is Verified,Created At,Updated At,Store Reply,Store Replied At,Customer Media 1,Customer Media 2
ORD-001,CUST-123,45,Great product!,I really loved this product. It exceeded my expectations.,5,approved,true,2024-01-15 10:00:00,,Thank you for your review!,,https://example.com/media1.jpg,
ORD-002,CUST-124,46,Could be better,"It was okay, but I expected more for the price.",3,pending_approval,true,2024-01-16 12:30:00,,,,,,
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

# Fera.ai Magento 2 Extension
This extension makes it easy to use Fera.ai to offer customers a live shopping experience in your product pages. To learn more about Fera go to https://www.fera.ai


## Installation
### 1. Create An Account
Go to https://app.fera.ai/signup?platform=magento2 and create a new account.

### 2. Follow the instructions to install the extension.
You will be provided with a link to the latest install files. Follow the instructions to install the app into your Magento store.

*That's it!*. If you follow the instructions described then your store will automatically be configured to connected to the Fera.ai servers.

### 3. Migrate to v3
If you are using old version v2 we highly recommend to migrate to v3 by contacting our support team via email at help a-t fera dot ai.

## Usage
Go to https://app.fera.ai/widgets to customize your experience!

## Product Export Console Command

This module includes a console command for exporting products from Magento 2 to Fera.ai.

### Command Usage

```bash
php bin/magento fera:products:export [options]
```

### Options

- `--limit (-l)`: Limit the number of products to export
- `--store-id (-s)`: Specify the store ID to export products from (default: 0)

### Examples

**Export all products**
```bash
php bin/magento fera:products:export
```

**Export first 100 products**
```bash
php bin/magento fera:products:export --limit 100
```

**Export products from specific store**
```bash
php bin/magento fera:products:export --store-id 1
```

**Combination of options**
```bash
php bin/magento fera:products:export --limit 50 --store-id 1
```

## Configuration

### Order Export Settings

The extension provides flexible order export options that can be configured per store:

#### Export orders on creation (Default: Yes)

**Admin Path:** `Stores > Configuration > Fera.ai > Sync Settings > Export orders on creation`

**Description:** Controls when orders are exported to Fera.ai:

- **Yes (Default):** Orders are exported immediately after creation for instant review collection
- **No:** Orders are only exported when they are marked as complete, reducing early exports for orders that may be cancelled

**Use Cases:**
- **Enable** for stores with high order completion rates and immediate review collection needs
- **Disable** for stores with high cancellation rates or B2B scenarios where orders remain pending for extended periods

**Important Notes:**
- This setting is store-scoped, allowing different behavior per store view
- When disabled, orders will still be exported when they transition to "Complete" status
- Queue processing handles the actual export, so changes take effect on the next queue run

## Help
If you're seeing this repo you're probably a trusted developer - so just feel free to email help a-t fera dot ai with any questions.

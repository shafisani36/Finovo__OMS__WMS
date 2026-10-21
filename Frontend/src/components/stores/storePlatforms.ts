import type { StorePlatform } from '../../services/omsService';

export interface PlatformField {
  key: string;
  label: string;
  type: 'text' | 'url' | 'password' | 'number';
  placeholder?: string;
  required?: boolean;
  help?: string;
}

export interface PlatformDefinition {
  value: StorePlatform;
  label: string;
  /** How the merchant gives Finovo access, shown above the platform fields. */
  instructions: string;
  /** Submit button text when saving sends the merchant to the platform to approve access. */
  submitLabel?: string;
  /** Optional key fields that, when all filled, connect directly and skip the approval step. */
  directKeyFields?: string[];
  fields: PlatformField[];
}

const databaseFields: PlatformField[] = [
  { key: 'db_name', label: 'Database Name', type: 'text', placeholder: 'e.g. opencart', required: true },
  { key: 'db_host', label: 'Database Host', type: 'text', placeholder: 'Default from server settings (127.0.0.1)' },
  { key: 'db_port', label: 'Port', type: 'number', placeholder: '3306' },
  { key: 'db_username', label: 'Database User', type: 'text', placeholder: 'Default from server settings' },
  { key: 'db_password', label: 'Database Password', type: 'password', placeholder: 'Default from server settings' },
];

/** Product image links are built from the shop's address, which its database does not contain. */
const shopWebsiteField: PlatformField = {
  key: 'site_url',
  label: 'Shop Website',
  type: 'url',
  placeholder: 'https://yourstore.com',
  help: 'Used to import product images.',
};

export const STORE_PLATFORMS: PlatformDefinition[] = [
  {
    value: 'shopify',
    label: 'Shopify',
    instructions: 'After saving you will be sent to Shopify to approve access for Finovo.',
    submitLabel: 'Continue to Shopify',
    fields: [
      { key: 'shop_domain', label: 'Shopify Store Address', type: 'text', placeholder: 'your-store.myshopify.com', required: true },
    ],
  },
  {
    value: 'woocommerce',
    label: 'WooCommerce',
    instructions: 'Leave the keys empty to approve access in your WordPress admin, or paste keys from WooCommerce → Settings → Advanced → REST API (Read/Write).',
    submitLabel: 'Continue to WordPress',
    directKeyFields: ['consumer_key', 'consumer_secret'],
    fields: [
      { key: 'site_url', label: 'Store Website', type: 'url', placeholder: 'https://yourstore.com', required: true },
      { key: 'consumer_key', label: 'Consumer Key', type: 'text', placeholder: 'ck_...' },
      { key: 'consumer_secret', label: 'Consumer Secret', type: 'password', placeholder: 'cs_...' },
    ],
  },
  {
    value: 'bigcommerce',
    label: 'BigCommerce',
    instructions: 'Click Continue, log in to BigCommerce and approve the Finovo app. Already have an API account? Paste its store hash and access token instead.',
    submitLabel: 'Continue to BigCommerce',
    directKeyFields: ['access_token'],
    fields: [
      {
        key: 'store_hash',
        label: 'Store Hash',
        type: 'text',
        placeholder: 'e.g. abc123xyz',
        help: 'Only needed with an access token. The code in your admin address: store-abc123xyz.mybigcommerce.com',
      },
      { key: 'access_token', label: 'API Access Token', type: 'password' },
    ],
  },
  {
    value: 'prestashop',
    label: 'PrestaShop',
    instructions: 'Click Continue to download the Finovo module, then upload it in PrestaShop admin → Modules → Module Manager. Already have a webservice key? Enter the store website and key instead.',
    submitLabel: 'Continue to PrestaShop',
    directKeyFields: ['webservice_key'],
    fields: [
      { key: 'site_url', label: 'Store Website', type: 'url', placeholder: 'https://yourstore.com', help: 'Only needed with a webservice key.' },
      { key: 'webservice_key', label: 'Webservice Key', type: 'password' },
    ],
  },
  {
    value: 'wix',
    label: 'Wix',
    instructions: 'Click Continue, log in to Wix, choose your site and approve the Finovo app. Or enter the site ID from your Wix dashboard address (dashboard/<site-id>/...) to connect with an API key.',
    submitLabel: 'Continue to Wix',
    directKeyFields: ['site_id'],
    fields: [
      { key: 'site_id', label: 'Wix Site ID', type: 'text', placeholder: '1a2b3c4d-1234-5678-9abc-1a2b3c4d5e6f', help: 'Only needed without the Finovo Wix app.' },
      { key: 'api_key', label: 'Wix API Key', type: 'password', help: 'Leave empty to use the API key set on the server.' },
    ],
  },
  {
    value: 'ebay',
    label: 'eBay',
    instructions: 'After saving you will be sent to eBay to sign in with the seller account and approve access.',
    submitLabel: 'Continue to eBay',
    fields: [],
  },
  {
    value: 'magento',
    label: 'Magento 2',
    instructions: 'In Magento admin → System → Integrations, activate an integration and copy its Access Token.',
    fields: [
      { key: 'site_url', label: 'Store Website', type: 'url', placeholder: 'https://yourstore.com', required: true },
      { key: 'access_token', label: 'Access Token', type: 'password', required: true },
    ],
  },
  {
    value: 'opencart',
    label: 'OpenCart',
    instructions: 'Click Continue to download the Finovo module, upload it in OpenCart admin → Extensions → Installer, then install Finovo under Extensions → Extensions → Modules. Finovo on the same server as the shop can use the shop database instead.',
    submitLabel: 'Continue to OpenCart',
    directKeyFields: ['db_name'],
    fields: [
      { ...databaseFields[0], required: false, help: 'Only needed without the Finovo module.' },
      ...databaseFields.slice(1),
      { key: 'table_prefix', label: 'Table Prefix', type: 'text', placeholder: 'oc_' },
      { ...shopWebsiteField, help: 'Only needed without the Finovo module; used to import product images.' },
    ],
  },
  {
    value: 'oscommerce',
    label: 'osCommerce',
    instructions: 'osCommerce has no usable API, so Finovo reads orders and products from the shop database.',
    fields: [
      ...databaseFields,
      { key: 'table_prefix', label: 'Table Prefix', type: 'text', placeholder: 'Leave empty if none' },
      { ...shopWebsiteField, help: 'Used to import product images. osCommerce 4 shops can leave it empty.' },
    ],
  },
  {
    value: 'custom_bridge',
    label: 'Finovo Custom Bridge',
    instructions: 'For a custom website. Finovo generates an API key and secret after saving; add them to the bridge files on the website.',
    fields: [
      { key: 'bridge_url', label: 'Bridge Address', type: 'url', placeholder: 'https://yourstore.com/api', required: true },
    ],
  },
  {
    value: 'manual',
    label: 'Manual Store',
    instructions: 'No online platform. Orders for this store are entered in Finovo.',
    fields: [],
  },
];

export const platformDefinition = (platform: string): PlatformDefinition | undefined =>
  STORE_PLATFORMS.find((p) => p.value === platform);

export const platformLabel = (platform: string): string => platformDefinition(platform)?.label ?? platform;

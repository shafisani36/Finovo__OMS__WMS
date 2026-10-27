import apiClient, { getAuthToken } from './apiClient';
import type { Permission, UserRole } from '../types/auth';

export type StorePlatform =
  | 'shopify'
  | 'woocommerce'
  | 'bigcommerce'
  | 'prestashop'
  | 'wix'
  | 'ebay'
  | 'magento'
  | 'opencart'
  | 'oscommerce'
  | 'custom_bridge'
  | 'manual';

export interface StoreModel {
  id: number;
  name: string;
  code: string;
  platform: StorePlatform;
  status: 'active' | 'setup_incomplete' | 'disconnected';
  /** What the Stores page shows: connected, awaiting_authorization, setup_incomplete or disconnected. */
  connection_state: StoreConnectionState;
  sync_status: 'healthy' | 'sync_issues';
  orders_awaiting_sync: number;
  external_reference: string | null;
  connection_error: string | null;
  connected_at: string | null;
  last_sync_at: string | null;
  warehouses: WarehouseModel[];
  settings?: {
    /** Automatic import (webhooks): whether the store notifies Finovo of new orders and product changes. */
    webhooks?: { status: 'active' | 'needs_public_url' | 'failed' | 'unsupported'; message: string; checked_at?: string };
  } | null;
}

/** Store platforms whose orders, statuses and stock Finovo keeps up to date. */
export const ORDER_PLATFORMS: StorePlatform[] = ['shopify', 'woocommerce', 'bigcommerce', 'prestashop', 'wix', 'ebay', 'magento', 'opencart', 'oscommerce'];

/** Store platforms an order taken in Finovo can be created on (eBay orders are placed by buyers only). */
export const ORDER_CREATE_PLATFORMS: StorePlatform[] = ORDER_PLATFORMS.filter((platform) => platform !== 'ebay');

/** Store platforms whose catalog Finovo edits (products created, changed and deleted in Finovo). */
export const PRODUCT_PLATFORMS: StorePlatform[] = ORDER_PLATFORMS;

/** Store platforms that notify Finovo of new orders and product changes at once (the others are read every minute). */
export const AUTO_IMPORT_PLATFORMS: StorePlatform[] = ['shopify', 'woocommerce', 'bigcommerce', 'prestashop', 'opencart', 'wix'];

export type StoreConnectionState = 'connected' | 'awaiting_authorization' | 'setup_incomplete' | 'disconnected';

export interface ConnectStorePayload {
  name: string;
  platform: StorePlatform;
  warehouse_id: number;
  credentials: Record<string, string | number>;
}

export type StoreNextAction =
  | { type: 'redirect'; url: string }
  | { type: 'install_app'; message: string }
  | { type: 'install_module'; url: string; message: string };

export interface ConnectStoreResponse {
  status: string;
  message: string;
  data: StoreModel;
  next_action: StoreNextAction | null;
  credentials: { api_key: string; api_secret: string } | null;
}

export type WarehouseStatus = 'draft' | 'setup_incomplete' | 'ready_for_activation' | 'active' | 'inactive' | 'closing' | 'closed';
/** Facility types, the same list the backend accepts (Warehouse::TYPE_*). */
export const WAREHOUSE_TYPES = [
  { value: 'distribution_center', label: 'Distribution Center', description: 'Large regional storage and distribution hub' },
  { value: 'fulfillment_center', label: 'Fulfillment Center', description: 'High-velocity e-commerce picking and packing' },
  { value: 'storage_warehouse', label: 'Storage Warehouse', description: 'Long-term bulk inventory holding' },
  { value: 'retail_store', label: 'Retail Store / Showroom', description: 'Customer-facing store with backroom storage' },
  { value: 'manufacturing', label: 'Manufacturing Facility', description: 'Raw materials and production staging' },
  { value: 'cross_dock', label: 'Cross-Docking Facility', description: 'Inbound goods go straight to outbound' },
  { value: '3pl', label: 'Third-Party Logistics (3PL)', description: 'Partner-managed external fulfillment' },
  { value: 'quarantine', label: 'Returns & Quarantine', description: 'Inspection, quarantine and returns triage' },
] as const;

export type WarehouseType = (typeof WAREHOUSE_TYPES)[number]['value'];

export interface WarehouseLocationModel {
  id: number;
  warehouse_id: number;
  organization_id: number;
  parent_id?: number | null;
  code: string;
  name?: string;
  /** zone, aisle, rack, shelf, bin, or a top-level area type such as receiving or picking. */
  location_type: string;
  barcode?: string;
  picking_eligible: boolean;
  putaway_eligible: boolean;
  capacity?: number;
  capacity_unit?: string;
  weight_capacity?: number;
  sort_order: number;
  is_active: boolean;
  children?: WarehouseLocationModel[];
  parent?: WarehouseLocationModel;
}

export interface WarehouseStaffModel {
  id: number;
  user_id: number;
  /** Role in this warehouse (picker, packer, manager...). */
  role: string;
  status: 'active' | 'revoked';
  assigned_at: string | null;
  expires_at: string | null;
  assigned_by: string | null;
  user: { id: number; name: string; email: string; role_label: string | null } | null;
}

export interface WarehouseBin {
  id: number;
  code: string;
  /** Short code shown on the map inside its group (rack). */
  label: string;
  group: string | null;
  units: number;
  capacity: number | null;
  state: 'empty' | 'partial' | 'full';
  picking: boolean;
  putaway: boolean;
}

export interface WarehouseZone {
  id: number | null;
  code: string;
  type: string | null;
  aisles: string | null;
  main_category: string | null;
  units: number;
  bins_total: number;
  bins_free: number;
  /** Share of bins in use; null for zones without bins. */
  percent: number | null;
  bins: WarehouseBin[];
}

export interface WarehouseOverview {
  kpis: {
    total_skus: number;
    on_hand: number;
    reserved: number;
    available: number;
    low_stock: number;
    out_of_stock: number;
    capacity_percent: number;
    empty_bins: number;
  };
  categories: { total: number; items: { name: string; units: number; percent: number }[] };
  capacity: { total: number; occupied: number; empty: number; percent: number };
  zones: WarehouseZone[];
}

export type WarehouseActivityType = 'all' | 'stock' | 'orders' | 'setup';

export interface WarehouseActivityItem {
  id: string;
  kind: 'stock' | 'orders' | 'setup' | 'staff';
  who: string;
  text: string;
  at: string | null;
}

export interface WarehouseReadinessIssue {
  check: string;
  message: string;
  severity: 'error' | 'warning';
}

export interface WarehouseReadinessModel {
  warehouse_id: number;
  name: string;
  status: WarehouseStatus;
  ready_for_activation: boolean;
  score: number;
  passed_checks: string[];
  issues: WarehouseReadinessIssue[];
}

export interface WarehouseModel {
  id: number;
  name: string;
  code: string;
  warehouse_type?: WarehouseType;
  status: WarehouseStatus;
  is_default: boolean;
  priority: number;
  is_active: boolean;
  address?: string;
  address_line_2?: string;
  city?: string;
  state?: string;
  postal_code?: string;
  country?: string;
  timezone?: string;
  latitude?: number;
  longitude?: number;
  contact_name?: string;
  contact_email?: string;
  contact_phone?: string;
  parent_warehouse_id?: number | null;
  operating_hours?: Record<string, any>;
  inventory_policies?: Record<string, any>;
  inbound_policies?: Record<string, any>;
  outbound_policies?: Record<string, any>;
  integration_settings?: Record<string, any>;
  locations_count?: number;
  memberships_count?: number;
  readiness?: WarehouseReadinessModel;
}

export interface StockMovementItem {
  id: number;
  reference: string;
  type: string;
  product: string;
  sku: string;
  warehouse: string;
  quantity: number;
  direction: 'in' | 'out';
  before: number;
  after: number;
  user: string;
  date: string;
  status: string;
  reason?: string;
}

export interface PickingItem {
  id: number;
  order_id: number;
  order_number: string;
  customer: string;
  product: string;
  quantity: number;
  items_count: number;
  status: 'not_started' | 'in_progress' | 'picked' | 'packed';
  status_label: string;
  warehouse: string;
  priority: string;
  /** The open wave the order is picked in, if any. */
  wave_number: string | null;
}

/** One order line on the scan screen, with the units scanned so far and the bin to pick from. */
export interface PickLine {
  order_item_id: number;
  product_variant_id: number | null;
  sku: string;
  barcode: string | null;
  name: string;
  image_url: string | null;
  quantity: number;
  picked: number;
  packed: number;
  location: string | null;
  /** False for a line without a Finovo product: it has no barcode and is checked by eye. */
  scannable: boolean;
}

export interface PickScanTask {
  id: number;
  order_id: number;
  order_number: string | null;
  customer: string | null;
  warehouse: string | null;
  status: PickingItem['status'];
  /** pick at the shelf, pack at the packing station, null once packed. */
  stage: 'pick' | 'pack' | null;
  wave_number: string | null;
  units: number;
  scanned: number;
  lines: PickLine[];
}

export interface PickWaveSummary {
  id: number;
  wave_number: string;
  warehouse: string | null;
  orders: number;
  created_at: string;
}

export interface PickWaveDetail {
  id: number;
  wave_number: string;
  status: 'open' | 'completed' | 'cancelled';
  warehouse: string | null;
  created_by: string | null;
  created_at: string;
  units: number;
  picked: number;
  totes: { tote: number; task_id: number; order_number: string | null; customer: string | null; status: PickingItem['status'] }[];
  /** One row per product in the order of the bins, with the units each tote takes. */
  lines: {
    product_variant_id: number;
    sku: string;
    name: string;
    image_url: string | null;
    location: string | null;
    quantity: number;
    picked: number;
    totes: { tote: number; order_number: string | null; quantity: number; picked: number }[];
  }[];
}

export interface DemandForecastRow {
  warehouse_id: number;
  warehouse_name: string | null;
  product_variant_id: number;
  product_name: string | null;
  variant: string | null;
  sku: string | null;
  sold: number;
  daily_rate: number;
  available: number;
  in_transit: number;
  days_of_cover: number;
  stockout_date: string;
  lead_time_days: number;
  suggested_quantity: number;
  status: 'order_now' | 'order_soon' | 'healthy';
}

/** A 3PL client: a brand whose stock is kept and shipped, billed with its own rate card. */
export interface ClientRates {
  storage_per_unit_day: number;
  receiving_per_unit: number;
  order_fee: number;
  additional_unit_fee: number;
  return_fee: number;
  monthly_minimum: number;
}

export interface ClientInput extends ClientRates {
  name: string;
  code: string;
  contact_name: string | null;
  email: string | null;
  phone: string | null;
  billing_address: string | null;
  currency: string;
  payment_terms_days: number;
  is_active: boolean;
  store_ids: number[];
}

export interface ClientModel extends Omit<ClientInput, 'store_ids'> {
  id: number;
  stores: { id: number; name: string; platform: string }[];
  /** Total of issued, unpaid invoices. */
  outstanding: number;
  overdue_invoices: number;
  /** Last day covered by an invoice that is not void. */
  billed_until: string | null;
}

export interface BillingStore {
  id: number;
  name: string;
  platform: string;
  client_id: number | null;
}

export interface ClientInvoiceLine {
  id?: number;
  type: string;
  description: string;
  quantity: number;
  rate: number;
  amount: number;
}

export interface ClientCharges {
  from: string;
  to: string;
  currency: string;
  total: number;
  lines: ClientInvoiceLine[];
  activity: { unit_days: number; units_received: number; orders_shipped: number; units_shipped: number; additional_units: number; returns: number };
}

export interface ClientInvoiceModel {
  id: number;
  invoice_number: string;
  client_id: number;
  client: { id: number; name: string; code: string } | null;
  status: 'draft' | 'issued' | 'paid' | 'void';
  currency: string;
  total: number;
  notes: string | null;
  period_start: string;
  period_end: string;
  due_date: string | null;
  is_overdue: boolean;
  issued_at: string | null;
  paid_at: string | null;
  created_at: string;
  lines?: ClientInvoiceLine[];
}

export type MoneyByCurrency = { currency: string; amount: number }[];

export interface ShipmentModel {
  id: number;
  order_id: number;
  order_number: string | null;
  customer: string;
  store: string | null;
  items_count: number;
  total_amount: string | null;
  currency: string | null;
  warehouse: string | null;
  carrier: string | null;
  tracking_number: string | null;
  tracking_url: string | null;
  status: 'pending' | 'shipped' | 'delivered';
  packed_at: string | null;
  shipped_at: string | null;
  delivered_at: string | null;
  dispatched_by: string | null;
}

export interface ShipmentKpis {
  ready_to_dispatch: number;
  dispatched_today: number;
  in_transit: number;
  delivered: number;
}

export interface ReturnModel {
  id: number;
  return_number: string;
  order_id: number;
  order_number: string | null;
  customer: string;
  store: string | null;
  warehouse: string | null;
  status: 'requested' | 'received' | 'completed' | 'rejected';
  reason: string | null;
  disposition: 'sellable' | 'damaged' | 'quarantine' | 'disposed' | null;
  notes: string | null;
  items_count: number;
  items: { product_name: string; sku: string | null; quantity: number }[];
  created_by: string | null;
  created_at: string | null;
  received_at: string | null;
  completed_at: string | null;
}

export interface ReturnKpis {
  requested: number;
  received: number;
  completed: number;
  rejected: number;
  restocked_units: number;
}

export interface ReturnableOrder {
  id: number;
  order_number: string;
  customer: string;
  items: { id: number; product_name: string; sku: string | null; shipped_quantity: number; returnable_quantity: number }[];
}

export type EmployeeRole = UserRole;
export type EmployeeStatus = 'active' | 'suspended';
/** all: every warehouse of the company. assigned: only the warehouses picked for the employee. */
export type WarehouseAccess = 'all' | 'assigned';

export interface EmployeeModel {
  id: number;
  name: string;
  email: string;
  role: EmployeeRole;
  role_label: string;
  status: EmployeeStatus;
  warehouse_access: WarehouseAccess;
  warehouses: { id: number; name: string }[];
  /** How many actions were allowed or denied for this employee alone. */
  custom_permissions: number;
  last_login_at: string | null;
  created_at: string | null;
  /** The signed-in user's own account. */
  is_you: boolean;
}

export interface EmployeeKpis {
  total: number;
  active: number;
  suspended: number;
  admins: number;
}

export interface EmployeePayload {
  name: string;
  email: string;
  role: EmployeeRole;
  password?: string;
  password_confirmation?: string;
  warehouse_access: WarehouseAccess;
  /** Sent only with "assigned" access; left out, current assignments are kept. */
  warehouse_ids?: number[];
}

export interface EmployeeOptions {
  roles: { value: EmployeeRole; label: string; description: string }[];
  warehouses: { id: number; name: string; code: string | null }[];
}

export type PermissionEffect = 'allow' | 'deny' | 'inherit';

export interface PermissionRow {
  key: Permission;
  label: string;
  /** People-and-access actions only an administrator can hand out. */
  sensitive: boolean;
  role_default: boolean;
  override: 'allow' | 'deny' | null;
  effective: boolean;
  can_change: boolean;
}

export interface PermissionSheet {
  employee: { id: number; name: string; role: EmployeeRole; role_label: string };
  /** Changes when anyone saves; sent back so a stale screen cannot overwrite newer changes. */
  version: number;
  editable: boolean;
  modules: { module: string; permissions: PermissionRow[] }[];
}

export interface StockLevels {
  on_hand: number;
  reserved: number;
  available: number;
  damaged: number;
  in_transit: number;
}

/** A value the store platform gave about a product or order: text, number, a list (e.g. tags) or a link. */
export type PlatformDetail = string | number | boolean | null | (string | number)[];

/** Everything known about one product, for its page. */
export interface ProductDetail {
  id: number;
  name: string;
  sku: string;
  barcode: string | null;
  category: string | null;
  description: string | null;
  is_active: boolean;
  image_url: string | null;
  images: string[];
  details: Record<string, PlatformDetail>;
  created_at: string | null;
  updated_at: string | null;
  stock: StockLevels;
  warehouses: ({ id: number; name: string | null; code: string | null } & StockLevels)[];
  variants: {
    id: number;
    sku: string;
    barcode: string | null;
    option_name: string | null;
    option_value: string | null;
    image_url: string | null;
    price: number;
    cost_price: number | null;
    is_active: boolean;
    stock: StockLevels;
    warehouses: ({ id: number; name: string | null } & StockLevels)[];
  }[];
  stores: {
    id: number;
    name: string | null;
    platform: StorePlatform | null;
    listings: { external_id: string; external_sku: string | null; external_name: string | null }[];
    /** The store's complete original product record, as it sent it. */
    platform_data: Record<string, unknown> | null;
  }[];
  /** Every store the product is on (used to preselect stores when editing). */
  store_ids: number[];
  sales: { orders: number; units: number };
  recent_movements: {
    id: number;
    type: string;
    quantity: number;
    balance_after: number | null;
    warehouse: string | null;
    reference: string | null;
    reason: string | null;
    created_at: string | null;
  }[];
}

export interface BarcodeSettings {
  format: 'CODE128' | 'CODE39' | 'EAN13' | 'UPC';
  width: number;
  height: number;
  display_value: boolean;
  font_size: number;
  margin: number;
  /** Store product/order details inside the barcode so phone scanners show them as text. */
  embed_details: boolean;
}

/** An order with its fulfillment state and the next steps it allows. */
export interface OrderFulfillment {
  id: number;
  order_number: string;
  customer: string;
  customer_email: string | null;
  store: string | null;
  order_status: string;
  payment_status: string;
  fulfillment_status: string;
  total_amount: string;
  currency: string | null;
  placed_at: string | null;
  items: { product_name: string; sku: string | null; quantity: number }[];
  /** The warehouse the order was sent to. */
  warehouse: { id: number; name: string } | null;
  can_allocate: boolean;
  /** Warehouses the order can be sent to, and whether each has the stock for it. */
  warehouse_options: { id: number; name: string; code: string | null; can_fulfil: boolean }[];
  can_cancel: boolean;
  pick_task: { id: number; status: 'not_started' | 'in_progress' | 'picked' | 'packed' } | null;
  shipment: { id: number; status: 'pending' | 'shipped' | 'delivered'; carrier: string | null; tracking_number: string | null; tracking_url: string | null } | null;
}

export interface ScannedProduct {
  id: number;
  product_id: number;
  name: string;
  variant: string | null;
  sku: string;
  barcode: string | null;
  image_url: string | null;
  price: string;
  is_active: boolean;
  stock: { on_hand: number; available: number; reserved: number };
  warehouses: { warehouse: string | null; on_hand: number; available: number; reserved: number }[];
}

export type ScanResult = { type: 'order'; data: OrderFulfillment } | { type: 'product'; data: ScannedProduct };

export interface ProductVariantModel {
  id: number;
  sku: string;
  name?: string;
  option_name?: string | null;
  option_value?: string | null;
  barcode?: string | null;
  price: number;
  cost_price?: number | null;
  is_active?: boolean;
  stock?: { on_hand: number; reserved: number; available: number };
}

export interface ProductVariantPayload {
  id?: number;
  sku: string;
  option_name?: string | null;
  option_value?: string | null;
  barcode?: string | null;
  price: number;
  cost_price?: number | null;
  is_active?: boolean;
}

export interface ProductPayload {
  name: string;
  sku: string;
  category?: string | null;
  description?: string | null;
  image_url?: string | null;
  is_active?: boolean;
  variants: ProductVariantPayload[];
  /** Stores to add the product to; stores it is already on always get the change. */
  store_ids?: number[];
}

/** What happened on each store after a change was sent to the connected stores. */
export interface PushReport {
  saved: string[];
  failed: Record<string, string>;
  skipped: string[];
}

export interface ProductModel {
  id: number;
  name: string;
  sku: string;
  barcode?: string;
  category?: string;
  description?: string | null;
  image_url?: string | null;
  is_active: boolean;
  default_variant_id?: number;
  variants?: ProductVariantModel[];
  variants_count?: number;
  stores?: Array<{ id: number; name: string; platform: StorePlatform }>;
  stock: {
    on_hand: number;
    reserved: number;
    available: number;
  };
}

export interface InventoryLocationItemModel {
  location_id: number;
  location_code: string;
  location_type: string;
  lot_number?: string | null;
  expiry_date?: string | null;
  condition: string;
  quantity: number;
  reserved: number;
  available: number;
}

export interface InventoryItemModel {
  id: number;
  warehouse_id: number;
  warehouse_name: string;
  warehouse_code: string;
  product_id: number;
  product_variant_id: number;
  product_name: string;
  sku: string;
  barcode?: string | null;
  category: string;
  base_uom: string;
  tracking_type: string;
  price: number;
  cost_price: number;
  on_hand: number;
  reserved: number;
  available: number;
  damaged: number;
  quarantined: number;
  in_transit: number;
  picked: number;
  locations?: InventoryLocationItemModel[];
}

export interface InventoryKpiModel {
  total_skus: number;
  total_on_hand: number;
  total_reserved: number;
  total_available: number;
  total_quarantined: number;
  total_damaged: number;
  total_in_transit: number;
  low_stock_count: number;
  out_of_stock_count: number;
  total_valuation: number;
}

export interface InventorySkuDetailModel {
  variant_id: number;
  product_id: number;
  product_name: string;
  sku: string;
  barcode?: string;
  category: string;
  base_uom: string;
  tracking_type: string;
  price: number;
  cost_price: number;
  total_on_hand: number;
  total_reserved: number;
  total_available: number;
  warehouse_balances: Array<{
    warehouse_id: number;
    warehouse_name: string;
    warehouse_code: string;
    on_hand: number;
    reserved: number;
    available: number;
    damaged: number;
    quarantined: number;
    in_transit: number;
  }>;
  location_balances: InventoryLocationItemModel[];
  recent_movements: Array<{
    id: number;
    reference: string;
    type: string;
    warehouse: string;
    location: string;
    quantity: number;
    before: number;
    after: number;
    reason: string;
    user: string;
    date: string;
  }>;
  active_reservations: Array<{
    id: number;
    order_id: number;
    warehouse_name: string;
    quantity: number;
    status: string;
    created_at: string;
  }>;
}

export interface InventoryReceiptModel {
  id: number;
  receipt_number: string;
  warehouse_id: number;
  warehouse_name: string;
  receiving_location: string;
  supplier_name?: string;
  purchase_order_number?: string;
  status: 'draft' | 'received' | 'putaway_in_progress' | 'completed' | 'cancelled';
  total_expected: number;
  total_received: number;
  items_count: number;
  received_by?: string | null;
  received_at?: string | null;
  created_at: string;
  items?: Array<{
    id: number;
    product_variant_id: number;
    product_name: string;
    sku: string;
    expected_quantity: number;
    received_quantity: number;
    lot_number?: string | null;
    expiry_date?: string | null;
    condition: string;
    status: string;
  }>;
}

export interface InventoryTransferModel {
  id: number;
  transfer_number: string;
  product_name: string;
  sku: string;
  from_warehouse_id: number;
  from_warehouse: string;
  from_location: string;
  to_warehouse_id: number;
  to_warehouse: string;
  to_location: string;
  quantity: number;
  received_quantity?: number | null;
  discrepancy_quantity: number;
  discrepancy_reason?: string | null;
  lot_number?: string | null;
  status: 'pending' | 'in_transit' | 'completed' | 'cancelled';
  notes?: string | null;
  dispatched_at?: string | null;
  received_at?: string | null;
  created_by: string;
  received_by?: string | null;
  created_at: string;
}

export interface StockAdjustmentModel {
  id: number;
  adjustment_number: string;
  warehouse_id: number;
  warehouse_name: string;
  location: string;
  product_name: string;
  sku: string;
  adjustment_type: 'add' | 'subtract' | 'recount';
  quantity: number;
  current_quantity: number;
  new_quantity: number;
  status: 'pending_approval' | 'approved' | 'rejected' | 'posted';
  reason: string;
  notes?: string | null;
  lot_number?: string | null;
  requested_by: string;
  approved_by?: string | null;
  approved_at?: string | null;
  created_at: string;
}

export interface CycleCountModel {
  id: number;
  count_number: string;
  title: string;
  warehouse_id: number;
  warehouse_name: string;
  status: 'draft' | 'in_progress' | 'review' | 'posted' | 'cancelled';
  scope_type: 'full' | 'zone' | 'location';
  scope_value?: string | null;
  total_items: number;
  counted_items: number;
  variance_count: number;
  notes?: string | null;
  created_by: string;
  approved_by?: string | null;
  posted_at?: string | null;
  created_at: string;
  items?: Array<{
    id: number;
    product_variant_id: number;
    product_name: string;
    sku: string;
    location_code: string;
    lot_number?: string | null;
    snapshot_quantity: number;
    counted_quantity: number | null;
    variance_quantity: number;
    status: string;
    notes?: string | null;
  }>;
}

export interface ReorderRuleModel {
  id: number;
  warehouse_id: number;
  warehouse_name: string;
  product_variant_id: number;
  product_name: string;
  sku: string;
  min_quantity: number;
  reorder_point: number;
  reorder_quantity: number;
  safety_stock: number;
  lead_time_days: number;
  preferred_supplier?: string | null;
  is_active: boolean;
}

export interface ReplenishmentRecommendationModel {
  rule_id: number;
  warehouse_id: number;
  warehouse_name: string;
  product_variant_id: number;
  product_name: string;
  sku: string;
  on_hand: number;
  reserved: number;
  in_transit: number;
  effective_position: number;
  reorder_point: number;
  safety_stock: number;
  suggested_quantity: number;
  lead_time_days: number;
  preferred_supplier: string;
  urgency: 'critical' | 'high' | 'medium';
}

export interface OrderItemModel {
  id: number;
  product_name: string;
  external_sku: string;
  /** Photo the store sent with this line, e.g. the chosen colour. */
  image_url?: string | null;
  quantity: number;
  unit_price: string;
  tax_amount?: string;
  discount_amount?: string;
  total_amount?: string;
  status: string;
  variant?: {
    id: number;
    sku: string;
    option_name?: string | null;
    option_value?: string | null;
    product?: { id: number; name: string; image_url?: string | null } | null;
  } | null;
}

export interface OrderAddress {
  name?: string;
  company?: string;
  address1?: string;
  address2?: string;
  city?: string;
  province?: string;
  zip?: string;
  country_code?: string;
  phone?: string;
}

export interface OrderPayload {
  store_id?: number | null;
  customer_name: string;
  customer_email?: string | null;
  customer_phone?: string | null;
  shipping_address?: OrderAddress | null;
  payment_status: 'pending' | 'paid';
  payment_method?: string | null;
  currency: string;
  notes?: string | null;
  shipping_amount?: number;
  discount_amount?: number;
  tax_amount?: number;
  /** Left out when editing an order whose lines do not change. */
  items?: { product_variant_id: number; quantity: number; unit_price: number }[];
}

export interface OrderModel {
  id: number;
  order_number: string;
  invoice_number?: string | null;
  /** The store's ID for the order; empty for an order that exists only in Finovo. */
  external_order_id: string | null;
  /** Who created the order by hand in Finovo (empty for imported orders). */
  created_by?: number | null;
  customer_name: string;
  customer_email?: string;
  customer_phone?: string | null;
  shipping_address?: Record<string, string> | null;
  billing_address?: Record<string, string> | null;
  order_status: string;
  payment_status: string;
  fulfillment_status: string;
  subtotal_amount?: string | null;
  shipping_amount?: string | null;
  tax_amount?: string | null;
  discount_amount?: string | null;
  payment_method?: string | null;
  total_amount: string;
  currency: string;
  notes?: string | null;
  /** Other facts the store gave (tags, sales channel...), by label. */
  details?: Record<string, PlatformDetail> | null;
  placed_at: string;
  store?: { id: number; name: string; platform: StorePlatform } | null;
  items?: OrderItemModel[];
}

export interface StockAlert {
  warehouse_id: number;
  warehouse_name: string | null;
  product_variant_id: number;
  product_name: string | null;
  variant: string | null;
  sku: string | null;
  image_url: string | null;
  available: number;
  threshold: number;
  has_rule: boolean;
  status: 'out_of_stock' | 'low_stock';
}

export interface StockAlertsResponse {
  status: string;
  data: StockAlert[];
  meta: { total: number; out_of_stock: number; low_stock: number; default_threshold: number };
}

export interface CustomerAddress {
  name?: string;
  company?: string;
  address1?: string;
  address2?: string;
  city?: string;
  province?: string;
  zip?: string;
  country?: string;
  country_code?: string;
  phone?: string;
}

export interface CustomerStoreRef {
  id: number;
  name: string;
  platform: StorePlatform;
}

/** Money spent in one currency (amounts in different currencies are never added together). */
export interface CurrencySpend {
  currency: string;
  amount: number;
  orders: number;
  average: number;
}

export interface CustomerModel {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  default_address: CustomerAddress | null;
  created_at: string;
  orders_count: number;
  spent: CurrencySpend[];
  first_order_at: string | null;
  latest_order_at: string | null;
  stores: CustomerStoreRef[];
}

export interface CustomerOrderModel {
  id: number;
  order_number: string;
  external_order_id: string;
  store: CustomerStoreRef | null;
  order_status: string;
  payment_status: string;
  fulfillment_status: string;
  total_amount: string;
  currency: string;
  notes: string | null;
  placed_at: string | null;
  shipping_address: CustomerAddress | null;
  items: Array<{
    id: number;
    product_name: string;
    external_sku: string;
    image_url: string | null;
    quantity: number;
    unit_price: string;
    total_amount: string;
    status: string;
  }>;
}

export interface CustomerDetailModel {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  default_address: CustomerAddress | null;
  created_at: string;
  stats: {
    orders_count: number;
    cancelled_orders: number;
    spent: CurrencySpend[];
    items_purchased: number;
    first_order_at: string | null;
    last_order_at: string | null;
  };
  stores: Array<CustomerStoreRef & { orders_count: number; last_order_at: string | null }>;
  orders: CustomerOrderModel[];
}

export /** Downloads a PDF from the API (with the signed-in user's token) under the name the server gives it. */
const downloadPdf = async (path: string, fallbackName: string, failure: string) => {
  const token = getAuthToken();
  const response = await fetch(path, {
    headers: token ? { Authorization: `Bearer ${token}` } : {},
    credentials: 'include',
  });
  if (!response.ok) {
    const body = await response.json().catch(() => null);
    throw new Error(body?.message || failure);
  }
  const filename = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
  const url = window.URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
};

const omsService = {
  // Stores
  async getStores(params?: { search?: string; status?: string; platform?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/stores', { params });
    return data;
  },

  /** Switch on (or check again) automatic import of the store's orders and products. */
  async enableStoreWebhooks(storeId: number): Promise<{ message: string; data: StoreModel }> {
    const { data } = await apiClient.post(`/stores/${storeId}/webhooks`);
    return data;
  },

  async createStore(payload: ConnectStorePayload): Promise<ConnectStoreResponse> {
    const { data } = await apiClient.post('/stores', payload);
    return data;
  },

  async getStoreAuthorization(storeId: number): Promise<{ next_action: StoreNextAction }> {
    const { data } = await apiClient.get(`/stores/${storeId}/authorization`);
    return data;
  },

  async testStoreConnection(storeId: number): Promise<{ status: string; message: string; data: StoreModel }> {
    const { data } = await apiClient.post(`/stores/${storeId}/test-connection`);
    return data;
  },

  async deleteStore(storeId: number): Promise<{ status: string; message: string }> {
    const { data } = await apiClient.delete(`/stores/${storeId}`);
    return data;
  },

  async syncStore(storeId: number) {
    const { data } = await apiClient.post(`/stores/${storeId}/sync`);
    return data;
  },

  /** Import the store's new and changed orders (skipped by the server when it was read in the last minute). */
  async syncStoreOrders(storeId: number): Promise<{ imported: boolean; orders_created: number; orders_updated: number }> {
    const { data } = await apiClient.post(`/stores/${storeId}/sync-orders`);
    return data.data;
  },

  // Warehouses
  async getWarehouseMetadata() {
    const { data } = await apiClient.get('/warehouses/metadata');
    return data;
  },

  async getWarehouses(params?: { search?: string; status?: string; type?: string }) {
    const { data } = await apiClient.get('/warehouses', { params });
    return data;
  },

  async getWarehouse(id: number) {
    const { data } = await apiClient.get(`/warehouses/${id}`);
    return data;
  },

  async createWarehouse(payload: any) {
    const { data } = await apiClient.post('/warehouses', payload);
    return data;
  },

  async updateWarehouse(id: number, payload: any) {
    const { data } = await apiClient.put(`/warehouses/${id}`, payload);
    return data;
  },

  async getWarehouseReadiness(id: number) {
    const { data } = await apiClient.get(`/warehouses/${id}/readiness`);
    return data;
  },

  async activateWarehouse(id: number) {
    const { data } = await apiClient.post(`/warehouses/${id}/activate`);
    return data;
  },

  async deactivateWarehouse(id: number) {
    const { data } = await apiClient.post(`/warehouses/${id}/deactivate`);
    return data;
  },

  async deleteWarehouse(id: number): Promise<{ status: string; message: string }> {
    const { data } = await apiClient.delete(`/warehouses/${id}`);
    return data;
  },

  async closeWarehouse(id: number, notes?: string) {
    const { data } = await apiClient.post(`/warehouses/${id}/close`, { notes });
    return data;
  },

  // Physical Layout & Locations
  async getWarehouseLocations(warehouseId: number, params?: { parent_id?: number; location_type?: string; per_page?: number }) {
    const { data } = await apiClient.get(`/warehouses/${warehouseId}/locations`, { params });
    return data;
  },

  async createWarehouseLocation(warehouseId: number, payload: any) {
    const { data } = await apiClient.post(`/warehouses/${warehouseId}/locations`, payload);
    return data;
  },

  async updateWarehouseLocation(warehouseId: number, locationId: number, payload: any) {
    const { data } = await apiClient.put(`/warehouses/${warehouseId}/locations/${locationId}`, payload);
    return data;
  },

  async deleteWarehouseLocation(warehouseId: number, locationId: number) {
    const { data } = await apiClient.delete(`/warehouses/${warehouseId}/locations/${locationId}`);
    return data;
  },

  async generateStarterLayout(warehouseId: number, payload: {
    preview?: boolean;
    aisles_count?: number;
    racks_per_aisle?: number;
    shelves_per_rack?: number;
    bins_per_shelf?: number;
    zone_code?: string;
  }) {
    const { data } = await apiClient.post(`/warehouses/${warehouseId}/locations/starter-layout`, payload);
    return data;
  },

  // Staff & Access
  async getWarehouseStaff(warehouseId: number): Promise<{
    data: { assigned_staff: WarehouseStaffModel[]; eligible_users: { id: number; name: string; email: string; role: string }[] };
  }> {
    const { data } = await apiClient.get(`/warehouses/${warehouseId}/staff`);
    return data;
  },

  async assignWarehouseStaff(warehouseId: number, payload: { user_id: number; role: string; permissions?: string[] }) {
    const { data } = await apiClient.post(`/warehouses/${warehouseId}/staff`, payload);
    return data;
  },

  async removeWarehouseStaff(warehouseId: number, membershipId: number) {
    const { data } = await apiClient.delete(`/warehouses/${warehouseId}/staff/${membershipId}`);
    return data;
  },

  // Controlled Opening Stock
  async previewOpeningStock(warehouseId: number, items: Array<{
    sku: string;
    location_code: string;
    quantity: number;
    lot_number?: string;
    expiry_date?: string;
  }>) {
    const { data } = await apiClient.post(`/warehouses/${warehouseId}/opening-stock/preview`, { items });
    return data;
  },

  async commitOpeningStock(warehouseId: number, payload: {
    items: Array<{
      sku: string;
      location_code: string;
      quantity: number;
      lot_number?: string;
      expiry_date?: string;
    }>;
    idempotency_key?: string;
  }) {
    const { data } = await apiClient.post(`/warehouses/${warehouseId}/opening-stock/commit`, payload);
    return data;
  },

  async getWarehouseOverview(warehouseId: number): Promise<{ data: WarehouseOverview }> {
    const { data } = await apiClient.get(`/warehouses/${warehouseId}/overview`);
    return data;
  },

  async getWarehouseActivity(
    warehouseId: number,
    params: { type?: WarehouseActivityType; page?: number; per_page?: number }
  ): Promise<{ data: WarehouseActivityItem[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }> {
    const { data } = await apiClient.get(`/warehouses/${warehouseId}/activity`, { params });
    return data;
  },

  // Stock Movements & Inventory
  async getStockMovements(params?: { store_id?: number; search?: string; type?: string; warehouse_id?: number; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/inventory/movements', { params });
    return data;
  },

  async transferStock(payload: { product_id: number; from_warehouse_id: number; to_warehouse_id: number; quantity: number; notes?: string }) {
    const { data } = await apiClient.post('/inventory/transfers', payload);
    return data;
  },

  async adjustStock(payload: { product_id: number; warehouse_id: number; adjustment_type: 'add' | 'subtract' | 'recount'; quantity: number; reason: string; notes?: string }) {
    const { data } = await apiClient.post('/inventory/adjustments', payload);
    return data;
  },

  async getAvailability(productId?: number, warehouseId?: number) {
    const { data } = await apiClient.get('/inventory/availability', { params: { product_id: productId, warehouse_id: warehouseId } });
    return data;
  },

  // Enterprise Inventory Engine
  async getInventory(params?: { search?: string; warehouse_id?: number; store_id?: number; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/inventory', { params });
    return data;
  },

  async getInventoryKpis(warehouseId?: number, storeId?: number) {
    const { data } = await apiClient.get('/inventory/kpis', { params: { warehouse_id: warehouseId, store_id: storeId } });
    return data;
  },

  async getInventorySkuDetail(variantId: number) {
    const { data } = await apiClient.get(`/inventory/sku/${variantId}`);
    return data;
  },

  async reconcileInventory(warehouseId?: number) {
    const { data } = await apiClient.post('/inventory/reconcile', { warehouse_id: warehouseId });
    return data;
  },

  // Inbound Receipts & Putaway
  async getInventoryReceipts(params?: { search?: string; warehouse_id?: number; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/inventory/receipts', { params });
    return data;
  },

  async createInventoryReceipt(payload: {
    warehouse_id: number;
    supplier_name?: string;
    purchase_order_number?: string;
    receiving_location_id?: number;
    notes?: string;
    items: Array<{
      product_variant_id: number;
      expected_quantity: number;
      lot_number?: string;
      expiry_date?: string;
      unit_cost?: number;
    }>;
  }) {
    const { data } = await apiClient.post('/inventory/receipts', payload);
    return data;
  },

  async confirmInventoryReceipt(receiptId: number, payload: {
    items: Array<{
      item_id: number;
      received_quantity: number;
      location_id?: number;
      lot_number?: string;
      expiry_date?: string;
      condition?: string;
    }>;
  }) {
    const { data } = await apiClient.post(`/inventory/receipts/${receiptId}/confirm`, payload);
    return data;
  },

  async putawayReceiptItem(receiptId: number, payload: {
    item_id: number;
    destination_location_id?: number;
    destination_location_code?: string;
    quantity: number;
  }) {
    const { data } = await apiClient.post(`/inventory/receipts/${receiptId}/putaway`, payload);
    return data;
  },

  // Multi-Stage Transfers
  async getInventoryTransfers(params?: { search?: string; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/inventory/transfers', { params });
    return data;
  },

  async createInventoryTransfer(payload: {
    from_warehouse_id: number;
    to_warehouse_id: number;
    product_variant_id: number;
    quantity: number;
    from_location_id?: number;
    to_location_id?: number;
    lot_number?: string;
    notes?: string;
    in_transit_only?: boolean;
  }) {
    const { data } = await apiClient.post('/inventory/transfers', payload);
    return data;
  },

  async receiveInventoryTransfer(transferId: number, payload: {
    received_quantity: number;
    destination_location_id?: number;
    discrepancy_reason?: string;
  }) {
    const { data } = await apiClient.post(`/inventory/transfers/${transferId}/receive`, payload);
    return data;
  },

  // Adjustments & Approvals
  async getInventoryAdjustments(params?: { search?: string; warehouse_id?: number; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/inventory/adjustments', { params });
    return data;
  },

  async createInventoryAdjustment(payload: {
    warehouse_id: number;
    product_variant_id: number;
    adjustment_type: 'add' | 'subtract' | 'recount';
    quantity: number;
    reason: string;
    notes?: string;
    warehouse_location_id?: number;
    lot_number?: string;
    require_approval?: boolean;
  }) {
    const { data } = await apiClient.post('/inventory/adjustments', payload);
    return data;
  },

  async approveInventoryAdjustment(adjustmentId: number) {
    const { data } = await apiClient.post(`/inventory/adjustments/${adjustmentId}/approve`);
    return data;
  },

  async rejectInventoryAdjustment(adjustmentId: number) {
    const { data } = await apiClient.post(`/inventory/adjustments/${adjustmentId}/reject`);
    return data;
  },

  // Cycle Counts
  async getCycleCounts(params?: { warehouse_id?: number; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/inventory/cycle-counts', { params });
    return data;
  },

  async createCycleCount(payload: {
    warehouse_id: number;
    title: string;
    scope_type: 'full' | 'zone' | 'location';
    scope_value?: string;
    notes?: string;
  }) {
    const { data } = await apiClient.post('/inventory/cycle-counts', payload);
    return data;
  },

  async recordCycleCounts(countId: number, counts: Array<{ item_id: number; counted_quantity: number; notes?: string }>) {
    const { data } = await apiClient.put(`/inventory/cycle-counts/${countId}/record-counts`, { counts });
    return data;
  },

  async approveAndPostCycleCount(countId: number) {
    const { data } = await apiClient.post(`/inventory/cycle-counts/${countId}/approve-and-post`);
    return data;
  },

  // Replenishment & Reorder
  async getReplenishmentRules() {
    const { data } = await apiClient.get('/inventory/replenishment/rules');
    return data;
  },

  async saveReplenishmentRule(payload: {
    warehouse_id: number;
    product_variant_id: number;
    min_quantity: number;
    reorder_point: number;
    reorder_quantity: number;
    safety_stock?: number;
    lead_time_days?: number;
    preferred_supplier?: string;
  }) {
    const { data } = await apiClient.post('/inventory/replenishment/rules', payload);
    return data;
  },

  /** Out-of-stock and low-stock items for the notification bell (at most 50, worst first). */
  async getStockAlerts(): Promise<StockAlertsResponse> {
    const { data } = await apiClient.get('/inventory/replenishment/alerts');
    return data;
  },

  async getReplenishmentRecommendations(warehouseId?: number) {
    const { data } = await apiClient.get('/inventory/replenishment/recommendations', { params: { warehouse_id: warehouseId } });
    return data;
  },

  /** Sales speed, days of stock left and the quantity to order, per item and warehouse. */
  async getDemandForecast(params: { warehouse_id?: number; days?: number; cover_days?: number }): Promise<{ data: DemandForecastRow[]; meta: { days: number; cover_days: number; order_now: number; order_soon: number } }> {
    const { data } = await apiClient.get('/inventory/replenishment/forecast', { params });
    return data;
  },

  // Picking & Packing
  async getPickingTasks(params?: { store_id?: number; search?: string; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/picking', { params });
    return data;
  },

  async updatePickStatus(taskId: number, status: string) {
    const { data } = await apiClient.put(`/picking/${taskId}/status`, { status });
    return data;
  },

  async completePickTask(taskId: number) {
    const { data } = await apiClient.post(`/picking/${taskId}/complete`);
    return data;
  },

  async bulkUpdatePickTasks(taskIds: number[], status: string) {
    const { data } = await apiClient.post('/picking/bulk-update', { task_ids: taskIds, status });
    return data;
  },

  async getPickLines(taskId: number): Promise<PickScanTask> {
    const { data } = await apiClient.get(`/picking/${taskId}/lines`);
    return data.data;
  },

  /** Scan one unit of the order (picked, or checked again at packing). */
  async scanPickUnit(taskId: number, code: string): Promise<{ message: string; completed: boolean; data: PickScanTask }> {
    const { data } = await apiClient.post(`/picking/${taskId}/scan`, { code });
    return data;
  },

  async getPickWaves(): Promise<PickWaveSummary[]> {
    const { data } = await apiClient.get('/picking/waves');
    return data.data;
  },

  async createPickWave(taskIds: number[]): Promise<{ message: string; data: PickWaveDetail }> {
    const { data } = await apiClient.post('/picking/waves', { task_ids: taskIds });
    return data;
  },

  async getPickWave(waveId: number): Promise<PickWaveDetail> {
    const { data } = await apiClient.get(`/picking/waves/${waveId}`);
    return data.data;
  },

  /** Scan one unit for a wave; the reply names the tote it goes into. */
  async scanWaveUnit(waveId: number, code: string): Promise<{ message: string; tote: number; data: PickWaveDetail }> {
    const { data } = await apiClient.post(`/picking/waves/${waveId}/scan`, { code });
    return data;
  },

  async cancelPickWave(waveId: number): Promise<{ message: string }> {
    const { data } = await apiClient.delete(`/picking/waves/${waveId}`);
    return data;
  },

  // Shipments
  async getShipments(params?: { store_id?: number; search?: string; status?: string; page?: number; per_page?: number }): Promise<{
    data: ShipmentModel[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
    kpis: ShipmentKpis;
  }> {
    const { data } = await apiClient.get('/shipments', { params });
    return data;
  },

  async dispatchShipment(shipmentId: number, payload: { carrier: string; tracking_number?: string; tracking_url?: string }) {
    const { data } = await apiClient.post(`/shipments/${shipmentId}/dispatch`, payload);
    return data;
  },

  async deliverShipment(shipmentId: number) {
    const { data } = await apiClient.post(`/shipments/${shipmentId}/deliver`);
    return data;
  },

  // Returns
  async getReturns(params?: { store_id?: number; search?: string; status?: string; page?: number; per_page?: number }): Promise<{
    data: ReturnModel[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
    kpis: ReturnKpis;
  }> {
    const { data } = await apiClient.get('/returns', { params });
    return data;
  },

  async getReturnableOrders(params?: { store_id?: number; search?: string }): Promise<{ data: ReturnableOrder[] }> {
    const { data } = await apiClient.get('/returns/returnable-orders', { params });
    return data;
  },

  async createReturn(payload: { order_id: number; reason: string; notes?: string; items: { order_item_id: number; quantity: number }[] }) {
    const { data } = await apiClient.post('/returns', payload);
    return data;
  },

  async receiveReturn(returnId: number) {
    const { data } = await apiClient.post(`/returns/${returnId}/receive`);
    return data;
  },

  async completeReturn(returnId: number, payload: { disposition: string; notes?: string }) {
    const { data } = await apiClient.post(`/returns/${returnId}/complete`, payload);
    return data;
  },

  async rejectReturn(returnId: number, reason: string) {
    const { data } = await apiClient.post(`/returns/${returnId}/reject`, { reason });
    return data;
  },

  // Employees
  async getEmployees(params?: { search?: string; role?: string; status?: string; page?: number; per_page?: number }): Promise<{
    data: EmployeeModel[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
    kpis: EmployeeKpis;
    options: EmployeeOptions;
  }> {
    const { data } = await apiClient.get('/employees', { params });
    return data;
  },

  async createEmployee(payload: EmployeePayload): Promise<{ message: string; data: EmployeeModel }> {
    const { data } = await apiClient.post('/employees', payload);
    return data;
  },

  async updateEmployee(id: number, payload: EmployeePayload): Promise<{ message: string; data: EmployeeModel }> {
    const { data } = await apiClient.put(`/employees/${id}`, payload);
    return data;
  },

  async setEmployeeStatus(id: number, status: EmployeeStatus): Promise<{ message: string; data: EmployeeModel }> {
    const { data } = await apiClient.put(`/employees/${id}/status`, { status });
    return data;
  },

  async resetEmployeePassword(id: number, password: string, password_confirmation: string): Promise<{ message: string }> {
    const { data } = await apiClient.post(`/employees/${id}/reset-password`, { password, password_confirmation });
    return data;
  },

  async deleteEmployee(id: number): Promise<{ message: string }> {
    const { data } = await apiClient.delete(`/employees/${id}`);
    return data;
  },

  async getEmployeePermissions(id: number): Promise<{ data: PermissionSheet }> {
    const { data } = await apiClient.get(`/employees/${id}/permissions`);
    return data;
  },

  async updateEmployeePermissions(
    id: number,
    payload: { expected_version: number; overrides: { permission: Permission; effect: PermissionEffect }[] }
  ): Promise<{ message: string; data: PermissionSheet }> {
    const { data } = await apiClient.put(`/employees/${id}/permissions`, payload);
    return data;
  },

  // Barcodes
  async getBarcodeSettings(): Promise<{ data: BarcodeSettings }> {
    const { data } = await apiClient.get('/barcode/settings');
    return data;
  },

  async updateBarcodeSettings(settings: BarcodeSettings): Promise<{ message: string; data: BarcodeSettings }> {
    const { data } = await apiClient.put('/barcode/settings', settings);
    return data;
  },

  async scanBarcode(code: string): Promise<ScanResult> {
    const { data } = await apiClient.get('/barcode/scan', { params: { code } });
    return data;
  },

  // Customers
  async getCustomers(params?: { search?: string; store_id?: number; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/customers', { params });
    return data;
  },

  async getCustomer(customerId: number): Promise<{ status: string; data: CustomerDetailModel }> {
    const { data } = await apiClient.get(`/customers/${customerId}`);
    return data;
  },

  // Orders
  async getOrders(params?: { search?: string; status?: string; store_id?: number; warehouse_id?: number; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/orders', { params });
    return data;
  },


  /** Downloads the order's invoice PDF; the order gets its invoice number the first time. */
  async downloadOrderInvoice(orderId: number, orderNumber: string) {
    await downloadPdf(`/api/v1/orders/${orderId}/invoice`, `Invoice-${orderNumber}.pdf`, 'Could not download the invoice.');
  },

  // 3PL billing
  async getClients(): Promise<{ data: ClientModel[]; stores: BillingStore[] }> {
    const { data } = await apiClient.get('/billing/clients');
    return data;
  },

  async saveClient(input: ClientInput, clientId?: number): Promise<{ message: string; data: ClientModel }> {
    const { data } = clientId ? await apiClient.put(`/billing/clients/${clientId}`, input) : await apiClient.post('/billing/clients', input);
    return data;
  },

  async deleteClient(clientId: number): Promise<{ message: string }> {
    const { data } = await apiClient.delete(`/billing/clients/${clientId}`);
    return data;
  },

  /** What the client owes for a period so far (this month when no dates are given). */
  async getClientCharges(clientId: number, params?: { from?: string; to?: string }): Promise<ClientCharges> {
    const { data } = await apiClient.get(`/billing/clients/${clientId}/charges`, { params });
    return data.data;
  },

  async getClientInvoices(params?: { client_id?: number; status?: string; page?: number; per_page?: number }): Promise<{
    data: ClientInvoiceModel[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
    kpis: { outstanding: MoneyByCurrency; overdue: MoneyByCurrency; paid_this_month: MoneyByCurrency; drafts: number };
  }> {
    const { data } = await apiClient.get('/billing/invoices', { params });
    return data;
  },

  async getClientInvoice(invoiceId: number): Promise<ClientInvoiceModel> {
    const { data } = await apiClient.get(`/billing/invoices/${invoiceId}`);
    return data.data;
  },

  async createClientInvoice(input: { client_id: number; period_start: string; period_end: string }): Promise<{ message: string; data: ClientInvoiceModel }> {
    const { data } = await apiClient.post('/billing/invoices', input);
    return data;
  },

  /** recalculate, issue, pay or void an invoice. */
  async clientInvoiceAction(invoiceId: number, action: 'recalculate' | 'issue' | 'pay' | 'void'): Promise<{ message: string; data: ClientInvoiceModel }> {
    const { data } = await apiClient.post(`/billing/invoices/${invoiceId}/${action}`);
    return data;
  },

  async addClientInvoiceLine(invoiceId: number, line: { description: string; quantity: number; rate: number }): Promise<{ message: string; data: ClientInvoiceModel }> {
    const { data } = await apiClient.post(`/billing/invoices/${invoiceId}/lines`, line);
    return data;
  },

  async removeClientInvoiceLine(invoiceId: number, lineId: number): Promise<{ message: string; data: ClientInvoiceModel }> {
    const { data } = await apiClient.delete(`/billing/invoices/${invoiceId}/lines/${lineId}`);
    return data;
  },

  async downloadClientInvoice(invoiceId: number, invoiceNumber: string) {
    await downloadPdf(`/api/v1/billing/invoices/${invoiceId}/pdf`, `${invoiceNumber}.pdf`, 'Could not download the invoice.');
  },

  /** Reserves stock for the order; without a warehouse the best-stocked linked warehouse is used. */
  async allocateOrder(orderId: number, warehouseId?: number) {
    const { data } = await apiClient.post(`/orders/${orderId}/allocate`, warehouseId ? { warehouse_id: warehouseId } : undefined);
    return data;
  },

  async getOrderFulfillment(orderId: number): Promise<{ data: OrderFulfillment }> {
    const { data } = await apiClient.get(`/orders/${orderId}/fulfillment`);
    return data;
  },

  async cancelOrder(orderId: number): Promise<{ message: string; push?: PushReport }> {
    const { data } = await apiClient.post(`/orders/${orderId}/cancel`);
    return data;
  },

  /** Send the order to its store as it is now: created there if missing, then its payment, cancellation or shipments. */
  async pushOrder(orderId: number): Promise<{ message: string }> {
    const { data } = await apiClient.post(`/orders/${orderId}/push`);
    return data;
  },

  async createOrder(payload: OrderPayload): Promise<{ message: string; data: OrderModel; push: PushReport }> {
    const { data } = await apiClient.post('/orders', payload);
    return data;
  },

  async updateOrder(orderId: number, payload: OrderPayload): Promise<{ message: string; data: OrderModel; push: PushReport }> {
    const { data } = await apiClient.put(`/orders/${orderId}`, payload);
    return data;
  },

  async deleteOrder(orderId: number): Promise<{ message: string }> {
    const { data } = await apiClient.delete(`/orders/${orderId}`);
    return data;
  },

  // Products
  async getProducts(params?: { search?: string; category?: string; store_id?: number; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/products', { params });
    return data;
  },

  async getProduct(productId: number): Promise<{ data: ProductDetail }> {
    const { data } = await apiClient.get(`/products/${productId}`);
    return data;
  },

  async createProduct(payload: ProductPayload): Promise<{ message: string; data: { id: number }; push: PushReport }> {
    const { data } = await apiClient.post('/products', payload);
    return data;
  },

  async updateProduct(productId: number, payload: ProductPayload): Promise<{ message: string; data: { id: number }; push: PushReport }> {
    const { data } = await apiClient.put(`/products/${productId}`, payload);
    return data;
  },

  async deleteProduct(productId: number): Promise<{ message: string; push: PushReport }> {
    const { data } = await apiClient.delete(`/products/${productId}`);
    return data;
  },

  // ==========================================
  // Purchase Requisitions (PR)
  // ==========================================
  async getPurchaseRequisitions(params?: {
    store_id?: number;
    search?: string;
    status?: string;
    warehouse_id?: number;
    priority?: string;
    page?: number;
    per_page?: number;
  }) {
    const { data } = await apiClient.get('/purchase-requisitions', { params });
    return data;
  },

  async createPurchaseRequisition(payload: {
    store_id?: number;
    warehouse_id: number;
    delivery_location_id?: number;
    department?: string;
    priority?: 'low' | 'normal' | 'high' | 'urgent';
    required_by_date?: string;
    purpose?: string;
    justification?: string;
    notes?: string;
    currency?: string;
    tax_rate?: number;
    items: Array<{
      product_variant_id: number;
      product_id?: number;
      sku: string;
      product_name: string;
      requested_quantity: number;
      estimated_unit_price: number;
      uom?: string;
      notes?: string;
    }>;
  }) {
    const { data } = await apiClient.post('/purchase-requisitions', payload);
    return data;
  },

  async getPurchaseRequisition(id: number) {
    const { data } = await apiClient.get(`/purchase-requisitions/${id}`);
    return data;
  },

  async submitPurchaseRequisition(id: number, comments?: string) {
    const { data } = await apiClient.post(`/purchase-requisitions/${id}/submit`, { comments });
    return data;
  },

  async approvePurchaseRequisition(id: number, approved_quantities?: Record<number, number>, comments?: string) {
    const { data } = await apiClient.post(`/purchase-requisitions/${id}/approve`, {
      approved_quantities,
      comments,
    });
    return data;
  },

  async rejectPurchaseRequisition(id: number, reason: string, comments?: string) {
    const { data } = await apiClient.post(`/purchase-requisitions/${id}/reject`, {
      reason,
      comments,
    });
    return data;
  },

  async convertPrToPo(id: number, payload: {
    supplier_name: string;
    supplier_email?: string;
    supplier_contact?: string;
    warehouse_id?: number;
    order_date?: string;
    expected_delivery_date?: string;
    payment_terms?: string;
    currency?: string;
    notes?: string;
    items?: Array<{
      purchase_requisition_item_id: number;
      ordered_quantity: number;
      unit_cost?: number;
    }>;
  }) {
    const { data } = await apiClient.post(`/purchase-requisitions/${id}/convert-po`, payload);
    return data;
  },

  async getStockPlanningContext(warehouseId: number, variantId: number) {
    const { data } = await apiClient.get('/purchase-requisitions/stock-context', {
      params: { warehouse_id: warehouseId, variant_id: variantId },
    });
    return data;
  },

  async downloadPrPdf(id: number, filename?: string) {
    const token = getAuthToken();
    const response = await fetch(`/api/v1/purchase-requisitions/${id}/pdf`, {
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      credentials: 'include',
    });
    if (!response.ok) throw new Error('Failed to download PR PDF');
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename || `PR-${id}.pdf`);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  },

  // ==========================================
  // Purchase Orders (PO)
  // ==========================================
  async getPurchaseOrders(params?: {
    store_id?: number;
    search?: string;
    status?: string;
    warehouse_id?: number;
    page?: number;
    per_page?: number;
  }) {
    const { data } = await apiClient.get('/purchase-orders', { params });
    return data;
  },

  async createPurchaseOrder(payload: {
    store_id?: number;
    supplier_name: string;
    supplier_email?: string;
    supplier_contact?: string;
    warehouse_id: number;
    order_date?: string;
    expected_delivery_date?: string;
    payment_terms?: string;
    currency?: string;
    tax_rate?: number;
    notes?: string;
    items: Array<{
      product_variant_id: number;
      sku: string;
      product_name: string;
      ordered_quantity: number;
      unit_cost: number;
      uom?: string;
      notes?: string;
    }>;
  }) {
    const { data } = await apiClient.post('/purchase-orders', payload);
    return data;
  },

  async getPurchaseOrder(id: number) {
    const { data } = await apiClient.get(`/purchase-orders/${id}`);
    return data;
  },

  async issuePurchaseOrder(id: number) {
    const { data } = await apiClient.post(`/purchase-orders/${id}/issue`);
    return data;
  },

  async cancelPurchaseOrder(id: number, reason?: string) {
    const { data } = await apiClient.post(`/purchase-orders/${id}/cancel`, { reason });
    return data;
  },

  // ==========================================
  // Goods Received Notes (GRN)
  // ==========================================
  async getGoodsReceivedNotes(params?: {
    store_id?: number;
    search?: string;
    status?: string;
    warehouse_id?: number;
    purchase_order_id?: number;
    page?: number;
    per_page?: number;
  }) {
    const { data } = await apiClient.get('/goods-received-notes', { params });
    return data;
  },

  async createGoodsReceivedNote(payload: {
    purchase_order_id: number;
    warehouse_id?: number;
    receiving_location_id?: number;
    supplier_delivery_note?: string;
    carrier?: string;
    tracking_or_vehicle_number?: string;
    received_at?: string;
    notes?: string;
    discrepancy_summary?: string;
    allow_over_receipt?: boolean;
    items?: Array<{
      purchase_order_item_id: number;
      received_quantity: number;
      accepted_quantity: number;
      damaged_quantity?: number;
      quarantined_quantity?: number;
      rejected_quantity?: number;
      pending_inspection_quantity?: number;
      destination_location_id?: number;
      lot_number?: string;
      expiry_date?: string;
      discrepancy_reason?: string;
      notes?: string;
    }>;
  }) {
    const { data } = await apiClient.post('/goods-received-notes', payload);
    return data;
  },

  async getGoodsReceivedNote(id: number) {
    const { data } = await apiClient.get(`/goods-received-notes/${id}`);
    return data;
  },

  async inspectGoodsReceivedNote(id: number, payload: {
    notes?: string;
    discrepancy_summary?: string;
    items: Array<{
      id: number;
      accepted_quantity: number;
      damaged_quantity?: number;
      quarantined_quantity?: number;
      rejected_quantity?: number;
      pending_inspection_quantity?: number;
      lot_number?: string;
      expiry_date?: string;
      discrepancy_reason?: string;
    }>;
  }) {
    const { data } = await apiClient.put(`/goods-received-notes/${id}/inspect`, payload);
    return data;
  },

  async postGrnToInventory(id: number) {
    const { data } = await apiClient.post(`/goods-received-notes/${id}/post`);
    return data;
  },

  async downloadGrnPdf(id: number, filename?: string) {
    const token = getAuthToken();
    const response = await fetch(`/api/v1/goods-received-notes/${id}/pdf`, {
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      credentials: 'include',
    });
    if (!response.ok) throw new Error('Failed to download GRN PDF');
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename || `GRN-${id}.pdf`);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  },
};

export default omsService;

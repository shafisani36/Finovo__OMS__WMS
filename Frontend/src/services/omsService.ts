import apiClient, { getAuthToken } from './apiClient';

export interface StoreModel {
  id: number;
  name: string;
  code: string;
  platform: string;
  status: 'active' | 'setup_incomplete' | 'disconnected';
  sync_status: 'healthy' | 'sync_issues';
  orders_awaiting_sync: number;
  last_sync_at: string | null;
  warehouses: WarehouseModel[];
}

export type WarehouseStatus = 'draft' | 'setup_incomplete' | 'ready_for_activation' | 'active' | 'inactive' | 'closing' | 'closed';
export type WarehouseType = 'distribution_center' | 'fulfillment_hub' | 'retail_backroom' | 'cross_dock' | 'quarantine_zone' | 'third_party_3pl';

export interface WarehouseLocationModel {
  id: number;
  warehouse_id: number;
  organization_id: number;
  parent_id?: number | null;
  code: string;
  name?: string;
  location_type: 'zone' | 'aisle' | 'rack' | 'shelf' | 'bin';
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
  warehouse_id: number;
  user_id: number;
  role: string;
  permissions?: string[];
  is_active: boolean;
  user?: {
    id: number;
    name: string;
    email: string;
  };
}

export interface WarehouseAuditLogModel {
  id: number;
  warehouse_id: number;
  user_id?: number;
  action: string;
  changes?: {
    before?: Record<string, any>;
    after?: Record<string, any>;
  };
  ip_address?: string;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
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
  type?: WarehouseType;
  status: WarehouseStatus;
  is_default: boolean;
  priority: number;
  is_active: boolean;
  address_line1?: string;
  address_line2?: string;
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
}

export interface ProductModel {
  id: number;
  name: string;
  sku: string;
  barcode?: string;
  category?: string;
  is_active: boolean;
  default_variant_id?: number;
  variants?: Array<{
    id: number;
    sku: string;
    name?: string;
    price: number;
  }>;
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
  product_variant_id?: number;
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

export interface OrderModel {
  id: number;
  order_number: string;
  external_order_id: string;
  customer_name: string;
  customer_email?: string;
  order_status: string;
  payment_status: string;
  fulfillment_status: string;
  total_amount: string;
  currency: string;
  placed_at: string;
  items?: Array<{
    id: number;
    product_name: string;
    external_sku: string;
    quantity: number;
    unit_price: string;
    status: string;
  }>;
}

export const omsService = {
  // Stores
  async getStores(params?: { search?: string; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/stores', { params });
    return data;
  },

  async createStore(payload: { name: string; platform: string; bridge_url?: string; warehouse_ids?: number[] }) {
    const { data } = await apiClient.post('/stores', payload);
    return data;
  },

  async syncStore(storeId: number) {
    const { data } = await apiClient.post(`/stores/${storeId}/sync`);
    return data;
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

  async closeWarehouse(id: number, notes?: string) {
    const { data } = await apiClient.post(`/warehouses/${id}/close`, { notes });
    return data;
  },

  // Physical Layout & Locations
  async getWarehouseLocations(warehouseId: number, params?: { parent_id?: number; location_type?: string }) {
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
  async getWarehouseStaff(warehouseId: number) {
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

  // Audit Logs
  async getWarehouseAuditLogs(warehouseId: number) {
    const { data } = await apiClient.get(`/warehouses/${warehouseId}/audit-logs`);
    return data;
  },

  // Stock Movements & Inventory
  async getStockMovements(params?: { search?: string; type?: string; warehouse_id?: number; page?: number; per_page?: number }) {
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
  async getInventory(params?: { search?: string; warehouse_id?: number; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/inventory', { params });
    return data;
  },

  async getInventoryKpis(warehouseId?: number) {
    const { data } = await apiClient.get('/inventory/kpis', { params: { warehouse_id: warehouseId } });
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

  async getReplenishmentRecommendations(warehouseId?: number) {
    const { data } = await apiClient.get('/inventory/replenishment/recommendations', { params: { warehouse_id: warehouseId } });
    return data;
  },

  // Picking & Packing
  async getPickingTasks(params?: { search?: string; status?: string; page?: number; per_page?: number }) {
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

  // Orders
  async getOrders(params?: { search?: string; status?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/orders', { params });
    return data;
  },

  async createOrder(payload: any) {
    const { data } = await apiClient.post('/orders', payload);
    return data;
  },

  async allocateOrder(orderId: number) {
    const { data } = await apiClient.post(`/orders/${orderId}/allocate`);
    return data;
  },

  async cancelOrder(orderId: number) {
    const { data } = await apiClient.post(`/orders/${orderId}/cancel`);
    return data;
  },

  // Products
  async getProducts(params?: { search?: string; category?: string; page?: number; per_page?: number }) {
    const { data } = await apiClient.get('/products', { params });
    return data;
  },

  async createProduct(payload: { name: string; sku: string; category?: string; barcode?: string; price?: number }) {
    const { data } = await apiClient.post('/products', payload);
    return data;
  },

  // ==========================================
  // Purchase Requisitions (PR)
  // ==========================================
  async getPurchaseRequisitions(params?: {
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
    supplier_name: string;
    supplier_email?: string;
    supplier_contact?: string;
    warehouse_id: number;
    purchase_requisition_id?: number;
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

import apiClient from './apiClient';

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
  stock: {
    on_hand: number;
    reserved: number;
    available: number;
  };
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
};

export default omsService;

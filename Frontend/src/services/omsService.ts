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

export interface WarehouseModel {
  id: number;
  name: string;
  code: string;
  city?: string;
  country?: string;
  priority: number;
  is_active: boolean;
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
  async getWarehouses() {
    const { data } = await apiClient.get('/warehouses');
    return data;
  },

  async createWarehouse(payload: { name: string; code?: string; city?: string; address?: string }) {
    const { data } = await apiClient.post('/warehouses', payload);
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

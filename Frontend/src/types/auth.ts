export type UserRole = 'admin' | 'manager' | 'sales' | 'operator';

/**
 * Actions an employee can be allowed (same keys as the backend's App\Enums\Permission).
 * The app hides what is not allowed; the server checks every request on its own.
 */
export type Permission =
  | 'employees.view'
  | 'employees.create'
  | 'employees.update'
  | 'employees.assign_role'
  | 'employees.suspend'
  | 'employees.reset_password'
  | 'employees.delete'
  | 'employees.manage_permissions'
  | 'stores.view'
  | 'stores.connect'
  | 'stores.sync'
  | 'stores.delete'
  | 'warehouses.view'
  | 'warehouses.manage'
  | 'warehouses.delete'
  | 'warehouse.employees.view'
  | 'warehouse.employees.assign'
  | 'orders.view'
  | 'orders.allocate'
  | 'orders.cancel'
  | 'orders.manage'
  | 'orders.delete'
  | 'customers.view'
  | 'products.view'
  | 'products.manage'
  | 'products.delete'
  | 'inventory.view'
  | 'inventory.receive'
  | 'inventory.transfer'
  | 'inventory.adjust'
  | 'inventory.approve'
  | 'picking.view'
  | 'picking.update'
  | 'shipments.view'
  | 'shipments.dispatch'
  | 'returns.view'
  | 'returns.manage'
  | 'purchasing.view'
  | 'purchasing.request'
  | 'purchasing.approve'
  | 'purchasing.order'
  | 'purchasing.receive'
  | 'billing.view'
  | 'billing.manage'
  | 'settings.barcode';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  role_label: string;
  status: 'active' | 'suspended';
  current_organization_id?: number | null;
  permissions: Permission[];
  /** Warehouses the user works in; null means every warehouse of the company. */
  warehouse_ids?: number[] | null;
  last_login_at: string | null;
}

/** Whether the signed-in user may perform an action. */
export const can = (user: User | null | undefined, permission: Permission): boolean =>
  user?.role === 'admin' || Boolean(user?.permissions?.includes(permission));

export interface ApiResponse<T = unknown> {
  success: boolean;
  message: string;
  data: T;
  errors: Record<string, string[]> | null;
}

export interface LoginResponseData {
  requires_otp: boolean;
  challenge_id?: string;
  destination?: string;
  expires_in_seconds?: number;
  resend_cooldown_seconds?: number;
  user?: User;
  token?: string;
}

export interface VerifyOtpResponseData {
  user: User;
  token: string;
}

export interface ResendOtpResponseData {
  challenge_id: string;
  destination: string;
  expires_in_seconds: number;
  resend_cooldown_seconds: number;
}

export interface ForgotPasswordResponseData {
  challenge_id: string | null;
  destination: string;
  expires_in_seconds: number;
  resend_cooldown_seconds: number;
}

export interface VerifyResetOtpResponseData {
  reset_token: string;
  expires_in_seconds: number;
}

export interface AdminOverviewData {
  role: 'admin';
  total_orders: number;
  inventory_items: number;
  connected_stores: number;
  active_shipments: number;
  warehouses_synced: number;
  system_health: string;
}

export interface OperatorTasksData {
  role: 'operator';
  active_pick_lists: number;
  pending_shipments: number;
  stock_movements_today: number;
  assigned_zone: string;
}

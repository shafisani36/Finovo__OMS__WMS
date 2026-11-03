export type UserRole = 'admin' | 'manager' | 'sales' | 'operator' | 'client';

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
  | 'couriers.manage'
  | 'returns.view'
  | 'returns.manage'
  | 'purchasing.view'
  | 'purchasing.request'
  | 'purchasing.approve'
  | 'purchasing.order'
  | 'purchasing.receive'
  | 'billing.view'
  | 'billing.manage'
  | 'settings.barcode'
  | 'forms.manage'
  | 'forms.submit';

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

/** A pick task on the work board. */
export interface WorkTask {
  id: number;
  order_number: string | null;
  warehouse: string | null;
  status: 'not_started' | 'in_progress' | 'picked';
  items_count: number;
  created_at: string | null;
}

export interface WorkerLoad {
  id: number;
  name: string;
  role: UserRole;
  open_tasks: number;
  done_today: number;
  scan_errors_today: number;
}

/** Admin work board: company totals, every worker's work today and the tasks nobody has yet. */
export interface AdminOverviewData {
  totals: { open_orders: number; open_pick_tasks: number; ready_to_dispatch: number; in_transit: number; connected_stores: number; active_warehouses: number };
  workers: WorkerLoad[];
  unassigned: WorkTask[];
}

/** The signed-in worker's pick tasks. */
export interface OperatorTasksData {
  my_tasks: WorkTask[];
  unassigned: number;
}

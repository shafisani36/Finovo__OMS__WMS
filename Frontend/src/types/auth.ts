export type UserRole = 'admin' | 'operator';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  role_label: string;
  status: 'active' | 'suspended';
  current_organization_id?: number | null;
  permissions: string[];
  last_login_at: string | null;
}

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

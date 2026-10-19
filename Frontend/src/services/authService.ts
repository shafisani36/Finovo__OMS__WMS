import { request, setAuthToken } from './apiClient';
import type {
  AdminOverviewData,
  ApiResponse,
  ForgotPasswordResponseData,
  LoginResponseData,
  OperatorTasksData,
  ResendOtpResponseData,
  User,
  VerifyOtpResponseData,
  VerifyResetOtpResponseData,
} from '../types/auth';

export const authService = {
  login: async (email: string, password: string, remember = false): Promise<ApiResponse<LoginResponseData>> => {
    return request<LoginResponseData>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password, remember }),
    });
  },

  verifyOtp: async (challenge_id: string, code: string): Promise<ApiResponse<VerifyOtpResponseData>> => {
    const response = await request<VerifyOtpResponseData>('/auth/otp/verify', {
      method: 'POST',
      body: JSON.stringify({ challenge_id, code }),
    });

    if (response.data?.token) {
      setAuthToken(response.data.token);
    }

    return response;
  },

  resendOtp: async (challenge_id: string): Promise<ApiResponse<ResendOtpResponseData>> => {
    return request<ResendOtpResponseData>('/auth/otp/resend', {
      method: 'POST',
      body: JSON.stringify({ challenge_id }),
    });
  },

  forgotPassword: async (email: string): Promise<ApiResponse<ForgotPasswordResponseData>> => {
    return request<ForgotPasswordResponseData>('/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email }),
    });
  },

  verifyResetOtp: async (challenge_id: string, code: string): Promise<ApiResponse<VerifyResetOtpResponseData>> => {
    return request<VerifyResetOtpResponseData>('/auth/password/verify-reset-otp', {
      method: 'POST',
      body: JSON.stringify({ challenge_id, code }),
    });
  },

  resetPassword: async (
    reset_token: string,
    password: string,
    password_confirmation: string
  ): Promise<ApiResponse<null>> => {
    return request<null>('/auth/password/reset', {
      method: 'POST',
      body: JSON.stringify({ reset_token, password, password_confirmation }),
    });
  },

  changePassword: async (
    current_password: string,
    new_password: string,
    new_password_confirmation: string
  ): Promise<ApiResponse<null>> => {
    return request<null>('/auth/password/change', {
      method: 'PUT',
      body: JSON.stringify({ current_password, new_password, new_password_confirmation }),
    });
  },

  getMe: async (): Promise<ApiResponse<{ user: User }>> => {
    return request<{ user: User }>('/auth/me', {
      method: 'GET',
    });
  },

  logout: async (): Promise<ApiResponse<null>> => {
    try {
      return await request<null>('/auth/logout', {
        method: 'POST',
      });
    } finally {
      setAuthToken(null);
    }
  },

  getAdminOverview: async (): Promise<ApiResponse<AdminOverviewData>> => {
    return request<AdminOverviewData>('/admin/overview', {
      method: 'GET',
    });
  },

  getOperatorTasks: async (): Promise<ApiResponse<OperatorTasksData>> => {
    return request<OperatorTasksData>('/operator/tasks', {
      method: 'GET',
    });
  },
};

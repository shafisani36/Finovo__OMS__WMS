import type { ApiResponse } from '../types/auth';

let inMemoryToken: string | null = null;

export const setAuthToken = (token: string | null): void => {
  inMemoryToken = token;
  if (token) {
    sessionStorage.setItem('finovo_auth_active', '1');
  } else {
    sessionStorage.removeItem('finovo_auth_active');
  }
};

export const getAuthToken = (): string | null => {
  return inMemoryToken;
};

export class ApiError extends Error {
  public status: number;
  public errors: Record<string, string[]> | null;

  constructor(message: string, status: number, errors: Record<string, string[]> | null = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

const BASE_URL = '/api/v1';

export const request = async <T = any>(
  endpoint: string,
  options: RequestInit = {}
): Promise<ApiResponse<T>> => {
  const url = endpoint.startsWith('http') ? endpoint : `${BASE_URL}${endpoint}`;

  const headers = new Headers(options.headers || {});
  headers.set('Accept', 'application/json');

  if (!(options.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }

  if (inMemoryToken) {
    headers.set('Authorization', `Bearer ${inMemoryToken}`);
  }

  try {
    const response = await fetch(url, {
      ...options,
      headers,
      credentials: 'include',
    });

    let payload: any;
    try {
      payload = await response.json();
    } catch {
      throw new ApiError(
        response.statusText || 'Unexpected server response.',
        response.status
      );
    }

    if (!response.ok) {
      const message = payload.message || 'An unexpected error occurred.';
      throw new ApiError(message, response.status, payload.errors);
    }

    return payload;
  } catch (error) {
    if (error instanceof ApiError) {
      throw error;
    }
    throw new ApiError(
      error instanceof Error ? error.message : 'Unable to connect to server. Please check your network.',
      0
    );
  }
};

export const apiClient = {
  request,
  async get<T = any>(endpoint: string, config?: { params?: Record<string, any> }) {
    let url = endpoint;
    if (config?.params) {
      const sp = new URLSearchParams();
      Object.entries(config.params).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') {
          sp.append(k, String(v));
        }
      });
      const qs = sp.toString();
      if (qs) url += (url.includes('?') ? '&' : '?') + qs;
    }
    const res = await request<T>(url, { method: 'GET' });
    return { data: res as any };
  },
  async post<T = any>(endpoint: string, body?: any) {
    const res = await request<T>(endpoint, {
      method: 'POST',
      body: body ? JSON.stringify(body) : undefined,
    });
    return { data: res as any };
  },
  async put<T = any>(endpoint: string, body?: any) {
    const res = await request<T>(endpoint, {
      method: 'PUT',
      body: body ? JSON.stringify(body) : undefined,
    });
    return { data: res as any };
  },
  async delete<T = any>(endpoint: string) {
    const res = await request<T>(endpoint, { method: 'DELETE' });
    return { data: res as any };
  },
};

export default apiClient;
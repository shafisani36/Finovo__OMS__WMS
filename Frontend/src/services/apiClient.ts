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

export const request = async <T>(
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

    let payload: ApiResponse<T>;
    try {
      payload = await response.json();
    } catch {
      throw new ApiError(
        response.statusText || 'Unexpected server response.',
        response.status
      );
    }

    if (!response.ok || !payload.success) {
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

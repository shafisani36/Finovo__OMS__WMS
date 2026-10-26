import { ApiError } from '../services/apiClient';

/**
 * Readable text for a failed request. Field messages from a failed save read better than Laravel's
 * "(and 1 more error)" summary, so they are listed in full.
 */
export const errorMessage = (err: unknown, fallback: string): string => {
  if (err instanceof ApiError && err.errors) return Object.values(err.errors).flat().join(' ');
  return err instanceof Error && err.message ? err.message : fallback;
};

import { useState, useCallback } from 'react';
import apiFetch from '@wordpress/api-fetch';

export interface UseFieldApiResult<T> {
  loading: boolean;
  error: string | null;
  request: (method: 'GET' | 'POST', data?: Record<string, unknown>) => Promise<T | null>;
}

export function useFieldApi<T = unknown>(endpoint: string): UseFieldApiResult<T> {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const request = useCallback(
    async (method: 'GET' | 'POST', data?: Record<string, unknown>): Promise<T | null> => {
      setLoading(true);
      setError(null);

      try {
        const response = await apiFetch<T>({
          path: endpoint,
          method,
          data: method === 'POST' ? data : undefined,
        });
        return response;
      } catch (err: unknown) {
        const message = err instanceof Error ? err.message : 'Request failed';
        setError(message);
        return null;
      } finally {
        setLoading(false);
      }
    },
    [endpoint]
  );

  return { loading, error, request };
}
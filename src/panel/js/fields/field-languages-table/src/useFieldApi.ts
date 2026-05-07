import { useState, useCallback } from 'react';
import apiFetch from '@wordpress/api-fetch';

export function useFieldApi<T>(endpoint: string) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const request = useCallback(
    async (method: 'GET' | 'POST', data?: Record<string, unknown>): Promise<T | null> => {
      setLoading(true);
      setError(null);

      try {
        return await apiFetch<T>({
          path: endpoint,
          method,
          data: method === 'POST' ? data : undefined,
        });
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : 'Request failed');
        return null;
      } finally {
        setLoading(false);
      }
    },
    [endpoint]
  );

  return { loading, error, request };
}
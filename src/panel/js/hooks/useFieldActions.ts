import { useState, useCallback } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { usePanelState } from './usePanelState';

interface ActionDefinition {
  label: string;
  confirm?: string;
  destructive?: boolean;
}

interface ActionResponse<T = unknown> {
  success: boolean;
  data?: T;
  message?: string;
}

interface UseFieldActionsOptions {
  fieldId: string;
  actionEndpoint: string;
  actions: Record<string, ActionDefinition>;
}

interface UseFieldActionsResult<T> {
  /** Currently executing action name, or null */
  loading: string | null;
  /** Last action result message */
  message: { type: 'success' | 'error'; text: string } | null;
  /** Clear the message */
  clearMessage: () => void;
  /** Execute an action */
  executeAction: (action: string, payload?: Record<string, unknown>) => Promise<T | null>;
  /** Get action definition */
  getAction: (action: string) => ActionDefinition | undefined;
}

/**
 * Hook for actionable fields that perform immediate-save operations.
 *
 * Usage:
 * ```tsx
 * const { loading, message, executeAction, clearMessage } = useFieldActions({
 *   fieldId,
 *   actionEndpoint: '/my-prefix/v1/oauth-action',
 *   actions: { connect: { label: 'Connect' }, disconnect: { label: 'Disconnect', confirm: 'Sure?' } },
 * });
 *
 * const handleConnect = async () => {
 *   const result = await executeAction('connect', { auth_code: '...' });
 *   if (result) {
 *     onChange(result); // Update field value with response
 *   }
 * };
 * ```
 */
export function useFieldActions<T = unknown>({
  fieldId,
  actionEndpoint,
  actions,
}: UseFieldActionsOptions): UseFieldActionsResult<T> {
  const [loading, setLoading] = useState<string | null>(null);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const { state } = usePanelState();

  const clearMessage = useCallback(() => {
    setMessage(null);
  }, []);

  const getAction = useCallback(
    (action: string) => actions[action],
    [actions]
  );

  const executeAction = useCallback(
    async (action: string, payload: Record<string, unknown> = {}): Promise<T | null> => {
      const actionDef = actions[action];

      if (!actionDef) {
        setMessage({ type: 'error', text: `Unknown action: ${action}` });
        return null;
      }

      // Show confirmation if required
      if (actionDef.confirm && !window.confirm(actionDef.confirm)) {
        return null;
      }

      setLoading(action);
      setMessage(null);

      try {
        const response = await apiFetch<ActionResponse<T>>({
          path: actionEndpoint,
          method: 'POST',
          data: {
            action,
            fieldId,
            nonce: state.nonce,
            ...payload,
          },
        });

        if (response.success) {
          setMessage({
            type: 'success',
            text: response.message ?? 'Action completed successfully.',
          });
          return response.data ?? null;
        } else {
          setMessage({
            type: 'error',
            text: response.message ?? 'Action failed.',
          });
          return null;
        }
      } catch (error: unknown) {
        const errorMessage = error instanceof Error ? error.message : 'Request failed';
        setMessage({ type: 'error', text: errorMessage });
        return null;
      } finally {
        setLoading(null);
      }
    },
    [actionEndpoint, actions, fieldId, state.nonce]
  );

  return {
    loading,
    message,
    clearMessage,
    executeAction,
    getAction,
  };
}

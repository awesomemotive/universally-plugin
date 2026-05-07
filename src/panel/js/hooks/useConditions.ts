import { useMemo } from 'react';
import { checkConditions } from '@timkit/conditions';
import { usePanelState } from './usePanelState';

type Conditions = string[] | string[][];

/**
 * Evaluate conditions against current panel values.
 * Uses @timkit/conditions syntax:
 * - ['a', 'b'] = OR (at least one must be true)
 * - [['a', 'b']] = AND (all must be true)
 * - [['a', 'b'], ['c', 'd']] = (a AND b) OR (c AND d)
 */
export function useConditions(conditions: Conditions | undefined): boolean {
  const { state } = usePanelState();

  return useMemo(() => {
    if (!conditions || conditions.length === 0) {
      return true;
    }

    try {
      return checkConditions(state.values, conditions);
    } catch (error) {
      console.error('[WP Panel] Condition evaluation error:', error);
      return true; // Show field on error
    }
  }, [conditions, state.values]);
}

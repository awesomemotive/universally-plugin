import type { ComponentType } from 'react';
import type { FieldComponentProps } from './types';

type FieldComponent = ComponentType<FieldComponentProps<any>>;

interface FieldRegistration {
  component: FieldComponent;
}

const registry = new Map<string, FieldRegistration>();

/**
 * Register a field type component.
 */
export function registerField(type: string, component: FieldComponent): void {
  registry.set(type, { component });
}

/**
 * Get a field type component.
 */
export function getFieldComponent(type: string): FieldComponent | undefined {
  return registry.get(type)?.component;
}

/**
 * Check if a field type is registered.
 */
export function hasField(type: string): boolean {
  return registry.has(type);
}

/**
 * Get all registered field types.
 */
export function getRegisteredTypes(): string[] {
  return Array.from(registry.keys());
}

// Expose globally for field packages
declare global {
  interface Window {
    wpPanel?: {
      registerField: typeof registerField;
      getFieldComponent: typeof getFieldComponent;
      hasField: typeof hasField;
    };
  }
}

if (typeof window !== 'undefined') {
  window.wpPanel = {
    registerField,
    getFieldComponent,
    hasField,
  };
}

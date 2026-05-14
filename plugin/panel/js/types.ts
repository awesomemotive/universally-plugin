/**
 * Tab definition in flat schema (type: "tab" is reserved).
 */
export interface TabItem {
  type: 'tab';
  id: string;
  label: string;
  storage?: 'single' | 'separate';
}

/**
 * Section definition in flat schema (type: "section" is reserved).
 */
export interface SectionItem {
  type: 'section';
  id: string;
  label?: string;
  description?: string;
  default?: 'open' | 'closed';
  showSave?: boolean;  // Show save button in section (default: true)
  capability?: string; // Required capability to view/edit fields in this section
  // Internal fields added by PHP parseSchema
  _tab?: string;
}

/**
 * Field definition in flat schema.
 */
export interface FieldItem {
  type: string; // Anything except 'tab'
  id: string;
  label: string;
  description?: string;
  placeholder?: string;
  default?: unknown;
  storage?: 'single' | 'separate' | string; // 'single', 'separate', or handler name
  conditions?: string[] | string[][];  // Always array: ['a', 'b'] = OR, [['a', 'b']] = AND
  options?: Record<string, string>;
  independent?: boolean;  // Field manages its own save, excluded from global Save Changes
  endpoint?: string;      // REST API endpoint for independent fields
  size?: 'tiny' | 'small' | 'medium' | 'full';  // Control max-width: tiny=80px, small=200px, medium=600px, full=100%
  separator?: boolean;  // Show bottom border/padding between fields (default: true)
  // Internal fields added by PHP parseSchema
  _tab?: string;
  _section?: string;
  _storage?: string;
  [key: string]: unknown;
}

/**
 * Schema item - either a tab, section, or field.
 */
export type SchemaItem = TabItem | SectionItem | FieldItem;

/**
 * Type guard to check if item is a tab.
 */
export function isTabItem(item: SchemaItem): item is TabItem {
  return item.type === 'tab';
}

/**
 * Type guard to check if item is a section.
 */
export function isSectionItem(item: SchemaItem): item is SectionItem {
  return item.type === 'section';
}

export interface MenuConfig {
  location?: string; // 'settings', 'toplevel', 'tools', or any menu slug for submenu
  icon?: string;     // Dashicon class (e.g., 'dashicons-admin-generic')
  iconPath?: string; // Path to custom SVG file relative to plugin (converted to data URI by PHP)
  position?: number;
}

/**
 * Header action link configuration.
 */
export interface HeaderAction {
  icon?: string;      // Dashicon class (e.g., 'dashicons-admin-site')
  iconPath?: string;  // Path to custom icon file relative to plugin (resolved to URL by PHP)
  label: string;
  href: string;
}

/**
 * Panel configuration with flat schema.
 */
export interface PanelConfig {
  id: string;
  title: string;
  logoPath?: string; // Path to logo image relative to plugin (displayed instead of title)
  headerActions?: HeaderAction[]; // Action links in header right side
  capability?: string;
  storage?: 'single' | 'separate';
  menu?: MenuConfig;
  schema: SchemaItem[];
}

/**
 * Parsed schema structure (computed by PHP, sent to JS).
 */
export interface ParsedSchema {
  tabs: Record<string, TabItem>;
  sections: Record<string, SectionItem>;
  fields: Record<string, FieldItem>;
}

export interface PanelData {
  config: PanelConfig;
  parsed: ParsedSchema; // Pre-parsed by PHP
  values: Record<string, unknown>;
  nonce: string;
  ajaxUrl: string;
  action: string;
  restUrl: string;
  restNonce: string;
  fieldData: Record<string, unknown>;
}

export interface PanelState {
  values: Record<string, unknown>;
  modified: boolean;
  saving: boolean;
  message: string | null;
  messageType: 'success' | 'error' | null;
  errors: Record<string, string>;
}

export type PanelAction =
  | { type: 'SET_VALUE'; field: string; value: unknown }
  | { type: 'SAVE_START' }
  | { type: 'SAVE_SUCCESS'; values: Record<string, unknown>; message: string }
  | { type: 'SAVE_ERROR'; message: string; errors?: Record<string, string> }
  | { type: 'CLEAR_MESSAGE' };

export interface FieldComponentProps<T = unknown> {
  fieldId: string;
  config: FieldItem;
  value: T;
  onChange: (value: T) => void;
  error?: string;
}

/**
 * Onboarding step configuration.
 */
export interface OnboardingStep {
  id: string;
  title: string;
  tabLabel?: string;
  description?: string;
  image?: string;
  required?: boolean;
  skippable?: boolean;
  fields: FieldItem[];
  buttons?: Partial<OnboardingButtons>;
}

/**
 * Onboarding button labels.
 */
export interface OnboardingButtons {
  next: string;
  back: string;
  skip: string;
  skipAll: string;
  finish: string;
}

/**
 * Onboarding configuration.
 */
export interface OnboardingConfig {
  enabled: boolean;
  skippable?: boolean;
  rerunnable?: boolean;
  title?: string;
  buttons?: Partial<OnboardingButtons>;
  steps: OnboardingStep[];
}

/**
 * Onboarding progress state.
 */
export interface OnboardingStateData {
  status: 'pending' | 'in_progress' | 'completed' | 'skipped';
  current_step: string | null;
  completed_steps: string[];
  skipped_steps: string[];
  started_at: number | null;
  completed_at: number | null;
}

/**
 * Onboarding data sent from PHP.
 */
export interface OnboardingData {
  config: OnboardingConfig;
  state: OnboardingStateData;
  fields: Record<string, FieldItem>;
}

/**
 * Extended PanelData with onboarding.
 */
export interface PanelDataWithOnboarding extends PanelData {
  onboarding?: OnboardingData;
}
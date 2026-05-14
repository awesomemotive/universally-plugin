// @ts-ignore – wp.components is provided by WordPress at runtime
import { ColorPalette } from '@wordpress/components';

interface ColorOption {
  name: string;
  slug: string;
  color: string;
}

interface FieldConfig {
  default?: string;
  colors?: ColorOption[];
  palette?: 'background' | 'text' | 'border';
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string;
  onChange: (value: string) => void;
}

const palettes: Record<string, ColorOption[]> = {
  background: [
    { name: 'White', slug: 'white', color: '#ffffff' },
    { name: 'Snow', slug: 'snow', color: '#f9fafb' },
    { name: 'Light Gray', slug: 'light-gray', color: '#f3f4f6' },
    { name: 'Warm Gray', slug: 'warm-gray', color: '#f5f5f4' },
    { name: 'Rose', slug: 'rose', color: '#fff1f2' },
    { name: 'Peach', slug: 'peach', color: '#fff7ed' },
    { name: 'Cream', slug: 'cream', color: '#fefce8' },
    { name: 'Mint', slug: 'mint', color: '#f0fdf4' },
    { name: 'Ice', slug: 'ice', color: '#eff6ff' },
    { name: 'Lavender', slug: 'lavender', color: '#faf5ff' },
    { name: 'Slate', slug: 'slate', color: '#1e293b' },
    { name: 'Black', slug: 'black', color: '#000000' },
  ],
  text: [
    { name: 'Black', slug: 'black', color: '#000000' },
    { name: 'Near Black', slug: 'near-black', color: '#111827' },
    { name: 'Charcoal', slug: 'charcoal', color: '#1f2937' },
    { name: 'Dark Gray', slug: 'dark-gray', color: '#374151' },
    { name: 'Gray', slug: 'gray', color: '#6b7280' },
    { name: 'Muted', slug: 'muted', color: '#9ca3af' },
    { name: 'White', slug: 'white', color: '#ffffff' },
    { name: 'Snow', slug: 'snow', color: '#f9fafb' },
  ],
  border: [
    { name: 'Transparent', slug: 'transparent', color: 'transparent' },
    { name: 'Faint', slug: 'faint', color: '#f3f4f6' },
    { name: 'Light', slug: 'light', color: '#e5e7eb' },
    { name: 'Silver', slug: 'silver', color: '#d1d5db' },
    { name: 'Gray', slug: 'gray', color: '#9ca3af' },
    { name: 'Dark', slug: 'dark', color: '#6b7280' },
    { name: 'Charcoal', slug: 'charcoal', color: '#374151' },
    { name: 'Black', slug: 'black', color: '#000000' },
  ],
};

const defaultPalette: ColorOption[] = [
  { name: 'Black', slug: 'black', color: '#000000' },
  { name: 'Gray', slug: 'gray', color: '#6b7280' },
  { name: 'Light Gray', slug: 'light-gray', color: '#d1d5db' },
  { name: 'White', slug: 'white', color: '#ffffff' },
];

export function ColorField({ config, value, onChange }: Props) {
  const colors = config.colors || (config.palette && palettes[config.palette]) || defaultPalette;

  return (
    <div className="wp-panel-color-field">
      <ColorPalette
        colors={colors}
        value={value || config.default || undefined}
        onChange={(newColor: string | undefined) => onChange(newColor || '')}
        clearable={true}
      />
    </div>
  );
}
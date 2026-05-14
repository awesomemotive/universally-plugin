interface FieldConfig {
  default?: number;
  min?: number;
  max?: number;
  step?: number;
  placeholder?: string;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: number | string;
  onChange: (value: number | string) => void;
}

export function NumberField({ fieldId, config, value, onChange }: Props) {
  return (
    <input
      type="number"
      id={fieldId}
      value={value ?? config.default ?? ''}
      onChange={(e) => onChange(e.target.value)}
      min={config.min}
      max={config.max}
      step={config.step}
      placeholder={config.placeholder}
    />
  );
}
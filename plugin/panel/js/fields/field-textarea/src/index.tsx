interface FieldConfig {
  default?: string;
  placeholder?: string;
  rows?: number;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string;
  onChange: (value: string) => void;
}

export function TextareaField({ fieldId, config, value, onChange }: Props) {
  return (
    <textarea
      id={fieldId}
      value={value ?? config.default ?? ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder={config.placeholder}
      rows={config.rows ?? 4}
    />
  );
}
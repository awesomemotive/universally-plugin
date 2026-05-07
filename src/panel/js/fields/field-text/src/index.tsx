interface FieldConfig {
  default?: string;
  placeholder?: string;
  inputType?: string;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string;
  onChange: (value: string) => void;
}

export function TextField({ fieldId, config, value, onChange }: Props) {
  return (
    <input
      type={config.inputType || 'text'}
      id={fieldId}
      value={value ?? config.default ?? ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder={config.placeholder}
    />
  );
}
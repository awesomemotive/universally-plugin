interface FieldConfig {
  default?: string;
  options?: Record<string, string>;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string;
  onChange: (value: string) => void;
}

export function SelectField({ fieldId, config, value, onChange }: Props) {
  const options = config.options ?? {};

  return (
    <select
      id={fieldId}
      value={value ?? config.default ?? ''}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">— Select —</option>
      {Object.entries(options).map(([val, label]) => (
        <option key={val} value={val}>
          {label}
        </option>
      ))}
    </select>
  );
}
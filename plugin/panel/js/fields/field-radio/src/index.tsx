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

export function RadioField({ fieldId, config, value, onChange }: Props) {
  const options = config.options ?? {};
  const selected = value ?? config.default ?? '';

  return (
    <div className="wp-panel-radio">
      {Object.entries(options).map(([val, label]) => (
        <label key={val} className="wp-panel-radio__option">
          <input
            type="radio"
            name={fieldId}
            value={val}
            checked={selected === val}
            onChange={(e) => onChange(e.target.value)}
          />
          <span>{label}</span>
        </label>
      ))}
    </div>
  );
}
interface FieldConfig {
  default?: string[];
  options?: Record<string, string>;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string[];
  onChange: (value: string[]) => void;
}

export function CheckboxesField({ fieldId, config, value, onChange }: Props) {
  const selectedValues = value ?? config.default ?? [];
  const options = config.options ?? {};

  const handleChange = (key: string, checked: boolean) => {
    if (checked) {
      onChange([...selectedValues, key]);
    } else {
      onChange(selectedValues.filter((v) => v !== key));
    }
  };

  return (
    <div className="wp-panel-checkboxes">
      {Object.entries(options).map(([key, label]) => (
        <label key={key} className="wp-panel-checkboxes__option">
          <input
            type="checkbox"
            id={`${fieldId}-${key}`}
            checked={selectedValues.includes(key)}
            onChange={(e) => handleChange(key, e.target.checked)}
          />
          <span>{label}</span>
        </label>
      ))}
    </div>
  );
}
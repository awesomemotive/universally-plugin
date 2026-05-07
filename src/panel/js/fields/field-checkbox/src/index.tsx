interface FieldConfig {
  default?: boolean;
  inlineLabel?: string;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: boolean;
  onChange: (value: boolean) => void;
}

export function CheckboxField({ fieldId, config, value, onChange }: Props) {
  return (
    <label className="wp-panel-checkbox">
      <input
        type="checkbox"
        id={fieldId}
        checked={value ?? config.default ?? false}
        onChange={(e) => onChange(e.target.checked)}
      />
      {config.inlineLabel && <span>{config.inlineLabel}</span>}
    </label>
  );
}
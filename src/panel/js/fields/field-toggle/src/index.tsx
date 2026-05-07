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

export function ToggleField({ fieldId, config, value, onChange }: Props) {
  const checked = value ?? config.default ?? false;

  return (
    <label className="wp-panel-toggle">
      <input
        type="checkbox"
        id={fieldId}
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
      />
      <span className="wp-panel-toggle__track">
        <span className="wp-panel-toggle__thumb" />
      </span>
      {config.inlineLabel && <span className="wp-panel-toggle__label">{config.inlineLabel}</span>}
    </label>
  );
}
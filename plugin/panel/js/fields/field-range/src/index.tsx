// @ts-ignore – wp.components is provided by WordPress at runtime
import { RangeControl } from '@wordpress/components';

interface FieldConfig {
  default?: number;
  min?: number;
  max?: number;
  step?: number;
  suffix?: string;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string;
  onChange: (value: string) => void;
}

export function RangeField({ config, value, onChange }: Props) {
  const min = config.min ?? 0;
  const max = config.max ?? 100;
  const step = config.step ?? 1;
  const suffix = config.suffix ?? '';

  const numValue = value !== '' && value !== undefined ? parseFloat(value) : (config.default ?? min);

  return (
    <div className="wp-panel-field__range__inner"><RangeControl
      value={numValue}
      min={min}
      max={max}
      step={step}
      renderTooltipContent={suffix ? ((v: any) => `${v ?? 0}${suffix}`) as any : undefined}
      onChange={(newValue: number | undefined) => {
        onChange(newValue !== undefined ? `${newValue}${suffix}` : '');
      }}
    />{!!suffix && <span className="wp-panel-field__range__suffix">{suffix}</span>}</div>
  );
}

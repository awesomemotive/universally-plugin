import type { FieldItem } from '../types';
import { useConditions } from '../hooks/useConditions';
import { usePanelState } from '../hooks/usePanelState';
import { getFieldComponent } from '../registry';
import { formatInline } from '../utils/formatInline';

interface FieldProps {
  fieldId: string;
  config: FieldItem;
}

export function Field({ fieldId, config }: FieldProps) {
  const { getValue, setValue, state, panelData } = usePanelState();
  const visible = useConditions(config.conditions);

  if (!visible) {
    return null;
  }

  const FieldComponent = getFieldComponent(config.type);
  const error = state.errors[fieldId];

  if (!FieldComponent) {
    return (
      <div className="wp-panel-field wp-panel-field--error">
        <div className="wp-panel-field__label">{config.label}</div>
        <div className="wp-panel-field__error">
          Unknown field type: {config.type}
        </div>
      </div>
    );
  }

  return (
    <div
      className={`wp-panel-field wp-panel-field--${config.type}${error ? ' has-error' : ''}${config.separator === false ? ' wp-panel-field--no-separator' : ''}`}
    >
      <div className="wp-panel-field__label">
        <label htmlFor={fieldId}>{config.label}</label>
      </div>
      <div className={`wp-panel-field__control wp-panel-field__control--${config.size || 'medium'}`}>
        <FieldComponent
          fieldId={fieldId}
          config={config}
          value={getValue(fieldId)}
          onChange={(value: unknown) => setValue(fieldId, value)}
          error={error}
        />
      </div>
      {config.description && (
        <div className="wp-panel-field__description">{formatInline(config.description, panelData.parsed.fields)}</div>
      )}
      {error && <div className="wp-panel-field__error">{error}</div>}
    </div>
  );
}
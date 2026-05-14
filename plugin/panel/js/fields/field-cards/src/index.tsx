interface OptionConfig {
  label: string;
  description?: string;
}

interface FieldConfig {
  default?: string | string[];
  options?: Record<string, OptionConfig | string>;
  max?: number; // 1 = single select, >1 or undefined = multi select
  columns?: 1 | 2 | 3; // Number of columns (default: 1)
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string | string[];
  onChange: (value: string | string[]) => void;
}

export function CardsField({ fieldId, config, value, onChange }: Props) {
  const isSingle = config.max === 1;

  // Normalize value to array for internal handling
  const currentValue: string[] = isSingle
    ? (value ? [value as string] : [])
    : (Array.isArray(value) ? value : (config.default ? (Array.isArray(config.default) ? config.default : [config.default]) : []));

  const normalizedOptions = config.options
    ? Object.entries(config.options).map(([optionValue, optionConfig]) => {
        if (typeof optionConfig === 'string') {
          return { value: optionValue, label: optionConfig, description: undefined };
        }
        return { value: optionValue, label: optionConfig.label, description: optionConfig.description };
      })
    : [];

  const toggleOption = (optionValue: string) => {
    if (isSingle) {
      onChange(optionValue);
    } else {
      if (currentValue.includes(optionValue)) {
        onChange(currentValue.filter((v) => v !== optionValue));
      } else {
        if (config.max && currentValue.length >= config.max) {
          return;
        }
        onChange([...currentValue, optionValue]);
      }
    }
  };

  const modeClass = isSingle ? 'wp-panel-cards--single' : 'wp-panel-cards--multi';
  const columns = config.columns || 1;
  const colsClass = `wp-panel-cards--cols-${columns}`;

  return (
    <div
      className={`wp-panel-cards ${modeClass} ${colsClass}`}
      role={isSingle ? 'radiogroup' : 'group'}
      aria-labelledby={`${fieldId}-label`}
    >
      {normalizedOptions.map((option) => {
        const isSelected = currentValue.includes(option.value);
        const optionId = `${fieldId}-${option.value}`;

        return (
          <div
            key={option.value}
            className={`wp-panel-cards__option${isSelected ? ' wp-panel-cards__option--selected' : ''}`}
            onClick={() => toggleOption(option.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleOption(option.value);
              }
            }}
            role={isSingle ? 'radio' : 'checkbox'}
            aria-checked={isSelected}
            tabIndex={0}
          >
            <input
              type={isSingle ? 'radio' : 'checkbox'}
              id={optionId}
              name={fieldId}
              value={option.value}
              checked={isSelected}
              onChange={() => toggleOption(option.value)}
              className="wp-panel-cards__input"
              tabIndex={-1}
            />
            <span className="wp-panel-cards__indicator" aria-hidden="true" />
            <div className="wp-panel-cards__content">
              <span className="wp-panel-cards__label">{option.label}</span>
              {option.description && (
                <span className="wp-panel-cards__description">{option.description}</span>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

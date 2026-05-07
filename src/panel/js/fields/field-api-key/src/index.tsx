import { useState, useEffect } from 'react';
import { useFieldApi } from './useFieldApi';

interface FieldConfig {
  endpoint: string;
  placeholder?: string;
  independent?: boolean;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
  value: string;
  onChange: (value: string) => void;
}

interface ApiKeyResponse {
  valid: boolean;
  message: string;
  value?: string;
}

const cache = new Map<string, ApiKeyResponse>();

function maskKey(key: string): string {
  if (key.length <= 8) return '*'.repeat(key.length);
  return key.slice(0, 4) + '*'.repeat(key.length - 8) + key.slice(-4);
}

export function ApiKeyField({ fieldId, config }: Props) {
  const cached = cache.get(config.endpoint);
  const [inputValue, setInputValue] = useState(cached?.value ?? '');
  const [valid, setValid] = useState(cached?.valid ?? false);
  const [message, setMessage] = useState<string | null>(cached?.message || null);
  const [messageType, setMessageType] = useState<'success' | 'error' | 'info'>(
    cached ? (cached.valid ? 'success' : 'info') : 'info'
  );

  const { loading, error, request } = useFieldApi<ApiKeyResponse>(config.endpoint);

  useEffect(() => {
    if (cache.has(config.endpoint)) return;
    const fetchStatus = async () => {
      const res = await request('GET');
      if (res) {
        cache.set(config.endpoint, res);
        setInputValue(res.value ?? '');
        setValid(res.valid);
        setMessage(res.message || null);
        setMessageType(res.valid ? 'success' : 'info');
      }
    };
    fetchStatus();
  }, [request, config.endpoint]);

  const handleActivate = async () => {
    const res = await request('POST', { value: inputValue });
    if (res) {
      cache.set(config.endpoint, { ...res, value: inputValue });
      setValid(res.valid);
      setMessage(res.message);
      setMessageType(res.valid ? 'success' : 'error');
    }
  };

  const handleDeactivate = async () => {
    const res = await request('POST', { value: '', action: 'deactivate' });
    if (res) {
      cache.set(config.endpoint, res);
      setInputValue('');
      setValid(false);
      setMessage(res.message);
      setMessageType('info');
    }
  };

  const statusClass = valid ? 'valid' : messageType === 'error' ? 'invalid' : '';
  const feedback = error ?? message;
  const feedbackClass = error ? 'error' : messageType;

  return (
    <div className="wp-panel-api-key">
      <div className="wp-panel-api-key__input-row">
        <input
          type="text"
          id={fieldId}
          className={`wp-panel-api-key__input ${statusClass}`}
          value={valid ? maskKey(inputValue) : inputValue}
          onChange={(e) => {
            setInputValue(e.target.value);
            setMessage(null);
          }}
          placeholder={loading ? '' : (config.placeholder ?? 'Enter your API key')}
          disabled={loading || valid}
        />
        {valid ? (
          <button
            type="button"
            className="wp-panel-api-key__button wp-panel-api-key__button--deactivate"
            onClick={handleDeactivate}
            disabled={loading}
          >
            {loading ? 'Working...' : 'Deactivate'}
          </button>
        ) : (
          <button
            type="button"
            className="wp-panel-api-key__button wp-panel-api-key__button--validate"
            onClick={handleActivate}
            disabled={loading || !inputValue.trim()}
          >
            {loading ? 'Validating...' : 'Activate'}
          </button>
        )}
      </div>

      {feedback && (
        <div className={`wp-panel-api-key__feedback wp-panel-api-key__feedback--${feedbackClass}`}>
          {feedbackClass === 'success' && <span className="wp-panel-api-key__checkmark">&#10003;</span>}
          {feedback}
        </div>
      )}
    </div>
  );
}

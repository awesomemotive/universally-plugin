import { useState } from 'react';

interface FieldConfig {
  content?: string;
  buttonText?: string;
  copiedText?: string;
  [key: string]: unknown;
}

interface Props {
  fieldId: string;
  config: FieldConfig;
}

export function CopyableField({ fieldId, config }: Props) {
  const [copied, setCopied] = useState(false);
  const content = config.content ?? '';
  const isMultiline = content.includes('\n');

  const handleCopy = () => {
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(content);
    } else {
      // Fallback for non-HTTPS contexts using Range/Selection API
      const mark = document.createElement('span');
      mark.textContent = content;
      mark.style.all = 'unset';
      mark.style.position = 'fixed';
      mark.style.top = '0';
      mark.style.clip = 'rect(0, 0, 0, 0)';
      mark.style.whiteSpace = 'pre';
      mark.style.userSelect = 'text';

      document.body.appendChild(mark);

      const selection = document.getSelection();
      const range = document.createRange();
      range.selectNodeContents(mark);
      selection?.removeAllRanges();
      selection?.addRange(range);

      document.execCommand('copy');

      selection?.removeAllRanges();
      document.body.removeChild(mark);
    }
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="wp-field-copyable wp-panel-field__general-style">
      {isMultiline ? (
        <pre id={fieldId} className="wp-field-copyable__content">{content}</pre>
      ) : (
        <div id={fieldId} className="wp-field-copyable__content">{content}</div>
      )}
      <button
        type="button"
        className="wp-field-copyable__button"
        onClick={handleCopy}
      >
        {copied ? (config.copiedText ?? 'Copied!') : (config.buttonText ?? 'Copy')}
      </button>
    </div>
  );
}

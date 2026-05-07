import type { ReactNode } from 'react';
import type { FieldItem } from '../types';

/**
 * Parse inline markdown in description strings into React elements.
 *
 * Supported syntax:
 * - **bold** → <strong>
 * - `code` → <code>
 * - [text](url) → <a href="url" target="_blank" rel="noopener noreferrer">
 * - {field_id} → resolved to the field's label, or left as-is if not found
 */
export function formatInline(text: string, fields?: Record<string, FieldItem>): ReactNode[] {
  const pattern = /(\*\*(.+?)\*\*|`([^`]+)`|\[([^\]]+)\]\((https?:\/\/[^)]+)\)|\{([a-zA-Z0-9_]+)\})/g;
  const result: ReactNode[] = [];
  let lastIndex = 0;
  let key = 0;
  let match: RegExpExecArray | null;

  while ((match = pattern.exec(text)) !== null) {
    if (match.index > lastIndex) {
      result.push(text.slice(lastIndex, match.index));
    }

    if (match[2]) {
      result.push(<strong key={key++}>{match[2]}</strong>);
    } else if (match[3]) {
      result.push(<code key={key++}>{match[3]}</code>);
    } else if (match[4] && match[5]) {
      result.push(<a key={key++} href={match[5]} target="_blank" rel="noopener noreferrer">{match[4]}</a>);
    } else if (match[6]) {
      const field = fields?.[match[6]];
      result.push(field ? field.label : match[0]);
    }

    lastIndex = match.index + match[0].length;
  }

  if (lastIndex < text.length) {
    result.push(text.slice(lastIndex));
  }

  return result;
}

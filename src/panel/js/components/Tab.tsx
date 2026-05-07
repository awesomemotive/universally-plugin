import type { FieldItem, SectionItem } from '../types';
import { Section } from './Section';

interface TabProps {
  tabId: string;
  fields: FieldItem[];
  sections: SectionItem[];
}

export function Tab({ tabId, fields, sections }: TabProps) {
  if (fields.length === 0 && sections.length === 0) {
    return (
      <div className="wp-panel-tab wp-panel-tab--empty">
        No fields configured for this tab.
      </div>
    );
  }

  // Group fields by section
  const fieldsBySection: Record<string, FieldItem[]> = { _untitled: [] };
  for (const section of sections) {
    fieldsBySection[section.id] = [];
  }

  // Distribute fields to their sections
  for (const field of fields) {
    const sectionId = field._section || '_untitled';
    if (fieldsBySection[sectionId]) {
      fieldsBySection[sectionId].push(field);
    } else {
      // Field references unknown section, put in untitled
      fieldsBySection._untitled.push(field);
    }
  }

  return (
    <div className="wp-panel-tab" data-tab={tabId}>
      {/* Untitled section fields (fields before any section) */}
      {fieldsBySection._untitled.length > 0 && (
        <Section section={null} fields={fieldsBySection._untitled} />
      )}

      {/* Named sections */}
      {sections.map((section) => (
        <Section
          key={section.id}
          section={section}
          fields={fieldsBySection[section.id] || []}
        />
      ))}
    </div>
  );
}
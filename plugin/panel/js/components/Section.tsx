import { PanelBody, Button } from '@wordpress/components';
import type { FieldItem, SectionItem } from '../types';
import { Field } from './Field';
import { usePanelState } from '../hooks/usePanelState';
import { formatInline } from '../utils/formatInline';

interface SectionProps {
  section: SectionItem | null; // null for untitled section
  fields: FieldItem[];
}

export function Section({ section, fields }: SectionProps) {
  const { state, save, panelData } = usePanelState();

  if (fields.length === 0 && !section) {
    return null;
  }

  // Show save button unless explicitly disabled
  const showSave = section?.showSave !== false;

  const SaveButton = showSave ? (
    <div className="wp-panel-section__save">
      <Button
        variant="primary"
        onClick={save}
        disabled={!state.modified || state.saving}
        isBusy={state.saving}
      >
        {state.saving ? 'Saving...' : 'Save Changes'}
      </Button>
    </div>
  ) : null;

  // Untitled section: no header, non-collapsible, same body styling
  if (!section) {
    return (
      <div className="wp-panel-section wp-panel-section--untitled components-panel__body is-opened">
        <div className="wp-panel-section__body">
          {fields.map((fieldConfig) => (
            <Field key={fieldConfig.id} fieldId={fieldConfig.id} config={fieldConfig} />
          ))}
          {SaveButton}
        </div>
      </div>
    );
  }

  // Named section: collapsible with header
  return (
    <PanelBody
      title={section.label}
      initialOpen={section.default !== 'closed'}
      className="wp-panel-section"
    >
      {section.description && (
        <p className="wp-panel-section__description">{formatInline(section.description, panelData.parsed.fields)}</p>
      )}
      {fields.map((fieldConfig) => (
        <Field key={fieldConfig.id} fieldId={fieldConfig.id} config={fieldConfig} />
      ))}
      {SaveButton}
    </PanelBody>
  );
}

import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, Placeholder } from '@wordpress/components';

interface Language {
  name: string;
  originalName?: string;
  region?: string;
  variant?: string;
  flagUrl?: string;
  url: string;
  urlPrefix?: string;
  isSource?: boolean;
  isCurrent?: boolean;
  isDisabled?: boolean;
}

interface BlockData {
  languages: Language[];
  settings: {
    showFlags: boolean;
    showNames: boolean;
    flagStyle: string;
  };
  styleAttr: string;
}

interface BlockAttributes {
  showFlags: string;
  showNames: string;
  flagStyle: string;
}

declare global {
  interface Window {
    universallyBlockData?: BlockData;
  }
}

interface EditProps {
  attributes: BlockAttributes;
  setAttributes: (attrs: Partial<BlockAttributes>) => void;
}

const THREE_WAY_BOOL = [
  { label: 'Use global setting', value: '' },
  { label: 'Yes', value: 'true' },
  { label: 'No', value: 'false' },
];

const FLAG_STYLE_OPTIONS = [
  { label: 'Use global setting', value: '' },
  { label: 'Rounded', value: 'rounded' },
  { label: 'Square', value: 'square' },
];

function parseStyleString(css: string): Record<string, string> {
  if (!css) return {};
  const result: Record<string, string> = {};
  for (const part of css.split(';')) {
    const i = part.indexOf(':');
    if (i === -1) continue;
    const key = part.slice(0, i).trim();
    const value = part.slice(i + 1).trim();
    if (key && value) result[key] = value;
  }
  return result;
}

export default function Edit({ attributes, setAttributes }: EditProps) {
  const blockProps = useBlockProps();
  const blockData = window.universallyBlockData;

  if (!blockData || !blockData.languages.length) {
    return (
      <div {...blockProps}>
        <Placeholder
          icon="translation"
          label="Language Switcher"
          instructions="No languages configured. Set up your site languages in the Universally settings."
        />
      </div>
    );
  }

  const { settings, styleAttr } = blockData;

  const config = {
    languages: blockData.languages,
    showFlags: attributes.showFlags === '' ? settings.showFlags : attributes.showFlags === 'true',
    showNames: attributes.showNames === '' ? settings.showNames : attributes.showNames === 'true',
    flagStyle: attributes.flagStyle === '' ? settings.flagStyle : attributes.flagStyle,
  };

  const configJson = JSON.stringify(config);

  return (
    <>
      <InspectorControls>
        <PanelBody title="Switcher Settings">
          <SelectControl
            label="Show Flags"
            value={attributes.showFlags}
            options={THREE_WAY_BOOL}
            onChange={(value: string) => setAttributes({ showFlags: value })}
          />
          <SelectControl
            label="Show Names"
            value={attributes.showNames}
            options={THREE_WAY_BOOL}
            onChange={(value: string) => setAttributes({ showNames: value })}
          />
          <SelectControl
            label="Flag Style"
            value={attributes.flagStyle}
            options={FLAG_STYLE_OPTIONS}
            onChange={(value: string) => setAttributes({ flagStyle: value })}
          />
        </PanelBody>
      </InspectorControls>
      <div {...blockProps}>
        {/* @ts-expect-error Web Component */}
        <universally-switcher
          key={configJson}
          data-config={configJson}
          style={parseStyleString(styleAttr)}
        />
      </div>
    </>
  );
}

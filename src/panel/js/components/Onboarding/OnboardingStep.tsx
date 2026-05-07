import type { OnboardingStep as OnboardingStepType, FieldItem } from '../../types';
import { Field } from '../Field';
import { formatInline } from '../../utils/formatInline';

interface OnboardingStepProps {
  step: OnboardingStepType;
  fields: Record<string, FieldItem>;
}

export function OnboardingStep({ step, fields }: OnboardingStepProps) {
  return (
    <div className="wp-onboarding__step">
      {step.image && (
        <div className="wp-onboarding__step-image">
          <img src={step.image} alt="" />
        </div>
      )}

      <h1 className="wp-onboarding__step-title">{step.title}</h1>

      {step.description && (
        <p className="wp-onboarding__step-description">{formatInline(step.description, fields)}</p>
      )}

      {step.fields.length > 0 && (
        <div className="wp-onboarding__step-fields">
          {step.fields.map((fieldConfig) => {
            const fieldId = fieldConfig.id;
            const mergedConfig = { ...fieldConfig, ...fields[fieldId] };
            return <Field key={fieldId} fieldId={fieldId} config={mergedConfig} />;
          })}
        </div>
      )}
    </div>
  );
}
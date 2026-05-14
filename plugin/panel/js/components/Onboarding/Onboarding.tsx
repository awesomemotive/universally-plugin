import { useState, useCallback } from 'react';
import type { OnboardingData, OnboardingButtons } from '../../types';
import { usePanelState } from '../../hooks/usePanelState';
import { useOnboardingState } from '../../hooks/useOnboardingState';
import { OnboardingTabs } from './OnboardingTabs';
import { OnboardingStep } from './OnboardingStep';

interface OnboardingProps {
  onboarding: OnboardingData;
  panelTitle: string;
}

const DEFAULT_BUTTONS: OnboardingButtons = {
  next: 'Next',
  back: 'Back',
  skip: 'Skip',
  skipAll: 'Skip Setup',
  finish: 'Finish',
};

export function Onboarding({ onboarding, panelTitle }: OnboardingProps) {
  const { panelData, getValue, dispatch } = usePanelState();
  const { state: obState, completeStep, skipStep, setCurrentStep, complete, skipAll, updateState } =
    useOnboardingState(onboarding.state);

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { config, fields } = onboarding;
  const steps = config.steps;

  const currentStepIndex = steps.findIndex((s) => s.id === obState.current_step);
  const currentStep = steps[currentStepIndex];
  const isFirstStep = currentStepIndex === 0;
  const isLastStep = currentStepIndex === steps.length - 1;

  const getButtonLabel = (key: keyof OnboardingButtons): string => {
    return currentStep?.buttons?.[key] ?? config.buttons?.[key] ?? DEFAULT_BUTTONS[key];
  };

  const saveStepAndAdvance = useCallback(
    async (action: 'complete' | 'skip' | 'finish' | 'skipAll', nextStepId?: string) => {
      setSaving(true);
      setError(null);
      dispatch({ type: 'CLEAR_MESSAGE' });

      try {
        const stepFieldIds = currentStep?.fields.map((f) => f.id) ?? [];
        const stepValues: Record<string, unknown> = {};
        for (const id of stepFieldIds) {
          stepValues[id] = getValue(id);
        }

        const response = await fetch(`${panelData.ajaxUrl}?action=${panelData.action}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            nonce: panelData.nonce,
            values: action === 'skipAll' ? {} : stepValues,
            onboarding: {
              stepId: currentStep?.id,
              nextStepId,
              action,
            },
          }),
        });

        const result = await response.json();

        if (result.success) {
          if (action === 'complete' && currentStep) {
            completeStep(currentStep.id);
          }
          if (action === 'skip' && currentStep) {
            skipStep(currentStep.id);
          }
          if (action === 'finish') {
            complete();
            window.location.reload();
            return;
          }
          if (action === 'skipAll') {
            skipAll();
            window.location.reload();
            return;
          }
          if (nextStepId) {
            setCurrentStep(nextStepId);
          }
          if (result.data?.onboardingState) {
            updateState(result.data.onboardingState);
          }
        } else {
          // Dispatch field errors to panel state so they show inline
          dispatch({
            type: 'SAVE_ERROR',
            message: result.data?.message ?? 'Failed to save',
            errors: result.data?.errors,
          });
          setError(result.data?.errors ? null : (result.data?.message ?? 'Failed to save'));
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to save');
      } finally {
        setSaving(false);
      }
    },
    [currentStep, panelData, getValue, dispatch, completeStep, skipStep, setCurrentStep, complete, skipAll, updateState]
  );

  const handleNext = () => {
    const nextStep = steps[currentStepIndex + 1];
    saveStepAndAdvance(isLastStep ? 'finish' : 'complete', nextStep?.id);
  };

  const handleSkip = () => {
    const nextStep = steps[currentStepIndex + 1];
    saveStepAndAdvance('skip', nextStep?.id);
  };

  const handleBack = () => {
    const prevStep = steps[currentStepIndex - 1];
    if (prevStep) {
      // Clear any validation errors when going back
      dispatch({ type: 'CLEAR_MESSAGE' });
      setCurrentStep(prevStep.id);
    }
  };

  const handleSkipAll = () => {
    saveStepAndAdvance('skipAll');
  };

  const handleStepClick = (stepId: string) => {
    setCurrentStep(stepId);
  };

  if (!currentStep) {
    return null;
  }

  return (
    <div className="wp-onboarding">
      <OnboardingTabs
        steps={steps}
        currentStepId={obState.current_step ?? steps[0].id}
        completedSteps={obState.completed_steps}
        skippedSteps={obState.skipped_steps}
        onStepClick={handleStepClick}
      />

      <header className="wp-onboarding__header">
        <div className="wp-onboarding__logo">{panelTitle}</div>
        {config.skippable && (
          <button
            type="button"
            className="wp-onboarding__exit-link"
            onClick={handleSkipAll}
            disabled={saving}
          >
            Go back to the Dashboard
          </button>
        )}
      </header>

      <main className="wp-onboarding__main">
        <OnboardingStep step={currentStep} fields={fields} />

        {error && <div className="wp-onboarding__error">{error}</div>}

        <div className="wp-onboarding__nav">
          {!isFirstStep && (
            <button
              type="button"
              className="wp-onboarding__btn wp-onboarding__btn--back"
              onClick={handleBack}
              disabled={saving}
            >
              {getButtonLabel('back')}
            </button>
          )}

          <div className="wp-onboarding__nav-primary">
            {currentStep.skippable && !isLastStep && (
              <button
                type="button"
                className="wp-onboarding__btn wp-onboarding__btn--skip"
                onClick={handleSkip}
                disabled={saving}
              >
                {getButtonLabel('skip')}
              </button>
            )}

            <button
              type="button"
              className="wp-onboarding__btn wp-onboarding__btn--next"
              onClick={handleNext}
              disabled={saving}
            >
              {saving ? 'Saving...' : isLastStep ? getButtonLabel('finish') : getButtonLabel('next')}
            </button>
          </div>
        </div>

        {config.skippable && (
          <button
            type="button"
            className="wp-onboarding__skip-all-link"
            onClick={handleSkipAll}
            disabled={saving}
          >
            Close And Exit Wizard Without Saving
          </button>
        )}
      </main>
    </div>
  );
}
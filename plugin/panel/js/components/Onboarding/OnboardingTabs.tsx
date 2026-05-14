import type { OnboardingStep } from '../../types';

interface OnboardingTabsProps {
  steps: OnboardingStep[];
  currentStepId: string;
  completedSteps: string[];
  skippedSteps: string[];
  onStepClick?: (stepId: string) => void;
}

export function OnboardingTabs({
  steps,
  currentStepId,
  completedSteps,
  skippedSteps,
  onStepClick,
}: OnboardingTabsProps) {
  const currentIndex = steps.findIndex((s) => s.id === currentStepId);

  return (
    <div className="wp-onboarding__tabs">
      {steps.map((step, index) => {
        const isCompleted = completedSteps.includes(step.id);
        const isSkipped = skippedSteps.includes(step.id);
        const isCurrent = step.id === currentStepId;
        const isPast = index < currentIndex;
        const canClick = isCompleted || isSkipped || isPast;

        return (
          <button
            key={step.id}
            className={`wp-onboarding__tab ${isCurrent ? 'is-current' : ''} ${isCompleted ? 'is-completed' : ''} ${isSkipped ? 'is-skipped' : ''}`}
            onClick={() => canClick && onStepClick?.(step.id)}
            disabled={!canClick}
            type="button"
          >
            <span className="wp-onboarding__tab-label">
              {step.tabLabel || step.title}
            </span>
          </button>
        );
      })}
      <div
        className="wp-onboarding__tabs-progress"
        style={{ width: `${((currentIndex + 1) / steps.length) * 100}%` }}
      />
    </div>
  );
}
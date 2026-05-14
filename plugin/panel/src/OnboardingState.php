<?php

namespace UniversallyPanel\Panel;

/**
 * Manages onboarding wizard progress state.
 */
final class OnboardingState
{
    private string $optionName;
    private ?array $state = null;

    /**
     * Default state structure.
     */
    private const DEFAULT_STATE = [
        'status' => 'pending',
        'current_step' => null,
        'completed_steps' => [],
        'skipped_steps' => [],
        'started_at' => null,
        'completed_at' => null,
    ];

    public function __construct(string $panelId)
    {
        $this->optionName = $panelId . '_onboarding';
    }

    /**
     * Get the full state array.
     */
    public function get(): array
    {
        if ($this->state === null) {
            $this->state = get_option($this->optionName, self::DEFAULT_STATE);
        }

        return $this->state;
    }

    /**
     * Get the current status.
     */
    public function getStatus(): string
    {
        return $this->get()['status'];
    }

    /**
     * Check if onboarding is active (pending or in_progress).
     */
    public function isActive(): bool
    {
        $status = $this->getStatus();
        return $status === 'pending' || $status === 'in_progress';
    }

    /**
     * Get the current step ID.
     */
    public function getCurrentStep(): ?string
    {
        return $this->get()['current_step'];
    }

    /**
     * Start the onboarding wizard.
     */
    public function start(string $firstStepId): void
    {
        $this->state = $this->get();
        $this->state['status'] = 'in_progress';
        $this->state['current_step'] = $firstStepId;
        $this->state['started_at'] = time();
        $this->save();
    }

    /**
     * Mark a step as completed.
     */
    public function markStepCompleted(string $stepId): void
    {
        $this->state = $this->get();

        // Add to completed steps if not already there
        if (!in_array($stepId, $this->state['completed_steps'], true)) {
            $this->state['completed_steps'][] = $stepId;
        }

        // Remove from skipped steps if present
        $this->state['skipped_steps'] = array_values(
            array_filter(
                $this->state['skipped_steps'],
                fn($id) => $id !== $stepId
            )
        );

        $this->save();
    }

    /**
     * Mark a step as skipped.
     */
    public function markStepSkipped(string $stepId): void
    {
        $this->state = $this->get();

        // Add to skipped steps if not already there
        if (!in_array($stepId, $this->state['skipped_steps'], true)) {
            $this->state['skipped_steps'][] = $stepId;
        }

        $this->save();
    }

    /**
     * Set the current step.
     */
    public function setCurrentStep(string $stepId): void
    {
        $this->state = $this->get();
        $this->state['current_step'] = $stepId;
        $this->save();
    }

    /**
     * Complete the onboarding wizard.
     */
    public function complete(): void
    {
        $this->state = $this->get();
        $this->state['status'] = 'completed';
        $this->state['completed_at'] = time();
        $this->save();
    }

    /**
     * Skip the onboarding wizard.
     */
    public function skip(): void
    {
        $this->state = $this->get();
        $this->state['status'] = 'skipped';
        $this->state['completed_at'] = time();
        $this->save();
    }

    /**
     * Reset the onboarding state.
     */
    public function reset(): void
    {
        delete_option($this->optionName);
        $this->state = null;
    }

    /**
     * Save the current state to the database.
     */
    private function save(): void
    {
        update_option($this->optionName, $this->state, false);
    }
}

import { useReducer, useCallback } from 'react';
import type { OnboardingStateData } from '../types';

type OnboardingAction =
  | { type: 'COMPLETE_STEP'; stepId: string }
  | { type: 'SKIP_STEP'; stepId: string }
  | { type: 'SET_CURRENT_STEP'; stepId: string }
  | { type: 'COMPLETE' }
  | { type: 'SKIP_ALL' }
  | { type: 'UPDATE_STATE'; state: OnboardingStateData };

function reducer(state: OnboardingStateData, action: OnboardingAction): OnboardingStateData {
  switch (action.type) {
    case 'COMPLETE_STEP':
      return {
        ...state,
        completed_steps: state.completed_steps.includes(action.stepId)
          ? state.completed_steps
          : [...state.completed_steps, action.stepId],
        skipped_steps: state.skipped_steps.filter((id) => id !== action.stepId),
      };

    case 'SKIP_STEP':
      return {
        ...state,
        skipped_steps: state.skipped_steps.includes(action.stepId)
          ? state.skipped_steps
          : [...state.skipped_steps, action.stepId],
      };

    case 'SET_CURRENT_STEP':
      return {
        ...state,
        current_step: action.stepId,
      };

    case 'COMPLETE':
      return {
        ...state,
        status: 'completed',
        completed_at: Math.floor(Date.now() / 1000),
      };

    case 'SKIP_ALL':
      return {
        ...state,
        status: 'skipped',
        completed_at: Math.floor(Date.now() / 1000),
      };

    case 'UPDATE_STATE':
      return action.state;

    default:
      return state;
  }
}

export interface UseOnboardingStateReturn {
  state: OnboardingStateData;
  completeStep: (stepId: string) => void;
  skipStep: (stepId: string) => void;
  setCurrentStep: (stepId: string) => void;
  complete: () => void;
  skipAll: () => void;
  updateState: (state: OnboardingStateData) => void;
}

export function useOnboardingState(initialState: OnboardingStateData): UseOnboardingStateReturn {
  const [state, dispatch] = useReducer(reducer, initialState);

  const completeStep = useCallback((stepId: string) => {
    dispatch({ type: 'COMPLETE_STEP', stepId });
  }, []);

  const skipStep = useCallback((stepId: string) => {
    dispatch({ type: 'SKIP_STEP', stepId });
  }, []);

  const setCurrentStep = useCallback((stepId: string) => {
    dispatch({ type: 'SET_CURRENT_STEP', stepId });
  }, []);

  const complete = useCallback(() => {
    dispatch({ type: 'COMPLETE' });
  }, []);

  const skipAll = useCallback(() => {
    dispatch({ type: 'SKIP_ALL' });
  }, []);

  const updateState = useCallback((newState: OnboardingStateData) => {
    dispatch({ type: 'UPDATE_STATE', state: newState });
  }, []);

  return {
    state,
    completeStep,
    skipStep,
    setCurrentStep,
    complete,
    skipAll,
    updateState,
  };
}
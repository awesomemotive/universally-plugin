import {
  createContext,
  useContext,
  useReducer,
  useCallback,
  type ReactNode,
  type Dispatch,
} from 'react';
import type { PanelState, PanelAction, PanelData } from '../types';

const initialState: PanelState = {
  values: {},
  modified: false,
  saving: false,
  message: null,
  messageType: null,
  errors: {},
  nonce: '',
};

function reducer(state: PanelState, action: PanelAction): PanelState {
  switch (action.type) {
    case 'SET_VALUE':
      return {
        ...state,
        values: { ...state.values, [action.field]: action.value },
        modified: true,
        message: null,
        messageType: null,
        errors: { ...state.errors, [action.field]: undefined } as Record<string, string>,
      };

    case 'SAVE_START':
      return {
        ...state,
        saving: true,
        message: null,
        messageType: null,
      };

    case 'SAVE_SUCCESS':
      return {
        ...state,
        values: action.values,
        modified: false,
        saving: false,
        message: action.message,
        messageType: 'success',
        errors: {},
      };

    case 'SAVE_ERROR':
      return {
        ...state,
        saving: false,
        message: action.message,
        messageType: 'error',
        errors: action.errors ?? {},
      };

    case 'CLEAR_MESSAGE':
      return {
        ...state,
        message: null,
        messageType: null,
        errors: {},
      };

    default:
      return state;
  }
}

interface PanelContextValue {
  state: PanelState;
  dispatch: Dispatch<PanelAction>;
  panelData: PanelData;
  setValue: (field: string, value: unknown) => void;
  getValue: <T = unknown>(field: string) => T;
  save: () => Promise<void>;
}

const PanelContext = createContext<PanelContextValue | null>(null);

interface PanelProviderProps {
  children: ReactNode;
  panelData: PanelData;
}

export function PanelProvider({ children, panelData }: PanelProviderProps) {
  const [state, dispatch] = useReducer(reducer, {
    ...initialState,
    values: panelData.values,
  });

  const setValue = useCallback((field: string, value: unknown) => {
    dispatch({ type: 'SET_VALUE', field, value });
  }, []);

  const getValue = useCallback(
    <T = unknown>(field: string): T => {
      return state.values[field] as T;
    },
    [state.values]
  );

  const save = useCallback(async () => {
    dispatch({ type: 'SAVE_START' });

    // Filter out independent fields - they manage their own save
    const valuesToSave = Object.entries(state.values).reduce<Record<string, unknown>>(
      (acc, [key, val]) => {
        const field = panelData.parsed.fields[key];
        if (field?.independent) return acc;
        acc[key] = val;
        return acc;
      },
      {}
    );

    try {
      const response = await fetch(
        `${panelData.ajaxUrl}?action=${panelData.action}`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            nonce: panelData.nonce,
            values: valuesToSave,
          }),
        }
      );

      const result = await response.json();

      if (result.success) {
        dispatch({
          type: 'SAVE_SUCCESS',
          values: result.data.values,
          message: result.data.message,
        });
      } else {
        dispatch({
          type: 'SAVE_ERROR',
          message: result.data?.message ?? 'Failed to save',
          errors: result.data?.errors,
        });
      }
    } catch (error) {
      dispatch({
        type: 'SAVE_ERROR',
        message: error instanceof Error ? error.message : 'Failed to save',
      });
    }
  }, [panelData, state.values]);

  return (
    <PanelContext.Provider
      value={{ state, dispatch, panelData, setValue, getValue, save }}
    >
      {children}
    </PanelContext.Provider>
  );
}

export function usePanelState(): PanelContextValue {
  const context = useContext(PanelContext);
  if (!context) {
    throw new Error('usePanelState must be used within PanelProvider');
  }
  return context;
}

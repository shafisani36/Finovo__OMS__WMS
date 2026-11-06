import { createFieldId, fieldType } from './fieldTypes';
import type { FieldTypeKey, FormField } from './fieldTypes';

/**
 * State of the form being designed. Every edit raises `changes`; a save records the change count it carried, so
 * edits made while a save is on its way stay marked unsaved.
 */
export interface BuilderState {
  name: string;
  fields: FormField[];
  selectedId: string | null;
  /** Server revision the draft was loaded from or last saved as. */
  revision: number;
  changes: number;
  savedChanges: number;
}

export type BuilderAction =
  | { type: 'loaded'; name: string; fields: FormField[]; revision: number }
  | { type: 'renamed'; name: string }
  | { type: 'added'; fieldType: FieldTypeKey; index?: number }
  | { type: 'moved'; id: string; index: number }
  | { type: 'updated'; id: string; patch: Partial<Omit<FormField, 'id' | 'type'>> }
  | { type: 'duplicated'; id: string }
  | { type: 'removed'; id: string }
  | { type: 'selected'; id: string | null }
  | { type: 'saved'; revision: number; changes: number };

export const initialBuilderState: BuilderState = { name: '', fields: [], selectedId: null, revision: 0, changes: 0, savedChanges: 0 };

export const hasUnsavedChanges = (state: BuilderState): boolean => state.changes !== state.savedChanges;

const edited = (state: BuilderState, next: Partial<BuilderState>): BuilderState => ({ ...state, ...next, changes: state.changes + 1 });

const clampIndex = (index: number | undefined, length: number): number => (index === undefined ? length : Math.max(0, Math.min(index, length)));

export const builderReducer = (state: BuilderState, action: BuilderAction): BuilderState => {
  switch (action.type) {
    case 'loaded':
      return { ...initialBuilderState, name: action.name, fields: action.fields, revision: action.revision };

    case 'renamed':
      return edited(state, { name: action.name });

    case 'added': {
      const field = fieldType(action.fieldType).create(createFieldId(state.fields.map((f) => f.id)));
      const fields = [...state.fields];
      fields.splice(clampIndex(action.index, fields.length), 0, field);
      return edited(state, { fields, selectedId: field.id });
    }

    case 'moved': {
      const from = state.fields.findIndex((f) => f.id === action.id);
      if (from === -1) return state;
      const fields = [...state.fields];
      const [field] = fields.splice(from, 1);
      // The drop index counts the field itself while it is still in place.
      const to = clampIndex(action.index > from ? action.index - 1 : action.index, fields.length);
      if (to === from) return state;
      fields.splice(to, 0, field);
      return edited(state, { fields });
    }

    case 'updated':
      return edited(state, { fields: state.fields.map((f) => (f.id === action.id ? { ...f, ...action.patch } : f)) });

    case 'duplicated': {
      const index = state.fields.findIndex((f) => f.id === action.id);
      if (index === -1) return state;
      const copy = { ...state.fields[index], id: createFieldId(state.fields.map((f) => f.id)), label: `${state.fields[index].label} (copy)` };
      const fields = [...state.fields];
      fields.splice(index + 1, 0, copy);
      return edited(state, { fields, selectedId: copy.id });
    }

    case 'removed':
      return edited(state, {
        // Fields shown only for the removed field's answer are shown always from now on.
        fields: state.fields.filter((f) => f.id !== action.id).map((f) => (f.show_if?.field === action.id ? { ...f, show_if: null } : f)),
        selectedId: state.selectedId === action.id ? null : state.selectedId,
      });

    case 'selected':
      return { ...state, selectedId: action.id };

    case 'saved':
      return { ...state, revision: action.revision, savedChanges: action.changes };
  }
};

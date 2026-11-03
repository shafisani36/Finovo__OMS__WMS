import { describe, expect, it } from 'vitest';
import { builderReducer, hasUnsavedChanges, initialBuilderState } from './builderReducer';
import type { BuilderAction, BuilderState } from './builderReducer';

const apply = (actions: BuilderAction[], state: BuilderState = initialBuilderState) => actions.reduce(builderReducer, state);
const ids = (state: BuilderState) => state.fields.map((field) => field.id);

describe('form builder state', () => {
  const loaded = apply([
    {
      type: 'loaded',
      name: 'Check-in',
      revision: 4,
      fields: [
        { id: 'f_a', type: 'text', label: 'A' },
        { id: 'f_b', type: 'text', label: 'B' },
        { id: 'f_c', type: 'text', label: 'C' },
      ],
    },
  ]);

  it('adds fields where they are dropped, with unique IDs, and selects them', () => {
    const state = apply([{ type: 'added', fieldType: 'number', index: 1 }, { type: 'added', fieldType: 'number' }], loaded);
    expect(state.fields.map((f) => f.type)).toEqual(['text', 'number', 'text', 'text', 'number']);
    expect(new Set(ids(state)).size).toBe(5);
    expect(state.selectedId).toBe(state.fields[4].id);
  });

  it('moves a field to the gap it was dropped on', () => {
    expect(ids(apply([{ type: 'moved', id: 'f_a', index: 3 }], loaded))).toEqual(['f_b', 'f_c', 'f_a']);
    expect(ids(apply([{ type: 'moved', id: 'f_c', index: 0 }], loaded))).toEqual(['f_c', 'f_a', 'f_b']);
    // Dropping a field next to itself changes nothing.
    expect(apply([{ type: 'moved', id: 'f_b', index: 2 }], loaded)).toBe(loaded);
  });

  it('updates, duplicates and removes fields', () => {
    const state = apply(
      [
        { type: 'updated', id: 'f_b', patch: { label: 'Box', required: true } },
        { type: 'duplicated', id: 'f_b' },
        { type: 'selected', id: 'f_a' },
        { type: 'removed', id: 'f_a' },
      ],
      loaded,
    );
    expect(state.fields[0]).toMatchObject({ id: 'f_b', label: 'Box', required: true });
    expect(state.fields[1]).toMatchObject({ label: 'Box (copy)', required: true });
    expect(state.fields[1].id).not.toBe('f_b');
    expect(state.selectedId).toBeNull();
  });

  it('keeps edits made during a save marked unsaved', () => {
    expect(hasUnsavedChanges(loaded)).toBe(false);
    const edited = apply([{ type: 'renamed', name: 'Check-in v2' }], loaded);
    const sending = edited.changes;
    const editedAgain = apply([{ type: 'updated', id: 'f_a', patch: { label: 'Aa' } }], edited);
    const saved = apply([{ type: 'saved', revision: 5, changes: sending }], editedAgain);
    expect(saved.revision).toBe(5);
    expect(hasUnsavedChanges(saved)).toBe(true);
    expect(hasUnsavedChanges(apply([{ type: 'saved', revision: 6, changes: saved.changes }], saved))).toBe(false);
  });
});

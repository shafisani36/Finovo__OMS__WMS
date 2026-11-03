import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import formService from '../services/formService';
import type { FormDetail } from '../services/formService';
import { ApiError } from '../services/apiClient';
import { builderReducer, hasUnsavedChanges, initialBuilderState } from '../components/forms/builderReducer';
import { errorMessage } from '../utils/errors';

/** Quiet time after the last edit before the draft is saved. */
const AUTOSAVE_MS = 800;

export type SyncStatus = 'loading' | 'saved' | 'unsaved' | 'saving' | 'error' | 'conflict';

/**
 * The form being designed and its sync with the server: load, autosave of the draft (one save at a time, with
 * the revision it was based on), publishing and restoring versions. A save refused because someone else saved
 * first stops autosave until the form is reloaded, so nobody's work is overwritten silently.
 */
export const useFormBuilder = (uuid: string) => {
  const [state, dispatch] = useReducer(builderReducer, initialBuilderState);
  const [form, setForm] = useState<FormDetail | null>(null);
  const [status, setStatus] = useState<SyncStatus>('loading');
  const [error, setError] = useState<string | null>(null);
  const latest = useRef(state);
  const inFlight = useRef<Promise<boolean> | null>(null);

  useEffect(() => {
    latest.current = state;
  });

  const load = useCallback(async () => {
    setStatus('loading');
    setError(null);
    try {
      const detail = await formService.get(uuid);
      setForm(detail);
      dispatch({ type: 'loaded', name: detail.name, fields: detail.schema.fields, revision: detail.revision });
      setStatus('saved');
    } catch (err) {
      setError(errorMessage(err, 'The form could not be loaded.'));
      setStatus('error');
    }
  }, [uuid]);

  useEffect(() => {
    load();
  }, [load]);

  /** Saves the latest draft once any running save is done; true when nothing is left unsaved. */
  const save = useCallback(async (): Promise<boolean> => {
    if (inFlight.current) await inFlight.current;
    const draft = latest.current;
    if (!hasUnsavedChanges(draft)) return true;

    setStatus('saving');
    const run = formService
      .saveDraft(uuid, draft.revision, draft.name, { fields: draft.fields })
      .then((saved) => {
        dispatch({ type: 'saved', revision: saved.revision, changes: draft.changes });
        setForm((current) => (current ? { ...current, ...saved, schema: { fields: draft.fields } } : current));
        setError(null);
        setStatus('saved');
        return true;
      })
      .catch((err: unknown) => {
        setError(errorMessage(err, 'The form could not be saved.'));
        setStatus(err instanceof ApiError && err.status === 409 ? 'conflict' : 'error');
        return false;
      })
      .finally(() => {
        inFlight.current = null;
      });
    inFlight.current = run;
    return run;
  }, [uuid]);

  // Autosave a moment after the last edit (not after a conflict: the user reloads first).
  const unsaved = hasUnsavedChanges(state);
  useEffect(() => {
    if (!unsaved || status === 'conflict' || status === 'loading') return;
    const timer = window.setTimeout(save, AUTOSAVE_MS);
    return () => window.clearTimeout(timer);
  }, [state.changes, unsaved, status, save]);

  // Warn before leaving the page with edits not saved yet.
  useEffect(() => {
    if (!unsaved) return;
    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [unsaved]);

  const publish = useCallback(async (): Promise<string> => {
    if (!(await save())) throw new Error('Save the form before publishing it.');
    const res = await formService.publish(uuid);
    setForm((current) => (current ? { ...current, ...res.data, published_schema: { fields: latest.current.fields } } : current));
    return res.message;
  }, [save, uuid]);

  const restore = useCallback(
    async (version: number): Promise<string> => {
      if (!(await save())) throw new Error('Save the form before restoring a version.');
      const res = await formService.restore(uuid, version);
      dispatch({ type: 'loaded', name: res.data.name, fields: res.data.schema.fields, revision: res.data.revision });
      setForm((current) => (current ? { ...current, ...res.data } : current));
      return res.message;
    },
    [save, uuid],
  );

  return {
    state,
    form,
    status: status === 'saved' && unsaved ? 'unsaved' : status,
    error,
    dispatch,
    save,
    publish,
    restore,
    reload: load,
  };
};

export default useFormBuilder;

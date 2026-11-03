import { useEffect, useState } from 'react';
import formService from '../services/formService';
import type { LookupOption } from '../services/formService';
import type { LookupSource } from '../components/forms/fieldTypes';
import { useDebouncedValue } from './useDebouncedValue';

/** Results already fetched in this tab, by source and search, so a form with many lookups asks once. */
const cache = new Map<string, Promise<LookupOption[]>>();

const fetchOptions = (source: LookupSource, search: string): Promise<LookupOption[]> => {
  const key = `${source}|${search}`;
  let pending = cache.get(key);
  if (!pending) {
    pending = formService.lookup(source, search);
    // A failed lookup is asked again next time instead of being remembered.
    pending.catch(() => cache.delete(key));
    cache.set(key, pending);
  }
  return pending;
};

/** Records of the company matching what the user types in a lookup field (searched 300 ms after typing stops). */
export const useLookupOptions = (source: LookupSource, search: string, enabled = true) => {
  const term = useDebouncedValue(search.trim(), 300);
  const [result, setResult] = useState<{ key: string; options: LookupOption[] } | null>(null);
  const key = `${source}|${term}`;

  useEffect(() => {
    if (!enabled) return;
    let current = true;
    fetchOptions(source, term)
      .then((options) => current && setResult({ key, options }))
      .catch(() => current && setResult({ key, options: [] }));
    return () => {
      current = false;
    };
  }, [enabled, source, term, key]);

  return { options: result?.options ?? [], loading: enabled && result?.key !== key };
};

export default useLookupOptions;

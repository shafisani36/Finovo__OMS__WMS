import { useEffect, useState } from 'react';

/**
 * The value once it has stopped changing for `delay` ms. Search boxes use it to query the server as the
 * user types (live search) without sending a request for every key press.
 */
export const useDebouncedValue = <T,>(value: T, delay = 300): T => {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
};

export default useDebouncedValue;

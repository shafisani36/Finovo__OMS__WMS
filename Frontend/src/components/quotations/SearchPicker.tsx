import React, { useEffect, useRef, useState } from 'react';
import { Loader2, Search } from 'lucide-react';

interface SearchPickerProps<T> {
  placeholder: string;
  /** Loads the options for a search term (an empty term loads the first page). */
  search: (term: string) => Promise<T[]>;
  optionKey: (option: T) => string | number;
  renderOption: (option: T) => React.ReactNode;
  onPick: (option: T) => void;
  emptyText?: string;
}

/** A search box that shows matching records from the server and returns the one picked. */
export function SearchPicker<T>({ placeholder, search, optionKey, renderOption, onPick, emptyText = 'Nothing found.' }: SearchPickerProps<T>) {
  const [term, setTerm] = useState('');
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [options, setOptions] = useState<T[]>([]);
  const wrapper = useRef<HTMLDivElement>(null);
  const searchRef = useRef(search);
  searchRef.current = search;

  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    const timer = setTimeout(async () => {
      setLoading(true);
      try {
        const results = await searchRef.current(term.trim());
        if (!cancelled) setOptions(results);
      } catch (err) {
        console.error(err);
        if (!cancelled) setOptions([]);
      } finally {
        if (!cancelled) setLoading(false);
      }
    }, 250);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [term, open]);

  useEffect(() => {
    const close = (event: MouseEvent) => {
      if (wrapper.current && !wrapper.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  return (
    <div className="qt-picker" ref={wrapper}>
      <div style={{ position: 'relative' }}>
        <Search size={14} color="#94a3b8" style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)' }} />
        <input
          className="qt-input"
          style={{ paddingLeft: 32 }}
          placeholder={placeholder}
          value={term}
          onFocus={() => setOpen(true)}
          onChange={(e) => {
            setTerm(e.target.value);
            setOpen(true);
          }}
          onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
        />
      </div>
      {open && (
        <div className="qt-picker-menu" role="listbox">
          {loading && (
            <div className="qt-picker-empty">
              <Loader2 size={14} className="animate-spin" />
            </div>
          )}
          {!loading && options.length === 0 && <div className="qt-picker-empty">{emptyText}</div>}
          {!loading &&
            options.map((option) => (
              <button
                type="button"
                key={optionKey(option)}
                className="qt-picker-option"
                onClick={() => {
                  onPick(option);
                  setTerm('');
                  setOpen(false);
                }}
              >
                {renderOption(option)}
              </button>
            ))}
        </div>
      )}
    </div>
  );
}

export default SearchPicker;

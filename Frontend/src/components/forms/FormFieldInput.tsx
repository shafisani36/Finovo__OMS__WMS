import React, { useState } from 'react';
import { useLookupOptions } from '../../hooks/useLookupOptions';
import type { FieldValue, FormField, LookupSource } from './fieldTypes';
import { MAX_FILE_BYTES } from './fieldTypes';

interface FormFieldInputProps {
  field: FormField;
  value: FieldValue | undefined;
  onChange?: (value: FieldValue) => void;
  error?: string | null;
  /** In the builder canvas: shown as it will look, not fillable. */
  preview?: boolean;
}

const LookupInput: React.FC<{ id: string; source: LookupSource; value: FieldValue | undefined; onChange: (value: FieldValue) => void; preview: boolean }> = ({
  id,
  source,
  value,
  onChange,
  preview,
}) => {
  const [search, setSearch] = useState('');
  const { options, loading } = useLookupOptions(source, search, !preview);

  return (
    <div className="fb-lookup">
      <input className="cf-input" placeholder={`Search ${source}...`} value={search} onChange={(e) => setSearch(e.target.value)} disabled={preview} aria-label={`Search ${source}`} />
      <select id={id} className="cf-input" value={value === null || value === undefined ? '' : String(value)} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} disabled={preview}>
        <option value="">{loading ? 'Searching...' : options.length ? 'Choose...' : 'Nothing found'}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
};

/** One field of a builder form: its label, input, help text and error. */
export const FormFieldInput: React.FC<FormFieldInputProps> = ({ field, value, onChange = () => undefined, error, preview = false }) => {
  const inputId = `field-${field.id}`;
  const text = typeof value === 'string' || typeof value === 'number' ? String(value) : '';
  const common = { id: inputId, className: 'cf-input', disabled: preview, placeholder: field.placeholder ?? undefined, 'aria-invalid': Boolean(error) };

  const input = (() => {
    switch (field.type) {
      case 'textarea':
        return <textarea {...common} rows={3} value={text} onChange={(e) => onChange(e.target.value)} maxLength={field.max_length ?? 5000} />;
      case 'number':
        return <input {...common} type="number" value={text} min={field.min ?? undefined} max={field.max ?? undefined} step="any" onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} />;
      case 'date':
        return <input {...common} type="date" value={text} onChange={(e) => onChange(e.target.value || null)} />;
      case 'dropdown':
        return (
          <select {...common} value={text} onChange={(e) => onChange(e.target.value || null)}>
            <option value="">Choose...</option>
            {(field.options ?? []).map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        );
      case 'checkbox':
        return (
          <label className="fb-checkbox">
            <input id={inputId} type="checkbox" checked={value === true} disabled={preview} onChange={(e) => onChange(e.target.checked)} />
            <span>{field.placeholder || 'Yes'}</span>
          </label>
        );
      case 'lookup':
        return <LookupInput id={inputId} source={field.source ?? 'products'} value={value} onChange={onChange} preview={preview} />;
      case 'file':
        return (
          <input
            id={inputId}
            className="cf-input"
            type="file"
            disabled={preview}
            accept="image/*,.pdf,.csv,.xlsx,.docx,.txt"
            onChange={(e) => onChange(e.target.files?.[0] ?? null)}
            aria-describedby={`${inputId}-limit`}
          />
        );
      default:
        return <input {...common} type="text" value={text} onChange={(e) => onChange(e.target.value)} maxLength={field.max_length ?? 255} />;
    }
  })();

  return (
    <div className={`cf-field fb-field fb-width-${field.width ?? 'full'}`}>
      <label className="cf-label" htmlFor={inputId}>
        {field.label}
        {field.required && <span className="fb-required"> *</span>}
      </label>
      {input}
      {field.type === 'file' && (
        <span id={`${inputId}-limit`} className="fb-help">
          Up to {MAX_FILE_BYTES / 1024 / 1024} MB.
        </span>
      )}
      {field.help && <span className="fb-help">{field.help}</span>}
      {error && <span className="fb-error">{error}</span>}
    </div>
  );
};

export default FormFieldInput;

import React from 'react';
import { Plus, X } from 'lucide-react';
import { FIELD_TYPES, LOOKUP_SOURCES, conditionSources } from './fieldTypes';
import type { FieldOption, FormField, LookupSource } from './fieldTypes';
import type { BuilderAction } from './builderReducer';

interface FieldInspectorProps {
  field: FormField | null;
  /** Every field of the form, for the "show only when" choice. */
  fields: FormField[];
  dispatch: (action: BuilderAction) => void;
}

/** Option value made from its label, e.g. "Out of stock" → "out_of_stock" (kept unique in the list). */
const optionValue = (label: string, options: FieldOption[], index: number): string => {
  const base = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 60) || `option_${index + 1}`;
  const taken = new Set(options.filter((_, i) => i !== index).map((option) => option.value));
  let value = base;
  for (let n = 2; taken.has(value); n++) value = `${base}_${n}`;
  return value;
};

const numberOrNull = (value: string): number | null => (value.trim() === '' ? null : Number(value));

/** Settings of the selected field; the settings offered depend on its type. */
export const FieldInspector: React.FC<FieldInspectorProps> = ({ field, fields, dispatch }) => {
  if (!field) {
    return (
      <aside className="fb-inspector">
        <h4 className="fb-panel-title">Field Settings</h4>
        <p className="fb-help">Select a field on the form to change it.</p>
      </aside>
    );
  }

  const type = FIELD_TYPES[field.type];
  const update = (patch: Partial<Omit<FormField, 'id' | 'type'>>) => dispatch({ type: 'updated', id: field.id, patch });
  const options = field.options ?? [];
  const setOptions = (next: FieldOption[]) => update({ options: next });
  const sources = conditionSources(fields, field.id);
  const source = sources.find((f) => f.id === field.show_if?.field) ?? null;
  const answersOf = (f: FormField): FieldOption[] | null =>
    f.type === 'dropdown' ? (f.options ?? []) : f.type === 'checkbox' ? [{ value: '1', label: 'Ticked' }, { value: '0', label: 'Not ticked' }] : null;
  const chooseSource = (id: string) => {
    const chosen = sources.find((f) => f.id === id);
    update({ show_if: chosen ? { field: chosen.id, equals: answersOf(chosen)?.[0]?.value ?? '' } : null });
  };

  if (!type.answered) {
    return (
      <aside className="fb-inspector" aria-label="Field settings">
        <h4 className="fb-panel-title">Page Break</h4>
        <label className="cf-field">
          <span className="cf-label">Step title</span>
          <input className="cf-input" value={field.label} maxLength={120} onChange={(e) => update({ label: e.target.value })} />
        </label>
        <p className="fb-help">Fields below this break are filled in on a new step, with Next and Back buttons.</p>
      </aside>
    );
  }

  return (
    <aside className="fb-inspector" aria-label="Field settings">
      <h4 className="fb-panel-title">{type.label} Field</h4>
      <label className="cf-field">
        <span className="cf-label">Label</span>
        <input className="cf-input" value={field.label} maxLength={120} onChange={(e) => update({ label: e.target.value })} />
      </label>
      <div className="cf-field">
        <span className="cf-label">Field ID</span>
        <code className="fb-code">{field.id}</code>
      </div>
      <label className="fb-checkbox">
        <input type="checkbox" checked={Boolean(field.required)} onChange={(e) => update({ required: e.target.checked })} />
        <span>Required</span>
      </label>
      <label className="cf-field">
        <span className="cf-label">Width</span>
        <select className="cf-input" value={field.width ?? 'full'} onChange={(e) => update({ width: e.target.value as 'full' | 'half' })}>
          <option value="full">Full row</option>
          <option value="half">Half row</option>
        </select>
      </label>

      {type.settings.includes('placeholder') && (
        <label className="cf-field">
          <span className="cf-label">Placeholder</span>
          <input className="cf-input" value={field.placeholder ?? ''} maxLength={120} onChange={(e) => update({ placeholder: e.target.value || null })} />
        </label>
      )}
      {type.settings.includes('max_length') && (
        <label className="cf-field">
          <span className="cf-label">Most characters</span>
          <input className="cf-input" type="number" min={1} max={5000} value={field.max_length ?? ''} onChange={(e) => update({ max_length: numberOrNull(e.target.value) })} />
        </label>
      )}
      {type.settings.includes('range') && (
        <div className="fb-inline">
          <label className="cf-field">
            <span className="cf-label">Smallest</span>
            <input className="cf-input" type="number" value={field.min ?? ''} onChange={(e) => update({ min: numberOrNull(e.target.value) })} />
          </label>
          <label className="cf-field">
            <span className="cf-label">Largest</span>
            <input className="cf-input" type="number" value={field.max ?? ''} onChange={(e) => update({ max: numberOrNull(e.target.value) })} />
          </label>
        </div>
      )}
      {type.settings.includes('source') && (
        <label className="cf-field">
          <span className="cf-label">Look up</span>
          <select className="cf-input" value={field.source ?? 'products'} onChange={(e) => update({ source: e.target.value as LookupSource })}>
            {LOOKUP_SOURCES.map((source) => (
              <option key={source.value} value={source.value}>
                {source.label}
              </option>
            ))}
          </select>
        </label>
      )}
      {type.settings.includes('options') && (
        <div className="cf-field">
          <span className="cf-label">Options</span>
          {options.map((option, index) => (
            <div key={index} className="fb-option-row">
              <input
                className="cf-input"
                value={option.label}
                maxLength={120}
                aria-label={`Option ${index + 1}`}
                onChange={(e) => setOptions(options.map((o, i) => (i === index ? { label: e.target.value, value: optionValue(e.target.value, options, index) } : o)))}
              />
              <button type="button" className="topbar-icon-button" aria-label={`Remove option ${index + 1}`} disabled={options.length === 1} onClick={() => setOptions(options.filter((_, i) => i !== index))}>
                <X size={14} />
              </button>
            </div>
          ))}
          <button
            type="button"
            className="btn-finovo-secondary"
            onClick={() => {
              const label = `Option ${options.length + 1}`;
              setOptions([...options, { label, value: optionValue(label, options, options.length) }]);
            }}
          >
            <Plus size={14} />
            <span>Add option</span>
          </button>
        </div>
      )}

      <label className="cf-field">
        <span className="cf-label">Help text</span>
        <input className="cf-input" value={field.help ?? ''} maxLength={300} onChange={(e) => update({ help: e.target.value || null })} />
      </label>

      <label className="cf-field">
        <span className="cf-label">Show only when</span>
        <select className="cf-input" value={source?.id ?? ''} onChange={(e) => chooseSource(e.target.value)} disabled={sources.length === 0}>
          <option value="">Always shown</option>
          {sources.map((f) => (
            <option key={f.id} value={f.id}>
              {f.label}
            </option>
          ))}
        </select>
      </label>
      {source && field.show_if && (
        <label className="cf-field">
          <span className="cf-label">Has the answer</span>
          {answersOf(source) ? (
            <select className="cf-input" value={field.show_if.equals} onChange={(e) => update({ show_if: { field: source.id, equals: e.target.value } })}>
              {answersOf(source)?.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          ) : (
            <input className="cf-input" value={field.show_if.equals} maxLength={120} onChange={(e) => update({ show_if: { field: source.id, equals: e.target.value } })} />
          )}
        </label>
      )}
    </aside>
  );
};

export default FieldInspector;

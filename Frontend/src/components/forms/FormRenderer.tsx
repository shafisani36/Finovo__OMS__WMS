import React, { useState } from 'react';
import { Loader2, Send } from 'lucide-react';
import FormFieldInput from './FormFieldInput';
import { emptyValues, toFormData, validateValues } from './fieldTypes';
import type { FieldValue, FormSchema } from './fieldTypes';
import formService from '../../services/formService';
import { ApiError } from '../../services/apiClient';
import { Alert } from '../common/Alert';
import { errorMessage } from '../../utils/errors';

interface FormRendererProps {
  uuid: string;
  schema: FormSchema;
  /** Preview: checks the answers here but sends nothing. */
  preview?: boolean;
  onSubmitted?: (message: string) => void;
}

/**
 * Fills in a published form. Answers are checked by each field's type first, then sent as multipart (with any
 * files) and checked again by the server; the server's messages are shown under the fields they belong to.
 */
export const FormRenderer: React.FC<FormRendererProps> = ({ uuid, schema, preview = false, onSubmitted }) => {
  const [values, setValues] = useState<Record<string, FieldValue>>(() => emptyValues(schema));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [sending, setSending] = useState(false);
  const [formKey, setFormKey] = useState(0);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setMessage(null);
    const found = validateValues(schema, values);
    setErrors(found);
    if (Object.keys(found).length > 0) return;
    if (preview) {
      setMessage({ type: 'success', text: 'All answers are valid. Nothing is saved in preview.' });
      return;
    }

    setSending(true);
    try {
      const res = await formService.submit(uuid, toFormData(schema, values));
      setValues(emptyValues(schema));
      setFormKey((key) => key + 1);
      setMessage({ type: 'success', text: res.message ?? 'Saved.' });
      onSubmitted?.(res.message ?? 'Saved.');
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key.replace(/^data\./, ''), messages[0]])));
      }
      setMessage({ type: 'error', text: errorMessage(err, 'Your answers could not be saved.') });
    } finally {
      setSending(false);
    }
  };

  return (
    <form key={formKey} className="fb-render" onSubmit={submit} noValidate>
      {message && <Alert type={message.type} message={message.text} />}
      <div className="fb-render-grid">
        {schema.fields.map((field) => (
          <FormFieldInput key={field.id} field={field} value={values[field.id]} error={errors[field.id]} onChange={(value) => setValues((current) => ({ ...current, [field.id]: value }))} />
        ))}
      </div>
      <button type="submit" className="btn-finovo-primary" disabled={sending || schema.fields.length === 0}>
        {sending ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
        <span>{preview ? 'Check Answers' : 'Submit'}</span>
      </button>
    </form>
  );
};

export default FormRenderer;

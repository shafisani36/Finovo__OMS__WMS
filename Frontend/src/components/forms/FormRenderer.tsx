import React, { useState } from 'react';
import { ArrowLeft, ArrowRight, Loader2, Send } from 'lucide-react';
import FormFieldInput from './FormFieldInput';
import { emptyValues, formSteps, isShown, toFormData, validateValues } from './fieldTypes';
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
 * Fills in a published form, one step at a time when it has page breaks. Fields hidden by their condition are not
 * shown, checked or sent. Answers are checked by each field's type first (a step before moving on), then sent as
 * multipart (with any files) and checked again by the server; its messages are shown under their fields.
 */
export const FormRenderer: React.FC<FormRendererProps> = ({ uuid, schema, preview = false, onSubmitted }) => {
  const [values, setValues] = useState<Record<string, FieldValue>>(() => emptyValues(schema));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [sending, setSending] = useState(false);
  const [formKey, setFormKey] = useState(0);
  const [stepIndex, setStepIndex] = useState(0);
  const steps = formSteps(schema);
  const step = steps[Math.min(stepIndex, steps.length - 1)];
  const isLast = stepIndex >= steps.length - 1;

  /** Errors for the fields of these steps only. */
  const errorsIn = (found: Record<string, string>, upTo: number) => {
    const ids = new Set(steps.slice(0, upTo + 1).flatMap((s) => s.fields.map((field) => field.id)));
    return Object.fromEntries(Object.entries(found).filter(([id]) => ids.has(id)));
  };
  const firstStepWithError = (found: Record<string, string>) => Math.max(0, steps.findIndex((s) => s.fields.some((field) => field.id in found)));

  const next = () => {
    const found = errorsIn(validateValues(schema, values), stepIndex);
    setErrors(found);
    if (Object.keys(found).length === 0) setStepIndex((index) => index + 1);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isLast) {
      next();
      return;
    }
    setMessage(null);
    const found = validateValues(schema, values);
    setErrors(found);
    if (Object.keys(found).length > 0) {
      setStepIndex(firstStepWithError(found));
      return;
    }
    if (preview) {
      setMessage({ type: 'success', text: 'All answers are valid. Nothing is saved in preview.' });
      return;
    }

    setSending(true);
    try {
      const res = await formService.submit(uuid, toFormData(schema, values));
      setValues(emptyValues(schema));
      setFormKey((key) => key + 1);
      setStepIndex(0);
      setMessage({ type: 'success', text: res.message ?? 'Saved.' });
      onSubmitted?.(res.message ?? 'Saved.');
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const serverErrors = Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key.replace(/^data\./, ''), messages[0]]));
        setErrors(serverErrors);
        setStepIndex(firstStepWithError(serverErrors));
      }
      setMessage({ type: 'error', text: errorMessage(err, 'Your answers could not be saved.') });
    } finally {
      setSending(false);
    }
  };

  return (
    <form key={formKey} className="fb-render" onSubmit={submit} noValidate>
      {message && <Alert type={message.type} message={message.text} />}
      {steps.length > 1 && (
        <div className="fb-step-header">
          <span className="fb-help">
            Step {stepIndex + 1} of {steps.length}
          </span>
          {step.title && <h4 className="fb-step-title">{step.title}</h4>}
        </div>
      )}
      <div className="fb-render-grid">
        {step.fields
          .filter((field) => isShown(field, values))
          .map((field) => (
            <FormFieldInput key={field.id} field={field} value={values[field.id]} error={errors[field.id]} onChange={(value) => setValues((current) => ({ ...current, [field.id]: value }))} />
          ))}
      </div>
      <div className="fb-step-actions">
        {stepIndex > 0 && (
          <button type="button" className="btn-finovo-secondary" onClick={() => setStepIndex((index) => index - 1)}>
            <ArrowLeft size={14} />
            <span>Back</span>
          </button>
        )}
        {isLast ? (
          <button type="submit" className="btn-finovo-primary" disabled={sending || schema.fields.length === 0}>
            {sending ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
            <span>{preview ? 'Check Answers' : 'Submit'}</span>
          </button>
        ) : (
          <button type="submit" className="btn-finovo-primary">
            <span>Next</span>
            <ArrowRight size={14} />
          </button>
        )}
      </div>
    </form>
  );
};

export default FormRenderer;

import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, CloudOff, History, Loader2, RefreshCw, Rocket, RotateCcw } from 'lucide-react';
import { Alert } from '../components/common/Alert';
import StatusPill from '../components/common/StatusPill';
import FieldPalette from '../components/forms/FieldPalette';
import BuilderCanvas from '../components/forms/BuilderCanvas';
import FieldInspector from '../components/forms/FieldInspector';
import FormRenderer from '../components/forms/FormRenderer';
import type { FormField, FormSchema } from '../components/forms/fieldTypes';
import { useFormBuilder } from '../hooks/useFormBuilder';
import type { SyncStatus } from '../hooks/useFormBuilder';
import formService from '../services/formService';
import type { FormSubmissionModel, FormVersionSummary } from '../services/formService';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { formatDate } from '../utils/format';
import { errorMessage } from '../utils/errors';
import '../components/forms/FormBuilder.css';

type Tab = 'design' | 'preview' | 'fill' | 'answers' | 'versions';

const SYNC_LABELS: Record<SyncStatus, string> = {
  loading: 'Loading...',
  saved: 'All changes saved',
  unsaved: 'Unsaved changes',
  saving: 'Saving...',
  error: 'Not saved',
  conflict: 'Changed by someone else',
};

/** An answer as text for the answers table. */
const answerText = (field: FormField, value: unknown): string => {
  if (value === null || value === undefined || value === '') return '—';
  if (field.type === 'checkbox') return value ? 'Yes' : 'No';
  if (field.type === 'dropdown') return field.options?.find((option) => option.value === value)?.label ?? String(value);
  if (field.type === 'lookup') return `#${value}`;
  if (field.type === 'file' && typeof value === 'object') return (value as { name?: string }).name ?? 'File';
  return String(value);
};

const AnswersTable: React.FC<{ uuid: string; schema: FormSchema }> = ({ uuid, schema }) => {
  const [rows, setRows] = useState<FormSubmissionModel[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    formService
      .submissions(uuid)
      .then((res) => setRows(res.data))
      .catch((err) => setError(errorMessage(err, 'Could not load the answers.')));
  }, [uuid]);

  if (error) return <Alert type="error" message={error} />;
  if (!rows) return <div className="fb-help">Loading answers...</div>;
  if (rows.length === 0) return <div className="fb-help">No answers yet.</div>;

  return (
    <div className="table-responsive-wrapper">
      <table className="finovo-table">
        <thead>
          <tr>
            <th>Sent</th>
            <th>By</th>
            {schema.fields.map((field) => (
              <th key={field.id}>{field.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id}>
              <td>
                {formatDate(row.created_at, true)} <span className="fb-help">v{row.version}</span>
              </td>
              <td>{row.user?.name ?? '—'}</td>
              {schema.fields.map((field) => (
                <td key={field.id}>{answerText(field, row.data[field.id])}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

/**
 * One builder form. Designers drag fields onto the canvas (saved automatically), preview, publish and restore
 * versions; everyone with access fills in the published version and sees the answers.
 */
export const FormBuilderPage: React.FC = () => {
  const { uuid = '' } = useParams<{ uuid: string }>();
  const { user } = useAuth();
  const designs = can(user, 'forms.manage');
  const { state, form, status, error, dispatch, publish, restore, reload } = useFormBuilder(uuid);
  const [tab, setTab] = useState<Tab>(designs ? 'design' : 'fill');
  const [banner, setBanner] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [versions, setVersions] = useState<FormVersionSummary[] | null>(null);

  const loadVersions = useCallback(() => {
    formService.versions(uuid).then(setVersions).catch((err) => setActionError(errorMessage(err, 'Could not load the versions.')));
  }, [uuid]);

  useEffect(() => {
    if (tab === 'versions') loadVersions();
  }, [tab, loadVersions]);

  const run = async (action: () => Promise<string>) => {
    setBusy(true);
    setActionError(null);
    try {
      setBanner(await action());
    } catch (err) {
      setActionError(errorMessage(err, 'That did not work.'));
    } finally {
      setBusy(false);
    }
  };

  const selected = state.fields.find((field) => field.id === state.selectedId) ?? null;
  const published = form?.published_schema ?? null;
  const tabs: { key: Tab; label: string }[] = [
    ...(designs ? [{ key: 'design' as const, label: 'Design' }, { key: 'preview' as const, label: 'Preview' }] : []),
    { key: 'fill', label: 'Fill In' },
    { key: 'answers', label: 'Answers' },
    ...(designs ? [{ key: 'versions' as const, label: 'Versions' }] : []),
  ];

  if (status === 'loading' && !form) {
    return (
      <div className="page-container">
        <Loader2 className="animate-spin" />
      </div>
    );
  }
  if (!form) {
    return (
      <div className="page-container">
        <Alert type="error" message={error ?? 'This form could not be found.'} />
      </div>
    );
  }

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <Link to="/forms" className="pd-link">
          <ArrowLeft size={13} style={{ verticalAlign: -2 }} /> Forms
        </Link>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>{state.name || form.name}</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section" style={{ flex: 1, minWidth: 0 }}>
          {designs ? (
            <input className="fb-title-input" value={state.name} maxLength={120} onChange={(e) => dispatch({ type: 'renamed', name: e.target.value })} aria-label="Form name" />
          ) : (
            <h1 className="page-title">{form.name}</h1>
          )}
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 4 }}>
            <StatusPill status={form.status === 'published' ? 'active' : form.status === 'changed' ? 'pending' : 'draft'} label={form.status === 'changed' ? 'Unpublished changes' : form.status} />
            {form.published_version && <span className="fb-help">Live: version {form.published_version}</span>}
            {designs && (
              <span className={`fb-sync fb-sync-${status}`}>
                {status === 'saved' ? <CheckCircle2 size={13} /> : status === 'error' || status === 'conflict' ? <CloudOff size={13} /> : <Loader2 size={13} className={status === 'saving' ? 'animate-spin' : ''} />}
                {SYNC_LABELS[status]}
              </span>
            )}
          </div>
        </div>
        {designs && (
          <button type="button" className="btn-finovo-primary" disabled={busy || status === 'conflict' || state.fields.length === 0 || !state.name.trim()} onClick={() => run(publish)}>
            {busy ? <Loader2 size={16} className="animate-spin" /> : <Rocket size={16} />}
            <span>Publish</span>
          </button>
        )}
      </div>

      {status === 'conflict' && (
        <div className="page-banner fb-conflict">
          <Alert type="error" message={error ?? 'This form was changed by someone else.'} />
          <button type="button" className="btn-finovo-secondary" onClick={reload}>
            <RefreshCw size={14} />
            <span>Reload latest</span>
          </button>
        </div>
      )}
      {status === 'error' && error && <Alert type="error" message={error} className="page-banner" />}
      {actionError && <Alert type="error" message={actionError} className="page-banner" />}
      {banner && <Alert type="success" message={banner} className="page-banner" />}

      <div className="fb-tabs" role="tablist">
        {tabs.map((t) => (
          <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} className={tab === t.key ? 'is-active' : ''} onClick={() => setTab(t.key)}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'design' && (
        <div className="fb-designer">
          <FieldPalette onAdd={(fieldType) => dispatch({ type: 'added', fieldType })} />
          <BuilderCanvas fields={state.fields} selectedId={state.selectedId} dispatch={dispatch} />
          <FieldInspector field={selected} dispatch={dispatch} />
        </div>
      )}

      {tab === 'preview' && (
        <div className="content-card fb-panel">
          <FormRenderer key={state.changes} uuid={uuid} schema={{ fields: state.fields }} preview />
        </div>
      )}

      {tab === 'fill' && (
        <div className="content-card fb-panel">
          {published ? <FormRenderer uuid={uuid} schema={published} /> : <div className="fb-help">This form is not published yet.</div>}
        </div>
      )}

      {tab === 'answers' && <div className="content-card fb-panel">{published ? <AnswersTable uuid={uuid} schema={published} /> : <div className="fb-help">No answers before the form is published.</div>}</div>}

      {tab === 'versions' && (
        <div className="content-card fb-panel">
          {!versions && <div className="fb-help">Loading versions...</div>}
          {versions?.length === 0 && <div className="fb-help">Not published yet.</div>}
          {versions?.map((version) => (
            <div key={version.version} className="fb-version-row">
              <History size={16} />
              <div style={{ flex: 1 }}>
                <strong>Version {version.version}</strong>
                {version.version === form.published_version && <StatusPill status="active" label="Live" />}
                <div className="fb-help">
                  {version.fields} field(s) · {formatDate(version.published_at, true)}
                  {version.published_by ? ` · ${version.published_by}` : ''}
                </div>
              </div>
              <button type="button" className="btn-finovo-secondary" disabled={busy} onClick={() => run(async () => { const message = await restore(version.version); setTab('design'); return message; })}>
                <RotateCcw size={14} />
                <span>Restore to draft</span>
              </button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default FormBuilderPage;

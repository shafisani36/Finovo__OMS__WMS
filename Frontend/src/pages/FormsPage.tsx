import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LayoutTemplate, Plus, Trash2 } from 'lucide-react';
import { Alert } from '../components/common/Alert';
import StatusPill from '../components/common/StatusPill';
import { useConfirmDialog } from '../components/common/ConfirmDialog';
import formService from '../services/formService';
import type { FormModel } from '../services/formService';
import { useAuth } from '../hooks/useAuth';
import { can } from '../types/auth';
import { formatDate } from '../utils/format';
import { errorMessage } from '../utils/errors';

type FormListItem = Omit<FormModel, 'schema'>;

const STATUS_PILLS: Record<FormModel['status'], { status: string; label: string }> = {
  draft: { status: 'draft', label: 'Draft' },
  published: { status: 'active', label: 'Published' },
  changed: { status: 'pending', label: 'Unpublished changes' },
};

/** Forms made with the drag-and-drop builder: designers create and open them, others fill in published ones. */
export const FormsPage: React.FC = () => {
  const { user } = useAuth();
  const designs = can(user, 'forms.manage');
  const navigate = useNavigate();
  const { confirm, dialog } = useConfirmDialog();
  const [forms, setForms] = useState<FormListItem[] | null>(null);
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);

  const load = () =>
    formService
      .list()
      .then(setForms)
      .catch((err) => setError(errorMessage(err, 'Could not load the forms.')));

  useEffect(() => {
    load();
  }, []);

  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const form = await formService.create(name.trim());
      navigate(`/forms/${form.uuid}`);
    } catch (err) {
      setError(errorMessage(err, 'The form could not be created.'));
    }
  };

  const remove = async (form: FormListItem) => {
    const ok = await confirm({ title: `Delete ${form.name}?`, message: 'The form, its versions and all its answers are deleted.', confirmLabel: 'Delete Form', tone: 'danger' });
    if (!ok) return;
    try {
      setBanner((await formService.remove(form.uuid)).message);
      load();
    } catch (err) {
      setError(errorMessage(err, 'The form could not be deleted.'));
    }
  };

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Forms</span>
      </nav>
      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">Forms</h1>
          <p className="page-subtitle">{designs ? 'Build forms by dragging fields, publish them, and collect answers.' : 'Forms your team uses.'}</p>
        </div>
        {designs && (
          <form onSubmit={create} style={{ display: 'flex', gap: 8 }}>
            <input className="cf-input" placeholder="New form name" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} aria-label="New form name" style={{ width: 220 }} />
            <button type="submit" className="btn-finovo-primary" disabled={!name.trim()}>
              <Plus size={16} />
              <span>Create Form</span>
            </button>
          </form>
        )}
      </div>

      {error && <Alert type="error" message={error} className="page-banner" />}
      {banner && <Alert type="success" message={banner} className="page-banner" />}

      <div className="content-card">
        <div className="table-responsive-wrapper">
          <table className="finovo-table">
            <thead>
              <tr>
                <th>Form</th>
                <th>Status</th>
                <th style={{ textAlign: 'right' }}>Answers</th>
                <th>Updated</th>
                {designs && <th />}
              </tr>
            </thead>
            <tbody>
              {forms?.length === 0 && (
                <tr>
                  <td colSpan={designs ? 5 : 4} style={{ textAlign: 'center', padding: 24, color: '#64748b' }}>
                    {designs ? 'No forms yet. Name one above to start building.' : 'No forms are published yet.'}
                  </td>
                </tr>
              )}
              {forms?.map((form) => (
                <tr key={form.uuid} onClick={() => navigate(`/forms/${form.uuid}`)} style={{ cursor: 'pointer' }}>
                  <td style={{ fontWeight: 700 }}>
                    <LayoutTemplate size={14} style={{ verticalAlign: -2, marginRight: 6, color: '#6366f1' }} />
                    {form.name}
                  </td>
                  <td>
                    <StatusPill {...STATUS_PILLS[form.status]} />
                    {form.published_version && <span className="fb-help"> v{form.published_version}</span>}
                  </td>
                  <td style={{ textAlign: 'right' }}>{form.submissions_count ?? 0}</td>
                  <td>{formatDate(form.updated_at, true)}</td>
                  {designs && (
                    <td style={{ textAlign: 'right' }}>
                      <button
                        type="button"
                        className="topbar-icon-button"
                        aria-label={`Delete ${form.name}`}
                        onClick={(e) => {
                          e.stopPropagation();
                          remove(form);
                        }}
                      >
                        <Trash2 size={15} />
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
      {dialog}
    </div>
  );
};

export default FormsPage;

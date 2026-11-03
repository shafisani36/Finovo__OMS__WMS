import apiClient, { request } from './apiClient';
import type { FormSchema, LookupSource } from '../components/forms/fieldTypes';

/** draft: never published; published: live as designed; changed: the draft has edits not published yet. */
export type FormStatus = 'draft' | 'published' | 'changed';

export interface FormModel {
  uuid: string;
  name: string;
  /** The draft for designers, the published layout for everyone else. */
  schema: FormSchema;
  /** Raised on every draft save; a save from an older revision is refused. */
  revision: number;
  published_version: number | null;
  status: FormStatus;
  submissions_count?: number;
  updated_at: string;
}

export interface FormDetail extends FormModel {
  published_schema: FormSchema | null;
}

export interface FormVersionSummary {
  version: number;
  fields: number;
  published_by: string | null;
  published_at: string;
}

export interface FormSubmissionModel {
  id: number;
  version: number;
  data: Record<string, unknown>;
  user: { id: number; name: string } | null;
  created_at: string;
}

export interface LookupOption {
  value: number;
  label: string;
}

/** Calls of the drag-and-drop form builder (see FormController). */
const formService = {
  async list(): Promise<Omit<FormModel, 'schema'>[]> {
    const { data } = await apiClient.get('/forms');
    return data.data;
  },

  async get(uuid: string): Promise<FormDetail> {
    const { data } = await apiClient.get(`/forms/${uuid}`);
    return data.data;
  },

  async create(name: string): Promise<FormModel> {
    const { data } = await apiClient.post('/forms', { name });
    return data.data;
  },

  /** Saves the draft; fails with status 409 when someone saved a newer revision first. */
  async saveDraft(uuid: string, revision: number, name: string, schema: FormSchema): Promise<FormModel> {
    const { data } = await apiClient.put(`/forms/${uuid}`, { revision, name, schema });
    return data.data;
  },

  async remove(uuid: string): Promise<{ message: string }> {
    const { data } = await apiClient.delete(`/forms/${uuid}`);
    return data;
  },

  async publish(uuid: string): Promise<{ message: string; data: FormModel }> {
    const { data } = await apiClient.post(`/forms/${uuid}/publish`);
    return data;
  },

  async versions(uuid: string): Promise<FormVersionSummary[]> {
    const { data } = await apiClient.get(`/forms/${uuid}/versions`);
    return data.data;
  },

  async restore(uuid: string, version: number): Promise<{ message: string; data: FormModel }> {
    const { data } = await apiClient.post(`/forms/${uuid}/versions/${version}/restore`);
    return data;
  },

  /** Sent as multipart so file fields travel with the answers. */
  async submit(uuid: string, body: FormData): Promise<{ message: string }> {
    return request(`/forms/${uuid}/submissions`, { method: 'POST', body });
  },

  async submissions(uuid: string, page = 1): Promise<{ data: FormSubmissionModel[]; meta: { current_page: number; last_page: number; total: number } }> {
    const { data } = await apiClient.get(`/forms/${uuid}/submissions`, { params: { page } });
    return data;
  },

  async lookup(source: LookupSource, search: string): Promise<LookupOption[]> {
    const { data } = await apiClient.get(`/forms/lookups/${source}`, { params: { search } });
    return data.data;
  },
};

export default formService;

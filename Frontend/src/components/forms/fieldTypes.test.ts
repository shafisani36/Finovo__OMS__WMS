import { describe, expect, it } from 'vitest';
import { FIELD_ID_PATTERN, FIELD_TYPES, MAX_FILE_BYTES, createFieldId, emptyValues, fieldType, toFormData, validateValues } from './fieldTypes';
import type { FormField, FormSchema } from './fieldTypes';

const schema: FormSchema = {
  fields: [
    { id: 'f_name', type: 'text', label: 'Name', required: true, max_length: 5 },
    { id: 'f_qty', type: 'number', label: 'Units', min: 1, max: 10 },
    { id: 'f_kind', type: 'dropdown', label: 'Kind', options: [{ value: 'damaged', label: 'Damaged' }] },
    { id: 'f_ok', type: 'checkbox', label: 'Seal checked', required: true },
    { id: 'f_photo', type: 'file', label: 'Photo' },
  ],
};

describe('field types', () => {
  it('creates fields with the type defaults and a valid unique ID', () => {
    const field = fieldType('dropdown').create('f_abc');
    expect(field).toMatchObject({ id: 'f_abc', type: 'dropdown', required: false, width: 'full' });
    expect(field.options).toHaveLength(2);
    expect(fieldType('lookup').create('f_x').source).toBe('products');

    const id = createFieldId([]);
    expect(id).toMatch(FIELD_ID_PATTERN);
    const taken = ['f_00000000'];
    let calls = 0;
    expect(createFieldId(taken, () => (calls++ === 0 ? '00000000' : '00000001'))).toBe('f_00000001');
  });

  it('inherits shared rules and adds its own checks', () => {
    expect(Object.keys(FIELD_TYPES)).toHaveLength(9);
    // Paragraph and barcode are text fields with other defaults.
    expect(fieldType('textarea').validate({ id: 'f_a', type: 'textarea', label: 'Notes' }, 'x'.repeat(300))).toBeNull();
    expect(fieldType('barcode').validate({ id: 'f_b', type: 'barcode', label: 'Code' }, 'x'.repeat(300))).toBe('Code can be at most 255 characters.');

    const values = { f_name: 'Too long', f_qty: 11, f_kind: 'missing', f_ok: false, f_photo: new File([new Uint8Array(MAX_FILE_BYTES + 1)], 'big.jpg') };
    expect(validateValues(schema, values)).toEqual({
      f_name: 'Name can be at most 5 characters.',
      f_qty: 'Units can be at most 10.',
      f_kind: 'Choose one of the Kind options.',
      f_ok: 'Seal checked is required.',
      f_photo: 'Photo must be smaller than 5 MB.',
    });
    expect(validateValues(schema, { ...emptyValues(schema), f_name: 'Sara', f_ok: true })).toEqual({});
  });

  it('builds the multipart body from the answers', () => {
    const photo = new File(['x'], 'box.jpg');
    const body = toFormData(schema, { f_name: 'Sara', f_qty: null, f_kind: 'damaged', f_ok: true, f_photo: photo });
    expect(body.get('data[f_name]')).toBe('Sara');
    expect(body.has('data[f_qty]')).toBe(false);
    expect(body.get('data[f_ok]')).toBe('1');
    expect((body.get('data[f_photo]') as File).name).toBe('box.jpg');
  });

  it('treats an unticked checkbox as empty and sends it as 0', () => {
    const field: FormField = { id: 'f_c', type: 'checkbox', label: 'Agree' };
    expect(fieldType('checkbox').validate(field, false)).toBeNull();
    expect(fieldType('checkbox').toPayload(false)).toBe('0');
  });
});

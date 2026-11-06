/**
 * Field types of the drag-and-drop form builder. Each type is a class: the base class holds what every field
 * shares (creating a field, the required check, turning a value into the request body) and each subclass adds
 * its own defaults, settings and checks. The layout contract matches the backend's FormSchema.
 */

export type FieldTypeKey = 'text' | 'textarea' | 'number' | 'date' | 'dropdown' | 'checkbox' | 'lookup' | 'barcode' | 'file' | 'page';

export type LookupSource = 'products' | 'customers' | 'warehouses' | 'stores';

export const LOOKUP_SOURCES: { value: LookupSource; label: string }[] = [
  { value: 'products', label: 'Products' },
  { value: 'customers', label: 'Customers' },
  { value: 'warehouses', label: 'Warehouses' },
  { value: 'stores', label: 'Stores' },
];

export interface FieldOption {
  value: string;
  label: string;
}

/** Show a field only when an earlier field has this answer (a checkbox answers "1" when ticked, "0" when not). */
export interface FieldCondition {
  field: string;
  equals: string;
}

export interface FormField {
  /** Unique within the form; the key of the field's answer. */
  id: string;
  type: FieldTypeKey;
  label: string;
  required?: boolean;
  width?: 'full' | 'half';
  placeholder?: string | null;
  help?: string | null;
  options?: FieldOption[];
  source?: LookupSource;
  min?: number | null;
  max?: number | null;
  max_length?: number | null;
  show_if?: FieldCondition | null;
}

export interface FormSchema {
  fields: FormField[];
}

export type FieldValue = string | number | boolean | File | null;

/** Settings the inspector shows for a type, besides label, required, width and help text. */
export type FieldSetting = 'placeholder' | 'max_length' | 'range' | 'options' | 'source';

/** Largest file a file field takes (same as the backend's FormSchema::MAX_FILE_KB). */
export const MAX_FILE_BYTES = 5 * 1024 * 1024;

/** Field IDs: a letter, then letters, digits or underscores (same rule as the backend). */
export const FIELD_ID_PATTERN = /^[a-z][a-z0-9_]{1,39}$/;

export abstract class FieldType {
  abstract readonly key: FieldTypeKey;
  abstract readonly label: string;
  abstract readonly description: string;
  readonly settings: readonly FieldSetting[] = [];
  /** False for layout-only fields (a page break) that take no answer. */
  readonly answered: boolean = true;

  /** A new field of this type with the given ID. */
  create(id: string): FormField {
    return { id, type: this.key, label: this.label, required: false, width: 'full', ...this.defaults() };
  }

  emptyValue(): FieldValue {
    return null;
  }

  isEmpty(value: FieldValue | undefined): boolean {
    return value === null || value === undefined || (typeof value === 'string' && value.trim() === '');
  }

  /** The message to show under the field, or null when the value is fine. */
  validate(field: FormField, value: FieldValue | undefined): string | null {
    if (this.isEmpty(value)) return field.required ? `${field.label} is required.` : null;
    return this.check(field, value as FieldValue);
  }

  /** The value as sent in the multipart body, or null to leave it out. */
  toPayload(value: FieldValue | undefined): string | Blob | null {
    return this.isEmpty(value) ? null : String(value);
  }

  protected defaults(): Partial<FormField> {
    return {};
  }

  protected check(_field: FormField, _value: FieldValue): string | null {
    return null;
  }
}

class TextFieldType extends FieldType {
  readonly key: FieldTypeKey = 'text';
  readonly label: string = 'Text';
  readonly description: string = 'One line of text';
  readonly settings: readonly FieldSetting[] = ['placeholder', 'max_length'];
  protected readonly defaultMaxLength: number = 255;

  protected check(field: FormField, value: FieldValue): string | null {
    const limit = field.max_length ?? this.defaultMaxLength;
    return String(value).length > limit ? `${field.label} can be at most ${limit} characters.` : null;
  }
}

class TextareaFieldType extends TextFieldType {
  readonly key: FieldTypeKey = 'textarea';
  readonly label = 'Paragraph';
  readonly description = 'Several lines of text';
  protected readonly defaultMaxLength = 5000;
}

class BarcodeFieldType extends TextFieldType {
  readonly key: FieldTypeKey = 'barcode';
  readonly label = 'Barcode';
  readonly description = 'Typed or scanned code';
  readonly settings: readonly FieldSetting[] = ['placeholder'];
}

class NumberFieldType extends FieldType {
  readonly key = 'number';
  readonly label = 'Number';
  readonly description = 'A quantity or amount';
  readonly settings: readonly FieldSetting[] = ['placeholder', 'range'];

  protected check(field: FormField, value: FieldValue): string | null {
    const number = Number(value);
    if (!Number.isFinite(number)) return `${field.label} must be a number.`;
    if (field.min !== null && field.min !== undefined && number < field.min) return `${field.label} must be at least ${field.min}.`;
    if (field.max !== null && field.max !== undefined && number > field.max) return `${field.label} can be at most ${field.max}.`;
    return null;
  }
}

class DateFieldType extends FieldType {
  readonly key = 'date';
  readonly label = 'Date';
  readonly description = 'A calendar date';

  protected check(field: FormField, value: FieldValue): string | null {
    return Number.isNaN(Date.parse(String(value))) ? `${field.label} must be a date.` : null;
  }
}

class DropdownFieldType extends FieldType {
  readonly key = 'dropdown';
  readonly label = 'Dropdown';
  readonly description = 'Pick one of a list';
  readonly settings: readonly FieldSetting[] = ['options'];

  protected defaults(): Partial<FormField> {
    return { options: [{ value: 'option_1', label: 'Option 1' }, { value: 'option_2', label: 'Option 2' }] };
  }

  protected check(field: FormField, value: FieldValue): string | null {
    return (field.options ?? []).some((option) => option.value === value) ? null : `Choose one of the ${field.label} options.`;
  }
}

class CheckboxFieldType extends FieldType {
  readonly key = 'checkbox';
  readonly label = 'Checkbox';
  readonly description = 'Yes or no';

  emptyValue(): FieldValue {
    return false;
  }

  /** A required checkbox must be ticked (e.g. "I checked the seal"). */
  isEmpty(value: FieldValue | undefined): boolean {
    return value !== true;
  }

  toPayload(value: FieldValue | undefined): string {
    return value === true ? '1' : '0';
  }
}

class LookupFieldType extends FieldType {
  readonly key = 'lookup';
  readonly label = 'Lookup';
  readonly description = 'A product, customer, warehouse or store';
  readonly settings: readonly FieldSetting[] = ['source'];

  protected defaults(): Partial<FormField> {
    return { source: 'products' };
  }
}

class FileFieldType extends FieldType {
  readonly key = 'file';
  readonly label = 'File';
  readonly description = 'A photo or document (5 MB max)';

  protected check(field: FormField, value: FieldValue): string | null {
    return value instanceof File && value.size > MAX_FILE_BYTES ? `${field.label} must be smaller than 5 MB.` : null;
  }

  toPayload(value: FieldValue | undefined): Blob | null {
    return value instanceof File ? value : null;
  }
}

/** Starts a new step of the form; its label is the step's title. */
class PageFieldType extends FieldType {
  readonly key = 'page';
  readonly label = 'Page Break';
  readonly description = 'Start a new step';
  readonly answered = false;

  create(id: string): FormField {
    return { id, type: this.key, label: 'Next step' };
  }
}

/** One instance of each type, in palette order. */
export const FIELD_TYPES: Record<FieldTypeKey, FieldType> = {
  text: new TextFieldType(),
  textarea: new TextareaFieldType(),
  number: new NumberFieldType(),
  date: new DateFieldType(),
  dropdown: new DropdownFieldType(),
  checkbox: new CheckboxFieldType(),
  lookup: new LookupFieldType(),
  barcode: new BarcodeFieldType(),
  file: new FileFieldType(),
  page: new PageFieldType(),
};

export const fieldType = (key: FieldTypeKey): FieldType => FIELD_TYPES[key];

const randomHex = (bytes: number): string =>
  Array.from(crypto.getRandomValues(new Uint8Array(bytes)), (byte) => byte.toString(16).padStart(2, '0')).join('');

/** A field ID not used in the form yet (random, so two editors adding fields do not collide). */
export const createFieldId = (taken: Iterable<string>, random: (bytes: number) => string = randomHex): string => {
  const used = new Set(taken);
  let id: string;
  do {
    id = `f_${random(4)}`;
  } while (used.has(id));
  return id;
};

/** A condition's form of an answer: what show_if.equals is compared with. */
export const conditionValue = (value: FieldValue | undefined): string =>
  typeof value === 'boolean' ? (value ? '1' : '0') : value === null || value === undefined || value instanceof File ? '' : String(value);

/** Whether a field is shown for these answers (same rule as the backend's exclude_unless). */
export const isShown = (field: FormField, values: Record<string, FieldValue>): boolean =>
  !field.show_if || conditionValue(values[field.show_if.field]) === field.show_if.equals;

/** Fields that take an answer and are shown for these answers. */
export const visibleAnswerFields = (fields: FormField[], values: Record<string, FieldValue>): FormField[] =>
  fields.filter((field) => fieldType(field.type).answered && isShown(field, values));

export interface FormStep {
  title: string;
  fields: FormField[];
}

/** The form split at its page breaks; a form without page breaks is one step. */
export const formSteps = (schema: FormSchema): FormStep[] => {
  const steps: FormStep[] = [{ title: '', fields: [] }];
  for (const field of schema.fields) {
    if (field.type === 'page') {
      if (steps[steps.length - 1].fields.length > 0 || steps.length > 1) steps.push({ title: field.label, fields: [] });
      else steps[0].title = field.label;
    } else steps[steps.length - 1].fields.push(field);
  }
  return steps;
};

/** Earlier fields a field can depend on: those above it that take an answer. */
export const conditionSources = (fields: FormField[], id: string): FormField[] => {
  const index = fields.findIndex((field) => field.id === id);
  return fields.slice(0, Math.max(index, 0)).filter((field) => fieldType(field.type).answered && field.type !== 'file');
};

/** Messages for every field whose value is not accepted, keyed by field ID. */
export const validateValues = (schema: FormSchema, values: Record<string, FieldValue>): Record<string, string> => {
  const errors: Record<string, string> = {};
  for (const field of visibleAnswerFields(schema.fields, values)) {
    const message = fieldType(field.type).validate(field, values[field.id]);
    if (message) errors[field.id] = message;
  }
  return errors;
};

/** The multipart body of a submission: data[<field id>] per answered field. */
export const toFormData = (schema: FormSchema, values: Record<string, FieldValue>): FormData => {
  const body = new FormData();
  for (const field of visibleAnswerFields(schema.fields, values)) {
    const payload = fieldType(field.type).toPayload(values[field.id]);
    if (payload !== null) body.append(`data[${field.id}]`, payload);
  }
  return body;
};

/** Starting values of a form: each field's empty value. */
export const emptyValues = (schema: FormSchema): Record<string, FieldValue> =>
  Object.fromEntries(schema.fields.filter((field) => fieldType(field.type).answered).map((field) => [field.id, fieldType(field.type).emptyValue()]));

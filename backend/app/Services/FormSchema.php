<?php

namespace App\Services;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The layout contract of a builder form, shared with the frontend builder: an ordered list of fields, each with a
 * unique ID, a type and the settings that type takes. Checks a layout before it is saved and turns a published
 * layout into the rules a submission is checked against.
 */
class FormSchema
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'dropdown', 'checkbox', 'lookup', 'barcode', 'file', 'page'];

    /** A "page" field starts a new step of the form; it has a title but no answer. */
    public const STEP = 'page';

    /** Records a lookup field can point to. */
    public const LOOKUP_SOURCES = ['products', 'customers', 'warehouses', 'stores'];

    public const MAX_FIELDS = 60;

    /** Largest file a file field takes, in kilobytes. */
    public const MAX_FILE_KB = 5120;

    /**
     * Rules for a layout sent by the builder.
     *
     * @return array<string, mixed>
     */
    public static function layoutRules(): array
    {
        return [
            'schema' => ['required', 'array:fields'],
            'schema.fields' => ['present', 'array', 'max:'.self::MAX_FIELDS],
            'schema.fields.*' => ['array:id,type,label,required,width,placeholder,help,options,source,min,max,max_length,show_if'],
            'schema.fields.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/', 'distinct'],
            'schema.fields.*.type' => ['required', Rule::in(self::TYPES)],
            'schema.fields.*.label' => ['required', 'string', 'max:120'],
            'schema.fields.*.required' => ['sometimes', 'boolean'],
            'schema.fields.*.width' => ['sometimes', Rule::in(['full', 'half'])],
            'schema.fields.*.placeholder' => ['sometimes', 'nullable', 'string', 'max:120'],
            'schema.fields.*.help' => ['sometimes', 'nullable', 'string', 'max:300'],
            'schema.fields.*.options' => ['required_if:schema.fields.*.type,dropdown', 'array', 'min:1', 'max:50'],
            'schema.fields.*.options.*' => ['array:value,label'],
            'schema.fields.*.options.*.value' => ['required', 'string', 'max:80', 'distinct'],
            'schema.fields.*.options.*.label' => ['required', 'string', 'max:120'],
            'schema.fields.*.source' => ['required_if:schema.fields.*.type,lookup', Rule::in(self::LOOKUP_SOURCES)],
            'schema.fields.*.min' => ['sometimes', 'nullable', 'numeric'],
            'schema.fields.*.max' => ['sometimes', 'nullable', 'numeric'],
            'schema.fields.*.max_length' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],
            // Shown (and checked) only when an earlier field has this answer
            'schema.fields.*.show_if' => ['sometimes', 'nullable', 'array:field,equals'],
            'schema.fields.*.show_if.field' => ['required_with:schema.fields.*.show_if', 'string', 'max:40'],
            'schema.fields.*.show_if.equals' => ['required_with:schema.fields.*.show_if', 'string', 'max:120'],
        ];
    }

    /**
     * A field may depend only on an answer given before it (an earlier field that is not a step).
     *
     * @param  array{fields: list<array<string, mixed>>}  $schema
     *
     * @throws ValidationException
     */
    public static function ensureValidConditions(array $schema): void
    {
        $earlier = [];
        foreach ($schema['fields'] as $index => $field) {
            $on = $field['show_if']['field'] ?? null;
            if ($on !== null && ! in_array($on, $earlier, true)) {
                throw ValidationException::withMessages(["schema.fields.{$index}.show_if" => "{$field['label']} can only depend on a field above it."]);
            }
            if ($field['type'] !== self::STEP) {
                $earlier[] = $field['id'];
            }
        }
    }

    /**
     * Rules for what someone filled in on this layout, keyed data.<field id>.
     *
     * @param  array{fields: list<array<string, mixed>>}  $schema
     * @param  callable(string): mixed  $lookupRule  the exists rule for a lookup source, scoped to the company
     * @return array<string, mixed>
     */
    public static function submissionRules(array $schema, callable $lookupRule): array
    {
        $rules = ['data' => ['array']];
        foreach ($schema['fields'] as $field) {
            if ($field['type'] === self::STEP) {
                continue;
            }
            // A field hidden by its condition is left out: not required, and its answer is dropped.
            $condition = isset($field['show_if']) ? ["exclude_unless:data.{$field['show_if']['field']},{$field['show_if']['equals']}"] : [];
            $rules["data.{$field['id']}"] = [...$condition, ($field['required'] ?? false) ? 'required' : 'nullable', ...self::valueRules($field, $lookupRule)];
        }

        return $rules;
    }

    /**
     * Values of the layout's fields only, in layout order (anything else sent is dropped).
     *
     * @param  array{fields: list<array<string, mixed>>}  $schema
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function pick(array $schema, array $data): array
    {
        $values = [];
        foreach ($schema['fields'] as $field) {
            if ($field['type'] === self::STEP) {
                continue;
            }
            $value = $data[$field['id']] ?? null;
            $values[$field['id']] = match ($field['type']) {
                'number' => $value === null ? null : $value + 0,
                'lookup' => $value === null ? null : (int) $value,
                'checkbox' => (bool) $value,
                default => $value,
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  callable(string): mixed  $lookupRule
     * @return list<mixed>
     */
    private static function valueRules(array $field, callable $lookupRule): array
    {
        return match ($field['type']) {
            'text', 'barcode' => ['string', 'max:'.($field['max_length'] ?? 255)],
            'textarea' => ['string', 'max:'.($field['max_length'] ?? 5000)],
            'number' => array_values(array_filter([
                'numeric',
                isset($field['min']) ? "min:{$field['min']}" : null,
                isset($field['max']) ? "max:{$field['max']}" : null,
            ])),
            'date' => ['date'],
            'dropdown' => [Rule::in(array_column($field['options'], 'value'))],
            'checkbox' => ['boolean'],
            'lookup' => ['integer', $lookupRule($field['source'])],
            'file' => ['file', 'max:'.self::MAX_FILE_KB, 'mimes:jpg,jpeg,png,webp,pdf,csv,xlsx,docx,txt'],
            default => throw ValidationException::withMessages(['schema' => "Unknown field type {$field['type']}."]),
        };
    }
}

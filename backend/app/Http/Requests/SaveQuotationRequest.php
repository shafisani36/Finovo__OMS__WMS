<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->currentOrganization()->id;
        $inOrganization = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $organizationId);

        return [
            'store_id' => ['required', 'integer', $inOrganization('stores')],
            'customer_id' => ['nullable', 'integer', $inOrganization('customers')],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'billing_address' => ['nullable', 'array'],
            'billing_address.*' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'issue_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:'.($this->input('issue_date') ?: 'today')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['nullable', 'integer', $inOrganization('product_variants')],
            'items.*.sku' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one item to the quotation.',
            'items.*.description.required' => 'Each item needs a description.',
            'valid_until.after_or_equal' => 'The quotation must be valid until the issue date or later.',
        ];
    }
}

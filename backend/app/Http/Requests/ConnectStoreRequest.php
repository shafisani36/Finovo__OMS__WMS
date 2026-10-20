<?php

namespace App\Http\Requests;

use App\Enums\StorePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $platform = StorePlatform::tryFrom((string) $this->input('platform'));

        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::enum(StorePlatform::class)],
            'warehouse_id' => ['required', 'integer'],
            'credentials' => ['nullable', 'array'],
            ...($platform?->credentialRules() ?? []),
        ];
    }

    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'Select the warehouse that fulfils this store\'s orders.',
            'credentials.shop_domain.required' => 'Enter your Shopify store address.',
            'credentials.site_url.required' => 'Enter the store website address.',
            'credentials.site_url.url' => 'Enter a full website address starting with http:// or https://.',
            'credentials.store_hash.required' => 'Enter your BigCommerce store hash.',
            'credentials.store_hash.regex' => 'The store hash has only lowercase letters and numbers, e.g. abc123xyz.',
            'credentials.webservice_key.required' => 'Enter the PrestaShop webservice key.',
            'credentials.site_id.required' => 'Enter the Wix site ID.',
            'credentials.site_id.uuid' => 'The Wix site ID looks like 1a2b3c4d-1234-5678-9abc-1a2b3c4d5e6f.',
            'credentials.access_token.required' => 'Enter the Magento integration access token.',
            'credentials.db_name.required' => 'Enter the shop database name.',
            'credentials.bridge_url.required' => 'Enter the bridge address on your website.',
        ];
    }
}

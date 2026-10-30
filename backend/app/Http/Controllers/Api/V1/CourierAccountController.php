<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Services\CourierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The company's courier accounts. Credentials are write-only: they are saved encrypted and never sent back,
 * only whether each one is filled.
 */
class CourierAccountController extends Controller
{
    public function __construct(
        protected CourierService $couriers
    ) {}

    public function index(Request $request): JsonResponse
    {
        $accounts = CourierAccount::where('organization_id', $request->user()->currentOrganization()->id)
            ->orderBy('name')
            ->get()
            ->map(fn (CourierAccount $account) => $this->present($account));

        return response()->json([
            'status' => 'success',
            'data' => $accounts,
            // What each courier needs, for the form.
            'carriers' => collect(CourierService::CARRIERS)->map(fn (array $carrier, string $code) => [
                'code' => $code,
                ...$carrier,
                'settings' => [...$carrier['settings'], ...CourierService::COMMON_SETTINGS],
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $account = CourierAccount::create(['organization_id' => $request->user()->currentOrganization()->id, ...$validated]);

        return response()->json(['status' => 'success', 'message' => "{$account->name} added.", 'data' => $this->present($account)], 201);
    }

    public function update(Request $request, CourierAccount $courierAccount): JsonResponse
    {
        $this->authorizeAccount($request, $courierAccount);
        $validated = $this->validated($request, $courierAccount);
        $courierAccount->update($validated);

        return response()->json(['status' => 'success', 'message' => "{$courierAccount->name} saved.", 'data' => $this->present($courierAccount)]);
    }

    /**
     * Parcels already booked keep their tracking numbers; they are no longer followed.
     */
    public function destroy(Request $request, CourierAccount $courierAccount): JsonResponse
    {
        $this->authorizeAccount($request, $courierAccount);
        $courierAccount->delete();

        return response()->json(['status' => 'success', 'message' => "{$courierAccount->name} removed."]);
    }

    /**
     * Check the credentials with the courier.
     */
    public function test(Request $request, CourierAccount $courierAccount): JsonResponse
    {
        $this->authorizeAccount($request, $courierAccount);
        $this->couriers->test($courierAccount);

        return response()->json(['status' => 'success', 'message' => "{$courierAccount->name} is connected.", 'data' => $this->present($courierAccount->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CourierAccount $account = null): array
    {
        $carrier = $account?->carrier ?? $request->input('carrier');
        $definition = CourierService::CARRIERS[$carrier] ?? null;

        $rules = [
            'carrier' => $account ? ['prohibited'] : ['required', Rule::in(array_keys(CourierService::CARRIERS))],
            'name' => ['required', 'string', 'max:100'],
            'sandbox' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'credentials' => [$account ? 'sometimes' : 'required', 'array'],
            'settings' => ['sometimes', 'array'],
            'settings.default_weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'settings.api_url' => ['nullable', 'url:https'],
        ];

        foreach ($definition['credentials'] ?? [] as $field) {
            // On an update a blank credential keeps the saved one.
            $rules["credentials.{$field['key']}"] = [$field['required'] && ! $account ? 'required' : 'nullable', 'string', 'max:500'];
        }
        foreach ($definition['settings'] ?? [] as $field) {
            $rules["settings.{$field['key']}"] = [$field['required'] ? 'required' : 'nullable', 'string', 'max:255'];
        }

        $validated = $request->validate($rules);
        $keys = collect($definition['credentials'] ?? [])->pluck('key');

        if (array_key_exists('credentials', $validated)) {
            $given = collect($validated['credentials'])->only($keys)->filter(fn ($value) => filled($value));
            $validated['credentials'] = [...($account?->credentials ?? []), ...$given->all()];
        }
        if (array_key_exists('settings', $validated)) {
            $allowed = collect([...($definition['settings'] ?? []), ...CourierService::COMMON_SETTINGS])->pluck('key');
            $validated['settings'] = collect($validated['settings'])->only($allowed)->map(fn ($value) => is_string($value) ? trim($value) : $value)->all();
        }

        return collect($validated)->except('carrier')->all() + ($account ? [] : ['carrier' => $carrier]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CourierAccount $account): array
    {
        $definition = CourierService::CARRIERS[$account->carrier] ?? ['name' => $account->carrier, 'credentials' => []];

        return [
            ...$account->only(['id', 'carrier', 'name', 'sandbox', 'is_active', 'last_error']),
            'carrier_name' => $definition['name'],
            'settings' => (object) ($account->settings ?? []),
            // Which credentials are saved, never their values.
            'credentials_set' => collect($definition['credentials'])->mapWithKeys(fn (array $field) => [$field['key'] => $account->credential($field['key']) !== ''])->all(),
            'last_tracked_at' => $account->last_tracked_at?->toIso8601String(),
        ];
    }

    private function authorizeAccount(Request $request, CourierAccount $account): void
    {
        abort_unless($account->organization_id === $request->user()->currentOrganization()->id, 404);
    }
}

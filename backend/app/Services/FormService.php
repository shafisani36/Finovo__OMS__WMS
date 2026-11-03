<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The data flow of builder forms: draft saves guarded by a revision number, publishing numbered versions,
 * restoring an old version into the draft, record lookups for lookup fields and checked submissions.
 */
class FormService
{
    /** Lookup results returned per search. */
    private const LOOKUP_LIMIT = 20;

    public function __construct(
        protected AccessControl $access
    ) {}

    public function create(User $user, string $name): Form
    {
        return Form::create([
            'organization_id' => $user->currentOrganization()->id,
            'name' => $name,
            'schema' => ['fields' => []],
            'updated_by' => $user->id,
        ]);
    }

    /**
     * Saves the draft layout when it was made from the latest revision; otherwise someone else saved in between.
     *
     * @param  array{fields: list<array<string, mixed>>}  $schema
     */
    public function saveDraft(Form $form, int $revision, string $name, array $schema, User $user): Form
    {
        $this->ensureSensibleRanges($schema);

        return DB::transaction(function () use ($form, $revision, $name, $schema, $user) {
            $locked = Form::whereKey($form->id)->lockForUpdate()->firstOrFail();
            if ($locked->revision !== $revision) {
                throw new ConflictHttpException('This form was changed by someone else. Reload it to see the latest layout.');
            }
            $locked->update(['name' => $name, 'schema' => $schema, 'revision' => $revision + 1, 'updated_by' => $user->id]);

            return $locked;
        });
    }

    /**
     * Publishes the draft as the next version; people fill in this version from now on.
     */
    public function publish(Form $form, User $user): Form
    {
        if ($form->schema['fields'] === []) {
            throw ValidationException::withMessages(['schema' => 'Add at least one field before publishing.']);
        }
        if ($form->status === 'published') {
            throw ValidationException::withMessages(['schema' => "Version {$form->published_version} already has this layout."]);
        }

        return DB::transaction(function () use ($form, $user) {
            $version = (int) $form->versions()->lockForUpdate()->max('version') + 1;
            $form->versions()->create(['version' => $version, 'schema' => $form->schema, 'published_by' => $user->id]);
            $form->update(['published_version' => $version]);

            return $form;
        });
    }

    /**
     * Copies an old version into the draft (publish it again to put it back in use).
     */
    public function restore(Form $form, int $version, User $user): Form
    {
        $schema = $form->versions()->where('version', $version)->firstOrFail()->schema;

        return $this->saveDraft($form, $form->revision, $form->name, $schema, $user);
    }

    /**
     * Checks what was filled in against the published version and stores it; files go to private storage.
     *
     * @param  array<string, mixed>  $input
     */
    public function submit(Form $form, array $input, User $user): FormSubmission
    {
        $version = $form->publishedVersion()
            ?? throw ValidationException::withMessages(['form' => "{$form->name} is not published yet."]);
        $schema = $version->schema;

        $data = FormSchema::pick($schema, Validator::make($input, FormSchema::submissionRules($schema, fn (string $source) => $this->lookupRule($user, $source)))->validate()['data'] ?? []);

        foreach ($data as $id => $value) {
            if ($value instanceof UploadedFile) {
                $data[$id] = ['name' => $value->getClientOriginalName(), 'path' => $value->store("forms/{$form->uuid}", 'local'), 'size' => $value->getSize()];
            }
        }

        return $form->submissions()->create(['version' => $version->version, 'data' => $data, 'user_id' => $user->id]);
    }

    /**
     * Records of the company a lookup field can point to, matching the search.
     *
     * @return list<array{value: int, label: string}>
     */
    public function lookup(User $user, string $source, ?string $search): array
    {
        $orgId = $user->currentOrganization()->id;
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $search).'%';

        $rows = match ($source) {
            'products' => ProductVariant::where('product_variants.organization_id', $orgId)
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('product_variants.sku', 'like', $like)->orWhere('products.name', 'like', $like)))
                ->orderBy('products.name')
                ->limit(self::LOOKUP_LIMIT)
                ->get(['product_variants.id', 'product_variants.sku', 'products.name'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => trim("{$row->name} ({$row->sku})")]),
            'customers' => Customer::where('organization_id', $orgId)
                ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                ->orderBy('name')
                ->limit(self::LOOKUP_LIMIT)
                ->get(['id', 'name', 'email'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => $row->email ? "{$row->name} ({$row->email})" : $row->name]),
            'warehouses' => $this->access->scopeToWarehouses(Warehouse::where('organization_id', $orgId), $user, 'id')
                ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)))
                ->orderBy('name')
                ->limit(self::LOOKUP_LIMIT)
                ->get(['id', 'name', 'code'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => "{$row->name} ({$row->code})"]),
            'stores' => Store::where('organization_id', $orgId)
                ->when($search, fn ($q) => $q->where('name', 'like', $like))
                ->orderBy('name')
                ->limit(self::LOOKUP_LIMIT)
                ->get(['id', 'name'])
                ->map(fn ($row) => ['value' => $row->id, 'label' => $row->name]),
        };

        return $rows->values()->all();
    }

    /**
     * A lookup value must be a record of the user's company (and of their warehouses).
     */
    private function lookupRule(User $user, string $source): mixed
    {
        $orgId = $user->currentOrganization()->id;
        $table = $source === 'products' ? 'product_variants' : $source;
        $rule = Rule::exists($table, 'id')->where('organization_id', $orgId);
        $warehouses = $source === 'warehouses' ? $this->access->warehouseIds($user) : null;

        return $warehouses === null ? $rule : $rule->whereIn('id', $warehouses);
    }

    /**
     * @param  array{fields: list<array<string, mixed>>}  $schema
     */
    private function ensureSensibleRanges(array $schema): void
    {
        foreach ($schema['fields'] as $index => $field) {
            if (isset($field['min'], $field['max']) && $field['max'] < $field['min']) {
                throw ValidationException::withMessages(["schema.fields.{$index}.max" => "The largest value of {$field['label']} is below its smallest value."]);
            }
        }
    }
}

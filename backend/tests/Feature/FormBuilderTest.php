<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Drag-and-drop form builder: a form's layout is saved as a draft (with a revision number so two editors cannot
 * overwrite each other), published as numbered versions, and filled in against the published version only.
 */
class FormBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['status' => 'active', 'role' => UserRole::ADMIN]);
        $this->actingAs($this->admin);
    }

    public function test_a_form_is_drafted_published_and_filled_in(): void
    {
        $orgId = $this->admin->currentOrganization()->id;
        $variant = Product::create(['organization_id' => $orgId, 'name' => 'Serum', 'sku' => 'SER-01'])
            ->variants()->create(['organization_id' => $orgId, 'sku' => 'SER-01', 'price' => 10]);

        $form = $this->postJson('/api/v1/forms', ['name' => 'Damage report'])->assertCreated()->json('data');
        $this->assertSame([0, 'draft', []], [$form['revision'], $form['status'], $form['schema']['fields']]);

        $saved = $this->putJson("/api/v1/forms/{$form['uuid']}", ['revision' => 0, 'name' => 'Damage report', 'schema' => ['fields' => $this->fields()]])
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $saved['revision']);

        // A save from a stale copy is refused instead of overwriting the newer layout.
        $this->putJson("/api/v1/forms/{$form['uuid']}", ['revision' => 0, 'name' => 'Old', 'schema' => ['fields' => []]])->assertStatus(409);

        // Nothing can be filled in before it is published.
        $this->postJson("/api/v1/forms/{$form['uuid']}/submissions", ['data' => []])->assertUnprocessable();

        $this->postJson("/api/v1/forms/{$form['uuid']}/publish")->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'published');

        $this->getJson('/api/v1/forms/lookups/products?search=SER')->assertOk()->assertJsonPath('data.0.value', $variant->id);

        $this->postJson("/api/v1/forms/{$form['uuid']}/submissions", ['data' => ['f_reason' => 'x', 'f_qty' => 0, 'f_kind' => 'other']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['data.f_qty', 'data.f_kind', 'data.f_product']);

        Storage::fake('local');
        $this->post("/api/v1/forms/{$form['uuid']}/submissions", [
            'data' => ['f_reason' => 'Box crushed', 'f_qty' => 2, 'f_kind' => 'damaged', 'f_product' => $variant->id, 'f_photo' => UploadedFile::fake()->createWithContent('damage-note.txt', 'Box crushed on arrival.')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.version', 1);

        $submission = $this->getJson("/api/v1/forms/{$form['uuid']}/submissions")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['Box crushed', 2, 'damaged', $variant->id], [$submission['data']['f_reason'], $submission['data']['f_qty'], $submission['data']['f_kind'], $submission['data']['f_product']]);
        Storage::disk('local')->assertExists($submission['data']['f_photo']['path']);
        $this->getJson("/api/v1/forms/{$form['uuid']}/submissions")->assertJsonPath("lookup_labels.products.{$variant->id}", 'Serum (SER-01)');
    }

    public function test_steps_and_conditional_fields(): void
    {
        $fields = [
            ['id' => 'p_one', 'type' => 'page', 'label' => 'What happened'],
            ['id' => 'f_kind', 'type' => 'dropdown', 'label' => 'Kind', 'required' => true, 'options' => [['value' => 'damaged', 'label' => 'Damaged'], ['value' => 'missing', 'label' => 'Missing']]],
            ['id' => 'p_two', 'type' => 'page', 'label' => 'Details'],
            ['id' => 'f_damage', 'type' => 'text', 'label' => 'Describe the damage', 'required' => true, 'show_if' => ['field' => 'f_kind', 'equals' => 'damaged']],
        ];
        $form = $this->postJson('/api/v1/forms', ['name' => 'Report'])->json('data');

        // A field cannot depend on one below it, or on a step.
        foreach ([array_reverse($fields), [...$fields, ['id' => 'f_bad', 'type' => 'text', 'label' => 'Bad', 'show_if' => ['field' => 'p_two', 'equals' => 'x']]]] as $bad) {
            $this->putJson("/api/v1/forms/{$form['uuid']}", ['revision' => 0, 'name' => 'Report', 'schema' => ['fields' => $bad]])->assertUnprocessable();
        }

        $this->putJson("/api/v1/forms/{$form['uuid']}", ['revision' => 0, 'name' => 'Report', 'schema' => ['fields' => $fields]])->assertOk();
        $this->postJson("/api/v1/forms/{$form['uuid']}/publish")->assertOk();
        $url = "/api/v1/forms/{$form['uuid']}/submissions";

        // Shown, so required; hidden, so not required and its answer is dropped.
        $this->postJson($url, ['data' => ['f_kind' => 'damaged']])->assertUnprocessable()->assertJsonValidationErrors('data.f_damage');
        $this->postJson($url, ['data' => ['f_kind' => 'missing', 'f_damage' => 'ignored']])->assertCreated();
        $this->postJson($url, ['data' => ['f_kind' => 'damaged', 'f_damage' => 'Lid cracked']])->assertCreated();

        $answers = collect($this->getJson($url)->json('data'))->pluck('data')->sortBy('f_kind')->values()->all();
        $this->assertSame([['f_kind' => 'damaged', 'f_damage' => 'Lid cracked'], ['f_kind' => 'missing', 'f_damage' => null]], $answers);
    }

    public function test_layouts_with_bad_or_duplicate_fields_are_refused(): void
    {
        $form = $this->postJson('/api/v1/forms', ['name' => 'Check-in'])->json('data');
        $field = ['id' => 'f_name', 'type' => 'text', 'label' => 'Name'];

        foreach ([
            [$field, $field],
            [['id' => 'bad id', 'type' => 'text', 'label' => 'Name']],
            [['id' => 'f_x', 'type' => 'script', 'label' => 'X']],
            [['id' => 'f_pick', 'type' => 'dropdown', 'label' => 'Pick', 'options' => []]],
            [['id' => 'f_look', 'type' => 'lookup', 'label' => 'Look', 'source' => 'users']],
        ] as $fields) {
            $this->putJson("/api/v1/forms/{$form['uuid']}", ['revision' => 0, 'name' => 'Check-in', 'schema' => ['fields' => $fields]])->assertUnprocessable();
        }
    }

    public function test_versions_are_kept_and_an_old_one_can_be_restored(): void
    {
        $form = $this->postJson('/api/v1/forms', ['name' => 'Survey'])->json('data');
        $one = [['id' => 'f_a', 'type' => 'text', 'label' => 'First']];
        $two = [...$one, ['id' => 'f_b', 'type' => 'date', 'label' => 'Second']];

        $this->putJson("/api/v1/forms/{$form['uuid']}", ['revision' => 0, 'name' => 'Survey', 'schema' => ['fields' => $one]]);
        $this->postJson("/api/v1/forms/{$form['uuid']}/publish");
        $this->putJson("/api/v1/forms/{$form['uuid']}", ['revision' => 1, 'name' => 'Survey', 'schema' => ['fields' => $two]]);
        $this->postJson("/api/v1/forms/{$form['uuid']}/publish");

        $this->getJson("/api/v1/forms/{$form['uuid']}/versions")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.version', 2);

        $this->postJson("/api/v1/forms/{$form['uuid']}/versions/1/restore")
            ->assertOk()
            ->assertJsonPath('data.schema.fields', $one)
            ->assertJsonPath('data.revision', 3);
    }

    public function test_staff_without_the_permission_cannot_design_forms_and_see_published_forms_only(): void
    {
        $form = $this->postJson('/api/v1/forms', ['name' => 'Draft only'])->json('data');
        $orgId = $this->admin->currentOrganization()->id;
        $operator = User::factory()->create(['status' => 'active', 'role' => UserRole::OPERATOR, 'current_organization_id' => $orgId]);
        $operator->memberships()->create(['organization_id' => $orgId, 'role' => 'operator']);

        $this->actingAs($operator);
        $this->postJson('/api/v1/forms', ['name' => 'Mine'])->assertForbidden();
        $this->getJson('/api/v1/forms')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/forms/{$form['uuid']}")->assertNotFound();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return [
            ['id' => 'f_reason', 'type' => 'text', 'label' => 'What happened', 'required' => true, 'width' => 'full'],
            ['id' => 'f_qty', 'type' => 'number', 'label' => 'Units', 'required' => true, 'min' => 1, 'max' => 100, 'width' => 'half'],
            ['id' => 'f_kind', 'type' => 'dropdown', 'label' => 'Kind', 'options' => [['value' => 'damaged', 'label' => 'Damaged'], ['value' => 'missing', 'label' => 'Missing']], 'width' => 'half'],
            ['id' => 'f_product', 'type' => 'lookup', 'label' => 'Product', 'source' => 'products', 'required' => true],
            ['id' => 'f_photo', 'type' => 'file', 'label' => 'Photo'],
        ];
    }
}

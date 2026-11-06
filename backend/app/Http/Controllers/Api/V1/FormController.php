<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\AccessControl;
use App\Services\FormSchema;
use App\Services\FormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Forms made with the drag-and-drop builder. Designers (forms.manage) see drafts and change layouts; everyone
 * else with forms.submit sees published forms only and fills them in.
 */
class FormController extends Controller
{
    public function __construct(
        protected FormService $forms,
        protected AccessControl $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $forms = Form::where('organization_id', $request->user()->currentOrganization()->id)
            ->when(! $this->designs($request), fn ($q) => $q->whereNotNull('published_version'))
            ->withCount('submissions')
            ->orderBy('name')
            ->get()
            // The list needs no layout (and people who only fill in forms never see drafts).
            ->each->makeHidden('schema');

        return response()->json(['status' => 'success', 'data' => $forms]);
    }

    /**
     * Designers get the draft; others get the published layout they fill in.
     */
    public function show(Request $request, Form $form): JsonResponse
    {
        $this->ensureVisible($request, $form);
        $published = $form->publishedVersion();

        return response()->json(['status' => 'success', 'data' => [
            ...$form->toArray(),
            'schema' => $this->designs($request) ? $form->schema : $published->schema,
            'published_schema' => $published?->schema,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:120']])['name'];

        return response()->json(['status' => 'success', 'message' => "{$name} was created.", 'data' => $this->forms->create($request->user(), $name)], 201);
    }

    /**
     * Saves the draft layout; refused with 409 when it was made from an older revision.
     */
    public function update(Request $request, Form $form): JsonResponse
    {
        $this->ensureVisible($request, $form);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:120'],
            ...FormSchema::layoutRules(),
        ]);

        return response()->json(['status' => 'success', 'data' => $this->forms->saveDraft($form, $data['revision'], $data['name'], $data['schema'], $request->user())]);
    }

    public function destroy(Request $request, Form $form): JsonResponse
    {
        $this->ensureVisible($request, $form);
        $form->delete();

        return response()->json(['status' => 'success', 'message' => "{$form->name} was deleted."]);
    }

    public function publish(Request $request, Form $form): JsonResponse
    {
        $this->ensureVisible($request, $form);
        $form = $this->forms->publish($form, $request->user());

        return response()->json(['status' => 'success', 'message' => "Version {$form->published_version} of {$form->name} is live.", 'data' => [...$form->toArray(), 'version' => $form->published_version]]);
    }

    public function versions(Request $request, Form $form): JsonResponse
    {
        $this->ensureVisible($request, $form);
        $versions = $form->versions()->with('publisher:id,name')->orderByDesc('version')->get()->map(fn ($version) => [
            'version' => $version->version,
            'fields' => count($version->schema['fields']),
            'published_by' => $version->publisher?->name,
            'published_at' => $version->created_at,
        ]);

        return response()->json(['status' => 'success', 'data' => $versions]);
    }

    public function restore(Request $request, Form $form, int $version): JsonResponse
    {
        $this->ensureVisible($request, $form);

        return response()->json(['status' => 'success', 'message' => "Version {$version} was copied into the draft. Publish to use it.", 'data' => $this->forms->restore($form, $version, $request->user())]);
    }

    public function submit(Request $request, Form $form): JsonResponse
    {
        $this->ensureVisible($request, $form);
        $submission = $this->forms->submit($form, $request->all(), $request->user());

        return response()->json(['status' => 'success', 'message' => 'Thank you, your answers were saved.', 'data' => $submission], 201);
    }

    public function submissions(Request $request, Form $form): JsonResponse
    {
        $this->ensureVisible($request, $form);
        $page = $form->submissions()->with('user:id,name')->latest('id')->paginate($this->perPage($request, 20));

        return response()->json([
            'status' => 'success',
            'data' => $page->items(),
            'lookup_labels' => $this->forms->lookupLabels($form, $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function lookup(Request $request, string $source): JsonResponse
    {
        abort_unless(in_array($source, FormSchema::LOOKUP_SOURCES, true), 404);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        return response()->json(['status' => 'success', 'data' => $this->forms->lookup($request->user(), $source, $search)]);
    }

    private function designs(Request $request): bool
    {
        return $this->access->allows($request->user(), Permission::FORMS_MANAGE);
    }

    /**
     * Forms of other companies, and unpublished forms for people who only fill them in, do not exist for the user.
     */
    private function ensureVisible(Request $request, Form $form): void
    {
        abort_unless($form->organization_id === $request->user()->currentOrganization()->id, 404);
        abort_unless($form->published_version !== null || $this->designs($request), 404);
    }
}

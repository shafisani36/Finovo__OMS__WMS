<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AccessControl;
use App\Services\AiAssistantService;
use App\Services\OperationalInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Read-only AI assistant for orders and stock, and insights worked out from the records.
 */
class AiController extends Controller
{
    /**
     * Likely duplicate orders and unusual stock events (each part only for users who may see it).
     */
    public function insights(Request $request, OperationalInsightsService $insights): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $insights->report($request->user())]);
    }

    public function ask(Request $request, AiAssistantService $assistant, AccessControl $access): JsonResponse
    {
        $user = $request->user();
        abort_unless($access->allows($user, 'orders.view') || $access->allows($user, 'inventory.view'), 403, 'The assistant answers about orders and stock, which you cannot see.');
        $question = $request->validate(['question' => ['required', 'string', 'min:3', 'max:1000']])['question'];

        if (! $assistant->configured()) {
            return response()->json(['status' => 'error', 'message' => 'The AI assistant is not set up yet: add OPENROUTER_API_KEY to the server settings.'], 503);
        }

        try {
            return response()->json(['status' => 'success', ...$assistant->ask($user, $question)]);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 502);
        }
    }
}

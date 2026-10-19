<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Notifications\PasswordChangedNotification;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class PasswordChangeController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Change password for an authenticated user, enforcing current password verification.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // 1. Verify current password
        if (! Hash::check($request->validated('current_password'), $user->password)) {
            $this->auditService->record('password.change_failed_invalid_current', $user->id, null, $request);

            return response()->json([
                'success' => false,
                'message' => 'The provided current password is incorrect.',
                'data' => null,
                'errors' => [
                    'current_password' => ['The provided current password does not match our records.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $newPassword = (string) $request->validated('new_password');

        // 2. Prevent reuse of current password
        if (Hash::check($newPassword, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Your new password cannot be the same as your current password.',
                'data' => null,
                'errors' => [
                    'new_password' => ['The new password must be different from your current password.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 3. Update password hash
        $user->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();

        // 4. Revoke all other personal access tokens, keeping the current active token valid
        $currentTokenId = $user->currentAccessToken()?->id;
        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        // 5. Send security alert notification
        try {
            $user->notify(new PasswordChangedNotification(
                ipAddress: $request->ip(),
                timestamp: now()->toIso8601String()
            ));
        } catch (\Throwable $e) {
            // Non-blocking security notification failure handling
        }

        $this->auditService->record('password.changed_successfully', $user->id, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'Your password has been changed successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }
}

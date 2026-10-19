<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:12'],
        ];
    }

    public function messages(): array
    {
        return [
            'reset_token.required' => 'Password reset authorization token is required.',
            'password.required' => 'A new password is required.',
            'password.min' => 'The password must be at least 12 characters.',
            'password.max' => 'The password may not be greater than 128 characters.',
            'password.confirmed' => 'The password confirmation does not match.',
            'password_confirmation.required' => 'Please confirm your new password.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation error.',
            'data' => null,
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CompleteFakePaymentActionRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'clientSecret' => ['required', 'string', 'max:255'],
            'outcome' => ['required', 'string', 'in:succeeded,failed'],
        ];
    }

    /**
     * The PaymentIntent id a client secret belongs to (`pi_..._secret_...`).
     */
    public function intentId(): string
    {
        return explode('_secret_', $this->string('clientSecret')->toString())[0];
    }

    public function succeeded(): bool
    {
        return $this->string('outcome')->toString() === 'succeeded';
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListAvailabilityRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'skus' => ['required', 'array', 'min:1', 'max:100'],
            'skus.*' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return list<string>
     */
    public function skus(): array
    {
        /** @var list<string> $skus */
        $skus = $this->validated('skus');

        return array_values(array_unique($skus));
    }
}

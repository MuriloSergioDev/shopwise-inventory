<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetStockLevelRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'onHand' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function onHand(): int
    {
        return $this->integer('onHand');
    }
}

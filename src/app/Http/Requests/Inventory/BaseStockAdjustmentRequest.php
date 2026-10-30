<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseStockAdjustmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize incoming input fields.
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        if (isset($input['reason']) && is_string($input['reason'])) {
            $input['reason'] = trim($input['reason']);
        }

        if (isset($input['notes']) && is_string($input['notes'])) {
            $trimmed = trim($input['notes']);
            $input['notes'] = $trimmed !== '' ? $trimmed : null;
        }

        if (isset($input['items']) && is_array($input['items'])) {
            foreach ($input['items'] as $index => $item) {
                if (is_array($item)) {
                    if (isset($item['unit_cost']) && is_string($item['unit_cost'])) {
                        $trimmedCost = trim($item['unit_cost']);
                        $input['items'][$index]['unit_cost'] = $trimmedCost !== '' ? $trimmedCost : null;
                    }
                    if (isset($item['counted_quantity']) && is_string($item['counted_quantity'])) {
                        $input['items'][$index]['counted_quantity'] = trim($item['counted_quantity']);
                    }
                }
            }
        }

        $this->replace($input);
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class StoreQuickDecaDocumentRequest extends StoreDecaDocumentRequest
{
    protected function prepareForValidation(): void
    {
        $template = config('deca_quick.templates')[$this->route('template')] ?? null;
        abort_unless($template, 404);
        $this->merge(collect($template)->except('title')->all());
        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'tractor_plate' => ['required', Rule::in(config('deca_quick.plates'))],
        ]);
    }
}

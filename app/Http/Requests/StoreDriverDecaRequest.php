<?php

namespace App\Http\Requests;

class StoreDriverDecaRequest extends StoreQuickDecaDocumentRequest
{
    public function authorize(): bool
    {
        return $this->attributes->has('deca_driver_access');
    }
}

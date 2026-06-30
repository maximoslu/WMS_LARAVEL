<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Booking;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isInternal = $this->user()?->canAccessRole(\App\Models\Role::ALMACEN) === true;

        return [
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'type' => ['required', Rule::in(Booking::types())],
            'scheduled_date' => ['required', 'date'],
            'scheduled_time_from' => [$isInternal ? 'nullable' : 'prohibited', 'date_format:H:i'],
            'scheduled_time_to' => [$isInternal ? 'nullable' : 'prohibited', 'date_format:H:i', 'after_or_equal:scheduled_time_from'],
            'contact_name' => [$isInternal ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'contact_phone' => [$isInternal ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'carrier_name' => [$isInternal ? 'nullable' : 'required', 'string', 'max:255'],
            'vehicle_plate' => [$isInternal ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'driver_name' => [$isInternal ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'pallets_expected' => [$isInternal ? 'nullable' : 'prohibited', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => [$isInternal ? 'nullable' : 'prohibited', 'string', 'max:5000'],
            'assigned_to' => [$isInternal ? 'nullable' : 'prohibited', 'integer', 'exists:users,id'],
            'warehouse_id' => [$isInternal ? 'nullable' : 'prohibited', 'integer', 'exists:warehouses,id'],
            'origin_destination' => [$isInternal ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'document_reference' => [$isInternal ? 'nullable' : 'prohibited', 'string', 'max:255'],
            'loading_dock' => [$isInternal ? 'nullable' : 'prohibited', 'string', 'max:255'],
        ];
    }
}

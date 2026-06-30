<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Booking;

class UpdateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'type' => ['required', Rule::in(Booking::types())],
            'scheduled_date' => ['required', 'date'],
            'scheduled_time_from' => ['nullable', 'date_format:H:i'],
            'scheduled_time_to' => ['nullable', 'date_format:H:i', 'after_or_equal:scheduled_time_from'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'vehicle_plate' => ['nullable', 'string', 'max:255'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'pallets_expected' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'origin_destination' => ['nullable', 'string', 'max:255'],
            'document_reference' => ['nullable', 'string', 'max:255'],
            'loading_dock' => ['nullable', 'string', 'max:255'],
        ];
    }
}

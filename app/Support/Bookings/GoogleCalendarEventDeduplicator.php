<?php

namespace App\Support\Bookings;

use App\Models\Booking;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GoogleCalendarEventDeduplicator
{
    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, array<string, mixed>>
     */
    public function removeEquivalentEvents(Collection $events, Collection $bookings): Collection
    {
        if ($events->isEmpty() || $bookings->isEmpty()) {
            return $events->values();
        }

        return $events
            ->reject(fn (array $event): bool => $bookings->contains(
                fn (Booking $booking): bool => $this->isEquivalent($event, $booking)
            ))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isEquivalent(array $event, Booking $booking): bool
    {
        $startsAt = $event['starts_at'] ?? null;

        if (! $startsAt || ! $booking->scheduled_date?->isSameDay($startsAt)) {
            return false;
        }

        $haystack = $this->normalize(implode(' ', array_filter([
            $event['title'] ?? null,
            $event['description'] ?? null,
            $event['location'] ?? null,
        ])));

        if ($haystack === '') {
            return false;
        }

        if (Str::contains($haystack, $this->normalize($booking->referenceCode()))) {
            return true;
        }

        $clientTokens = array_filter([
            $booking->client?->name,
            $booking->client?->code,
        ]);

        $hasClient = collect($clientTokens)
            ->map(fn (?string $value): string => $this->normalize((string) $value))
            ->filter()
            ->contains(fn (string $value): bool => Str::contains($haystack, $value));

        if (! $hasClient) {
            return false;
        }

        $typeNeedle = $this->normalize($booking->typeLabel());

        return $typeNeedle !== '' && Str::contains($haystack, $typeNeedle);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}

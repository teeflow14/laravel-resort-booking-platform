<?php

// ============================================================
// app/Services/AvailabilityService.php
// ============================================================

namespace App\Services;

use App\Models\AvailabilityCalendar;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AvailabilityService
{
    /**
     * Check if a room type has available rooms for given dates.
     */
    public function checkAvailability(
        int    $roomTypeId,
        string $checkIn,
        string $checkOut,
        int    $roomsNeeded = 1,
        ?int   $excludeBookingId = null
    ): array {
        // Total physical rooms of this type
        $totalRooms = Room::where('room_type_id', $roomTypeId)
            ->where('status', 'available')
            ->where('is_active', true)
            ->count();

        // Count rooms already booked in this date range
        // Uses correct column 'status' and scopeOverlapping
        $bookedRooms = (int) Booking::where('room_type_id', $roomTypeId)
            ->whereIn('status', ['pending', 'confirmed', 'checked_in'])
            ->overlapping($checkIn, $checkOut)
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->sum('rooms_count');

        $availableRooms = $totalRooms - $bookedRooms;

        return [
            'available'       => $availableRooms >= $roomsNeeded,
            'total_rooms'     => $totalRooms,
            'booked_rooms'    => $bookedRooms,
            'available_rooms' => max(0, $availableRooms),
            'requested'       => $roomsNeeded,
        ];
    }

    /**
     * Get unavailable dates for a room type (for booking calendar).
     */
    public function getUnavailableDates(int $roomTypeId, string $month): array
    {
        $cacheKey = "unavailable_{$roomTypeId}_{$month}";

        return Cache::remember($cacheKey, 300, function () use ($roomTypeId, $month) {
            $start = Carbon::parse($month)->startOfMonth();
            $end   = Carbon::parse($month)->addMonths(2)->endOfMonth();

            $totalRooms = Room::where('room_type_id', $roomTypeId)
                ->where('status', 'available')
                ->where('is_active', true)
                ->count();

            if ($totalRooms === 0) {
                return collect(CarbonPeriod::create($start, $end))
                    ->map(fn($d) => $d->format('Y-m-d'))
                    ->toArray();
            }

            // Bookings that touch this month
            $bookings = Booking::where('room_type_id', $roomTypeId)
                ->whereIn('status', ['confirmed', 'checked_in'])  // correct column: status
                ->where('check_in',  '<=', $end->format('Y-m-d'))
                ->where('check_out', '>=', $start->format('Y-m-d'))
                ->get(['check_in', 'check_out', 'rooms_count']);

            $blockedDates = AvailabilityCalendar::where('room_type_id', $roomTypeId)
                ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
                ->where('is_blocked', true)
                ->pluck('date')
                ->toArray();

            $unavailable = [];

            foreach (CarbonPeriod::create($start, $end) as $day) {
                $dateStr     = $day->format('Y-m-d');
                $bookedCount = 0;

                foreach ($bookings as $booking) {
                    $bIn  = Carbon::parse($booking->check_in);
                    $bOut = Carbon::parse($booking->check_out);
                    if ($day->gte($bIn) && $day->lt($bOut)) {
                        $bookedCount += $booking->rooms_count;
                    }
                }

                if ($bookedCount >= $totalRooms || in_array($dateStr, $blockedDates)) {
                    $unavailable[] = $dateStr;
                }
            }

            return $unavailable;
        });
    }

    /**
     * Assign physical rooms to a confirmed booking.
     */
    public function assignRooms(Booking $booking): void
    {
        $rooms = Room::where('room_type_id', $booking->room_type_id)
            ->where('status', 'available')
            ->where('is_active', true)
            ->whereDoesntHave('bookings', function ($q) use ($booking) {
                $q->whereIn('bookings.status', ['confirmed', 'checked_in'])
                  ->where('bookings.id', '!=', $booking->id)  // qualify with table name
                  ->overlapping(
                      $booking->check_in->format('Y-m-d'),
                      $booking->check_out->format('Y-m-d')
                  );
            })
            ->limit($booking->rooms_count)
            ->get();

        if ($rooms->count() < $booking->rooms_count) {
            throw new \RuntimeException('Not enough rooms available to assign. Please contact support.');
        }

        $booking->rooms()->sync($rooms->pluck('id'));
    }

    /**
     * Clear cached availability after any booking change.
     */
    public function invalidateCache(int $roomTypeId, string $checkIn, string $checkOut): void
    {
        $start = Carbon::parse($checkIn);
        $end   = Carbon::parse($checkOut);

        for ($m = $start->copy(); $m->lte($end); $m->addMonth()) {
            Cache::forget("unavailable_{$roomTypeId}_{$m->format('Y-m')}");
        }
    }
}

// end app/Services/AvailabilityService.php

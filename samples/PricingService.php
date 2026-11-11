<?php

// ============================================================
// app/Services/PricingService.php
// ============================================================

namespace App\Services;

use App\Models\AvailabilityCalendar;
use App\Models\Coupon;
use App\Models\RoomType;
use App\Models\SeasonalRate;
use App\Support\BookingRules;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PricingService
{
    private const WEEKEND_DAYS = [Carbon::FRIDAY, Carbon::SATURDAY];

    public function calculate(
        RoomType $roomType,
        string $checkIn,
        string $checkOut,
        int $adults,
        int $children,
        int $roomsCount = 1,
        ?string $couponCode = null,
        bool $seniorDiscount = false,
        int $seniorGuests = 0,
    ): array {
        $checkInDate = Carbon::parse($checkIn);
        $checkOutDate = Carbon::parse($checkOut);
        $nights = $checkInDate->diffInDays($checkOutDate);

        if ($nights < 1) {
            throw new \InvalidArgumentException('Check-out must be after check-in.');
        }

        $nightlyBreakdown = $this->calculateNightlyRates($roomType, $checkInDate, $checkOutDate);
        $roomSubtotal = array_sum(array_column($nightlyBreakdown, 'price')) * $roomsCount;

        $extraAdults = max(0, $adults - ($roomType->max_adults ?? 2) * $roomsCount);
        $extraChildren = max(0, $children - ($roomType->max_children ?? 2) * $roomsCount);
        $extraCharges = (
            $extraAdults * (float) ($roomType->extra_adult_price ?? 0) +
            $extraChildren * (float) ($roomType->extra_child_price ?? 0)
        ) * $nights;

        $subtotalBeforeDiscount = $roomSubtotal + $extraCharges;
        $longStayDiscount = $this->calculateLongStayDiscount($subtotalBeforeDiscount, $nights);

        $couponDiscount = 0;
        $couponData = null;
        if ($couponCode) {
            try {
                $couponData = $this->validateAndCalculateCoupon($couponCode, $subtotalBeforeDiscount - $longStayDiscount, $nights);
                $couponDiscount = $couponData['discount'] ?? 0;
            } catch (\InvalidArgumentException) {
                // Invalid coupon — just ignore it, don't crash
            }
        }

        // Senior Citizen / PWD discount (Settings → VAT, Deposit & Discounts). When it may not be combined,
        // long-stay and coupon discounts are dropped for this booking.
        $seniorOn = $seniorDiscount && BookingRules::seniorEnabled();
        $othersIgnored = false;
        if ($seniorOn && ! BookingRules::seniorCombinesWithOtherDiscounts() && ($longStayDiscount > 0 || $couponDiscount > 0)) {
            $longStayDiscount = 0;
            $couponDiscount = 0;
            $couponData = null;
            $othersIgnored = true;
        }

        $totalDiscount = $longStayDiscount + $couponDiscount;
        $taxableAmount = $subtotalBeforeDiscount - $totalDiscount;
        // Rates come from Admin → Settings → VAT & Fees (they used to be fixed at 12% and 5% here).
        $vatRate = self::vatRate();
        $serviceRate = self::serviceChargeRate();

        $seniorAmount = 0.0;
        $seniorMode = $seniorOn ? BookingRules::seniorMode() : null;
        $seniorRate = $seniorOn ? BookingRules::seniorPercent() : null;
        $totalGuests = max(1, $adults + $children);
        $seniorCount = $seniorOn ? max(1, min($seniorGuests ?: 1, $totalGuests)) : null;

        switch ($seniorMode) {
            case BookingRules::SENIOR_MODE_ROOM_NO_VAT:
                $seniorAmount = round($taxableAmount * $seniorRate / 100, 2);
                $taxAmount = 0.0;
                $serviceFee = round(($taxableAmount - $seniorAmount) * $serviceRate / 100, 2);
                break;

            case BookingRules::SENIOR_MODE_PROPORTIONAL:
                $seniorShare = $taxableAmount * $seniorCount / $totalGuests;
                $seniorAmount = round($seniorShare * $seniorRate / 100, 2);
                $taxAmount = round(($taxableAmount - $seniorShare) * $vatRate / 100, 2);
                $serviceFee = round(($taxableAmount - $seniorAmount) * $serviceRate / 100, 2);
                break;

            case BookingRules::SENIOR_MODE_TOTAL:
                $taxAmount = round($taxableAmount * $vatRate / 100, 2);
                $serviceFee = round($taxableAmount * $serviceRate / 100, 2);
                $seniorAmount = round(($taxableAmount + $taxAmount + $serviceFee) * $seniorRate / 100, 2);
                break;

            default:
                $taxAmount = round($taxableAmount * $vatRate / 100, 2);
                $serviceFee = round($taxableAmount * $serviceRate / 100, 2);
        }

        $totalAmount = round($taxableAmount - $seniorAmount + $taxAmount + $serviceFee, 2);
        $depositAmount = BookingRules::depositEnabled() ? round($totalAmount * BookingRules::depositPercent() / 100, 2) : 0.0;

        return [
            'nights' => $nights,
            'rooms_count' => $roomsCount,
            'nightly_breakdown' => $nightlyBreakdown,
            'avg_nightly_rate' => round($roomSubtotal / $nights / $roomsCount, 2),
            'room_subtotal_raw' => round($roomSubtotal, 2),
            'extra_charges' => round($extraCharges, 2),
            'subtotal' => round($subtotalBeforeDiscount, 2),
            'long_stay_discount' => round($longStayDiscount, 2),
            'coupon_discount' => round($couponDiscount, 2),
            'total_discount' => round($totalDiscount, 2),
            'senior_discount' => $seniorAmount,
            'senior_discount_mode' => $seniorMode,
            'senior_discount_rate' => $seniorRate,
            'senior_guests' => $seniorCount,
            'other_discounts_ignored' => $othersIgnored,
            'tax_amount' => $taxAmount,
            'service_charge' => $serviceFee,
            'vat_rate' => $vatRate,
            'service_charge_rate' => $serviceRate,
            'total_amount' => $totalAmount,
            'deposit_amount' => $depositAmount,
            'deposit_percent' => $depositAmount > 0 ? BookingRules::depositPercent() : 0,
            'balance_after_deposit' => round($totalAmount - $depositAmount, 2),
            'coupon' => $couponData,
        ];
    }

    private function calculateNightlyRates(RoomType $roomType, Carbon $checkIn, Carbon $checkOut): array
    {
        // Clone checkOut before subDay so we don't mutate the original
        $period = CarbonPeriod::create($checkIn, (clone $checkOut)->subDay());
        $breakdown = [];

        $seasonalRates = $this->getSeasonalRates($roomType->id, $checkIn, $checkOut);

        // Load calendar custom prices (column is custom_price in actual DB)
        $calendarOverrides = AvailabilityCalendar::where('room_type_id', $roomType->id)
            ->whereBetween('date', [$checkIn->format('Y-m-d'), $checkOut->format('Y-m-d')])
            ->whereNotNull('custom_price')
            ->pluck('custom_price', 'date')
            ->toArray();

        foreach ($period as $day) {
            $dateStr = $day->format('Y-m-d');

            if (isset($calendarOverrides[$dateStr])) {
                $price = (float) $calendarOverrides[$dateStr];
                $priceType = 'override';
            } elseif ($rate = $this->findApplicableSeasonalRate($seasonalRates, $day)) {
                // fixed_price takes precedence over multiplier if set
                $price = ($rate->fixed_price && $rate->fixed_price > 0)
                    ? (float) $rate->fixed_price
                    : round((float) $roomType->base_price * (float) $rate->rate_multiplier, 2);
                $priceType = 'seasonal';
            } elseif ($roomType->weekend_price && in_array($day->dayOfWeek, self::WEEKEND_DAYS)) {
                $price = (float) $roomType->weekend_price;
                $priceType = 'weekend';
            } else {
                $price = (float) $roomType->base_price;
                $priceType = 'base';
            }

            $breakdown[] = [
                'date' => $dateStr,
                'day' => $day->format('D'),
                'price' => $price,
                'price_type' => $priceType,
            ];
        }

        return $breakdown;
    }

    private function calculateLongStayDiscount(float $subtotal, int $nights): float
    {
        $rate = match (true) {
            $nights >= 30 => 0.15,
            $nights >= 14 => 0.10,
            $nights >= 7 => 0.05,
            default => 0,
        };

        return round($subtotal * $rate, 2);
    }

    public function validateAndCalculateCoupon(string $code, float $subtotal, int $nights): array
    {
        $coupon = Coupon::where('code', strtoupper($code))->where('is_active', true)->first();

        if (! $coupon) {
            throw new \InvalidArgumentException('Invalid or expired coupon code.');
        }

        $now = now();
        if ($coupon->valid_from && $now->lt($coupon->valid_from)) {
            throw new \InvalidArgumentException('This coupon is not yet active.');
        }
        if ($coupon->valid_until && $now->gt($coupon->valid_until)) {
            throw new \InvalidArgumentException('This coupon has expired.');
        }
        if ($coupon->max_uses && $coupon->used_count >= $coupon->max_uses) {
            throw new \InvalidArgumentException('This coupon has reached its usage limit.');
        }
        if ($coupon->min_nights && $coupon->min_nights > $nights) {
            throw new \InvalidArgumentException("This coupon requires a minimum {$coupon->min_nights}-night stay.");
        }

        $discount = match ($coupon->type) {
            'percentage' => round($subtotal * ($coupon->value / 100), 2),
            'fixed' => min((float) $coupon->value, $subtotal),
            default => 0,
        };

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'type' => $coupon->type,
            'value' => $coupon->value,
            'discount' => $discount,
        ];
    }

    private function getSeasonalRates(int $roomTypeId, Carbon $start, Carbon $end): Collection
    {
        $cacheKey = "seasonal_{$roomTypeId}_{$start->format('Ymd')}_{$end->format('Ymd')}";

        return Cache::remember($cacheKey, 3600, function () use ($roomTypeId, $start, $end) {
            return SeasonalRate::where('is_active', true)
                ->where('start_date', '<=', $end->format('Y-m-d'))
                ->where('end_date', '>=', $start->format('Y-m-d'))
                ->where(function ($q) use ($roomTypeId) {
                    $q->where('apply_to_all_rooms', true)
                        ->orWhere('room_type_id', $roomTypeId);
                })
                ->orderByDesc('priority')
                ->get();
        });
    }

    private function findApplicableSeasonalRate(Collection $rates, Carbon $day): ?SeasonalRate
    {
        return $rates->first(fn (SeasonalRate $r) => $day->between($r->start_date, $r->end_date));
    }

    /**
     * VAT percentage from Settings → VAT & Fees.
     */
    public static function vatRate(): float
    {
        return max(0.0, (float) site_setting('vat_rate', config('app.resort.vat_rate', 12)));
    }

    /**
     * Service charge percentage from Settings → VAT & Fees.
     */
    public static function serviceChargeRate(): float
    {
        return max(0.0, (float) site_setting('service_charge', config('app.resort.service_charge', 5)));
    }
}

// end app/Services/PricingService.php

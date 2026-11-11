# Laravel Resort Booking Platform

A country-ready **hotel and beach-resort booking platform** built with **Laravel 13**: room types and live availability, seasonal pricing and coupons, online payments through seven gateways, and a full CMS-driven public website with an admin panel. It ships with a web installer so a resort can set it up on their own hosting.

> This repository is a **showcase**. The full source code is private because the platform is a commercial product.
> It contains an overview, the architecture and a few representative files. I'm happy to walk through the complete codebase on a call.

**Author:** Alabi Babatunde, PHP/Laravel backend developer · [LinkedIn](https://www.linkedin.com/in/tundeblog)

---

## At a glance

| | |
|---|---|
| Framework | Laravel 13, PHP 8.4, MySQL / MariaDB |
| Front end | Blade, Tailwind CSS, Alpine.js |
| Domain models | 32 Eloquent models |
| HTTP layer | 40 controllers |
| Automated tests | 107 PHPUnit feature tests |
| Payments | Stripe, PayPal, Paystack, Flutterwave, PayMongo, Xendit, NOWPayments + manual methods |

## Features

**Guest website**
- Room types with galleries, amenities and reviews
- Availability calendar that blocks fully booked and admin-blocked dates
- Booking flow with guests, seasonal rates, long-stay / senior / coupon discounts, and deposit or full payment
- Online payment or manual payment instructions, plus booking confirmation emails
- Restaurant, activities, FAQ, gallery, blog and contact pages, all editable from the CMS

**Admin panel**
- Bookings: create on behalf of guests, confirm, check in / check out, assign physical rooms
- Rooms and room types, seasonal rates, blocked dates, coupons
- Payment gateway configuration with **encrypted credentials**, plus regional gateways
- Page editor with sections, media library with chunked uploads, navigation, themes
- Email templates and email logs, currencies with automatic exchange-rate updates
- Roles and permissions, audit log, platform updates and a web installer

## Architecture

```
BookingController ──► BookingService ──► PricingService (seasonal rates, discounts, deposits)
                          │
                          ├──► AvailabilityService (overlap queries, cached calendars, room assignment)
                          └──► PaymentService ──► GatewayInterface
                                                   ├── StripeGateway   ├── PaystackGateway
                                                   ├── PayPalGateway   ├── FlutterwaveGateway
                                                   ├── PayMongoGateway ├── XenditGateway
                                                   └── NowPaymentsGateway / ManualPaymentService
```

- **Gateways are swappable adapters** behind one small interface (`initiate` + `handleCallback`). Adding a provider never touches booking logic.
- **Availability is computed, not stored:** physical rooms minus overlapping active bookings, with admin blocks on top, cached per room type and month and cleared on every booking change.
- **Pricing is isolated** in its own service, so seasonal rates and discount rules are tested independently of the booking flow.

## Code samples

| File | What it shows |
|---|---|
| [`samples/AvailabilityService.php`](samples/AvailabilityService.php) | Date-range overlap queries, cached unavailable-date calendars, and assigning physical rooms without double-booking |
| [`samples/PricingService.php`](samples/PricingService.php) | Night-by-night pricing with seasonal rates, plus long-stay, senior and coupon discounts and the admin rules for combining them |
| [`samples/GatewayInterface.php`](samples/GatewayInterface.php) | The contract every payment provider implements |
| [`samples/tests/BookingRulesTest.php`](samples/tests/BookingRulesTest.php) | Feature tests for discount modes, deposits vs. full payment, staff payment approval, gateway switching and manual payment methods with required fields |

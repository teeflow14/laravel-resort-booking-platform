<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Booking;
use App\Models\EmailLog;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\Payment\PaymentService;
use App\Services\PricingService;
use App\Support\BookingRules;
use App\Support\Cms\Icons;
use App\Support\PaymentGateways;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesResortData;
use Tests\TestCase;

class BookingRulesTest extends TestCase
{
    use CreatesResortData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Setting::set('vat_rate', '12');
        Setting::set('service_charge', '5');
        Setting::set('currency', 'PHP');
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(int $roomTypeId, array $overrides = []): array
    {
        return array_merge([
            'room_type_id' => $roomTypeId,
            'check_in' => now()->addDays(20)->toDateString(),
            'check_out' => now()->addDays(22)->toDateString(),
            'adults' => 2,
            'children' => 0,
            'rooms_count' => 1,
            'first_name' => 'Lola',
            'last_name' => 'Reyes',
            'email' => 'lola@example.com',
            'phone' => '+63 917 000 0000',
        ], $overrides);
    }

    public function test_senior_discount_is_priced_by_the_mode_the_admin_chose(): void
    {
        $roomType = $this->roomType(['base_price' => 5000]);
        Setting::set('senior_discount_enabled', '1');
        Setting::set('senior_discount_percent', '20');
        $price = fn (int $seniors = 0) => app(PricingService::class)->calculate($roomType, now()->addDays(20)->toDateString(), now()->addDays(22)->toDateString(), 2, 0, 1, null, true, $seniors);

        Setting::set('senior_discount_mode', BookingRules::SENIOR_MODE_TOTAL);
        $total = $price();
        $this->assertEqualsWithDelta(2340.0, $total['senior_discount'], 0.001); // 20% of 11,700
        $this->assertEqualsWithDelta(9360.0, $total['total_amount'], 0.001);

        Setting::set('senior_discount_mode', BookingRules::SENIOR_MODE_ROOM_NO_VAT);
        $noVat = $price();
        $this->assertEqualsWithDelta(0.0, $noVat['tax_amount'], 0.001);
        $this->assertEqualsWithDelta(8400.0, $noVat['total_amount'], 0.001); // 8,000 + 5% service

        Setting::set('senior_discount_mode', BookingRules::SENIOR_MODE_PROPORTIONAL);
        $proportional = $price(1);
        $this->assertEqualsWithDelta(1000.0, $proportional['senior_discount'], 0.001); // 20% of the senior's half
        $this->assertEqualsWithDelta(600.0, $proportional['tax_amount'], 0.001); // VAT only on the other half
        $this->assertEqualsWithDelta(10050.0, $proportional['total_amount'], 0.001);
    }

    public function test_senior_discount_is_ignored_while_switched_off(): void
    {
        $roomType = $this->roomType(['base_price' => 5000]);

        $pricing = app(PricingService::class)->calculate($roomType, now()->addDays(20)->toDateString(), now()->addDays(22)->toDateString(), 2, 0, 1, null, true);

        $this->assertSame(0.0, $pricing['senior_discount']);
        $this->assertEqualsWithDelta(11700.0, $pricing['total_amount'], 0.001);
    }

    public function test_deposit_is_due_first_and_the_guest_may_pay_in_full(): void
    {
        Setting::set('deposit_enabled', '1');
        Setting::set('deposit_percent', '30');
        $roomType = $this->roomType(['base_price' => 5000]);

        $number = $this->postJson(route('booking.store'), $this->bookingPayload($roomType->id))
            ->assertOk()->assertJsonPath('success', true)->json('booking_number');

        $booking = Booking::query()->where('booking_number', $number)->firstOrFail();
        $this->assertEqualsWithDelta(3510.0, (float) $booking->deposit_amount, 0.001); // 30% of 11,700
        $this->assertEqualsWithDelta(3510.0, app(PaymentService::class)->amountDue($booking), 0.001);
        $this->assertEqualsWithDelta(11700.0, app(PaymentService::class)->amountDue($booking, true), 0.001);

        $this->withSession(['guest_booking' => $number])
            ->get(route('booking.payment', $number))
            ->assertOk()->assertSee('Deposit due now')->assertSee('₱3,510.00');
    }

    public function test_senior_id_upload_is_required_only_when_the_admin_asks_for_it(): void
    {
        Storage::fake('local');
        Setting::set('senior_discount_enabled', '1');
        Setting::set('senior_require_id', '1');
        $roomType = $this->roomType(['base_price' => 5000]);

        $this->postJson(route('booking.store'), $this->bookingPayload($roomType->id, ['senior_discount' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('senior_id');

        $number = $this->post(route('booking.store'), $this->bookingPayload($roomType->id, [
            'senior_discount' => 1,
            'senior_id' => UploadedFile::fake()->image('senior-id.jpg'),
        ]), ['Accept' => 'application/json'])->assertOk()->json('booking_number');

        $booking = Booking::query()->where('booking_number', $number)->firstOrFail();
        $this->assertTrue($booking->hasSeniorDiscount());
        Storage::disk('local')->assertExists($booking->senior_id_path);

        // Staff can open the private ID file; guests cannot.
        $this->get(route('admin.bookings.senior-id', $booking))->assertRedirect();
        $this->actingAs($this->admin())->get(route('admin.bookings.senior-id', $booking))->assertOk();
    }

    public function test_admin_can_remove_the_senior_discount_and_the_guest_is_emailed(): void
    {
        Setting::set('senior_discount_enabled', '1');
        $roomType = $this->roomType(['base_price' => 5000]);
        $number = $this->postJson(route('booking.store'), $this->bookingPayload($roomType->id, ['senior_discount' => 1]))->json('booking_number');
        $booking = Booking::query()->where('booking_number', $number)->firstOrFail();
        $this->assertEqualsWithDelta(9360.0, (float) $booking->total_amount, 0.001);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.bookings.show', $booking))
            ->assertOk()->assertSee('Senior Citizen / PWD')->assertSee('Remove discount');

        $this->actingAs($admin)
            ->postJson(route('admin.bookings.remove-senior', $booking))
            ->assertOk()->assertJsonPath('success', true);

        $booking->refresh();
        $this->assertFalse($booking->hasSeniorDiscount());
        $this->assertEqualsWithDelta(11700.0, (float) $booking->total_amount, 0.001);
        $this->assertTrue(EmailLog::query()->where('template_key', 'senior_discount_removed')->where('recipient', 'lola@example.com')->exists());
    }

    public function test_guest_sees_pending_until_staff_approve_the_payment(): void
    {
        $booking = $this->booking();
        $booking->payments()->create(['gateway' => 'manual', 'amount' => 11200, 'currency' => 'PHP', 'status' => 'pending', 'transaction_id' => 'MANUAL-TEST1']);

        $this->withSession(['guest_booking' => $booking->booking_number])
            ->get(route('booking.confirmation', $booking->booking_number))
            ->assertOk()->assertSee('Booking Pending — Verifying Your Payment')->assertDontSee('Booking Confirmed!');

        $payment = $booking->payments()->first();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.payments.show', $payment->id))->assertOk()->assertSee('Balance due');
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->assertSee('Senior Citizen / PWD discount');
        $this->actingAs($admin)->postJson(route('admin.payments.confirm', $payment->id))->assertOk();

        $this->withSession(['guest_booking' => $booking->booking_number])
            ->get(route('booking.confirmation', $booking->booking_number))
            ->assertOk()->assertSee('Booking Confirmed!');
    }

    public function test_old_success_page_no_longer_claims_a_booking_is_confirmed(): void
    {
        $booking = $this->booking();

        $this->get(route('payment.success', ['booking' => $booking->booking_number]))
            ->assertRedirect(route('booking.confirmation', $booking->booking_number));
    }

    public function test_card_option_shows_the_admin_message_while_no_processor_is_connected(): void
    {
        $booking = $this->booking();

        $card = collect(PaymentGateways::available())->firstWhere('key', PaymentGateways::CARD);
        $this->assertTrue($card['unavailable']);
        $this->assertSame(BookingRules::DEFAULT_CARD_MESSAGE, $card['unavailable_message']);
        $this->assertNotContains('card', PaymentGateways::availableKeys(PaymentGateways::AUTOMATIC));

        $this->withSession(['guest_booking' => $booking->booking_number])
            ->get(route('booking.payment', $booking->booking_number))
            ->assertOk()->assertSee('Credit / Debit Card')->assertSee('Credit card terminal is undergoing maintenance', false);

        // With Stripe keys saved and chosen as the processor, cards are paid online.
        Setting::set('card_processor', 'stripe');
        Setting::setSecret('stripe_secret_enc', 'sk_test_123');
        $this->assertContains('card', PaymentGateways::availableKeys(PaymentGateways::AUTOMATIC));
        $this->assertSame('stripe', PaymentGateways::resolve('card'));
    }

    public function test_admin_can_switch_a_gateway_off_and_edit_how_guests_see_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.gateways.index'))->assertOk()->assertSee('Payment Gateways')->assertSee('Credit / Debit Card');
        $this->actingAs($admin)->get(route('admin.gateways.edit', 'card'))->assertOk();

        $this->actingAs($admin)->postJson(route('admin.gateways.toggle', 'card'), ['enabled' => 0])->assertOk();
        $this->assertNull(collect(PaymentGateways::available())->firstWhere('key', 'card'));

        $this->actingAs($admin)->postJson(route('admin.gateways.update', 'card'), [
            'enabled' => 1, 'label' => 'Visa / Mastercard', 'sort' => 1, 'card_processor' => 'none',
        ])->assertOk();

        $card = collect(PaymentGateways::available())->firstWhere('key', 'card');
        $this->assertSame('Visa / Mastercard', $card['label']);
    }

    public function test_admin_builds_a_manual_method_and_the_guest_must_send_its_required_fields(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.payment-methods.create'))->assertOk()->assertSee('What the guest must submit');

        $this->actingAs($admin)->postJson(route('admin.payment-methods.store'), [
            'name' => 'RCBC Deposit', 'is_active' => true,
            'details' => [['label' => 'Bank', 'value' => 'RCBC', 'copyable' => false], ['label' => 'Account number', 'value' => '0000009065823255', 'copyable' => true], ['label' => 'Swift', 'value' => 'RCBCPHMM', 'copyable' => false]],
            'fields' => [
                ['label' => 'Deposit slip', 'type' => 'file', 'required' => true],
                ['label' => 'Sender name', 'type' => 'text', 'required' => true],
                ['label' => 'Paid as', 'type' => 'select', 'required' => false, 'options' => "Full payment\nDeposit"],
            ],
        ])->assertOk();

        $method = PaymentMethod::query()->where('name', 'RCBC Deposit')->firstOrFail();
        $this->assertCount(3, $method->detailRows());
        $this->assertSame(['deposit_slip', 'sender_name', 'paid_as'], array_column($method->guestFields(), 'key'));
        $this->assertTrue($method->requires_proof);

        $booking = $this->booking();
        $this->withSession(['guest_booking' => $booking->booking_number])
            ->get(route('booking.payment', $booking->booking_number))
            ->assertOk()->assertSee('RCBCPHMM')->assertSee('Deposit slip')->assertSee('Sender name');

        $this->withSession(['guest_booking' => $booking->booking_number])
            ->postJson(route('payment.manual'), ['booking_number' => $booking->booking_number, 'gateway' => $method->gatewayKey()])
            ->assertStatus(422)->assertJsonValidationErrors(['fields.deposit_slip', 'fields.sender_name']);

        $this->withSession(['guest_booking' => $booking->booking_number])
            ->post(route('payment.manual'), [
                'booking_number' => $booking->booking_number, 'gateway' => $method->gatewayKey(),
                'fields' => ['deposit_slip' => UploadedFile::fake()->image('slip.jpg'), 'sender_name' => 'Ana Cruz', 'paid_as' => 'Deposit'],
            ], ['Accept' => 'application/json'])->assertOk();

        $payment = $booking->payments()->latest('id')->firstOrFail();
        $this->assertSame('Ana Cruz', collect($payment->field_values)->firstWhere('key', 'sender_name')['value']);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->actingAs($admin)->get(route('admin.payments.show', $payment->id))->assertOk()->assertSee('Submitted by the guest')->assertSee('Ana Cruz');
        $this->actingAs($admin)->get(route('admin.payments.field-file', [$payment, 0]))->assertOk();
    }

    public function test_settings_save_the_deposit_and_senior_rules(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.settings.booking-rules'), [
            'vat_rate' => 12, 'service_charge' => 10,
            'deposit_enabled' => true, 'deposit_percent' => 50,
            'senior_discount_enabled' => true, 'senior_discount_percent' => 20, 'senior_discount_mode' => 'proportional',
            'senior_require_id' => true, 'senior_combine_discounts' => false, 'senior_discount_note' => 'Bring your ID.',
        ])->assertOk();

        $this->assertTrue(BookingRules::depositEnabled());
        $this->assertSame(50.0, BookingRules::depositPercent());
        $this->assertSame(BookingRules::SENIOR_MODE_PROPORTIONAL, BookingRules::seniorMode());
        $this->assertTrue(BookingRules::seniorRequiresId());
        $this->assertSame('Bring your ID.', BookingRules::seniorNote());
    }

    public function test_emoji_icons_show_as_svg_icons_and_amenities_can_be_added_from_the_room_form(): void
    {
        $this->assertSame('snowflake', Icons::nameFor('❄️'));
        $this->assertSame('soup', Icons::nameFor('Breakfast 🍲'));
        $this->assertStringContainsString('<svg', (string) icon('📶'));

        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('admin.amenities.store'), ['name' => 'Free Parking', 'icon' => 'parking'])
            ->assertOk()->assertJsonPath('amenity.icon', 'parking');

        // Same name again just returns the existing amenity (it gets ticked on the room).
        $this->actingAs($admin)->postJson(route('admin.amenities.store'), ['name' => 'Free Parking', 'icon' => 'car'])->assertOk();
        $this->assertSame(1, Amenity::query()->where('name', 'Free Parking')->count());

        $amenity = Amenity::query()->firstWhere('name', 'Free Parking');
        $this->actingAs($admin)->postJson(route('admin.amenities.update', $amenity), ['_method' => 'PUT', 'name' => 'Free Parking Lot', 'icon' => 'car'])->assertOk();
        $this->assertSame('car', $amenity->fresh()->icon);
    }

    public function test_every_template_email_is_written_to_the_email_log(): void
    {
        $roomType = $this->roomType();

        $this->postJson(route('booking.store'), $this->bookingPayload($roomType->id))->assertOk();

        $log = EmailLog::query()->where('template_key', 'booking_received')->firstOrFail();
        $this->assertSame('lola@example.com', $log->recipient);
        $this->assertStringContainsString('pending', $log->subject);

        $this->actingAs($this->admin())->get(route('admin.emails.log'))->assertOk()->assertSee('lola@example.com');
    }
}

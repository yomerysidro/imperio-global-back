<?php

namespace Tests\Feature;

use App\Models\Pack;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
use App\Models\SponsorRelation;
use App\Models\User;
use App\Services\Core\MembershipRankService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MembershipRankTest extends TestCase
{
    use DatabaseTransactions;

    public function test_monthly_services_points_activation_and_structure_are_required_together(): void
    {
        $now = CarbonImmutable::parse('2026-11-15 12:00:00', 'America/Lima');
        $this->travelTo($now);
        $member = $this->member('Socio');
        $front = $this->member('Frontal');
        SponsorRelation::create(['user_code' => $front->uuid, 'sponsor_code' => $member->uuid, 'state' => true]);
        $pack = Pack::create(['title' => 'Servicio de prueba', 'category' => 'Servicio',
            'points' => 100, 'price' => 100, 'discount' => 0, 'state' => true]);
        $product = Pack::create(['title' => 'Producto de prueba', 'category' => 'Producto',
            'points' => 100, 'price' => 100, 'discount' => 0, 'state' => true]);
        $personal = $this->order($pack, $member);
        $group = $this->order($pack, $front);
        $this->points($member, $personal, PaymentOrderPoint::COMPRA, 100);
        $groupPoints = $this->points($member, $group, PaymentOrderPoint::GRUPAL, 400);
        $this->points($member, $this->order($product, $member), PaymentOrderPoint::COMPRA, 10000);

        $service = app(MembershipRankService::class);
        $progress = $service->progress($member, $now);
        $this->assertSame(500.0, $progress['points']);
        $this->assertNull($progress['current']);
        $this->assertFalse($progress['next']['activation_met']);
        $this->assertFalse($progress['next']['structure_met']);

        PaymentLog::create(['payment_order_id' => $personal->id, 'user_id' => $member->id, 'state' => PaymentLog::PAGADO]);
        $now = $now->addMinutes(2);
        $this->travelTo($now);
        $progress = $service->progress($member, $now);
        $this->assertTrue($progress['next']['activation_met']);
        $this->assertNull($progress['current']);

        PaymentLog::create(['payment_order_id' => $group->id, 'user_id' => $front->id, 'state' => PaymentLog::PAGADO]);
        $now = $now->addMinutes(2);
        $this->travelTo($now);
        $progress = $service->progress($member, $now);
        $this->assertSame('Bronce', $progress['current']['title']);
        $this->assertSame('Plata', $progress['next']['title']);
        $this->assertSame(1, DB::table('membership_rank_periods')->where('user_id', $member->id)->count());

        $groupPoints->update(['state' => false]);
        $now = $now->addMinutes(2);
        $this->travelTo($now);
        $this->assertNull($service->progress($member, $now)['current']);
        $now = $now->addMonth()->startOfMonth();
        $this->travelTo($now);
        $progress = $service->progress($member, $now);
        $this->assertSame(0.0, $progress['points']);
        $this->assertNull($progress['current']);
    }

    private function member(string $name): User
    {
        $code = (string) Str::uuid();
        return User::create(['uuid' => $code, 'name' => $name, 'email' => $code.'@example.test',
            'password' => bcrypt('test-password'), 'active' => false, 'is_admin' => false]);
    }

    private function order(Pack $pack, User $member): PaymentOrder
    {
        return PaymentOrder::create(['currency' => 'PEN', 'amount' => 100, 'sponsor_code' => $member->uuid,
            'pack_id' => $pack->id, 'token' => (string) Str::uuid()]);
    }

    private function points(User $member, PaymentOrder $order, string $type, int $points): PaymentOrderPoint
    {
        return PaymentOrderPoint::create(['user_code' => $member->uuid, 'user_id' => $member->id,
            'payment_order_id' => $order->id, 'sponsor_code' => $order->sponsor_code,
            'point' => $points, 'type' => $type, 'payment' => false, 'state' => true]);
    }
}

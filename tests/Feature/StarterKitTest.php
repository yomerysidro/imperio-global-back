<?php

namespace Tests\Feature;

use App\Models\Option;
use App\Models\Pack;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
use App\Models\Product;
use App\Models\SponsorRelation;
use App\Models\User;
use App\Services\Core\AffiliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StarterKitTest extends TestCase
{
    use DatabaseTransactions;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql'
            || !preg_match('/^imperio_mlm_test_[a-z0-9_]+$/i', DB::getDatabaseName())) {
            throw new \RuntimeException('Use php tests/run-isolated-mysql.php.');
        }
    }

    public function test_registration_is_idempotent_and_exposes_the_original_image(): void
    {
        Storage::fake('public');
        $original = getenv('STARTER_KIT_TEST_IMAGE');
        if ($original) $this->assertFileExists($original);
        $bytes = $original ? file_get_contents($original) : base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXfcAAAAASUVORK5CYII='
        );
        Storage::disk('public')->put('test-source/Kit_Inicio.png', $bytes);
        $arguments = ['image' => Storage::disk('public')->path('test-source/Kit_Inicio.png')];
        $this->artisan('packs:register-starter', $arguments)->assertSuccessful();
        $pack = Pack::with('file')->where('title', Pack::STARTER_TITLE)->sole();
        $this->artisan('packs:register-starter', $arguments)->expectsOutput($pack->id)->assertSuccessful();
        $this->assertSame(1, Pack::where('title', Pack::STARTER_TITLE)->count());
        $this->assertGreaterThan(0, $pack->image);
        $extension = getimagesizefromstring($bytes)[2] === IMAGETYPE_JPEG ? 'jpg' : 'png';
        $this->assertSame($extension, $pack->file->extension);
        $this->assertStringEndsWith('.'.$extension, $pack->file->path);
        $this->assertSame($bytes, Storage::disk('public')->get($pack->file->path));
        $response = $this->getJson('/api/v1/pack/search')->assertOk();
        $response->assertJsonFragment(['id' => $pack->id, 'image' => $pack->image]);
        $response->assertJsonFragment(['name' => 'Kit_Inicio.png', 'path' => $pack->file->path]);
    }

    public function test_admin_can_register_a_member_with_the_kit_without_recording_a_purchase(): void
    {
        $admin = User::factory()->create(['uuid' => 'KIT-ROOT', 'is_admin' => true]);
        config(['mlm.root_uuid' => $admin->uuid]);
        Passport::actingAs($admin);
        $kit = Pack::create(['title' => Pack::STARTER_TITLE, 'category' => 'Producto',
            'price' => 50, 'points' => 0, 'discount' => 35, 'state' => true]);

        $result = $this->postJson('/api/v1/users/create-user', [
            'name' => 'Socio Kit',
            'email' => 'socio-kit@example.test',
            'dni' => 'KIT-NEW',
            'sponsor' => $admin->uuid,
            'plan' => $kit->id,
            'password' => 'long-kit-password',
        ])->assertOk()->assertJsonPath('success', true);

        $member = User::findOrFail($result->json('data.user_id'));
        $this->assertSame($kit->id, $member->assignedPack->id);
        $this->assertDatabaseMissing('payment_logs', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('payment_order_points', ['user_code' => $member->uuid]);
        $this->assertDatabaseHas('sponsor_relations', [
            'user_code' => $member->uuid, 'sponsor_code' => $admin->uuid, 'state' => true,
        ]);
    }

    public static function normalPacks(): array
    {
        return [
            ['Pack Consumidor', 'Producto', 100, 45],
            ['Pack Empresario', 'Producto', 300, 45],
            ['Pack Diamante', 'Producto', 1000, 45],
            ['Pack Servicio Digital', 'Servicio', 150, 40],
        ];
    }

    #[DataProvider('normalPacks')]
    public function test_control_replaces_the_assigned_kit_without_financial_records(
        string $title,
        string $category,
        int $points,
        int $discount
    ): void {
        $admin = User::factory()->create(['uuid' => 'KIT-ROOT', 'is_admin' => true]);
        $member = User::factory()->create(['uuid' => 'KIT-MEMBER', 'is_admin' => false]);
        config(['mlm.root_uuid' => $admin->uuid]);
        app(AffiliationService::class)->attach($member, $admin->uuid, 'registration');
        $kit = Pack::create(['title' => Pack::STARTER_TITLE, 'category' => 'Producto',
            'price' => 50, 'points' => 0, 'discount' => 35, 'state' => true]);
        $target = Pack::create(['title' => $title, 'category' => $category, 'price' => 100,
            'points' => $points, 'discount' => $discount, 'state' => true]);
        $product = Product::create(['title' => 'Test product', 'price' => 100,
            'points' => 12, 'stock' => 10, 'state' => true, 'user_id' => $admin->id]);
        $baseline = [PaymentOrder::count(), PaymentLog::count(), PaymentOrderPoint::count()];
        $payload = ['userCode' => $member->uuid, 'userFullName' => $member->name];

        Passport::actingAs($admin);
        $this->postJson('/api/v1/users/modify', $payload + ['packId' => $kit->id])
            ->assertOk()->assertJsonPath('success', true);
        $this->getJson('/api/v1/users/'.$member->id)->assertOk()
            ->assertJsonPath('data.packs_by_category.product.pack.id', $kit->id)
            ->assertJsonPath('data.packs_by_category.product.assigned', true);

        Passport::actingAs($member->fresh());
        $this->getJson('/api/v1/product/search')->assertOk()->assertJsonFragment([
            'id' => $product->id, 'discount_percentage' => 35, 'subtotal' => 100,
            'discount_amount' => 35, 'final_price' => 65, 'points' => 12,
        ]);
        $field = $category === 'Servicio' ? 'serviceId' : 'packId';
        $this->postJson('/api/v1/users/modify', $payload + [$field => $target->id])->assertForbidden();

        Passport::actingAs($admin);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/v1/users/modify', $payload + [$field => $target->id])
                ->assertOk()->assertJsonPath('success', true);
        }
        $slot = $category === 'Servicio' ? 'service' : 'product';
        $otherSlot = $slot === 'service' ? 'product' : 'service';
        $this->getJson('/api/v1/users/'.$member->id)->assertOk()
            ->assertJsonPath("data.packs_by_category.$slot.pack.id", $target->id)
            ->assertJsonPath("data.packs_by_category.$otherSlot.pack", null);
        $this->getJson('/api/v1/users/find-all?plan='.$target->id)->assertOk()
            ->assertJsonPath("data.items.0.packs_by_category.$slot.pack.id", $target->id);
        $this->getJson('/api/v1/users/find-all?plan='.$kit->id)->assertOk()
            ->assertJsonCount(0, 'data.items');
        $this->assertSame($points, (int) $member->fresh()->assignedPack->points);
        $this->assertSame($title, $member->fresh()->package_name);
        $this->assertSame(1, Option::where('option_key', $member->assigned_pack_key)->count());
        $this->assertTrue(SponsorRelation::where('user_code', $member->uuid)
            ->where('sponsor_code', $admin->uuid)->where('state', true)->exists());
        $this->assertSame($baseline, [PaymentOrder::count(), PaymentLog::count(), PaymentOrderPoint::count()]);

        Passport::actingAs($member->fresh());
        $this->getJson('/api/v1/product/search')->assertOk()->assertJsonFragment([
            'id' => $product->id, 'discount_percentage' => $discount, 'subtotal' => 100,
            'discount_amount' => $discount, 'final_price' => 100 - $discount, 'points' => 12,
        ]);
    }
}

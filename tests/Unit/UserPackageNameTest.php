<?php

namespace Tests\Unit;

use App\Models\Pack;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentProductOrder;
use App\Models\User;
use Tests\TestCase;

class UserPackageNameTest extends TestCase
{
    public function test_batched_purchases_preserve_package_names_and_category_order(): void
    {
        config(['mlm.root_uuid' => 'DOSB']);
        $user = new User(['uuid' => 'TEST-MEMBER']);
        $oldPack = new Pack(['title' => 'Servicio anterior', 'category' => 'Servicio']);
        $newPack = new Pack(['title' => 'Servicio actual', 'category' => 'Servicio']);
        $productPack = new Pack(['title' => 'Producto actual', 'category' => 'Producto']);

        $oldLog = new PaymentLog();
        $oldLog->created_at = '2026-01-01 00:00:00';
        $oldLog->setRelation('paymentOrder', (new PaymentOrder())->setRelation('pack', $oldPack));
        $newLog = new PaymentLog();
        $newLog->created_at = '2026-02-01 00:00:00';
        $newLog->setRelation('paymentOrder', (new PaymentOrder())->setRelation('pack', $newPack));
        $productOrder = new PaymentProductOrder();
        $productOrder->created_at = '2026-02-01 00:00:00';
        $productOrder->setRelation('pack', $productPack);

        $this->assertSame(
            'Servicio actual + Producto actual',
            $user->packageNameFromPurchases(collect([$oldLog, $newLog]), collect([$productOrder]))
        );
        $this->assertSame('Sin paquetes registrados', $user->packageNameFromPurchases(collect(), collect()));
        $this->assertSame('Corporativo', (new User(['uuid' => 'DOSB']))->packageNameFromPurchases(collect(), collect()));
    }
}

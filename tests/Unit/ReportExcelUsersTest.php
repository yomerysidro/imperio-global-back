<?php

namespace Tests\Unit;

use App\Exports\ReportExcelUsers;
use PHPUnit\Framework\TestCase;

class ReportExcelUsersTest extends TestCase
{
    public function test_financial_report_separates_product_and_service_residuals(): void
    {
        $export = new ReportExcelUsers([]);

        $this->assertCount(21, $export->headings());
        $this->assertSame('Bonos de Patrocinio', $export->headings()[5]);
        $this->assertSame('Bonos de Patrocinio Cobrados', $export->headings()[6]);
        $this->assertSame('Residual Producto (R)', $export->headings()[7]);
        $this->assertSame('Residual Servicio (RS)', $export->headings()[8]);
        $this->assertSame('Bonos Residual Total', $export->headings()[9]);
        $this->assertSame('Bonos Totales', $export->headings()[10]);
        $this->assertSame('Gran Total', $export->headings()[14]);
        $this->assertSame('Total Generado', $export->headings()[16]);
        $this->assertSame('Pago Pendiente', $export->headings()[17]);
        $this->assertSame('Total Cobrado', $export->headings()[18]);
        $this->assertSame('Disponible por Cobrar', $export->headings()[19]);
        $this->assertSame('Ultima Fecha de Cobro', $export->headings()[20]);
    }
}

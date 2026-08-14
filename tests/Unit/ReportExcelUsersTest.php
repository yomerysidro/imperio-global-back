<?php

namespace Tests\Unit;

use App\Exports\ReportExcelUsers;
use PHPUnit\Framework\TestCase;

class ReportExcelUsersTest extends TestCase
{
    public function test_financial_report_has_the_expected_fourteen_columns(): void
    {
        $export = new ReportExcelUsers([]);

        $this->assertCount(14, $export->headings());
        $this->assertSame('Bonos de Patrocinio', $export->headings()[5]);
        $this->assertSame('Bonos de Patrocinio Cobrados', $export->headings()[6]);
        $this->assertSame('Bonos Residual', $export->headings()[7]);
        $this->assertSame('Bonos Totales', $export->headings()[8]);
        $this->assertSame('Gran Total', $export->headings()[12]);
    }
}

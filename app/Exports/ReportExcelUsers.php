<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class ReportExcelUsers implements FromArray, WithHeadings, WithEvents
{
    protected $users;

    public function __construct(array $users)
    {
        $this->users = $users;
    }

    public function array(): array
    {
        return $this->users;
    }

    public function headings(): array
    {
        return [
            'Nombre y Apellidos', 
            'ID usuario',
            'Estado (Activo/desactivo)', 
            'Plan de Afiliación',
            'Bono personales de afiliados',
            'Bonos de Patrocinio',
            'Bonos de Patrocinio Cobrados',
            'Residual Producto (R)',
            'Residual Servicio (RS)',
            'Bonos Residual Total',
            'Bonos Totales',
            'Puntos por tu plan Actual',
            'Puntos por compras personales',
            'Bono Infinito',
            'Gran Total',
            'Rango',
            'Total Generado',
            'Pago Pendiente',
            'Total Cobrado',
            'Disponible por Cobrar',
            'Ultima Fecha de Cobro',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                // Aplica estilos a la cabecera (fila 1)
                $event->sheet->getStyle('A1:U1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '215EE9'] // azul
                    ],
                    'alignment' => ['horizontal' => 'center'],
                ]);

                // Ancho automático de columnas
                foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K', 'N', 'O'] as $col) {
                    $event->sheet->getStyle("{$col}2:{$col}".$event->sheet->getHighestRow())
                        ->getNumberFormat()->setFormatCode('#,##0.00');
                }

                $lastRow = $event->sheet->getHighestRow();
                $event->sheet->getStyle("A{$lastRow}:U{$lastRow}")->getFont()->setBold(true);

                foreach (range('A', 'U') as $col) {
                    $event->sheet->getDelegate()->getColumnDimension($col)->setAutoSize(true);
                }
            }
        ];
    }
}

<!DOCTYPE html>
<html>
<head>
    <title>Finanzas Imperio</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            margin: 20mm;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 30px;
        }
        p {
            text-align: justify;
            margin: 0;
            color: #888888;
        }
        h3{
            color: #888888;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div>
        <h1>Imperio Global SAC - {{ $mes }} {{ $year }}</h1>

        <h3 >Finanzas Imperio</h3>

        <h4 style="border-bottom: 1px solid #888888;">Comisiones económicas generadas</h4>

        <p style="color: #000000;">Bonos de patrocinio válidos: <b>S/ {{ number_format($patrocinioUserActive, 2) }}</b></p>
        <div style="margin-bottom: 20px;"></div>

        <p style="color: #000000;">Residual de producto (R): <b>S/ {{ number_format($residualProductActive, 2) }}</b></p>
        <p style="color: #000000;">Residual de servicio (RS): <b>S/ {{ number_format($residualServiceActive, 2) }}</b></p>
        <p style="color: #000000;">Bonos residuales totales: <b>S/ {{ number_format($residualUserActive, 2) }}</b></p>
        <div style="margin-bottom: 20px;"></div>

        <p>Bono infinito: <b>S/ {{ number_format($infinityUser, 2) }}</b></p>
        <p>Total de comisiones de la empresa: <b style="color: #000000;">S/ {{ number_format($totalPoint, 2) }}</b></p>
        <p>Movimientos válidos: <b>{{ $ledger['movimientos_validos'] ?? 0 }}</b></p>
        <p>Movimientos anulados: <b>{{ $ledger['movimientos_anulados'] ?? 0 }}</b></p>

    </div>
</body>
</html>

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

        <h4 style="border-bottom: 1px solid #888888;">Resultados de Puntos Personales</h4>

        <p>Puntos Patrocinio Totales de todos los Usuarios Activos = <b>{{ $patrocinioUserActive }}</b></p>
        <p>Puntos Patrocinio Totales de todos los Usuarios Inactivos = <b>{{ $patrocinioUserInactive }}</b></p>
        <p style="color: #000000;">Puntos Patrocinio totales de toda la red de Imperio: <b>{{ $patrocinioUserActive + $patrocinioUserInactive }}</b></p>
        <div style="margin-bottom: 20px;"></div>

        <p>Puntos Residuales Totales de todos los Usuarios Activos = <b>{{ $residualUserActive }}</b></p>
        <p>Puntos Residuales Totales de todos los Usuarios Inactivos = <b>{{ $residualUserInactive }}</b></p>
        <p style="color: #000000;">Puntos Residuales totales de toda la red de Imperio <b>{{ $residualUserActive + $residualUserInactive }}</b></p>
        <div style="margin-bottom: 20px;"></div>

        <p>Puntos Totales en toda la red de imperio  = <b style="color: #000000;">{{ $totalPoint }}</b></p>
        <p>Suma de Bonos infinitos de todos los usuarios Activos: <b>{{ $infinityUser }}</b></p>

    </div>
</body>
</html>
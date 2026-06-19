<!DOCTYPE html>
<html>
<head>
    <title></title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            margin: 20mm;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 5px;
            line-height: 1;
        }
        p {
            text-align: justify;
            margin: 0;
            color: #888888;
        }
        b{
            color: #000000;
        }
        h3{
            color: #888888;
            font-size: 18px;
            margin-top: 5px;
        }
        hr{
            border-color: #DDDDDD;
            border-top: 0;
        }
        footer {
            position: fixed; /* Fija el pie de página en cada página */
            bottom: 0px;     /* Lo coloca en la parte inferior */
            left: 0px;
            right: 0px;
            height: 80px;    /* Altura del pie de página */
            
            /** Estilos adicionales **/
            text-align: center;
            color: #555;
            font-size: 12px;
            line-height: 2; /* Alinea verticalmente el texto */
            padding-left: 30px;
            padding-right: 30px;
        }
        h2{
            margin-top: 5px;
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <main>
        <h1>Imperio Global SAC - {{ $mes }} {{ $year }}</h1>
        <h3 >{{ $address }}</h3>
        <br><br>
        <table width="100%">
            <tbody>
                <tr>
                    <td>
                        <p style="color: #000000"><b>ID de usuario</b></p>
                        <p style="color: #000000">{{ $code }}</p>
                    </td>
                    <td>
                        <p style="color: #000000"><b>Nombres y Apellidos</b></p>
                        <p style="color: #000000">{{ $fullname }}</p>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding: 20px">
                    </td>
                </tr>
                <tr>
                    <td>
                        <b>Resultados de Puntos Personales</b>
                        <hr>
                    </td>
                    <td>
                        <b>Rango</b>
                        <hr>
                    </td>
                </tr>
                <tr>
                    <td>
                        <p>Bonos Patrocinio = <b>{{ $patrocinio }}</b></p>
                        <p>Bonos Residual = <b>{{ $residual + $pointAfiliado + $personalGlobal }}</b></p>
                        <p>Bonos Totales = <b>{{ $patrocinio + $residual + $pointAfiliado + $personalGlobal }}</b></p>
                        <p>Bonos Grupales = <b>{{ $pointGroup }}</b></p>
                        <p>Bonos por plan Actual = <b>{{ $compra + $personal }}</b></p>
                        <p>Bonos Totales = <b>{{ $pointGroup + $compra + $personal }}</b></p>
                        <p>Gran total = <b>{{ $totalPoint }}</b></p>
                    </td>
                    <td style="vertical-align: top;">
                        <p><b>{{ $range }}</b></p>
                        <p>Bono Infinito:  = <b>{{ $infinito }}</b></p>
                        <p>Plan Actual = <b>{{ $plan }}</b></p>
                    </td>
                </tr>
            </tbody>
        </table>
    </main>
    <footer>
        <hr>
        <h2>Gerencia Comercial de Imperio Global</h2>
        <p style="text-align: center;">Ética de la Empresa, Cumple las reglas, protege tu código y asegura tu legado. ¡Tu esfuerzo vale la pena!</p>
    </footer>
</body>
</html>
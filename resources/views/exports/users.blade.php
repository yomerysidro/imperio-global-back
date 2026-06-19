<table>
    <thead>
    <tr>
        <th>Estado (Activo/desactivo)</th>
        <th>Nombre y Apellidos</th>
        <th>ID usuario</th>
        <th>Plan de Afiliación</th>
        <th>Bono por compras personales</th>
        <th>Bonos de Patrocinio</th>
        <th>Bonos Residual</th>
        <th>Bonos Totales</th>
        <th>Puntos Grupales</th>
        <th>Puntos por tu plan Actual</th>
        <th>Puntos Totales</th>
        <th>Gran Total</th>
        <th>Rango</th>
        <th>Número de veces que alcanzó rango</th>
    </tr>
    </thead>
    <tbody>
    @foreach($users as $user)
        <tr>
            <td>{{ $user->estado }}</td>
            <td>{{ $user->nombres }}</td>
            <td>{{ strtoupper($user->codigo) }}</td>
            <td>{{ $user->plan }}</td>
            <td>{{ $user->bono_personal }}</td>
            <td>{{ $user->bono_pratocinio }}</td>
            <td>{{ $user->bono_residual }}</td>
            <td>{{ $user->bono_totales }}</td>
            <td>{{ $user->punto_grupales }}</td>
            <td>{{ $user->punto_plan_actual }}</td>
            <td>{{ $user->punto_plan_actual }}</td>
            <td>{{ $user->gran_total }}</td>
            <td>{{ $user->rango }}</td>
            <td>{{ $user->count_rango }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

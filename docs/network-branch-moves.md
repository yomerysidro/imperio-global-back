# Movimientos administrativos de ramas MLM

## Endpoint

`POST /api/v1/admin/network/moves/confirm`

Requiere autenticación `auth:api`, usuario administrador y las dos banderas de certificación:

- `NETWORK_MOVE_BACKEND_CERTIFIED=true`
- `NETWORK_MOVE_CONFIRMATION_ENABLED=true`

Ambas permanecen deshabilitadas por defecto. Mientras alguna esté apagada, el endpoint devuelve `423` sin consultar ni modificar la genealogía.

Solicitud:

```json
{
  "userCode": "UUID_DEL_SOCIO",
  "newSponsorCode": "UUID_DEL_PATROCINADOR"
}
```

La interfaz envía una sola confirmación. Laravel calcula internamente la rama y vuelve a validar el árbol bajo bloqueos antes de escribir. No se envía ni se requiere un token de simulación.

Una respuesta `200` incluye `operation_id`, `status`, socio movido, patrocinador anterior y nuevo, total de descendientes, total afectado, hash de estructura, ruta ascendente y la genealogía confirmada con patrocinador directo y nivel actual para cada afectado. `403` indica falta de permisos, `422` una validación de árbol/finanzas fallida, `423` confirmaciones no certificadas y `500` un fallo revertido.

## Integridad y persistencia

`sponsor_relations` es la fuente de relaciones actuales; los asientos históricos no se editan. El movimiento cambia únicamente la relación superior del socio solicitado. Las relaciones internas se comparan antes y después. Los niveles son derivados de la profundidad desde la raíz corporativa configurada y se devuelven en la respuesta; no se escriben en asientos financieros.

El servicio serializa movimientos, altas y cálculos financieros mediante el bloqueo transaccional de la raíz corporativa configurada. Verifica autorización, patrocinador destino, cuentas protegidas, rama, rutas ascendentes anterior y nueva, ciclos, duplicados, relaciones huérfanas que intersecten esas rutas, membresía, niveles y relaciones internas. Cualquier error que afecte el movimiento cancela la transacción InnoDB. La auditoría y el cambio de patrocinador se guardan en la misma transacción.

Las cuentas globales fuera del árbol se devuelven como advertencia y se comparan antes/después para asegurar que el movimiento no desconecte a otras cuentas. No se conectan ni eliminan automáticamente. Un defecto desconectado que intercepte la rama o la ruta del patrocinador sí bloquea el movimiento.

## Pedidos pendientes y liquidación

Los pedidos pendientes sin puntos distribuidos no impiden un movimiento. Se exponen como información, con propietario, tipo, estado, importe y estado de generación de puntos. El movimiento no altera pedidos, estados, importes, propietarios, packs ni historiales.

La regla de liquidación es única: una orden sin asientos de comisión inicia su cálculo al confirmarse el pago y toma una sola fotografía lógica de la genealogía vigente dentro de la transacción que serializa el árbol. `PaymentOrder.sponsor_code` sigue siendo el dato histórico de creación y no se usa para mezclar niveles de compensación con esa genealogía vigente.

Si una orden pendiente ya tiene asientos/puntos, el servicio la bloquea para conciliación. Las confirmaciones repetidas de operaciones que ya tienen asientos no añaden beneficiarios después de un movimiento. Las distribuciones completas ocurren dentro de la transacción del flujo financiero; los asientos previos no se recalculan.

## Habilitación

No habilitar las dos banderas hasta que una base MySQL de pruebas separada permita ejecutar las pruebas de integración de ramas, pagos tardíos, comisiones, reintentos, concurrencia, rollback y auditoría. La conexión actual solo tiene permisos sobre `imperiog`, que no debe usarse para pruebas destructivas.

Ver production-dynamic-network.md para el ejecutor aislado y los pendientes de certificación.
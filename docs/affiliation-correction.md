# Corrección de afiliaciones

## Causa y alcance

`UserController::createUser` creaba usuario, verificación, orden, pago y puntos,
pero omitía `sponsor_relations`. `CommissionService::confirmPoint` ya consulta la
relación vigente; no crea genealogía a partir del patrocinador de una orden.
Por eso registrar el patrocinador solamente en `payment_orders` no conecta al socio.

La búsqueda de escrituras de usuarios encontró el registro público,
el administrativo y el comando explícito de inicialización corporativa.
Este último es una excepción deliberada: la raíz no debe tener padre.
Las invitaciones adjuntan cuentas existentes. Compras y activaciones no crean cuentas.
No se inspeccionó un repositorio frontend ni el servidor remoto.

## Implementación

- `AffiliationService::sponsor`: exige transacción, bloquea la raíz configurada,
  resuelve el UUID exacto y comprueba cada ascendente, existencia, padre único y ciclos.
- `AffiliationService::attach`: guarda UUID canónicos, `state=true` y origen;
  repetir la misma asignación activa devuelve la fila existente. Rechaza relaciones
  distintas o inactivas y el autopatrocinio. No modifica asignaciones anteriores.
- Registro administrativo: adjunta la relación antes de cualquier cálculo financiero,
  dentro de su transacción original. Un fallo revierte usuario, relación, orden y pago.
- Registro público: utiliza el mismo servicio, normaliza el DNI y repite la comprobación
  de duplicados bajo el bloqueo compartido con movimientos.
- Confirmación de invitaciones: bloquea el token, valida aceptación booleana,
  utiliza el servicio y cierra las transacciones al rechazar. Conserva restricciones
  originales sobre pagos ya confirmados. No permite reemplazar un padre.
- Verificación de invitaciones: consulta sin abrir una transacción que quedaba pendiente;
  rechaza patrocinadores fuera de la red.
- Consulta del patrocinador: solo utiliza `sponsor_relations`.
- Cancelación de invitaciones: conserva la genealogía establecida; cancelar metadatos
  de invitación ya no desconecta al socio ni su rama.

Se conserva el bloqueo corporativo usado por movimientos y confirmaciones financieras.
Una afiliación posterior a un movimiento consulta los padres vigentes. No se reescriben
descendientes, puntos ni destinatarios históricos.

## Diagnóstico y reparación propuesta

Ejecutar `php artisan mlm:diagnose-affiliations` o agregar `--uuid=<UUID>`.
El comando ejecuta una transacción MySQL READ ONLY y siempre la revierte.
Reporta cuentas sin relación, relaciones inactivas, múltiples filas, ciclos,
padres inexistentes, cadenas desconectadas y posibles IDs internos sin UUID coincidente.
Una referencia numérica que coincide con un UUID existente no puede clasificarse
como ID equivocado sin evidencia externa; los candidatos nunca son asignaciones.
La evidencia individual incluye órdenes, invitaciones y auditorías de movimientos.

El diagnóstico local encontró 12 cuentas desconectadas, 11 sin relación propia
y una con padre desconectado. No se ejecutó reparación alguna.

Plan para cada reparación, sujeto a autorización explícita:

1. Archivar diagnóstico de solo lectura e identificar la cuenta por UUID e ID.
2. Corroborar evidencia original de afiliación (orden/invitación), fechas y auditorías.
   Una orden posterior o un patrocinador histórico no prueban por sí solos la elección
   original. Cualquier conflicto o ausencia de evidencia exige revisión administrativa.
3. Entregar un manifiesto revisable con UUID, patrocinador, IDs de evidencia y motivo.
4. Dentro de una transacción: bloquear raíz, releer evidencia y relación, validar
   la cadena vigente y crear únicamente la relación ausente autorizada.
5. Guardar auditoría durable con operador, evidencia, antes/después e identificador
   único de reparación en la misma transacción. Una repetición idéntica debe ser no-op;
   una diferencia debe abortar. No reutilizar silenciosamente auditorías de movimientos.
6. Validar árbol, ruta, descendientes y ausencia de escrituras financieras antes
   de confirmar. Si falla una validación, revertir todo.

No se incluye un comando de aplicación: primero debe aprobarse el manifiesto y
definirse la auditoría de reparación. No conectar huérfanos a la raíz por defecto.

## Finanzas y pedidos pendientes

No se modificaron motores de comisiones, fórmulas, porcentajes, activación,
servicios, residuales, rangos, bonos ni requisitos de elegibilidad.
El motor central ya consulta la genealogía activa y protege distribuciones iniciadas
frente a reintentos. La corrección suministra la relación faltante antes de invocarlo.
Esto no certifica todos los callbacks de proveedores: requieren pruebas de integración.

No se añaden restricciones por pedidos pendientes. El servicio de movimientos existente
permite pendientes sin distribución y bloquea los que ya tienen puntos/comisiones
iniciados para conciliación. Se conservan órdenes, estados y beneficiarios históricos.

## Validación

Ejecutado: suite Unit, **18 pruebas / 72 aserciones**, sin fallos; sintaxis PHP y
`git diff --check`. Diagnóstico local de solo lectura sobre MySQL con prefijo `ig_`.
También pasaron las dos pruebas de `RegistrationSponsorValidationTest`, con ocho
aserciones: total ejecutado de 20 pruebas y 80 aserciones.

Preparado en `DynamicNetworkTest`, pendiente de ejecución MySQL aislada:
registro bajo raíz; registro múltiple bajo integrante movido; consulta inmediata;
credenciales y consulta autenticada del patrocinador; ruta a raíz; duplicados;
patrocinador inválido/desconectado; rollback por fallo de relación; redes de
50/160/320 descendientes; registro administrativo antes de puntos; elegibilidad
y porcentaje de fixture; conservación de distribución en reintento tras mover;
invitación que intenta cambiar padre y cancelación que conserva genealogía.
Se conservan también pruebas previas de movimientos, auditoría y bloqueo entre conexiones.

Las pruebas usan transacciones y requieren esquema desechable previamente migrado,
sin `migrate:fresh`. `Tests/TestCase.php` bloquea su ejecución contra bases reales.
El runner exige `TEST_MYSQL_DATABASE=imperio_mlm_test_*`; admite conexión mediante
`TEST_MYSQL_HOST`, `PORT`, `USERNAME`, `PASSWORD`. Ejecutar:
`php tests/run-isolated-mysql.php`.

El usuario MySQL local no tiene permisos CREATE DATABASE y no ve ningún esquema
`imperio_mlm_test_*`; el intento de preparar un esquema aislado fue rechazado.
No se ejecutaron las pruebas de integración ni se utilizó la base real como alternativa.
Faltan pruebas de login con emisión real de token Passport, callbacks completos,
servicios/residuales/rangos de extremo a extremo y concurrencia de registro real.

## Despliegue

Cambios de backend, sin nuevas migraciones y sin cambios requeridos en el contrato
frontend. El frontend debe enviar `sponsor` al alta administrativa y `sponsor_code`
al registro público; esto no se verificó desde un navegador.
`MLM_ROOT_UUID` debe corresponder a la raíz real de cada base por separado.
No hay dependencia del nombre de la base ni de los UUID locales.

Se mantiene GitHub Actions. El respaldo de `.env` ahora solo se copia si el archivo
no existe; no reemplaza el `.env` existente del servidor. El workflow conserva
su paso previo de migraciones pendientes: revisarlas antes de desplegar.
No se hizo push, despliegue, acceso al servidor ni modificaciones a `imperio_bd`.
La certificación sobre servidor y la reparación de datos existentes quedan pendientes.

## Revision de conservacion MLM - 2026-11-08

Esta revision sustituye las indicaciones anteriores de despliegue y validacion:
- Se retiraron exclusivamente los archivos nuevos sin versionar ProductionNetworkReadinessTest.php, network-move-worker.php y las dos migraciones 2026_08_11 de esquema legacy/inventario. No se retiro ningun archivo de pruebas o migracion historico versionado.
- Se restauro ServiceTreeManager a la elegibilidad de HEAD: la condicion de actividad agregada en la preparacion reciente no formaba parte de su comportamiento anterior. No se cambiaron porcentajes ni formulas.
- Se corrigio la llamada accidental a $strtoupper en la auditoria de movimientos por lote.
- Se conservaron AffiliationService, altas transaccionales y sponsor_relations vigente, auditoria, proteccion de distribuciones existentes y bloqueos de concurrencia.
- GitHub Actions ya no ejecuta migraciones. El preflight consulta el esquema instalado, informa migraciones pendientes y nunca invoca up() de la migracion de auditoria. Conserva el .env existente.
- Validacion ejecutada en esta revision: Unit 19 pruebas/73 aserciones; RegistrationSponsorValidationTest 2 pruebas/8 aserciones; sintaxis de PHP modificado y git diff --check. No se ejecutaron movimientos, migraciones, consultas de diagnostico sobre bases reales ni despliegues.
- Los pedidos pendientes sin puntos no bloquean. Los registros parciales siguen protegidos para conciliacion individual; esta revision no modifica saldos ni selecciona nuevos beneficiarios para distribuciones historicas.
- No se certifica produccion. Quedan pendientes integracion MySQL aislada, esquema y configuracion reales del servidor, callbacks y verificacion Angular. Las banderas de confirmacion/certificacion permanecen sin cambios.

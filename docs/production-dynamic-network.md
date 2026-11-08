# Despliegue y diagnóstico del backend dinámico

## Estado

NO CERTIFICADO PARA PRODUCCIÓN. Las pruebas unitarias pasan (15 pruebas, 57 aserciones). La verificación de sintaxis y las diez rutas administrativas pasan. Las pruebas MySQL no se ejecutaron: el servidor MySQL80 está activo, pero `user_ig` no puede crear una base aislada (error 1044). No se han ejecutado migraciones ni pruebas destructivas sobre `imperiog`.

## Preparación

Se requiere PHP 8.2 o superior (composer.json), PDO MySQL, MySQL 8 e InnoDB. Usar credenciales propias del servidor. No subir .env, volcados, usuarios, órdenes ni la base local.

1. Instalar dependencias con `composer install --no-dev --optimize-autoloader` en el servidor.
2. Configurar `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY`, conexión MySQL, correo, colas y Passport según la instalación. Utilizar HTTPS.
3. Revisar migraciones pendientes y respaldar la base de destino si ya contiene datos. Ejecutar `php artisan migrate --force` solamente contra la conexión de destino verificada.
4. En una instalación vacía ejecutar `php artisan mlm:initialize-root corporativo@su-dominio --name="Empresa"`. La contraseña se pide de forma oculta. El comando genera una cuenta real, usa exclusión MySQL y no imprime la contraseña. No agrega socios ficticios. Si hay cuentas y falta configuración, se detiene sin alterarlas.
5. Guardar el UUID mostrado en `MLM_ROOT_UUID`. Si la cuenta ya existe, configurar directamente su UUID real. La raíz no puede tener patrocinador activo. Repetir el inicializador con raíz y correo correctos no cambia la cuenta.
6. Ejecutar `php artisan config:cache` y reiniciar procesos persistentes. No ejecutar `config:cache` con una conexión o UUID del entorno local.
7. Mantener `NETWORK_MOVE_BACKEND_CERTIFIED=false` y `NETWORK_MOVE_CONFIRMATION_ENABLED=false` hasta completar la certificación. La API de lectura no requiere esas banderas. La selección de línea vive temporalmente en Angular y no se persiste.

## Migraciones

`2026_11_06_000000` crea `network_move_audits` InnoDB. `2026_11_07_000001` agrega unicidad de `users.uuid`: si existen duplicados, falla sin fusionar cuentas; deben revisarse antes del despliegue.

Se retiraron las reparaciones históricas que imponían usuarios locales, reconstruían patrocinios desde bonos o recalculaban importes antiguos. Sus archivos conservan el nombre para respetar el historial de migraciones, pero no vuelven a modificar datos. La migración inicial de `sponsor_relations` conserva el backfill de invitaciones aceptadas y primeras compras de cuentas existentes; ya no impone una rama corporativa. En una base vacía no crea usuarios ni relaciones. Las migraciones ya aplicadas no se reejecutan ni revierten automáticamente sus cambios anteriores.

No ejecutar rollback de migraciones históricas sobre producción sin revisar su efecto. Los niveles de genealogía se derivan de las relaciones actuales; no se reescriben niveles ni patrocinadores en puntos históricos.

## Pruebas aisladas

Usar una cuenta MySQL exclusiva de pruebas con permiso para crear esquemas `imperio_test_*`. El ejecutor acepta `TEST_MYSQL_HOST`, `TEST_MYSQL_PORT`, `TEST_MYSQL_USERNAME`, `TEST_MYSQL_PASSWORD` como variables del proceso. No guardar secretos en el repositorio.

Ejecutar `php tests/run-isolated-mysql.php`. Crea un esquema aleatorio `imperio_test_` seguido de doce dígitos hexadecimales y lanza PHPUnit sobre ese esquema, ignorando la caché de configuración de producción. Retiene el esquema para diagnóstico y muestra su nombre. Las clases con RefreshDatabase rechazan cualquier conexión que no sea MySQL y no siga ese patrón antes de poder migrar.

La suite nueva contempla cuentas con UUID aleatorios, varias ramas, alta de socio, movimiento y conservación de conexiones internas, auditoría, rechazo de ciclos, rollback forzado y exclusión mediante dos conexiones. Estas pruebas están escritas, pendientes de ejecución MySQL.

Falta completar y superar la matriz financiera de integración: confirmaciones tardías de pedidos de productos/servicios y afiliaciones, beneficiarios y puntos correctos tras movimientos, idempotencia y reintentos, conservación de saldos/pagos, movimientos concurrentes completos y carga con árboles grandes. La exclusión de una segunda conexión no sustituye esas pruebas integrales.

## Política financiera implementada pendiente de certificación

Un pendiente sin puntos no bloquea un movimiento. Al confirmar el pago se usa la genealogía activa bajo bloqueo transaccional de la raíz. Los pedidos y puntos históricos no se reescriben durante el movimiento. Un pendiente con puntos ya generados bloquea el cambio para conciliación: es una anomalía de procesamiento parcial, no un bloqueo por estar pendiente. No certificar este comportamiento hasta probar todos los flujos financieros con MySQL aislado.

Validación adicional: RegistrationSponsorValidationTest pasa (2 pruebas, 8 aserciones). Total ejecutado: 17 pruebas, 65 aserciones. Se retiró también el agregado virtual de 100 puntos por invitado histórico de la cuenta corporativa; los totales se derivan de los asientos existentes, sin escribir saldos.

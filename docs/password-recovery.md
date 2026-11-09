# Recuperacion de contrasena

Los endpoints anteriores se conservan. Angular debe implementar tres pasos:

1. `POST /api/v1/auth/recover-password` con `{"email":"socio@example.com"}`. Guardar `data.validation`. SMTP y `CreateUserMail` siguen enviando el codigo de cuatro digitos, generado por `CodeGenerator` mediante `random_int`. Vence en 15 minutos.
2. `POST /api/v1/auth/validate-code/{validation}` con `{"code":"1234"}`. Guardar `data.recovery_token` en memoria; vence en `data.expires_in` (300 segundos). La contrasena todavia no cambia. La respuesta lleva `Cache-Control: no-store`.
3. `POST /api/v1/auth/reset-password` con:

```json
{
  "validation": "uuid-recibido",
  "recovery_token": "token-recibido-de-64-caracteres",
  "password": "Contrasena-elegida-2026!",
  "password_confirmation": "Contrasena-elegida-2026!"
}
```

La respuesta exitosa conserva el formato `success/data/message`. Mostrar el mensaje y dirigir al login habitual. Usar HTTPS y excluir contrasenas y recovery_token de registros y analitica. No enviar el token en URLs. Los errores de campos son 422, los limites de envio 429 y las fallas de infraestructura 503.

`LoginController::validate` antes generaba `Str::password(12)`, actualizaba el usuario y enviaba `PasswordUserMail`. Ahora entrega una autorizacion aleatoria de 256 bits y guarda solo su SHA-256. El codigo se guarda como HMAC-SHA-256 con APP_KEY, asociado al UUID; CHAR(4) contiene solamente `----`. No se acepta el codigo almacenado por el flujo antiguo: las solicitudes previas al despliegue requieren reenvio.

La cache existente conserva usuario, vencimiento, intentos y la fase actual. Al validar se elimina el hash del codigo y se sustituye por el del token. Cinco errores invalidan la solicitud. Se permite un envio por minuto y cinco por hora por usuario, usando el historial existente de solicitudes, ademas del throttle por IP de las rutas. Un reenvio invalida las solicitudes anteriores, incluso si ya tenian token.

Las operaciones toman un bloqueo de fila del usuario y vuelven a leer la solicitud bajo bloqueo dentro de una transaccion. El cambio usa Hash::make y consume la solicitud en esa misma transaccion; elimina el token en cache y revoca access tokens y refresh tokens Passport del usuario. Tambien rota remember_token. El login actual se conserva. Los JWT ya emitidos se rechazan por la revocacion Passport; no se implementa un cambio global del almacenamiento de sesiones web.

## Antes del despliegue

- Comprobar cache persistente compartida y base con bloqueos de fila/transacciones. Se admiten database, redis, memcached y dynamodb; array sigue prohibido fuera de testing. File se admite con las condiciones siguientes. No se crean tablas ni migraciones. Una perdida de cache rechaza la recuperacion y requiere nuevo codigo.
- Mantener APP_KEY estable entre instancias. Evitar replicas de lectura con retraso para la cache y las consultas de recuperacion.
- Integrar los tres pasos en Angular y coordinar el cambio de contrato de validate-code.
- Ejecutar AccountSecurityTest en una base desechable ya preparada mediante `tests/run-isolated-mysql.php`, sin `--migrate`. No ejecutarlo contra cuentas reales.
- Certificar solicitudes simultaneas, reenvios, vencimiento del token y login HTTP con Passport antes de desplegar.

## Verificacion local

### Cache file en Contabo

Para el servidor unico, mantener `CACHE_STORE=file` y establecer en el `.env` de produccion:

```dotenv
PASSWORD_RECOVERY_FILE_CACHE_SHARED=true
```

Esta opcion confirma que todos los procesos PHP que atienden recuperacion usan el mismo almacenamiento local persistente para datos y bloqueos. No activarla en discos efimeros, contenedores con volumenes independientes ni varios servidores con discos locales distintos. La aplicacion no puede descubrir esa topologia mediante una prueba de escritura.

Antes de activarla, comprobar como `www-data` que `storage/framework/cache/data` existe y permite escribir. `config/cache.php` ya utiliza ese directorio para datos y bloqueos. Si se personaliza `lock_path`, tambien debe existir, permitir escritura y estar compartido por los mismos procesos. Conservar estos directorios entre releases; no limpiar la cache durante recuperaciones en curso.

En cada entrada al flujo se comprueban los directorios y se ejecuta una sonda con claves aleatorias y TTL de 10 segundos: put/get/forget desde repositorios independientes, adquisicion de lock, rechazo de un segundo propietario, liberacion y readquisicion. Las claves de sonda se eliminan; no contienen datos de socios. Si falla alguna comprobacion se conserva el 503. Los bloqueos de fila y las transacciones originales siguen protegiendo las mutaciones de recuperacion.

El workflow existente de GitHub Actions conserva el `.env` del servidor y reconstruye la configuracion con `php artisan optimize`. Configurar la opcion antes del despliegue; no es necesario modificar el workflow. Sus llamadas a `optimize:clear` pueden invalidar recuperaciones pendientes, que requeriran un nuevo codigo. No se ejecutaron el workflow ni comandos remotos durante esta correccion.

Pruebas especificas sin base de datos: `php vendor/phpunit/phpunit/phpunit tests/Feature/RecoveryFileCacheTest.php`. Incluyen modo production, rechazo de array, habilitacion explicita, directorios ausentes y dos procesos PHP que verifican visibilidad de datos y exclusion de bloqueos. Esta comprobacion local no certifica los permisos efectivos ni los montajes del servidor Contabo.

Resultado de esta correccion: sintaxis de controlador, configuracion y prueba correctas; `git diff --check` correcto; Unit 19 pruebas / 73 aserciones; RecoveryFileCacheTest 6 pruebas / 11 aserciones; CorsProductionOriginsTest y ExampleTest de Feature 5 pruebas / 28 aserciones. `php tests/run-isolated-mysql.php --filter=AccountSecurityTest` rechazo la ejecucion por falta de `TEST_MYSQL_DATABASE` seguro. No se certifica el flujo completo con MySQL ni SMTP de produccion.

### Registro previo

Sintaxis PHP, rutas y git diff --check correctos. Suite Unit existente: 19 pruebas, 73 aserciones. Se actualizaron metodos existentes de AccountSecurityTest sin crear pruebas nuevas: flujo valido, error de codigo, reutilizacion, confirmacion incorrecta, token incorrecto, guardado, doble uso, validacion de credenciales nuevas/anteriores, cinco intentos y codigo vencido. Su ejecucion fue bloqueada por la proteccion del repositorio al no tener una base MySQL aislada seleccionada; no se certifica su resultado. No se ejecutaron migraciones ni cambios de datos reales.

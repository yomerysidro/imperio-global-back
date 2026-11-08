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

- Comprobar cache persistente compartida (actualmente CACHE_STORE=database), tablas de cache existentes y base con bloqueos de fila/transacciones. No se crean tablas ni migraciones. Array/file no se admiten para este flujo fuera de testing. Una perdida de cache rechaza la recuperacion y requiere nuevo codigo.
- Mantener APP_KEY estable entre instancias. Evitar replicas de lectura con retraso para la cache y las consultas de recuperacion.
- Integrar los tres pasos en Angular y coordinar el cambio de contrato de validate-code.
- Ejecutar AccountSecurityTest en una base desechable ya preparada mediante `tests/run-isolated-mysql.php`, sin `--migrate`. No ejecutarlo contra cuentas reales.
- Certificar solicitudes simultaneas, reenvios, vencimiento del token y login HTTP con Passport antes de desplegar.

## Verificacion local

Sintaxis PHP, rutas y git diff --check correctos. Suite Unit existente: 19 pruebas, 73 aserciones. Se actualizaron metodos existentes de AccountSecurityTest sin crear pruebas nuevas: flujo valido, error de codigo, reutilizacion, confirmacion incorrecta, token incorrecto, guardado, doble uso, validacion de credenciales nuevas/anteriores, cinco intentos y codigo vencido. Su ejecucion fue bloqueada por la proteccion del repositorio al no tener una base MySQL aislada seleccionada; no se certifica su resultado. No se ejecutaron migraciones ni cambios de datos reales.

# API de genealogía dinámica

Todas las rutas están protegidas con Passport (`auth:api`) y requieren `is_admin`. La genealogía se obtiene de `sponsor_relations` activas y los UUID persistidos en `users`; la selección temporal de Angular no se guarda en el backend.

| Método | Ruta | Uso |
| --- | --- | --- |
| GET | `/api/v1/admin/network/root` | Raíz configurada del entorno |
| GET | `/api/v1/admin/network/genealogy?q=texto&page=1&perPage=50` | Búsqueda paginada por nombre/UUID y árbol existente |
| GET | `/api/v1/admin/network/genealogy/{uuid}` | Perfil, patrocinador, nivel, hijos directos paginados, descendientes totales y ruta ascendente |
| GET | `/api/v1/admin/network/genealogy/{uuid}/children` | Hijos directos reales |
| GET | `/api/v1/admin/network/genealogy/{uuid}/ancestors` | Cadena ascendente real |
| GET | `/api/v1/admin/network/genealogy/{uuid}/descendants` | Descendencia actual |
| POST | `/api/v1/admin/network/moves/preview` | Vista previa opcional; no escribe |
| POST | `/api/v1/admin/network/moves/confirm` | Confirma un movimiento, sujeto a las banderas de certificación |

El detalle de socio incluye `user.uuid`, `sponsor.uuid/name`, `level`, `direct_children_total`, `descendant_count` y `descendants_count` (alias para compatibilidad), `route_ascending` y perfil `active/package_name`. Las listas usan paginación Laravel: `data`, `current_page`, `per_page`, `total`, `last_page`.

Angular puede mantener una secuencia seleccionada en memoria y compararla con `sponsor.uuid`; esa selección no representa relaciones actuales. Para ejecutar un cambio real se envía `POST /moves/confirm` con `{"userCode":"UUID_SOCIO","newSponsorCode":"UUID_PATROCINADOR"}`. El backend calcula la rama, valida ciclos y guarda auditoría junto con el único cambio de patrocinador directo en una transacción. No se aceptan conteos de Angular como autoridad.

Una raíz sin configurar es un error de instalación. La consulta del detalle devuelve 404 si el socio no está conectado a la raíz. Las demás consultas muestran relaciones realmente persistidas y no crean enlaces implícitos desde nombres o datos históricos.

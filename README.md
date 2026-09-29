# stellar-demo
stellar hackaton 30 septiembre 2026

## Estructura

- `backend/` — API PHP (Slim), Stellar SDK, esquema de base de datos
  (Postgres/MySQL/SQLite). Ver `backend/README` (este mismo contrato de
  endpoints aplica) y `backend/Dockerfile` para el deploy.
- `frontend/` — Angular (Josue). Consume los endpoints documentados abajo.

<img width="854" height="685" alt="image" src="https://github.com/user-attachments/assets/8baf3798-94f2-422e-9ceb-0b6009f81741" />


POST /faucet
Request:


{ "destination": "GABC...56chars" }
Response (200):


{ "ok": true, "hash": "abc123..." }
Errores: 400 missing_destination, 400 invalid_destination, 500 issuer_not_configured, 422 horizon_rejected (con result_codes.transaction y result_codes.operations)

POST /cajas
Requiere login de administrador: header `Authorization: Bearer <token>` (el token sale de POST /auth/login). La caja queda asociada a la organizacion del admin (campo organizacion_id en la respuesta de GET /cajas/{id}).
Request:


{ "nombre": "Caja 4to Medio B", "curso": "4to Medio B", "public_key": "GBTC...", "umbral": 2 }
Response (201):


{ "ok": true, "id": 1 }
Errores: 400 missing_fields, 401 unauthorized

GET /cajas/{id}
Response (200):


{
  "ok": true,
  "caja": {
    "id": 1,
    "nombre": "Caja 4to Medio B",
    "curso": "4to Medio B",
    "public_key": "GBTC...",
    "umbral": 2,
    "creado_en": "2026-09-22 13:23:51",
    "members": [
      { "id": 1, "nombre": "Josue Valenzuela", "public_key": "GB2K..." }
    ]
  }
}
Error: 404 caja_not_found

GET /cajas/{id}/estado
Consulta en vivo a Stellar (Horizon) — balance real y ultimas transacciones de la cuenta de la caja. No usa la base de datos para esto (solo lee cajas.public_key).
Response (200):


{
  "ok": true,
  "public_key": "GBTC...",
  "balances": [
    { "asset": "XLM", "balance": "9999.9998900" }
  ],
  "transacciones": [
    { "tipo": "payment", "creado_en": "2026-09-25T13:43:52Z", "transaction_hash": "9952434a..." }
  ]
}
Error: 404 caja_not_found, 404 account_not_found_on_stellar (la caja existe en la BD pero su public_key todavia no tiene cuenta creada/fondeada en Stellar)

POST /cajas/{id}/members
Request:


{ "nombre": "Tomas B.", "public_key": "GASV..." }
Response (201):


{ "ok": true, "id": 2 }
POST /cajas/{id}/proposals
Request:


{ "destino": "GDN4...", "monto": "20", "motivo": "Arriendo de cancha", "xdr": "AAAAAgAAAAA..." }
Response (201):


{ "ok": true, "id": 1 }
GET /cajas/{id}/proposals
Response (200):


{
  "ok": true,
  "proposals": [
    {
      "id": 1,
      "caja_id": 1,
      "destino": "GDN4...",
      "monto": "20",
      "motivo": "Arriendo de cancha",
      "xdr": "AAAAAgAAAAA...",
      "estado": "pendiente",
      "creado_en": "2026-09-22 13:23:51",
      "signatures": [
        { "member_id": 1, "firmado_en": "2026-09-22 13:23:51" }
      ]
    }
  ]
}
POST /proposals/{id}/signatures
Request:


{ "member_id": 2, "xdr": "AAAAAgAAAAA...FIRMADO" }
Response (201):


{ "ok": true }

POST /proposals/{id}/ejecutar
Cuando ya se juntaron las firmas necesarias (segun umbral de la caja), toma el XDR final guardado y lo envia de verdad a Stellar. Marca la propuesta como "ejecutada".
Response (200):


{ "ok": true, "hash": "6c14d4bf..." }
Errores: 404 proposal_not_found, 404 caja_not_found, 409 proposal_already_executed, 409 not_enough_signatures (incluye firmas y umbral), 422 horizon_rejected (con result_codes)

PUT /cajas/{id}
Requiere Bearer token de un admin de la organizacion de la caja (o de cualquier admin si la caja no tiene organizacion, legacy).
Request:


{ "nombre": "Caja 4to Medio B (renombrada)" }
Response (200):


{ "ok": true }
Errores: 400 missing_fields, 401 unauthorized, 403 forbidden, 404 caja_not_found

DELETE /cajas/{id}
Misma regla de auth que PUT /cajas/{id}. Borra primero las firmas de sus propuestas, las propuestas, los miembros y los eventos de la caja, y despues la caja.
Response (200):


{ "ok": true }
Errores: 401 unauthorized, 403 forbidden, 404 caja_not_found

PUT /cajas/{id}/members/{memberId}
Misma regla de auth que PUT /cajas/{id}.
Request:


{ "nombre": "Tomas B." }
Response (200):


{ "ok": true }
Errores: 400 missing_fields, 401 unauthorized, 403 forbidden, 404 caja_not_found, 404 member_not_found

DELETE /cajas/{id}/members/{memberId}
Misma regla de auth que PUT /cajas/{id}.
Response (200):


{ "ok": true }
Errores: 401 unauthorized, 403 forbidden, 404 caja_not_found, 404 member_not_found

POST /usuarios
Registra una persona (nombre + clave publica, email y password opcionales para login). Si la clave ya existe, devuelve el mismo id con ya_existia: true.
Request:


{ "nombre": "Tomas B.", "public_key": "GASV...", "email": "tomas@x.cl", "password": "clave123" }
Response (201):


{ "ok": true, "id": 1 }
Errores: 400 missing_fields, 409 email_duplicado

GET /usuarios
Requiere Bearer token de admin (cualquier organizacion).
Response (200):


{ "ok": true, "usuarios": [ { "id": 1, "nombre": "Tomas B.", "public_key": "GASV...", "email": "tomas@x.cl", "creado_en": "2026-09-28 20:00:00" } ] }
Error: 401 unauthorized

PUT /usuarios/{id}
Requiere Bearer token de admin.
Request:


{ "nombre": "Tomas B. (editado)" }
Response (200):


{ "ok": true }
Errores: 400 missing_fields, 401 unauthorized, 404 usuario_not_found

DELETE /usuarios/{id}
Requiere Bearer token de admin. Borra tambien sus sesiones.
Response (200):


{ "ok": true }
Errores: 401 unauthorized, 404 usuario_not_found

POST /auth/usuario/login
Login de usuario (abierto, sin admin). El token se usa como `Authorization: Bearer <token>` en GET /usuarios/me/cajas. Dura 7 dias.
Request:


{ "email": "tomas@x.cl", "password": "clave123" }
Response (200):


{ "ok": true, "token": "1507cc...", "usuario": { "id": 1, "nombre": "Tomas B.", "public_key": "GASV...", "email": "tomas@x.cl" } }
Errores: 400 missing_fields, 401 credenciales_invalidas

GET /usuarios/me/cajas
Requiere Bearer token de USUARIO (el de POST /auth/usuario/login, no el de admin). Devuelve las cajas donde el usuario es miembro (match por public_key en members).
Response (200):


{ "ok": true, "cajas": [ { "id": 1, "organizacion_id": 1, "nombre": "Caja 4B", "curso": "4B", "public_key": "GBTC...", "umbral": 2, "creado_en": "..." } ] }
Error: 401 unauthorized

GET /usuarios/{public_key}
Response (200):


{ "ok": true, "usuario": { "id": 1, "nombre": "Tomas B.", "public_key": "GASV...", "creado_en": "2026-09-28 20:00:00" } }
Error: 404 usuario_not_found

GET /cajas/{id}/trazabilidad
Historial de acciones de la caja (caja creada, miembro agregado, propuesta creada/firmada/ejecutada, faucet). detalle viene como objeto JSON ya decodificado.
Response (200):


{
  "ok": true,
  "eventos": [
    {
      "id": 3,
      "caja_id": 1,
      "tipo": "propuesta_firmada",
      "detalle": { "proposal_id": "1", "member_id": 2 },
      "creado_en": "2026-09-28 20:05:00"
    },
    {
      "id": 2,
      "caja_id": 1,
      "tipo": "propuesta_creada",
      "detalle": { "proposal_id": "1", "destino": "GDN4...", "monto": "20", "motivo": "Arriendo" },
      "creado_en": "2026-09-28 20:00:00"
    }
  ]
}
Error: 404 caja_not_found

Tipos de evento: caja_creada, caja_editada, caja_eliminada, miembro_agregado, miembro_editado, miembro_eliminado, propuesta_creada, propuesta_firmada, propuesta_ejecutada, faucet_pedido, usuario_creado, usuario_editado, usuario_eliminado, usuario_login, organizacion_creada, organizacion_editada, organizacion_eliminada, admin_creado, admin_editado, admin_eliminado, admin_login (los que no son de una caja van con caja_id null).

POST /auth/login
Request:


{ "email": "admin@curso.cl", "password": "secreto123" }
Response (200):


{ "ok": true, "token": "97f0dbe9...", "email": "admin@curso.cl", "organizacion_id": 1 }
Errores: 400 missing_fields, 401 credenciales_invalidas
El token se manda en las rutas protegidas como `Authorization: Bearer <token>`. Dura 7 dias.

POST /organizaciones
Crea la organizacion y su primer admin de una vez (bootstrap abierto: cualquiera puede registrar una organizacion nueva).
Request:


{ "nombre": "Curso 4to Medio B", "email": "admin@curso.cl", "password": "secreto123" }
Response (201):


{ "ok": true, "id": 1, "admin_id": 1 }
Errores: 400 missing_fields, 409 email_ya_registrado

POST /organizaciones/{id}/admins
Agrega un admin a la organizacion. Requiere Bearer token de un admin DE ESA organizacion.
Request:


{ "email": "segundo@curso.cl", "password": "otro12345" }
Response (201):


{ "ok": true, "id": 2 }
Errores: 400 missing_fields, 401 unauthorized, 403 forbidden (token de otra organizacion), 409 email_ya_registrado

GET /organizaciones/{id}/admins
Requiere Bearer token de un admin de esa organizacion.
Response (200):


{ "ok": true, "admins": [ { "id": 1, "organizacion_id": 1, "email": "admin@curso.cl", "creado_en": "2026-09-28 20:49:32" } ] }
Errores: 401 unauthorized, 403 forbidden

PUT /organizaciones/{id}/admins/{adminId}
Cambia el password de un admin de la organizacion. Requiere Bearer token de un admin DE ESA organizacion.
Request:


{ "password": "nueva12345" }
Response (200):


{ "ok": true }
Errores: 400 missing_fields, 401 unauthorized, 403 forbidden, 404 admin_not_found

DELETE /organizaciones/{id}/admins/{adminId}
Requiere Bearer token de un admin de esa organizacion. No deja a la organizacion sin admins.
Response (200):


{ "ok": true }
Errores: 401 unauthorized, 403 forbidden, 404 admin_not_found, 409 ultimo_admin

GET /organizaciones/{id}/cajas
Requiere Bearer token de un admin de esa organizacion.
Response (200):


{ "ok": true, "cajas": [ { "id": 1, "organizacion_id": 1, "nombre": "Caja 4B", "curso": "4B", "public_key": "GBTC...", "umbral": 2, "creado_en": "..." } ] }
Errores: 401 unauthorized, 403 forbidden

PUT /organizaciones/{id}
Requiere Bearer token de un admin de esa organizacion.
Request:


{ "nombre": "Curso 4to Medio B (renombrado)" }
Response (200):


{ "ok": true }
Errores: 400 missing_fields, 401 unauthorized, 403 forbidden

DELETE /organizaciones/{id}
Requiere Bearer token de un admin de esa organizacion. Solo borra si la organizacion no tiene cajas; si tiene, responde 409. Al borrar elimina las sesiones y los admins de la org.
Response (200):


{ "ok": true }
Errores: 401 unauthorized, 403 forbidden, 409 organizacion_tiene_cajas

Que requiere login y que no:
- CON login de admin (Bearer token de POST /auth/login): POST /cajas, PUT /cajas/{id}, DELETE /cajas/{id}, PUT /cajas/{id}/members/{memberId}, DELETE /cajas/{id}/members/{memberId}, GET /usuarios, PUT /usuarios/{id}, DELETE /usuarios/{id}, POST /organizaciones/{id}/admins, GET /organizaciones/{id}/admins, PUT /organizaciones/{id}/admins/{adminId}, DELETE /organizaciones/{id}/admins/{adminId}, GET /organizaciones/{id}/cajas, PUT /organizaciones/{id}, DELETE /organizaciones/{id}.
- CON login de usuario (Bearer token de POST /auth/usuario/login): GET /usuarios/me/cajas.
- SIN login (no rompe el flujo del miembro que aprueba gastos): GET /cajas/{id}, GET /cajas/{id}/estado, GET /cajas/{id}/proposals, GET /cajas/{id}/trazabilidad, POST /cajas/{id}/members, POST /cajas/{id}/proposals, POST /proposals/{id}/signatures, POST /proposals/{id}/ejecutar, POST /faucet, POST /usuarios, GET /usuarios/{public_key}, POST /organizaciones, POST /auth/login, POST /auth/usuario/login.

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
Request:


{ "nombre": "Caja 4to Medio B", "curso": "4to Medio B", "public_key": "GBTC...", "umbral": 2 }
Response (201):


{ "ok": true, "id": 1 }
Error: 400 missing_fields

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

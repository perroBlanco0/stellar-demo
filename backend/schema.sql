-- Esquema mínimo para "Caja con gobernanza".
-- Stellar sigue siendo la fuente de verdad del dinero; esto solo da contexto
-- legible (nombres, motivos, estado de firmas) que la cadena no guarda.

-- Multi-tenant: cada caja pertenece a una organizacion, y cada
-- organizacion tiene sus propios administradores (login con token).
CREATE TABLE IF NOT EXISTS organizaciones (
    id SERIAL PRIMARY KEY,
    nombre TEXT NOT NULL,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS admin_users (
    id SERIAL PRIMARY KEY,
    organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id) ON DELETE CASCADE,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS admin_sessions (
    id SERIAL PRIMARY KEY,
    admin_user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    token TEXT NOT NULL UNIQUE,
    expira_en TIMESTAMPTZ NOT NULL
);

CREATE TABLE IF NOT EXISTS cajas (
    id SERIAL PRIMARY KEY,
    organizacion_id INTEGER REFERENCES organizaciones(id),
    nombre TEXT NOT NULL,
    curso TEXT,
    public_key TEXT NOT NULL,
    umbral INTEGER NOT NULL,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS members (
    id SERIAL PRIMARY KEY,
    caja_id INTEGER NOT NULL REFERENCES cajas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    public_key TEXT NOT NULL,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS proposals (
    id SERIAL PRIMARY KEY,
    caja_id INTEGER NOT NULL REFERENCES cajas(id) ON DELETE CASCADE,
    destino TEXT NOT NULL,
    monto TEXT NOT NULL,
    motivo TEXT,
    xdr TEXT NOT NULL,
    estado TEXT NOT NULL DEFAULT 'pendiente',
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS proposal_signatures (
    id SERIAL PRIMARY KEY,
    proposal_id INTEGER NOT NULL REFERENCES proposals(id) ON DELETE CASCADE,
    member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    firmado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Personas que usan la app; un usuario puede ser member de varias cajas.
CREATE TABLE IF NOT EXISTS usuarios (
    id SERIAL PRIMARY KEY,
    nombre TEXT NOT NULL,
    public_key TEXT NOT NULL UNIQUE,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Trazabilidad: cada accion relevante queda registrada con su contexto.
CREATE TABLE IF NOT EXISTS eventos (
    id SERIAL PRIMARY KEY,
    caja_id INTEGER REFERENCES cajas(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL,
    detalle TEXT,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_members_caja ON members(caja_id);
CREATE INDEX IF NOT EXISTS idx_proposals_caja ON proposals(caja_id);
CREATE INDEX IF NOT EXISTS idx_signatures_proposal ON proposal_signatures(proposal_id);
CREATE INDEX IF NOT EXISTS idx_eventos_caja ON eventos(caja_id);
CREATE INDEX IF NOT EXISTS idx_admin_users_org ON admin_users(organizacion_id);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_token ON admin_sessions(token);

-- Migracion para BD existentes: agrega organizacion_id a cajas si falta.
ALTER TABLE cajas ADD COLUMN IF NOT EXISTS organizacion_id INTEGER REFERENCES organizaciones(id);

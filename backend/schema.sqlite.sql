-- Version SQLite de schema.sql, solo para desarrollo local / mock sin Postgres.
-- Misma forma de datos, sintaxis adaptada (AUTOINCREMENT en vez de SERIAL,
-- TEXT con default CURRENT_TIMESTAMP en vez de TIMESTAMPTZ).

CREATE TABLE IF NOT EXISTS organizaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id) ON DELETE CASCADE,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    token TEXT NOT NULL UNIQUE,
    expira_en TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS cajas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organizacion_id INTEGER REFERENCES organizaciones(id),
    nombre TEXT NOT NULL,
    curso TEXT,
    public_key TEXT NOT NULL,
    umbral INTEGER NOT NULL,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    caja_id INTEGER NOT NULL REFERENCES cajas(id) ON DELETE CASCADE,
    nombre TEXT NOT NULL,
    public_key TEXT NOT NULL,
    puede_aprobar INTEGER NOT NULL DEFAULT 1,
    usuario_id INTEGER REFERENCES usuarios(id),
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS proposals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    caja_id INTEGER NOT NULL REFERENCES cajas(id) ON DELETE CASCADE,
    destino TEXT NOT NULL,
    monto TEXT NOT NULL,
    motivo TEXT,
    xdr TEXT NOT NULL,
    estado TEXT NOT NULL DEFAULT 'pendiente',
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS proposal_signatures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proposal_id INTEGER NOT NULL REFERENCES proposals(id) ON DELETE CASCADE,
    member_id INTEGER NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    firmado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    public_key TEXT NOT NULL UNIQUE,
    email TEXT,
    password_hash TEXT,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS usuario_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    token TEXT NOT NULL UNIQUE,
    expira_en TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    caja_id INTEGER REFERENCES cajas(id) ON DELETE CASCADE,
    tipo TEXT NOT NULL,
    detalle TEXT,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_members_caja ON members(caja_id);
CREATE INDEX IF NOT EXISTS idx_proposals_caja ON proposals(caja_id);
CREATE INDEX IF NOT EXISTS idx_signatures_proposal ON proposal_signatures(proposal_id);
CREATE INDEX IF NOT EXISTS idx_eventos_caja ON eventos(caja_id);
CREATE INDEX IF NOT EXISTS idx_admin_users_org ON admin_users(organizacion_id);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_token ON admin_sessions(token);
CREATE INDEX IF NOT EXISTS idx_usuario_sessions_token ON usuario_sessions(token);

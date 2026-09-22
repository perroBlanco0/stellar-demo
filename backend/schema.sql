-- Esquema mínimo para "Caja con gobernanza".
-- Stellar sigue siendo la fuente de verdad del dinero; esto solo da contexto
-- legible (nombres, motivos, estado de firmas) que la cadena no guarda.

CREATE TABLE IF NOT EXISTS cajas (
    id SERIAL PRIMARY KEY,
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

CREATE INDEX IF NOT EXISTS idx_members_caja ON members(caja_id);
CREATE INDEX IF NOT EXISTS idx_proposals_caja ON proposals(caja_id);
CREATE INDEX IF NOT EXISTS idx_signatures_proposal ON proposal_signatures(proposal_id);

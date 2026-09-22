-- Version MySQL de schema.sql (para MySQL Workbench / servidor MySQL local).
-- Misma forma de datos; sintaxis adaptada: AUTO_INCREMENT en vez de SERIAL,
-- TIMESTAMP DEFAULT CURRENT_TIMESTAMP en vez de TIMESTAMPTZ/NOW(), InnoDB
-- para que las FOREIGN KEY (ON DELETE CASCADE) funcionen.

CREATE TABLE IF NOT EXISTS cajas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    curso VARCHAR(255),
    public_key VARCHAR(64) NOT NULL,
    umbral INT NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caja_id INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    public_key VARCHAR(64) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (caja_id) REFERENCES cajas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS proposals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caja_id INT NOT NULL,
    destino VARCHAR(64) NOT NULL,
    monto VARCHAR(32) NOT NULL,
    motivo VARCHAR(255),
    xdr TEXT NOT NULL,
    estado VARCHAR(32) NOT NULL DEFAULT 'pendiente',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (caja_id) REFERENCES cajas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS proposal_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    member_id INT NOT NULL,
    firmado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_members_caja ON members(caja_id);
CREATE INDEX idx_proposals_caja ON proposals(caja_id);
CREATE INDEX idx_signatures_proposal ON proposal_signatures(proposal_id);

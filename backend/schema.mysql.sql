-- Version MySQL de schema.sql (para MySQL Workbench / servidor MySQL local).
-- Misma forma de datos; sintaxis adaptada: AUTO_INCREMENT en vez de SERIAL,
-- TIMESTAMP DEFAULT CURRENT_TIMESTAMP en vez de TIMESTAMPTZ/NOW(), InnoDB
-- para que las FOREIGN KEY (ON DELETE CASCADE) funcionen.

CREATE TABLE IF NOT EXISTS organizaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizacion_id INT NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organizacion_id) REFERENCES organizaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    expira_en TIMESTAMP NOT NULL,
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cajas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organizacion_id INT,
    nombre VARCHAR(255) NOT NULL,
    curso VARCHAR(255),
    public_key VARCHAR(64) NOT NULL,
    umbral INT NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organizacion_id) REFERENCES organizaciones(id)
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

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    public_key VARCHAR(64) NOT NULL UNIQUE,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caja_id INT,
    tipo VARCHAR(64) NOT NULL,
    detalle TEXT,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (caja_id) REFERENCES cajas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_members_caja ON members(caja_id);
CREATE INDEX idx_proposals_caja ON proposals(caja_id);
CREATE INDEX idx_signatures_proposal ON proposal_signatures(proposal_id);
CREATE INDEX idx_eventos_caja ON eventos(caja_id);
CREATE INDEX idx_admin_users_org ON admin_users(organizacion_id);
CREATE INDEX idx_admin_sessions_token ON admin_sessions(token);

-- Migracion para BD existentes (correr una vez si cajas ya existe sin la columna):
-- ALTER TABLE cajas ADD COLUMN organizacion_id INT, ADD FOREIGN KEY (organizacion_id) REFERENCES organizaciones(id);

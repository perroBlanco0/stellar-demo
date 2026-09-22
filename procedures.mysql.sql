-- Stored Procedures para la Caja con gobernanza (MySQL).
-- Correr DESPUES de schema.mysql.sql, en el mismo schema (ej. mydb).

DELIMITER $$

-- ---------- cajas ----------

CREATE PROCEDURE sp_crear_caja(
    IN p_nombre VARCHAR(255),
    IN p_curso VARCHAR(255),
    IN p_public_key VARCHAR(64),
    IN p_umbral INT,
    OUT p_id INT
)
BEGIN
    INSERT INTO cajas (nombre, curso, public_key, umbral)
    VALUES (p_nombre, p_curso, p_public_key, p_umbral);
    SET p_id = LAST_INSERT_ID();
END $$

CREATE PROCEDURE sp_obtener_caja(IN p_caja_id INT)
BEGIN
    SELECT * FROM cajas WHERE id = p_caja_id;
END $$

-- ---------- members ----------

CREATE PROCEDURE sp_agregar_member(
    IN p_caja_id INT,
    IN p_nombre VARCHAR(255),
    IN p_public_key VARCHAR(64),
    OUT p_id INT
)
BEGIN
    INSERT INTO members (caja_id, nombre, public_key)
    VALUES (p_caja_id, p_nombre, p_public_key);
    SET p_id = LAST_INSERT_ID();
END $$

CREATE PROCEDURE sp_listar_members(IN p_caja_id INT)
BEGIN
    SELECT id, nombre, public_key FROM members WHERE caja_id = p_caja_id;
END $$

-- ---------- proposals ----------

CREATE PROCEDURE sp_crear_proposal(
    IN p_caja_id INT,
    IN p_destino VARCHAR(64),
    IN p_monto VARCHAR(32),
    IN p_motivo VARCHAR(255),
    IN p_xdr TEXT,
    OUT p_id INT
)
BEGIN
    INSERT INTO proposals (caja_id, destino, monto, motivo, xdr, estado)
    VALUES (p_caja_id, p_destino, p_monto, p_motivo, p_xdr, 'pendiente');
    SET p_id = LAST_INSERT_ID();
END $$

CREATE PROCEDURE sp_listar_proposals(IN p_caja_id INT)
BEGIN
    SELECT * FROM proposals WHERE caja_id = p_caja_id ORDER BY id DESC;
END $$

-- ---------- proposal_signatures ----------

CREATE PROCEDURE sp_firmar_proposal(
    IN p_proposal_id INT,
    IN p_member_id INT,
    IN p_xdr TEXT
)
BEGIN
    UPDATE proposals SET xdr = p_xdr WHERE id = p_proposal_id;

    INSERT INTO proposal_signatures (proposal_id, member_id, firmado_en)
    VALUES (p_proposal_id, p_member_id, CURRENT_TIMESTAMP);
END $$

CREATE PROCEDURE sp_listar_signatures(IN p_proposal_id INT)
BEGIN
    SELECT member_id, firmado_en FROM proposal_signatures WHERE proposal_id = p_proposal_id;
END $$

DELIMITER ;

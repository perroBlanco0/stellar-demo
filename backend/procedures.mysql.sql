-- Stored Procedures para la Caja con gobernanza (MySQL).
-- Correr DESPUES de schema.mysql.sql, en el mismo schema (ej. mydb).

DELIMITER $$

-- ---------- cajas ----------

CREATE PROCEDURE sp_crear_caja(
    IN p_organizacion_id INT,
    IN p_nombre VARCHAR(255),
    IN p_curso VARCHAR(255),
    IN p_public_key VARCHAR(64),
    IN p_umbral INT,
    OUT p_id INT
)
BEGIN
    INSERT INTO cajas (organizacion_id, nombre, curso, public_key, umbral)
    VALUES (p_organizacion_id, p_nombre, p_curso, p_public_key, p_umbral);
    SET p_id = LAST_INSERT_ID();
END $$

CREATE PROCEDURE sp_obtener_caja(IN p_caja_id INT)
BEGIN
    SELECT * FROM cajas WHERE id = p_caja_id;
END $$

-- ---------- members ----------

-- Cambio de firma (usuario_id para miembros vinculados a usuarios):
-- hay que borrar el SP viejo antes de recrearlo.
DROP PROCEDURE IF EXISTS sp_agregar_member $$
CREATE PROCEDURE sp_agregar_member(
    IN p_caja_id INT,
    IN p_nombre VARCHAR(255),
    IN p_public_key VARCHAR(64),
    IN p_usuario_id INT,
    OUT p_id INT
)
BEGIN
    INSERT INTO members (caja_id, nombre, public_key, usuario_id)
    VALUES (p_caja_id, p_nombre, p_public_key, p_usuario_id);
    SET p_id = LAST_INSERT_ID();
END $$

-- Cambio de columnas devueltas (puede_aprobar + usuario_id).
DROP PROCEDURE IF EXISTS sp_listar_members $$
CREATE PROCEDURE sp_listar_members(IN p_caja_id INT)
BEGIN
    SELECT id, nombre, public_key, puede_aprobar, usuario_id
    FROM members WHERE caja_id = p_caja_id;
END $$

CREATE PROCEDURE sp_obtener_miembro(IN p_id INT)
BEGIN
    SELECT * FROM members WHERE id = p_id;
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

-- ---------- usuarios ----------

-- Cambio de firma (email + password_hash para el login de usuarios):
-- hay que borrar el SP viejo antes de recrearlo.
DROP PROCEDURE IF EXISTS sp_crear_usuario $$
CREATE PROCEDURE sp_crear_usuario(
    IN p_nombre VARCHAR(255),
    IN p_public_key VARCHAR(64),
    IN p_email VARCHAR(255),
    IN p_password_hash VARCHAR(255),
    OUT p_id INT
)
BEGIN
    INSERT INTO usuarios (nombre, public_key, email, password_hash)
    VALUES (p_nombre, p_public_key, p_email, p_password_hash);
    SET p_id = LAST_INSERT_ID();
END $$

CREATE PROCEDURE sp_obtener_usuario(IN p_public_key VARCHAR(64))
BEGIN
    SELECT * FROM usuarios WHERE public_key = p_public_key;
END $$

CREATE PROCEDURE sp_obtener_usuario_por_email(IN p_email VARCHAR(255))
BEGIN
    SELECT * FROM usuarios WHERE email = p_email;
END $$

CREATE PROCEDURE sp_obtener_usuario_por_id(IN p_id INT)
BEGIN
    SELECT * FROM usuarios WHERE id = p_id;
END $$

CREATE PROCEDURE sp_listar_usuarios()
BEGIN
    SELECT id, nombre, public_key, email, creado_en FROM usuarios ORDER BY id;
END $$

CREATE PROCEDURE sp_actualizar_usuario(
    IN p_id INT,
    IN p_nombre VARCHAR(255)
)
BEGIN
    UPDATE usuarios SET nombre = p_nombre WHERE id = p_id;
END $$

CREATE PROCEDURE sp_eliminar_usuario(IN p_id INT)
BEGIN
    DELETE FROM usuarios WHERE id = p_id;
END $$

CREATE PROCEDURE sp_crear_sesion_usuario(
    IN p_usuario_id INT,
    IN p_token VARCHAR(128),
    IN p_expira_en TIMESTAMP
)
BEGIN
    INSERT INTO usuario_sessions (usuario_id, token, expira_en)
    VALUES (p_usuario_id, p_token, p_expira_en);
END $$

CREATE PROCEDURE sp_obtener_sesion_usuario_por_token(IN p_token VARCHAR(128))
BEGIN
    SELECT u.id, u.nombre, u.public_key, u.email
    FROM usuario_sessions s
    JOIN usuarios u ON u.id = s.usuario_id
    WHERE s.token = p_token AND s.expira_en > CURRENT_TIMESTAMP;
END $$

CREATE PROCEDURE sp_listar_cajas_por_public_key(IN p_public_key VARCHAR(64))
BEGIN
    SELECT c.id, c.organizacion_id, c.nombre, c.curso, c.public_key, c.umbral, c.creado_en
    FROM cajas c
    JOIN members m ON m.caja_id = c.id
    WHERE m.public_key = p_public_key
    ORDER BY c.id;
END $$

CREATE PROCEDURE sp_listar_cajas_por_organizacion(IN p_organizacion_id INT)
BEGIN
    SELECT id, organizacion_id, nombre, curso, public_key, umbral, creado_en
    FROM cajas WHERE organizacion_id = p_organizacion_id ORDER BY id;
END $$

CREATE PROCEDURE sp_actualizar_caja(
    IN p_id INT,
    IN p_nombre VARCHAR(255)
)
BEGIN
    UPDATE cajas SET nombre = p_nombre WHERE id = p_id;
END $$

-- Los hijos (signatures/proposals/members/eventos) se borran en PHP,
-- dentro de la misma transaccion, antes de llamar a este SP.
CREATE PROCEDURE sp_eliminar_caja(IN p_id INT)
BEGIN
    DELETE FROM cajas WHERE id = p_id;
END $$

-- Cambio de firma (puede_aprobar para apagar/encender aprobaciones).
DROP PROCEDURE IF EXISTS sp_actualizar_miembro $$
CREATE PROCEDURE sp_actualizar_miembro(
    IN p_id INT,
    IN p_nombre VARCHAR(255),
    IN p_puede_aprobar TINYINT
)
BEGIN
    UPDATE members SET nombre = p_nombre, puede_aprobar = p_puede_aprobar WHERE id = p_id;
END $$

CREATE PROCEDURE sp_eliminar_miembro(IN p_id INT)
BEGIN
    DELETE FROM members WHERE id = p_id;
END $$

-- ---------- organizaciones / admins / sesiones ----------

CREATE PROCEDURE sp_crear_organizacion(
    IN p_nombre VARCHAR(255),
    OUT p_id INT
)
BEGIN
    INSERT INTO organizaciones (nombre)
    VALUES (p_nombre);
    SET p_id = LAST_INSERT_ID();
END $$

CREATE PROCEDURE sp_crear_admin_user(
    IN p_organizacion_id INT,
    IN p_email VARCHAR(255),
    IN p_password_hash VARCHAR(255),
    OUT p_id INT
)
BEGIN
    INSERT INTO admin_users (organizacion_id, email, password_hash)
    VALUES (p_organizacion_id, p_email, p_password_hash);
    SET p_id = LAST_INSERT_ID();
END $$

CREATE PROCEDURE sp_listar_admins(IN p_organizacion_id INT)
BEGIN
    SELECT id, organizacion_id, email, creado_en
    FROM admin_users WHERE organizacion_id = p_organizacion_id;
END $$

CREATE PROCEDURE sp_obtener_admin_por_email(IN p_email VARCHAR(255))
BEGIN
    SELECT * FROM admin_users WHERE email = p_email;
END $$

CREATE PROCEDURE sp_crear_sesion(
    IN p_admin_user_id INT,
    IN p_token VARCHAR(128),
    IN p_expira_en TIMESTAMP
)
BEGIN
    INSERT INTO admin_sessions (admin_user_id, token, expira_en)
    VALUES (p_admin_user_id, p_token, p_expira_en);
END $$

CREATE PROCEDURE sp_obtener_sesion_por_token(IN p_token VARCHAR(128))
BEGIN
    SELECT au.id, au.email, au.organizacion_id
    FROM admin_sessions s
    JOIN admin_users au ON au.id = s.admin_user_id
    WHERE s.token = p_token AND s.expira_en > CURRENT_TIMESTAMP;
END $$

CREATE PROCEDURE sp_actualizar_organizacion(
    IN p_id INT,
    IN p_nombre VARCHAR(255)
)
BEGIN
    UPDATE organizaciones SET nombre = p_nombre WHERE id = p_id;
END $$

-- admin_sessions y admin_users se borran en PHP antes de llamar a este SP.
CREATE PROCEDURE sp_eliminar_organizacion(IN p_id INT)
BEGIN
    DELETE FROM organizaciones WHERE id = p_id;
END $$

CREATE PROCEDURE sp_actualizar_admin(
    IN p_id INT,
    IN p_password_hash VARCHAR(255)
)
BEGIN
    UPDATE admin_users SET password_hash = p_password_hash WHERE id = p_id;
END $$

CREATE PROCEDURE sp_eliminar_admin(IN p_id INT)
BEGIN
    DELETE FROM admin_users WHERE id = p_id;
END $$

CREATE PROCEDURE sp_contar_admins(IN p_organizacion_id INT)
BEGIN
    SELECT COUNT(*) AS total FROM admin_users WHERE organizacion_id = p_organizacion_id;
END $$

-- ---------- eventos (trazabilidad) ----------

CREATE PROCEDURE sp_registrar_evento(
    IN p_caja_id INT,
    IN p_tipo VARCHAR(64),
    IN p_detalle TEXT
)
BEGIN
    INSERT INTO eventos (caja_id, tipo, detalle)
    VALUES (p_caja_id, p_tipo, p_detalle);
END $$

CREATE PROCEDURE sp_listar_eventos(IN p_caja_id INT)
BEGIN
    SELECT * FROM eventos WHERE caja_id = p_caja_id ORDER BY id DESC;
END $$

DELIMITER ;

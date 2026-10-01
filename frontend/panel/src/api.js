// Llamadas al backend. Una función por endpoint del contrato del README.
// En dev usamos el proxy de Vite (/api) para esquivar CORS; en producción
// llamamos directo al backend de Render (debe estar en CORS_ALLOWED_ORIGIN).
const API = import.meta.env.DEV ? '/api' : 'https://stellar-demo-backend.onrender.com';

async function req(metodo, ruta, cuerpo, claveToken = 'admin_token') {
  const headers = {};
  if (cuerpo) headers['Content-Type'] = 'application/json';

  // Las llamadas llevan el token de la sesión que corresponda
  // (admin por defecto; los endpoints de usuario piden 'usuario_token').
  const token = localStorage.getItem(claveToken);
  if (token) headers['Authorization'] = 'Bearer ' + token;

  try {
    const res = await fetch(API + ruta, {
      method: metodo,
      headers,
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
    });
    const datos = await res.json().catch(() => ({}));
    return { estado: res.status, datos };
  } catch (e) {
    return { estado: 0, datos: { ok: false, error: 'sin_conexion' } };
  }
}

export const despertar = () => req('GET', '/');
export const verCaja = (id) => req('GET', `/cajas/${id}`);
export const verEstado = (id) => req('GET', `/cajas/${id}/estado`);
export const verPropuestas = (id) => req('GET', `/cajas/${id}/proposals`);
export const crearCaja = (nombre, curso, publicKey, umbral) =>
  req('POST', '/cajas', { nombre, curso, public_key: publicKey, umbral });
export const agregarMiembro = (cajaId, nombre, publicKey) =>
  req('POST', `/cajas/${cajaId}/members`, { nombre, public_key: publicKey });
export const agregarMiembroPorUsuario = (cajaId, usuarioId) =>
  req('POST', `/cajas/${cajaId}/members`, { usuario_id: usuarioId });
export const crearPropuesta = (cajaId, destino, monto, motivo, xdr) =>
  req('POST', `/cajas/${cajaId}/proposals`, { destino, monto, motivo, xdr });
export const firmarPropuesta = (propuestaId, memberId, xdr) =>
  req('POST', `/proposals/${propuestaId}/signatures`, { member_id: memberId, xdr });
export const ejecutarPropuesta = (propuestaId) =>
  req('POST', `/proposals/${propuestaId}/ejecutar`, {});
export const pedirFondos = (destino) => req('POST', '/faucet', { destination: destino });

// Administración de organizaciones (la creación de cajas ya va con token
// porque req() lo adjunta solo cuando existe en localStorage).
export const entrarAdmin = (email, password) => req('POST', '/auth/login', { email, password });
export const crearOrganizacion = (nombre, email, password) =>
  req('POST', '/organizaciones', { nombre, email, password });
export const agregarAdmin = (orgId, email, password) =>
  req('POST', `/organizaciones/${orgId}/admins`, { email, password });
export const listarAdmins = (orgId) => req('GET', `/organizaciones/${orgId}/admins`);
export const listarOrganizaciones = () => req('GET', '/organizaciones');
export const actualizarAdmin = (orgId, adminId, password) =>
  req('PUT', `/organizaciones/${orgId}/admins/${adminId}`, { password });
export const cambiarRolAdmin = (orgId, adminId, rol) =>
  req('PUT', `/organizaciones/${orgId}/admins/${adminId}`, { rol });
export const eliminarAdmin = (orgId, adminId) =>
  req('DELETE', `/organizaciones/${orgId}/admins/${adminId}`);

// Organización y sus cajas (todo con token de administrador).
export const listarCajasOrganizacion = (orgId) => req('GET', `/organizaciones/${orgId}/cajas`);
export const actualizarOrganizacion = (orgId, nombre) =>
  req('PUT', `/organizaciones/${orgId}`, { nombre });
export const eliminarOrganizacion = (orgId) => req('DELETE', `/organizaciones/${orgId}`);
export const actualizarCaja = (id, nombre) => req('PUT', `/cajas/${id}`, { nombre });
export const eliminarCaja = (id) => req('DELETE', `/cajas/${id}`);
export const actualizarMiembro = (cajaId, memberId, nombre) =>
  req('PUT', `/cajas/${cajaId}/members/${memberId}`, { nombre });
export const toggleAprobacionMiembro = (cajaId, memberId, puedeAprobar) =>
  req('PUT', `/cajas/${cajaId}/members/${memberId}`, { puede_aprobar: puedeAprobar });
export const eliminarMiembro = (cajaId, memberId) =>
  req('DELETE', `/cajas/${cajaId}/members/${memberId}`);

// Usuarios registrados y su sesión propia (distinta de la de admin).
export const listarUsuarios = () => req('GET', '/usuarios');
export const crearUsuario = (nombre, publicKey, email, password) =>
  req('POST', '/usuarios', { nombre, public_key: publicKey, email, password });
export const actualizarUsuario = (id, nombre) => req('PUT', `/usuarios/${id}`, { nombre });
export const eliminarUsuario = (id) => req('DELETE', `/usuarios/${id}`);
export const entrarUsuario = (email, password) =>
  req('POST', '/auth/usuario/login', { email, password });
export const misCajas = () => req('GET', '/usuarios/me/cajas', undefined, 'usuario_token');

// Recuperar clave por código al correo (sirve para usuarios y admins).
export const recuperarClave = (email) => req('POST', '/auth/recuperar', { email });
export const cambiarClave = (email, codigo, password) =>
  req('POST', '/auth/cambiar-clave', { email, codigo, password });

// Historial de acciones de una caja (lectura pública).
export const listarTrazabilidad = (cajaId) => req('GET', `/cajas/${cajaId}/trazabilidad`);

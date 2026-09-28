// Llamadas al backend. Una función por endpoint del contrato del README.
// En dev usamos el proxy de Vite (/api) para esquivar CORS; en producción
// llamamos directo al backend de Render (debe estar en CORS_ALLOWED_ORIGIN).
const API = import.meta.env.DEV ? '/api' : 'https://stellar-demo-backend.onrender.com';

async function req(metodo, ruta, cuerpo) {
  const headers = {};
  if (cuerpo) headers['Content-Type'] = 'application/json';

  // Si hay sesión de administrador, todas las llamadas la llevan.
  const token = localStorage.getItem('admin_token');
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

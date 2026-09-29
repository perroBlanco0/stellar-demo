<script setup>
import { ref, onMounted } from 'vue';
import {
  verCaja, verEstado, verPropuestas, agregarMiembro, agregarMiembroPorUsuario,
  toggleAprobacionMiembro, listarUsuarios, crearPropuesta,
  firmarPropuesta, ejecutarPropuesta, pedirFondos, listarTrazabilidad,
} from './api';
import {
  generarClaves, activarCuenta, prepararCaja, agregarFirmante,
  construirXdrPago, firmarXdr, activosDeCuenta,
  clavePublicaValida, claveSecretaValida,
} from './stellar';
import { confirmar, listo, falla, avisar } from './avisos';

const props = defineProps({ id: Number, despertando: Boolean });

const caja = ref(null);
const estado = ref(null);        // { balances, transacciones }
const sinActivar = ref(false);
const propuestas = ref([]);
const eventos = ref([]);         // historial de la caja (trazabilidad)
const activos = ref([]);         // activos reales de la cuenta (con emisor)
const cargando = ref(true);
const error = ref('');

// formularios
const nuevoMiembro = ref({ nombre: '', publica: '', secretaGenerada: '', claveCaja: '' });
const nuevaPropuesta = ref({ destino: '', monto: '', motivo: '', activoIdx: 0 });
const aprobacion = ref(null);    // { propuesta, memberId, clave }
const ocupado = ref(false);

// Sesión de administrador (para listar usuarios y prender/apagar aprobaciones)
const haySesionAdmin = ref(false);
const usuariosRegistrados = ref([]);   // usuarios aún no miembros de esta caja
const usuarioSeleccionado = ref('');

// Sesión de usuario registrado: si es miembro y su clave de aprobación
// quedó recordada al registrarse, aprueba con un clic.
const miembroSesion = ref(null);       // miembro de esta caja que calza con la sesión
const claveRecordada = ref('');        // clave_aprobacion_<public_key> de localStorage

function claveMaestra() {
  return localStorage.getItem('caja_secreta_' + props.id) || '';
}

function registrarReciente() {
  if (!caja.value) return;
  const lista = JSON.parse(localStorage.getItem('cajas_recientes') || '[]');
  const nueva = [
    { id: Number(caja.value.id), nombre: caja.value.nombre },
    ...lista.filter((c) => Number(c.id) !== Number(caja.value.id)),
  ].slice(0, 8);
  localStorage.setItem('cajas_recientes', JSON.stringify(nueva));
  window.dispatchEvent(new Event('cajas-recientes-cambio'));
}

function textoError(datos) {
  const mapa = {
    caja_not_found: 'No se encontró la caja.',
    account_not_found_on_stellar: 'La caja aún no está activada en la red.',
    missing_fields: 'Faltan datos por completar.',
    not_enough_signatures: 'Aún faltan aprobaciones.',
    proposal_already_executed: 'Esa solicitud ya fue ejecutada.',
    horizon_rejected: 'La red rechazó la operación.',
    invalid_destination: 'La cuenta de destino no es válida.',
    issuer_not_configured: 'El servicio de fondos no está disponible.',
    sin_conexion: 'Sin conexión con el servicio. Reintenta en un momento.',
    destino_no_existe: 'La cuenta de destino no existe y no puede recibir este activo.',
    aprobacion_desactivada: 'Este miembro tiene la aprobación desactivada.',
    usuario_not_found: 'No se encontró el usuario.',
    miembro_duplicado: 'Esa persona ya es miembro de esta caja.',
    member_not_found: 'No se encontró al miembro.',
    proposal_not_found: 'No se encontró la solicitud.',
    unauthorized: 'La sesión de administrador venció. Entra de nuevo.',
    forbidden: 'No tienes permiso para esta acción.',
  };
  return mapa[datos.error] || 'Ocurrió un error (' + (datos.error || 'desconocido') + ').';
}

function cortar(clave) {
  return clave && clave.length > 14 ? clave.slice(0, 6) + '…' + clave.slice(-6) : clave;
}

function fechaBonita(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return isNaN(d) ? iso : d.toLocaleString('es-CL', { dateStyle: 'medium', timeStyle: 'short' });
}

// Las firmas pueden venir como 'signatures' (member_id) o 'firmas'
// (public_key + created_at), según el backend. Leemos las dos.
function firmasDeLista(p) {
  return p.signatures || p.firmas || [];
}

function firmasDe(p) {
  return firmasDeLista(p).length;
}

function yaFirmo(p, memberId) {
  const m = (caja.value?.members || []).find((x) => x.id === memberId);
  return firmasDeLista(p).some(
    (s) => s.member_id === memberId || (m && s.public_key && s.public_key === m.public_key)
  );
}

// Nombres de los miembros que ya aprobaron esta solicitud.
function nombresQueAprobaron(p) {
  return firmasDeLista(p)
    .map((s) => {
      const m = (caja.value?.members || []).find(
        (x) => x.id === s.member_id || (s.public_key && x.public_key === s.public_key)
      );
      return m ? m.nombre : null;
    })
    .filter(Boolean);
}

// El campo puede no venir en backends antiguos: ahí todos aprueban.
function puedeAprobar(m) {
  if (m.puede_aprobar === undefined || m.puede_aprobar === null) return true;
  return !!Number(m.puede_aprobar);
}

// Quienes pueden aparecer en el "¿Quién aprueba?": sin firmar y con
// aprobación prendida (los apagados no pueden firmar).
function miembrosSinFirmar(p) {
  return (caja.value?.members || []).filter((m) => !yaFirmo(p, m.id) && puedeAprobar(m));
}

function listoParaEjecutar(p) {
  return p.estado === 'pendiente' && firmasDe(p) >= (caja.value?.umbral || 1);
}

// ---- Historial: tipos de evento en palabras simples ----
const TIPOS_EVENTO = {
  caja_creada: 'Caja creada',
  caja_editada: 'Caja renombrada',
  caja_eliminada: 'Caja eliminada',
  miembro_agregado: 'Miembro agregado',
  miembro_editado: 'Miembro renombrado',
  miembro_eliminado: 'Miembro eliminado',
  propuesta_creada: 'Solicitud de gasto creada',
  propuesta_firmada: 'Aprobación registrada',
  propuesta_ejecutada: 'Gasto ejecutado',
  faucet_pedido: 'Fondos de prueba pedidos',
  usuario_creado: 'Usuario registrado',
  usuario_editado: 'Usuario renombrado',
  usuario_eliminado: 'Usuario eliminado',
  usuario_login: 'Usuario entró',
  organizacion_creada: 'Organización creada',
  organizacion_editada: 'Organización renombrada',
  organizacion_eliminada: 'Organización eliminada',
  admin_creado: 'Administrador agregado',
  admin_editado: 'Contraseña de administrador actualizada',
  admin_eliminado: 'Administrador eliminado',
  admin_login: 'Administrador entró',
};

function tituloEvento(tipo) {
  return TIPOS_EVENTO[tipo] || (tipo || '').replace(/_/g, ' ');
}

// Datos clave del detalle, sin campos técnicos (claves, hashes, ids).
function resumenEvento(e) {
  const d = e.detalle || {};
  const partes = [];
  if (d.nombre) partes.push(d.nombre);
  if (d.curso) partes.push(d.curso);
  if (d.email) partes.push(d.email);
  if (d.monto) partes.push('Monto ' + d.monto);
  if (d.destino) partes.push('a ' + cortar(d.destino));
  if (d.motivo) partes.push('«' + d.motivo + '»');
  if (d.umbral) partes.push(d.umbral + ' por gasto');
  return partes.join(' · ');
}

// Cruza la sesión de usuario con los miembros de la caja: si calza su
// clave pública, puede aprobar con un clic usando la clave recordada.
function cargarSesionUsuario() {
  const guardada = localStorage.getItem('usuario_sesion');
  const sesion = guardada ? JSON.parse(guardada) : null;
  const pk = sesion?.public_key;
  miembroSesion.value = pk
    ? (caja.value?.members || []).find((m) => m.public_key === pk) || null
    : null;
  claveRecordada.value = pk ? localStorage.getItem('clave_aprobacion_' + pk) || '' : '';
}

// Lista de usuarios registrados para el agregado directo (solo con
// sesión de admin: GET /usuarios la exige). Filtramos los que ya son
// miembros de esta caja por su clave pública.
async function cargarUsuariosRegistrados() {
  if (!haySesionAdmin.value) return;
  const r = await listarUsuarios();
  if (r.datos.ok) {
    const pks = new Set((caja.value?.members || []).map((m) => m.public_key));
    usuariosRegistrados.value = (r.datos.usuarios || []).filter((u) => !pks.has(u.public_key));
  }
}

async function cargarTodo() {
  cargando.value = true;
  error.value = '';
  try {
    const r = await verCaja(props.id);
    if (!r.datos.ok) {
      error.value = textoError(r.datos);
      caja.value = null;
      return;
    }
    caja.value = r.datos.caja;
    registrarReciente();

    const e = await verEstado(props.id);
    sinActivar.value = e.datos.error === 'account_not_found_on_stellar';
    estado.value = e.datos.ok ? e.datos : { balances: [], transacciones: [] };

    const p = await verPropuestas(props.id);
    propuestas.value = p.datos.ok ? p.datos.proposals : [];

    const t = await listarTrazabilidad(props.id);
    eventos.value = t.datos.ok ? (t.datos.eventos || []) : [];

    cargarSesionUsuario();
    await cargarUsuariosRegistrados();

    activos.value = [];
    if (e.datos.ok) {
      try {
        activos.value = await activosDeCuenta(caja.value.public_key);
      } catch (err) {
        /* la lista de activos del formulario queda vacía */
      }
    }
  } finally {
    cargando.value = false;
  }
}

// Activación manual (cuando la caja existe pero aún no tiene cuenta en la red).
async function activar() {
  const quiere = await confirmar('¿Activar la caja?', 'La caja se fondea y queda lista para operar.');
  if (!quiere) return;
  ocupado.value = true;
  try {
    if (!(await activarCuenta(caja.value.public_key))) {
      falla('No se pudo activar', 'No se pudo activar la caja. Reintenta en un momento.');
      return;
    }
    const secreta = claveMaestra();
    let detalle = '';
    if (secreta) {
      try {
        await prepararCaja(caja.value.public_key, secreta, caja.value.umbral);
      } catch (e) {
        detalle = 'Su configuración de aprobaciones quedó pendiente.';
      }
    } else {
      detalle = 'Ojo: sin la clave maestra no se pueden inscribir aprobadores en la red.';
    }
    await listo('Caja activada', detalle || undefined);
  } finally {
    ocupado.value = false;
  }
}

async function fondos() {
  const quiere = await confirmar('¿Pedir fondos de prueba?', 'Se enviará saldo de prueba a esta caja.');
  if (!quiere) return;
  ocupado.value = true;
  try {
    const r = await pedirFondos(caja.value.public_key);
    if (r.datos.ok) {
      await listo('Fondos enviados', 'Los fondos de prueba ya van en camino a la caja.');
    } else {
      falla('No se pudo', textoError(r.datos));
    }
  } finally {
    ocupado.value = false;
  }
}

// ---- Miembros ----
function generarClavesMiembro() {
  const c = generarClaves();
  nuevoMiembro.value.publica = c.publica;
  nuevoMiembro.value.secretaGenerada = c.secreta;
}

async function agregar() {
  error.value = '';
  const m = nuevoMiembro.value;
  if (!m.nombre.trim() || !clavePublicaValida(m.publica)) {
    avisar('Revisa los datos', 'El miembro necesita nombre y una clave pública válida.');
    return;
  }
  const quiere = await confirmar('¿Agregar miembro?', m.nombre.trim() + ' podrá aprobar gastos de esta caja.');
  if (!quiere) return;
  ocupado.value = true;
  try {
    // Si tenemos la clave maestra, inscribimos al miembro como aprobador en la red.
    const secreta = claveMaestra() || m.claveCaja.trim();
    let pendienteRed = false;
    if (secreta) {
      try {
        await agregarFirmante(caja.value.public_key, secreta, m.publica);
      } catch (e) {
        pendienteRed = true;
      }
    } else {
      pendienteRed = true;
    }
    const r = await agregarMiembro(props.id, m.nombre.trim(), m.publica);
    if (!r.datos.ok) {
      falla('No se pudo', textoError(r.datos));
      return;
    }
    // Si se generaron claves aquí, la de aprobación se muestra en el aviso
    // (después de cerrar, la página recarga y ya no se vuelve a mostrar).
    const secretaMiembro = m.secretaGenerada;
    nuevoMiembro.value = { nombre: '', publica: '', secretaGenerada: '', claveCaja: '' };
    const nota = pendienteRed
      ? 'Quedó registrado, pero su inscripción como aprobador en la red quedó pendiente.'
      : '';
    const claveHtml = secretaMiembro
      ? '<div style="word-break:break-all;font-size:13px">Clave de aprobación (entrégala solo a esa persona):<br><code>' +
        secretaMiembro + '</code></div>'
      : '';
    await listo('Miembro agregado', [nota, claveHtml].filter(Boolean).join('<br>') || undefined);
  } finally {
    ocupado.value = false;
  }
}

// Agregar por usuario registrado: el servidor no tiene su clave de
// aprobación, así que el aviso le dice cómo entrar para aprobar.
async function agregarPorUsuario() {
  error.value = '';
  const u = usuariosRegistrados.value.find((x) => x.id === usuarioSeleccionado.value);
  if (!u) {
    avisar('Falta elegir', 'Elige un usuario registrado.');
    return;
  }
  const quiere = await confirmar('¿Agregar miembro?', u.nombre + ' podrá aprobar gastos de esta caja.');
  if (!quiere) return;
  ocupado.value = true;
  try {
    // Si tenemos la clave maestra, lo inscribimos como aprobador en la red.
    const secreta = claveMaestra() || nuevoMiembro.value.claveCaja.trim();
    let pendienteRed = false;
    if (secreta) {
      try {
        await agregarFirmante(caja.value.public_key, secreta, u.public_key);
      } catch (e) {
        pendienteRed = true;
      }
    } else {
      pendienteRed = true;
    }
    const r = await agregarMiembroPorUsuario(props.id, u.id);
    if (!r.datos.ok) {
      falla('No se pudo', textoError(r.datos));
      return;
    }
    usuarioSeleccionado.value = '';
    const nota = pendienteRed
      ? 'Quedó registrado, pero su inscripción como aprobador en la red quedó pendiente.<br>'
      : '';
    await listo(
      'Miembro agregado',
      nota + 'El miembro aprueba entrando con su correo en Acceso → Mis cajas.'
    );
  } finally {
    ocupado.value = false;
  }
}

// Prender/apagar la aprobación de un miembro (solo con sesión de admin).
async function toggleAprobacion(m) {
  const nuevo = !puedeAprobar(m);
  const quiere = await confirmar(
    nuevo ? '¿Activar la aprobación?' : '¿Desactivar la aprobación?',
    nuevo
      ? m.nombre + ' volverá a poder aprobar gastos.'
      : m.nombre + ' no podrá aprobar gastos hasta que la actives de nuevo.'
  );
  if (!quiere) return;
  ocupado.value = true;
  try {
    const r = await toggleAprobacionMiembro(props.id, m.id, nuevo);
    if (!r.datos.ok) {
      (r.estado === 401 || r.estado === 403 ? avisar : falla)('No se pudo', textoError(r.datos));
      return;
    }
    await listo(
      nuevo ? 'Aprobación activada' : 'Aprobación desactivada',
      nuevo ? m.nombre + ' ya puede aprobar gastos.' : m.nombre + ' quedó sin poder aprobar.'
    );
  } finally {
    ocupado.value = false;
  }
}

// ---- Propuestas ----
async function proponer() {
  error.value = '';
  const p = nuevaPropuesta.value;
  const activo = activos.value[p.activoIdx];
  if (!clavePublicaValida(p.destino)) {
    avisar('Destino inválido', 'La cuenta de destino no es válida.');
    return;
  }
  if (!p.monto || Number(p.monto) <= 0) {
    avisar('Monto inválido', 'Ingresa un monto válido.');
    return;
  }
  if (!activo) {
    avisar('Sin activos', 'La caja no tiene activos disponibles todavía.');
    return;
  }
  const quiere = await confirmar(
    '¿Crear la solicitud?',
    p.monto + ' ' + activo.codigo + ' a ' + cortar(p.destino) + (p.motivo ? ' · ' + p.motivo : '')
  );
  if (!quiere) return;
  ocupado.value = true;
  try {
    const xdr = await construirXdrPago(
      caja.value.public_key, p.destino.trim(), p.monto, activo.codigo, activo.emisor
    );
    const r = await crearPropuesta(props.id, p.destino.trim(), String(p.monto), p.motivo.trim(), xdr);
    if (!r.datos.ok) {
      falla('No se pudo', textoError(r.datos));
      return;
    }
    nuevaPropuesta.value = { destino: '', monto: '', motivo: '', activoIdx: 0 };
    await listo('Solicitud creada', 'Ya puede ser aprobada por los miembros.');
  } catch (e) {
    falla('No se pudo', e.message === 'destino_no_existe' ? textoError({ error: e.message }) : 'No se pudo armar la solicitud.');
  } finally {
    ocupado.value = false;
  }
}

// ---- Aprobar y ejecutar ----
async function aprobar() {
  error.value = '';
  const a = aprobacion.value;
  if (!a.memberId) {
    avisar('Falta elegir', 'Elige quién aprueba.');
    return;
  }
  if (!claveSecretaValida(a.clave)) {
    avisar('Clave inválida', 'La clave de aprobación no es válida.');
    return;
  }
  const quiere = await confirmar('¿Confirmar tu aprobación?', 'La solicitud quedará aprobada a tu nombre.');
  if (!quiere) return;
  if (await registrarFirma(a.propuesta, a.memberId, a.clave)) aprobacion.value = null;
}

// Un clic para el usuario logueado que es miembro y tiene su clave de
// aprobación recordada: firma directo, sin elegir nombre ni pegar nada.
function puedoAprobarDirecto(p) {
  return !!(
    miembroSesion.value &&
    claveRecordada.value &&
    puedeAprobar(miembroSesion.value) &&
    !yaFirmo(p, miembroSesion.value.id)
  );
}

async function aprobarMio(p) {
  error.value = '';
  const quiere = await confirmar('¿Confirmar tu aprobación?', 'La solicitud quedará aprobada a tu nombre.');
  if (!quiere) return;
  await registrarFirma(p, miembroSesion.value.id, claveRecordada.value);
}

// Firma la solicitud y avisa; si completa las aprobaciones requeridas,
// se ejecuta sola. Devuelve true solo si quedó registrada.
async function registrarFirma(p, memberId, clave) {
  ocupado.value = true;
  try {
    const xdrFirmado = firmarXdr(p.xdr, clave.trim());
    const r = await firmarPropuesta(p.id, memberId, xdrFirmado);
    if (!r.datos.ok) {
      (r.datos.error === 'aprobacion_desactivada' ? avisar : falla)('No se pudo', textoError(r.datos));
      return false;
    }
    // Si con esta aprobación se completa lo requerido, se ejecuta solo.
    let detalle = 'Tu aprobación quedó registrada.';
    if (firmasDe(p) + 1 >= caja.value.umbral) {
      const ej = await ejecutarPropuesta(p.id);
      detalle = ej.datos.ok
        ? 'La solicitud quedó aprobada y ejecutada.'
        : 'Tu aprobación quedó registrada, pero al ejecutar: ' + textoError(ej.datos);
    }
    await listo('Aprobación registrada', detalle);
    return true;
  } catch (e) {
    falla('No se pudo', 'No se pudo registrar la aprobación.');
    return false;
  } finally {
    ocupado.value = false;
  }
}

async function ejecutar(p) {
  const quiere = await confirmar(
    '¿Ejecutar la solicitud?',
    'Se enviarán ' + p.monto + ' a ' + cortar(p.destino) + '. No se puede deshacer.'
  );
  if (!quiere) return;
  ocupado.value = true;
  error.value = '';
  try {
    const r = await ejecutarPropuesta(p.id);
    if (r.datos.ok) {
      await listo('Solicitud ejecutada', 'El gasto ya quedó registrado.');
    } else {
      falla('No se pudo', textoError(r.datos));
    }
  } finally {
    ocupado.value = false;
  }
}

function copiar(texto) {
  navigator.clipboard?.writeText(texto);
}

onMounted(async () => {
  // Esperamos a que el backend despierte antes de pedir datos reales.
  while (props.despertando) await new Promise((r) => setTimeout(r, 500));
  haySesionAdmin.value = !!localStorage.getItem('admin_token');
  await cargarTodo();
});
</script>

<template>
  <div class="pagina">
    <div v-if="cargando" class="texto-2 py-5 text-center">Cargando caja…</div>

    <div v-else-if="!caja" class="card tarjeta">
      <div class="card-body text-center py-5">
        <p class="texto-error">{{ error || 'No se pudo abrir la caja.' }}</p>
        <a class="btn-acento" href="#/">Volver al inicio</a>
      </div>
    </div>

    <template v-else>
      <div class="d-flex align-items-baseline justify-content-between flex-wrap gap-2 mb-1">
        <h1 class="titulo mb-0">{{ caja.nombre }}</h1>
        <span class="texto-2">Caja #{{ caja.id }}</span>
      </div>
      <p class="subtitulo">
        {{ caja.curso || 'Sin grupo' }} &middot; cada gasto necesita
        <strong>{{ caja.umbral }}</strong> {{ caja.umbral === 1 ? 'aprobación' : 'aprobaciones' }}
      </p>

      <p v-if="error" class="texto-error">{{ error }}</p>

      <!-- Fondos -->
      <div class="card tarjeta">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Fondos</h5>
            <div class="d-flex gap-2">
              <button class="btn btn-sm btn-outline-secondary" :disabled="ocupado" @click="cargarTodo">
                Actualizar
              </button>
              <button class="btn btn-sm btn-outline-secondary" :disabled="ocupado" @click="fondos">
                Pedir fondos de prueba
              </button>
            </div>
          </div>

          <div v-if="sinActivar" class="aviso-pendiente">
            La caja aún no está activada en la red.
            <button class="btn-acento btn-sm ms-2" :disabled="ocupado" @click="activar">
              {{ ocupado ? 'Activando…' : 'Activar ahora' }}
            </button>
          </div>

          <template v-else>
            <div v-if="!estado.balances.length" class="texto-2">Sin movimientos todavía.</div>
            <div v-for="b in estado.balances" :key="b.asset" class="balance-fila">
              <span class="balance-monto">{{ Number(b.balance).toLocaleString('es-CL') }}</span>
              <span class="balance-activo">{{ b.asset }}</span>
            </div>

            <h6 class="texto-2 mt-4 mb-2">Últimos movimientos</h6>
            <div v-if="!estado.transacciones.length" class="texto-2">Nada por aquí aún.</div>
            <div v-for="t in estado.transacciones" :key="t.transaction_hash" class="movimiento-fila">
              <span>{{ t.tipo }}</span>
              <span class="texto-2">{{ fechaBonita(t.creado_en) }}</span>
            </div>
          </template>
        </div>
      </div>

      <!-- Solicitudes de gasto -->
      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Solicitudes de gasto</h5>
          <div v-if="!propuestas.length" class="texto-2">No hay solicitudes todavía.</div>

          <div v-for="p in propuestas" :key="p.id" class="propuesta">
            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div>
                <div class="propuesta-monto">{{ p.monto }} a {{ cortar(p.destino) }}</div>
                <div class="texto-2">{{ p.motivo || 'Sin motivo' }}</div>
              </div>
              <div class="text-end">
                <span :class="['badge-estado', p.estado === 'ejecutada' ? 'estado-ok' : 'estado-pendiente']">
                  {{ p.estado }}
                </span>
                <div class="texto-2 mt-1">
                  {{ firmasDe(p) }} de {{ caja.umbral }} aprobaciones
                  <template v-if="nombresQueAprobaron(p).length">
                    · ya aprobaron: {{ nombresQueAprobaron(p).join(', ') }}
                  </template>
                </div>
              </div>
            </div>

            <div class="d-flex gap-2 mt-2" v-if="p.estado === 'pendiente'">
              <button
                v-if="puedoAprobarDirecto(p)"
                class="btn btn-sm btn-acento"
                :disabled="ocupado"
                @click="aprobarMio(p)"
              >
                Aprobar como {{ miembroSesion.nombre }}
              </button>
              <button
                v-if="miembrosSinFirmar(p).length"
                class="btn btn-sm btn-acento-outline"
                @click="aprobacion = { propuesta: p, memberId: '', clave: '' }"
              >
                Aprobar
              </button>
              <button
                v-if="listoParaEjecutar(p)"
                class="btn btn-sm btn-acento"
                :disabled="ocupado"
                @click="ejecutar(p)"
              >
                Ejecutar
              </button>
            </div>

            <!-- Aprobar: solo pide quién eres y tu clave -->
            <div v-if="aprobacion && aprobacion.propuesta.id === p.id" class="aprobar-caja mt-2">
              <div class="row g-2 align-items-end">
                <div class="col-md-4">
                  <label class="form-label">¿Quién aprueba?</label>
                  <select v-model="aprobacion.memberId" class="form-select">
                    <option value="" disabled>Elige tu nombre</option>
                    <option v-for="m in miembrosSinFirmar(p)" :key="m.id" :value="m.id">
                      {{ m.nombre }}
                    </option>
                  </select>
                </div>
                <div class="col-md-5">
                  <label class="form-label">Tu clave de aprobación</label>
                  <input v-model="aprobacion.clave" type="password" class="form-control" autocomplete="off" />
                </div>
                <div class="col-md-3 d-flex gap-2">
                  <button class="btn-acento" :disabled="ocupado" @click="aprobar">
                    {{ ocupado ? 'Aprobando…' : 'Confirmar' }}
                  </button>
                  <button class="btn btn-outline-secondary" @click="aprobacion = null">Cancelar</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Nueva solicitud -->
          <hr class="my-4" />
          <h6 class="mb-3">Nueva solicitud</h6>
          <div v-if="sinActivar" class="texto-2">Activa la caja para poder crear solicitudes.</div>
          <div v-else class="row g-2 align-items-end">
            <div class="col-md-4">
              <label class="form-label">Cuenta de destino</label>
              <input v-model="nuevaPropuesta.destino" class="form-control" placeholder="G…" />
            </div>
            <div class="col-md-2">
              <label class="form-label">Monto</label>
              <input v-model="nuevaPropuesta.monto" type="number" min="0" step="any" class="form-control" />
            </div>
            <div class="col-md-2">
              <label class="form-label">Activo</label>
              <select v-model="nuevaPropuesta.activoIdx" class="form-select">
                <option v-for="(a, i) in activos" :key="a.codigo + a.emisor" :value="i">
                  {{ a.codigo }}
                </option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Motivo</label>
              <input v-model="nuevaPropuesta.motivo" class="form-control" placeholder="Ej: arriendo de cancha" />
            </div>
            <div class="col-md-1">
              <button class="btn-acento w-100 text-nowrap" :disabled="ocupado" @click="proponer">Crear</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Miembros -->
      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Miembros</h5>
          <div v-if="!caja.members?.length" class="texto-2">Todavía no hay miembros.</div>
          <div v-for="m in caja.members" :key="m.id" class="miembro-fila">
            <span>{{ m.nombre }}</span>
            <span class="d-flex align-items-center gap-2">
              <span class="clave-corta texto-2">{{ cortar(m.public_key) }}</span>
              <button
                v-if="haySesionAdmin"
                :class="['badge-estado badge-boton', puedeAprobar(m) ? 'estado-ok' : 'estado-pendiente']"
                :disabled="ocupado"
                @click="toggleAprobacion(m)"
              >
                Aprobación: {{ puedeAprobar(m) ? 'ON' : 'OFF' }}
              </button>
              <span
                v-else
                :class="['badge-estado', puedeAprobar(m) ? 'estado-ok' : 'estado-pendiente']"
              >
                Aprobación: {{ puedeAprobar(m) ? 'ON' : 'OFF' }}
              </span>
            </span>
          </div>

          <hr class="my-4" />
          <h6 class="mb-3">Agregar miembro</h6>
          <div class="row g-2 align-items-end">
            <div class="col-md-4">
              <label class="form-label">Nombre</label>
              <input v-model="nuevoMiembro.nombre" class="form-control" placeholder="Ej: Tomas B." />
            </div>
            <div class="col-md-5">
              <label class="form-label">Clave pública</label>
              <input v-model="nuevoMiembro.publica" class="form-control" placeholder="G…" />
            </div>
            <div class="col-md-3 d-flex gap-2">
              <button class="btn btn-outline-secondary" @click="generarClavesMiembro">Generar claves</button>
              <button class="btn-acento" :disabled="ocupado" @click="agregar">Agregar</button>
            </div>
          </div>

          <div v-if="nuevoMiembro.secretaGenerada" class="clave-bloque mt-3">
            <div class="clave-etiqueta">
              Clave de aprobación del miembro — entrégala solo a esa persona, no se vuelve a mostrar.
            </div>
            <div class="clave-mono">{{ nuevoMiembro.secretaGenerada }}</div>
            <button class="btn btn-sm btn-outline-secondary mt-2" @click="copiar(nuevoMiembro.secretaGenerada)">
              Copiar clave
            </button>
          </div>

          <div v-if="!claveMaestra()" class="mt-3">
            <label class="form-label">
              Clave maestra de la caja (opcional; inscribe al miembro como aprobador en la red)
            </label>
            <input v-model="nuevoMiembro.claveCaja" type="password" class="form-control" autocomplete="off" />
          </div>

          <!-- Alternativa: agregar un usuario ya registrado (necesita sesión
               de administrador para listar los usuarios) -->
          <template v-if="haySesionAdmin">
            <hr class="my-3" />
            <h6 class="mb-3">O agregar un usuario registrado</h6>
            <div v-if="usuariosRegistrados.length" class="row g-2 align-items-end">
              <div class="col-md-9">
                <label class="form-label">Usuario</label>
                <select v-model="usuarioSeleccionado" class="form-select">
                  <option value="" disabled>Elige un usuario</option>
                  <option v-for="u in usuariosRegistrados" :key="u.id" :value="u.id">
                    {{ u.nombre }}{{ u.email ? ' · ' + u.email : '' }}
                  </option>
                </select>
              </div>
              <div class="col-md-3">
                <button class="btn-acento w-100" :disabled="ocupado" @click="agregarPorUsuario">
                  Agregar
                </button>
              </div>
            </div>
            <p v-else class="texto-2 mb-0">
              No hay usuarios registrados para agregar (o ya son todos miembros).
            </p>
            <p class="texto-secundario mt-2 mb-0">
              El usuario aprueba entrando con su correo en Acceso → Mis cajas.
            </p>
          </template>
        </div>
      </div>

      <!-- Historial: todo lo que ha pasado en la caja -->
      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Historial</h5>
          <div v-if="!eventos.length" class="texto-2">Sin movimientos registrados todavía.</div>
          <div v-for="e in eventos" :key="e.id" class="movimiento-fila">
            <span>
              {{ tituloEvento(e.tipo) }}
              <span v-if="resumenEvento(e)" class="texto-2">&middot; {{ resumenEvento(e) }}</span>
            </span>
            <span class="texto-2">{{ fechaBonita(e.creado_en) }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

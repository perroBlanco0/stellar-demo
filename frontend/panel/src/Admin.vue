<script setup>
import { ref, onMounted, watch } from 'vue';
import {
  crearOrganizacion, agregarAdmin, listarAdmins, listarOrganizaciones,
  actualizarAdmin, cambiarRolAdmin, eliminarAdmin, listarCajasOrganizacion,
  actualizarCaja, eliminarCaja, listarUsuarios, actualizarUsuario,
  eliminarUsuario, actualizarOrganizacion, eliminarOrganizacion,
} from './api';
import { confirmar, listo, falla, avisar, pedirTexto } from './avisos';

// Configuración: distinta del flujo de aprobación de gastos.
// Aquí entra quien administra una organización (crea cajas, agrega admins),
// no el miembro que solo aprueba gastos en una caja.

// La sección viene de la URL (#/config/SECCION): el menú del sidebar
// enlaza directo a cada parte de esta vista.
const props = defineProps({ seccion: { type: String, default: 'admins' } });
// URL -> pestaña interna (admins, cajas, usuarios, organizacion -> org)
const SECCIONES = { admins: 'admins', cajas: 'cajas', usuarios: 'usuarios', organizacion: 'org', organizaciones: 'orgs' };
// pestaña interna -> URL
const RUTAS = { admins: 'admins', cajas: 'cajas', usuarios: 'usuarios', org: 'organizacion', orgs: 'organizaciones' };

const sesion = ref(null); // { token, email, organizacion_id, rol }
const cargando = ref(false);

// Pestañas del panel con sesión: admins | cajas | usuarios | org | orgs (super)
const pestana = ref(SECCIONES[props.seccion] || 'admins');
const admins = ref([]);
const cajas = ref([]);
const usuarios = ref([]);
const organizaciones = ref([]);
const orgNombre = ref(localStorage.getItem('admin_org_nombre') || '');

// Rol super: puede ver todas las organizaciones y asignar/quitar super a otros.
const esSuper = () => sesion.value && sesion.value.rol === 'super';

// Crear organización (abierto: es el bootstrap, crea org + primer admin).
const orgNuevoNombre = ref('');
const orgEmail = ref('');
const orgPassword = ref('');

// Agregar admin a mi organización.
const nuevoEmail = ref('');
const nuevoPassword = ref('');

// Renombrar la organización.
const renombrarOrg = ref('');

function cargarSesion() {
  const guardada = localStorage.getItem('admin_sesion');
  sesion.value = guardada ? JSON.parse(guardada) : null;
}

async function salir() {
  const quiere = await confirmar('¿Salir?', 'Se cierra la sesión de administrador.');
  if (!quiere) return;
  localStorage.removeItem('admin_token');
  localStorage.removeItem('admin_sesion');
  location.reload();
}

// La sesión venció o no alcanza para la acción: la limpiamos y avisamos.
function sesionVencida(r) {
  if (r.estado !== 401) return false;
  localStorage.removeItem('admin_token');
  localStorage.removeItem('admin_sesion');
  cargarSesion();
  avisar('Sesión vencida', 'Entra de nuevo.');
  return true;
}

function textoError(codigo) {
  const mapa = {
    missing_fields: 'Completa todos los campos.',
    credenciales_invalidas: 'Correo o contraseña incorrectos.',
    email_ya_registrado: 'Ese correo ya está registrado.',
    email_duplicado: 'Ese correo ya está registrado.',
    unauthorized: 'La sesión venció. Entra de nuevo.',
    forbidden: 'No perteneces a esa organización.',
    ultimo_admin: 'No puedes eliminar al último administrador de la organización.',
    organizacion_tiene_cajas: 'La organización todavía tiene cajas: elimínalas primero desde la sección Cajas.',
    caja_not_found: 'No se encontró la caja.',
    usuario_not_found: 'No se encontró el usuario.',
    sin_conexion: 'Sin conexión con el servicio. Intenta de nuevo.',
  };
  return mapa[codigo] || (codigo ? 'Algo salió mal (' + codigo + ').' : 'Algo salió mal. Intenta de nuevo.');
}

async function crearOrg() {
  if (!orgNuevoNombre.value.trim() || !orgEmail.value.trim() || !orgPassword.value) {
    avisar('Faltan datos', 'Completa nombre, correo y contraseña.');
    return;
  }
  const quiere = await confirmar('¿Crear la organización?', orgNuevoNombre.value.trim());
  if (!quiere) return;
  cargando.value = true;
  try {
    const r = await crearOrganizacion(orgNuevoNombre.value.trim(), orgEmail.value.trim(), orgPassword.value);
    if (!r.datos.ok) {
      if (!sesionVencida(r)) falla('No se pudo', textoError(r.datos.error));
      return;
    }
    await listo('Organización creada', orgNuevoNombre.value.trim() + ' ya existe.');
    orgNuevoNombre.value = '';
    orgEmail.value = '';
    orgPassword.value = '';
  } finally {
    cargando.value = false;
  }
}

// Super puede promover a super o bajar a admin.
async function cambiarRol(a, orgId) {
  const nuevo = a.rol === 'super' ? 'admin' : 'super';
  const quiere = await confirmar(
    '¿Cambiar el rol de ' + a.email + '?',
    nuevo === 'super' ? 'Pasara a ser super-administrador.' : 'Dejara de ser super-administrador.'
  );
  if (!quiere) return;
  const r = await cambiarRolAdmin(orgId || sesion.value.organizacion_id, a.id, nuevo);
  if (!r.datos.ok) {
    if (!sesionVencida(r)) {
      (r.datos.error === 'ultimo_super' ? avisar : falla)('No se pudo', textoError(r.datos.error));
    }
    return;
  }
  await listo('Rol actualizado', a.email + ' ahora es ' + nuevo + '.');
}

// ---- Cargas por pestaña ----
async function cargarAdmins() {
  if (!sesion.value) return;
  const r = await listarAdmins(sesion.value.organizacion_id);
  if (r.datos.ok) {
    admins.value = r.datos.admins;
  } else {
    sesionVencida(r) || avisar('No se pudo cargar', textoError(r.datos.error));
  }
}

async function cargarCajas() {
  if (!sesion.value) return;
  const r = await listarCajasOrganizacion(sesion.value.organizacion_id);
  if (r.datos.ok) {
    cajas.value = r.datos.cajas || [];
  } else {
    sesionVencida(r) || avisar('No se pudo cargar', textoError(r.datos.error));
  }
}

async function cargarUsuarios() {
  const r = await listarUsuarios();
  if (r.datos.ok) {
    usuarios.value = r.datos.usuarios || [];
  } else {
    sesionVencida(r) || avisar('No se pudo cargar', textoError(r.datos.error));
  }
}

async function cargarOrganizaciones() {
  const r = await listarOrganizaciones();
  if (r.datos.ok) {
    organizaciones.value = r.datos.organizaciones || [];
  } else {
    sesionVencida(r) || avisar('No se pudo cargar', textoError(r.datos.error));
  }
}

function cargarPestana(p) {
  if (p === 'admins') cargarAdmins();
  else if (p === 'cajas') cargarCajas();
  else if (p === 'usuarios') cargarUsuarios();
  else if (p === 'orgs') cargarOrganizaciones();
}

function cambiarPestana(p) {
  if (pestana.value === p) return;
  pestana.value = p;
  // La pestaña queda en la URL: el menú enlaza directo a cada sección.
  location.hash = '#/config/' + RUTAS[p];
  cargarPestana(p);
}

// El menú del sidebar cambia la URL; aquí seguimos la sección pedida.
watch(
  () => props.seccion,
  (s) => cambiarPestana(SECCIONES[s] || 'admins')
);

// ---- Admins ----
async function agregar() {
  if (!nuevoEmail.value.trim() || !nuevoPassword.value) {
    avisar('Faltan datos', 'Completa correo y contraseña.');
    return;
  }
  const quiere = await confirmar('¿Agregar administrador?', nuevoEmail.value.trim());
  if (!quiere) return;
  cargando.value = true;
  try {
    const r = await agregarAdmin(sesion.value.organizacion_id, nuevoEmail.value.trim(), nuevoPassword.value);
    if (!r.datos.ok) {
      if (!sesionVencida(r)) falla('No se pudo', textoError(r.datos.error));
      return;
    }
    nuevoEmail.value = '';
    nuevoPassword.value = '';
    await listo('Administrador agregado', 'Ya puede entrar con su correo.');
  } finally {
    cargando.value = false;
  }
}

async function cambiarPassword(a) {
  const nueva = await pedirTexto(
    'Contraseña nueva',
    'Para ' + a.email + '. Déjala en claro con esa persona.',
    { password: true, placeholder: 'Nueva contraseña' }
  );
  if (nueva === null) return;
  if (!nueva.trim()) {
    avisar('Contraseña vacía', 'Escribe una contraseña nueva.');
    return;
  }
  const r = await actualizarAdmin(sesion.value.organizacion_id, a.id, nueva);
  if (!r.datos.ok) {
    if (!sesionVencida(r)) falla('No se pudo', textoError(r.datos.error));
    return;
  }
  await listo('Contraseña actualizada', a.email + ' ya puede entrar con la contraseña nueva.');
}

async function quitarAdmin(a) {
  const quiere = await confirmar(
    '¿Eliminar administrador?',
    a.email + ' ya no podrá administrar la organización.'
  );
  if (!quiere) return;
  const r = await eliminarAdmin(sesion.value.organizacion_id, a.id);
  if (!r.datos.ok) {
    if (!sesionVencida(r)) {
      (r.datos.error === 'ultimo_admin' ? avisar : falla)('No se pudo', textoError(r.datos.error));
    }
    return;
  }
  await listo('Administrador eliminado', a.email + ' ya no tiene acceso.');
}

// ---- Cajas ----
async function renombrarCaja(c) {
  const nombre = await pedirTexto('Renombrar caja', 'Nombre nuevo para «' + c.nombre + '».', {
    valor: c.nombre,
  });
  if (nombre === null) return;
  if (!nombre.trim()) {
    avisar('Nombre vacío', 'Escribe un nombre para la caja.');
    return;
  }
  const r = await actualizarCaja(c.id, nombre.trim());
  if (!r.datos.ok) {
    if (!sesionVencida(r)) {
      (r.estado === 403 || r.estado === 409 ? avisar : falla)('No se pudo', textoError(r.datos.error));
    }
    return;
  }
  await listo('Caja renombrada', 'Ahora se llama «' + nombre.trim() + '».');
}

async function quitarCaja(c) {
  const quiere = await confirmar(
    '¿Eliminar la caja «' + c.nombre + '»?',
    'Se borran también sus miembros, solicitudes y su historial. No se puede deshacer.'
  );
  if (!quiere) return;
  const r = await eliminarCaja(c.id);
  if (!r.datos.ok) {
    if (!sesionVencida(r)) {
      (r.estado === 403 || r.estado === 409 ? avisar : falla)('No se pudo', textoError(r.datos.error));
    }
    return;
  }
  await listo('Caja eliminada', '«' + c.nombre + '» quedó eliminada.');
}

// ---- Usuarios ----
async function renombrarUsuario(u) {
  const nombre = await pedirTexto('Renombrar usuario', 'Nombre nuevo para «' + u.nombre + '».', {
    valor: u.nombre,
  });
  if (nombre === null) return;
  if (!nombre.trim()) {
    avisar('Nombre vacío', 'Escribe un nombre para el usuario.');
    return;
  }
  const r = await actualizarUsuario(u.id, nombre.trim());
  if (!r.datos.ok) {
    if (!sesionVencida(r)) falla('No se pudo', textoError(r.datos.error));
    return;
  }
  await listo('Usuario renombrado', 'Ahora se llama «' + nombre.trim() + '».');
}

async function quitarUsuario(u) {
  const quiere = await confirmar(
    '¿Eliminar usuario?',
    u.nombre + ' ya no podrá entrar ni aprobar gastos.'
  );
  if (!quiere) return;
  const r = await eliminarUsuario(u.id);
  if (!r.datos.ok) {
    if (!sesionVencida(r)) falla('No se pudo', textoError(r.datos.error));
    return;
  }
  await listo('Usuario eliminado', u.nombre + ' quedó eliminado.');
}

// ---- Organización ----
async function guardarNombreOrg() {
  if (!renombrarOrg.value.trim()) {
    avisar('Nombre vacío', 'Escribe el nombre nuevo de la organización.');
    return;
  }
  const quiere = await confirmar('¿Renombrar la organización?', 'Quedará como «' + renombrarOrg.value.trim() + '».');
  if (!quiere) return;
  const r = await actualizarOrganizacion(sesion.value.organizacion_id, renombrarOrg.value.trim());
  if (!r.datos.ok) {
    if (!sesionVencida(r)) falla('No se pudo', textoError(r.datos.error));
    return;
  }
  orgNombre.value = renombrarOrg.value.trim();
  localStorage.setItem('admin_org_nombre', orgNombre.value);
  await listo('Organización renombrada', 'Ahora se llama «' + orgNombre.value + '».');
}

async function quitarOrganizacion() {
  const primero = await confirmar(
    '¿Eliminar la organización?',
    'Se eliminan sus administradores y su acceso. Las cajas deben borrarse antes.'
  );
  if (!primero) return;
  const segundo = await confirmar(
    'Confirma una última vez',
    'Esta acción no se puede deshacer.'
  );
  if (!segundo) return;
  const r = await eliminarOrganizacion(sesion.value.organizacion_id);
  if (!r.datos.ok) {
    if (!sesionVencida(r)) {
      (r.datos.error === 'organizacion_tiene_cajas' ? avisar : falla)('No se pudo', textoError(r.datos.error));
    }
    return;
  }
  localStorage.removeItem('admin_token');
  localStorage.removeItem('admin_sesion');
  localStorage.removeItem('admin_org_nombre');
  await listo('Organización eliminada', 'Tu sesión quedó cerrada.');
}

onMounted(() => {
  cargarSesion();
  cargarPestana(pestana.value);
});
</script>

<template>
  <div class="pagina">
    <h1 class="titulo">Configuración</h1>
    <p class="subtitulo">
      Las organizaciones administran sus cajas. Solo un administrador puede crear
      cajas nuevas o agregar más administradores. El acceso es por correo y contraseña.
    </p>

    <!-- Sin sesión: el login convencional vive en #/ -->
    <div v-if="!sesion" class="card tarjeta" style="max-width: 420px;">
      <div class="card-body">
        <h5 class="mb-2">Configuración</h5>
        <p class="texto-2 mb-3">Para administrar tu organización primero debes entrar.</p>
        <a class="btn-acento" href="#/">Entrar</a>
      </div>
    </div>

    <!-- Con sesión: mi organización -->
    <template v-else>
      <div class="card tarjeta mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h5 class="mb-1">Sesión de administrador</h5>
            <p class="mb-0 texto-secundario">
              {{ sesion.email }} &middot; {{ orgNombre || ('organización #' + sesion.organizacion_id) }}
            </p>
          </div>
          <button class="btn btn-sm btn-outline-secondary" @click="salir">Salir</button>
        </div>
      </div>

      <div class="nav-pestanas">
        <button :class="{ activo: pestana === 'admins' }" @click="cambiarPestana('admins')">Administradores</button>
        <button :class="{ activo: pestana === 'cajas' }" @click="cambiarPestana('cajas')">Cajas</button>
        <button :class="{ activo: pestana === 'usuarios' }" @click="cambiarPestana('usuarios')">Usuarios</button>
        <button :class="{ activo: pestana === 'org' }" @click="cambiarPestana('org')">Organización</button>
        <button v-if="esSuper()" :class="{ activo: pestana === 'orgs' }" @click="cambiarPestana('orgs')">Organizaciones</button>
      </div>

      <!-- Admins -->
      <div v-if="pestana === 'admins'" class="fila-tarjetas">
        <div class="card tarjeta">
          <div class="card-body">
            <h5 class="mb-3">Administradores de mi organización</h5>
            <ul class="lista-simple" v-if="admins.length">
              <li v-for="a in admins" :key="a.id" class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <span>
                  {{ a.email }}
                  <span v-if="a.rol === 'super'" class="badge bg-primary ms-1">super</span>
                  <span class="texto-secundario">· desde {{ (a.creado_en || '').slice(0, 10) }}</span>
                </span>
                <span class="d-flex gap-1">
                  <button v-if="esSuper()" class="btn btn-sm btn-outline-secondary" @click="cambiarRol(a)">
                    {{ a.rol === 'super' ? 'Quitar super' : 'Hacer super' }}
                  </button>
                  <button class="btn btn-sm btn-outline-secondary" @click="cambiarPassword(a)">
                    Contraseña
                  </button>
                  <button class="btn btn-sm btn-outline-danger" @click="quitarAdmin(a)">Eliminar</button>
                </span>
              </li>
            </ul>
            <p v-else class="texto-secundario mb-0">Sin administradores registrados.</p>
          </div>
        </div>

        <div class="card tarjeta">
          <div class="card-body">
            <h5 class="mb-3">Agregar administrador</h5>
            <label class="form-label">Correo</label>
            <input v-model="nuevoEmail" type="email" class="form-control mb-2" placeholder="nuevo@ejemplo.cl" />
            <label class="form-label">Contraseña</label>
            <input v-model="nuevoPassword" type="password" class="form-control mb-3" />
            <button class="btn-acento-outline" :disabled="cargando" @click="agregar">
              Agregar administrador
            </button>
          </div>
        </div>
      </div>

      <!-- Cajas -->
      <div v-if="pestana === 'cajas'" class="card tarjeta">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Cajas de la organización</h5>
            <button class="btn btn-sm btn-outline-secondary" @click="cargarCajas">Actualizar</button>
          </div>
          <ul class="lista-simple" v-if="cajas.length">
            <li v-for="c in cajas" :key="c.id" class="d-flex justify-content-between align-items-center flex-wrap gap-1">
              <span>
                {{ c.nombre }}
                <span class="texto-secundario">
                  · {{ c.umbral }} {{ c.umbral === 1 ? 'aprobación' : 'aprobaciones' }}
                  · {{ (c.creado_en || '').slice(0, 10) }}
                </span>
              </span>
              <span class="d-flex gap-1">
                <a class="btn btn-sm btn-outline-secondary" :href="'#/caja/' + c.id">Abrir</a>
                <button class="btn btn-sm btn-outline-secondary" @click="renombrarCaja(c)">Renombrar</button>
                <button class="btn btn-sm btn-outline-danger" @click="quitarCaja(c)">Eliminar</button>
              </span>
            </li>
          </ul>
          <p v-else class="texto-secundario mb-0">La organización aún no tiene cajas.</p>
        </div>
      </div>

      <!-- Usuarios -->
      <div v-if="pestana === 'usuarios'" class="card tarjeta">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Usuarios registrados</h5>
            <button class="btn btn-sm btn-outline-secondary" @click="cargarUsuarios">Actualizar</button>
          </div>
          <ul class="lista-simple" v-if="usuarios.length">
            <li v-for="u in usuarios" :key="u.id" class="d-flex justify-content-between align-items-center flex-wrap gap-1">
              <span>
                {{ u.nombre }}
                <span class="texto-secundario">
                  · {{ u.email || 'sin correo' }} · {{ (u.creado_en || '').slice(0, 10) }}
                </span>
              </span>
              <span class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-secondary" @click="renombrarUsuario(u)">Renombrar</button>
                <button class="btn btn-sm btn-outline-danger" @click="quitarUsuario(u)">Eliminar</button>
              </span>
            </li>
          </ul>
          <p v-else class="texto-secundario mb-0">Sin usuarios registrados.</p>
        </div>
      </div>

      <!-- Organizaciones (solo super) -->
      <div v-if="pestana === 'orgs'" class="fila-tarjetas">
        <div class="card tarjeta">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="mb-0">Organizaciones</h5>
              <button class="btn btn-sm btn-outline-secondary" @click="cargarOrganizaciones">Actualizar</button>
            </div>
            <ul class="lista-simple" v-if="organizaciones.length">
              <li v-for="o in organizaciones" :key="o.id" class="d-flex justify-content-between align-items-center flex-wrap gap-1">
                <span>
                  #{{ o.id }} {{ o.nombre }}
                  <span class="texto-secundario">
                    · {{ o.admins }} {{ o.admins === 1 ? 'admin' : 'admins' }}
                    · {{ o.cajas }} {{ o.cajas === 1 ? 'caja' : 'cajas' }}
                    · {{ (o.creado_en || '').slice(0, 10) }}
                  </span>
                </span>
              </li>
            </ul>
            <p v-else class="texto-secundario mb-0">Sin organizaciones registradas.</p>
          </div>
        </div>

        <div class="card tarjeta">
          <div class="card-body">
            <h5 class="mb-3">Crear organización</h5>
            <label class="form-label">Nombre de la organización</label>
            <input v-model="orgNuevoNombre" class="form-control mb-2" placeholder="Ej: Curso 4to Medio B" />
            <label class="form-label">Correo del primer administrador</label>
            <input v-model="orgEmail" type="email" class="form-control mb-2" placeholder="admin@ejemplo.cl" />
            <label class="form-label">Contraseña</label>
            <input v-model="orgPassword" type="password" class="form-control mb-3" />
            <button class="btn-acento-outline" :disabled="cargando" @click="crearOrg">
              Crear organización
            </button>
          </div>
        </div>
      </div>

      <!-- Organización -->
      <div v-if="pestana === 'org'" class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-1">Mi organización</h5>
          <p class="texto-2 mb-3">{{ orgNombre || ('Organización #' + sesion.organizacion_id) }}</p>
          <div class="row g-2 align-items-end">
            <div class="col-md-8">
              <label class="form-label">Nombre nuevo</label>
              <input v-model="renombrarOrg" class="form-control" :placeholder="orgNombre || 'Nombre de la organización'" />
            </div>
            <div class="col-md-4">
              <button class="btn-acento-outline w-100" @click="guardarNombreOrg">Renombrar</button>
            </div>
          </div>

          <hr class="my-4" />
          <h6 class="mb-2">Zona delicada</h6>
          <p class="texto-2 mb-3">
            Eliminar la organización borra también a sus administradores. Primero debes
            eliminar sus cajas desde la sección Cajas.
          </p>
          <button class="btn btn-outline-danger btn-sm" @click="quitarOrganizacion">
            Eliminar organización
          </button>
        </div>
      </div>
    </template>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { entrarAdmin, crearOrganizacion, agregarAdmin, listarAdmins } from './api';
import { confirmar, listo, falla, avisar } from './avisos';

// Sección de administración: distinta del flujo de aprobación de gastos.
// Aquí entra quien administra una organización (crea cajas, agrega admins),
// no el miembro que solo aprueba gastos en una caja.

const email = ref('');
const password = ref('');
const sesion = ref(null); // { token, email, organizacion_id }
const admins = ref([]);
const cargando = ref(false);

// Crear organización (abierto: es el bootstrap, crea org + primer admin).
const orgNombre = ref('');
const orgEmail = ref('');
const orgPassword = ref('');

// Agregar admin a mi organización.
const nuevoEmail = ref('');
const nuevoPassword = ref('');

function cargarSesion() {
  const guardada = localStorage.getItem('admin_sesion');
  sesion.value = guardada ? JSON.parse(guardada) : null;
}

function guardarSesion(datos) {
  localStorage.setItem('admin_token', datos.token);
  localStorage.setItem(
    'admin_sesion',
    JSON.stringify({ token: datos.token, email: datos.email, organizacion_id: datos.organizacion_id })
  );
  cargarSesion();
}

async function salir() {
  const quiere = await confirmar('¿Salir?', 'Se cierra la sesión de administrador.');
  if (!quiere) return;
  localStorage.removeItem('admin_token');
  localStorage.removeItem('admin_sesion');
  location.reload();
}

async function cargarAdmins() {
  if (!sesion.value) return;
  const r = await listarAdmins(sesion.value.organizacion_id);
  if (r.datos.ok) {
    admins.value = r.datos.admins;
  } else if (r.estado === 401 || r.estado === 403) {
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_sesion');
    cargarSesion();
    avisar('Sesión vencida', 'Entra de nuevo.');
  }
}

function textoError(codigo) {
  const mapa = {
    missing_fields: 'Completa todos los campos.',
    credenciales_invalidas: 'Correo o contraseña incorrectos.',
    email_ya_registrado: 'Ese correo ya está registrado.',
    unauthorized: 'La sesión venció. Entra de nuevo.',
    forbidden: 'No perteneces a esa organización.',
    sin_conexion: 'Sin conexión con el servicio. Intenta de nuevo.',
  };
  return mapa[codigo] || 'Algo salió mal (' + codigo + ').';
}

async function entrar() {
  cargando.value = true;
  try {
    const r = await entrarAdmin(email.value.trim(), password.value);
    if (!r.datos.ok) {
      falla('No se pudo entrar', textoError(r.datos.error));
      return;
    }
    guardarSesion(r.datos);
    await listo('Sesión iniciada', 'Ya puedes administrar tu organización.');
  } finally {
    cargando.value = false;
  }
}

async function crearOrg() {
  if (!orgNombre.value.trim() || !orgEmail.value.trim() || !orgPassword.value) {
    avisar('Faltan datos', 'Completa nombre, correo y contraseña.');
    return;
  }
  const quiere = await confirmar('¿Crear la organización?', orgNombre.value.trim());
  if (!quiere) return;
  cargando.value = true;
  try {
    const r = await crearOrganizacion(orgNombre.value.trim(), orgEmail.value.trim(), orgPassword.value);
    if (!r.datos.ok) {
      falla('No se pudo', textoError(r.datos.error));
      return;
    }
    // Sin recargar: dejamos el correo listo en el formulario de entrada.
    await listo(
      'Organización creada',
      'Entra con <strong>' + orgEmail.value.trim() + '</strong> para administrarla.',
      false
    );
    email.value = orgEmail.value.trim();
    orgNombre.value = '';
    orgEmail.value = '';
    orgPassword.value = '';
  } finally {
    cargando.value = false;
  }
}

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
      falla('No se pudo', textoError(r.datos.error));
      return;
    }
    nuevoEmail.value = '';
    nuevoPassword.value = '';
    await listo('Administrador agregado', 'Ya puede entrar con su correo.');
  } finally {
    cargando.value = false;
  }
}

onMounted(() => {
  cargarSesion();
  cargarAdmins();
});
</script>

<template>
  <div class="pagina">
    <h1 class="titulo">Administración</h1>
    <p class="subtitulo">
      Las organizaciones administran sus cajas. Solo un administrador puede crear
      cajas nuevas o agregar más administradores. El acceso es por correo y contraseña.
    </p>

    <!-- Sin sesión: entrar o crear organización -->
    <div v-if="!sesion" class="fila-tarjetas">
      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Entrar</h5>
          <label class="form-label">Correo</label>
          <input v-model="email" type="email" class="form-control mb-2" placeholder="admin@ejemplo.cl" />
          <label class="form-label">Contraseña</label>
          <input
            v-model="password"
            type="password"
            class="form-control mb-3"
            @keyup.enter="entrar"
          />
          <button class="btn-acento" :disabled="cargando" @click="entrar">
            {{ cargando ? 'Entrando…' : 'Entrar' }}
          </button>
        </div>
      </div>

      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Crear organización nueva</h5>
          <label class="form-label">Nombre de la organización</label>
          <input v-model="orgNombre" class="form-control mb-2" placeholder="Ej: Curso 4to Medio B" />
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

    <!-- Con sesión: mi organización -->
    <template v-else>
      <div class="card tarjeta mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h5 class="mb-1">Sesión de administrador</h5>
            <p class="mb-0 texto-secundario">
              {{ sesion.email }} &middot; organización #{{ sesion.organizacion_id }}
            </p>
          </div>
          <button class="btn btn-sm btn-outline-secondary" @click="salir">Salir</button>
        </div>
      </div>

      <div class="fila-tarjetas">
        <div class="card tarjeta">
          <div class="card-body">
            <h5 class="mb-3">Administradores de mi organización</h5>
            <ul class="lista-simple" v-if="admins.length">
              <li v-for="a in admins" :key="a.id">
                {{ a.email }}
                <span class="texto-secundario">· desde {{ (a.creado_en || '').slice(0, 10) }}</span>
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
    </template>

  </div>
</template>

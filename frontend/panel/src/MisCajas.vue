<script setup>
import { ref, onMounted } from 'vue';
import { misCajas, verPropuestas } from './api';
import { confirmar, listo, avisar } from './avisos';

// Vista del usuario registrado: las cajas donde es miembro.
// Usa su propio token (usuario_token), no el de administrador.
const sesion = ref(null);
const cajas = ref([]);
const pendientes = ref({}); // caja.id -> cuántas solicitudes siguen pendientes
const cargando = ref(true);

function cargarSesion() {
  const guardada = localStorage.getItem('usuario_sesion');
  sesion.value = guardada ? JSON.parse(guardada) : null;
}

async function salir() {
  const quiere = await confirmar('¿Salir?', 'Se cierra tu sesión de usuario.');
  if (!quiere) return;
  localStorage.removeItem('usuario_token');
  localStorage.removeItem('usuario_sesion');
  location.hash = '#/';
  await listo('Sesión cerrada', 'Hasta la próxima.');
}

onMounted(async () => {
  cargarSesion();
  if (!sesion.value) {
    cargando.value = false;
    return;
  }
  const r = await misCajas();
  if (r.datos.ok) {
    cajas.value = r.datos.cajas || [];
    // Contamos las solicitudes pendientes de cada caja para el badge.
    for (const c of cajas.value) {
      verPropuestas(c.id).then((p) => {
        if (p.datos.ok) {
          pendientes.value[c.id] = (p.datos.proposals || []).filter(
            (x) => x.estado === 'pendiente'
          ).length;
        }
      });
    }
  } else if (r.estado === 401) {
    localStorage.removeItem('usuario_token');
    localStorage.removeItem('usuario_sesion');
    cargarSesion();
    avisar('Sesión vencida', 'Entra de nuevo desde Acceso.');
  } else {
    avisar('No se pudo cargar', 'No pudimos traer tus cajas (' + (r.datos.error || 'error') + ').');
  }
  cargando.value = false;
});
</script>

<template>
  <div class="pagina">
    <h1 class="titulo">Mis cajas</h1>
    <p class="subtitulo">Las cajas donde eres miembro y puedes aprobar gastos.</p>

    <div v-if="cargando" class="texto-2 py-5 text-center">Cargando tus cajas…</div>

    <div v-else-if="!sesion" class="card tarjeta">
      <div class="card-body text-center py-5">
        <p class="texto-2 mb-3">Para ver tus cajas primero tienes que entrar.</p>
        <a class="btn-acento" href="#/acceso">Ir a Acceso</a>
      </div>
    </div>

    <template v-else>
      <div class="card tarjeta mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h5 class="mb-1">{{ sesion.nombre }}</h5>
            <p class="mb-0 texto-secundario">{{ sesion.email }}</p>
          </div>
          <button class="btn btn-sm btn-outline-secondary" @click="salir">Salir</button>
        </div>
      </div>

      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Cajas donde eres miembro</h5>
          <p v-if="!cajas.length" class="texto-2 mb-0">
            Todavía no eres miembro de ninguna caja. Pídele a un administrador que te agregue
            con tu clave pública.
          </p>
          <div v-for="c in cajas" :key="c.id" class="miembro-fila">
            <span>
              {{ c.nombre }}
              <span class="texto-secundario">
                &middot; {{ c.umbral }} {{ c.umbral === 1 ? 'aprobación' : 'aprobaciones' }} por gasto
              </span>
              <span v-if="pendientes[c.id]" class="badge-estado estado-pendiente ms-2">
                {{ pendientes[c.id] }} {{ pendientes[c.id] === 1 ? 'pendiente' : 'pendientes' }}
              </span>
            </span>
            <a class="btn-acento btn-sm" :href="'#/caja/' + c.id">Abrir</a>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

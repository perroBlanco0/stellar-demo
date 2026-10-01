<script setup>
import { ref } from 'vue';
import { crearCaja } from './api';
import { generarClaves, activarCuenta, prepararCaja } from './stellar';
import { confirmar, avisar, falla } from './avisos';

// Crear caja nueva. Vista separada: antes vivía mezclada con el inicio.
const props = defineProps({ despertando: Boolean });

const nombre = ref('');
const curso = ref('');
const umbral = ref(2);
const creando = ref(false);
const aviso = ref('');
const creada = ref(null); // { id, secreta, activada }

function irACaja(id) {
  location.hash = '#/caja/' + id;
}

function registrarReciente(id, nombreCaja) {
  const lista = JSON.parse(localStorage.getItem('cajas_recientes') || '[]');
  const nueva = [
    { id: Number(id), nombre: nombreCaja },
    ...lista.filter((c) => Number(c.id) !== Number(id)),
  ].slice(0, 8);
  localStorage.setItem('cajas_recientes', JSON.stringify(nueva));
  window.dispatchEvent(new Event('cajas-recientes-cambio'));
}

async function crear() {
  aviso.value = '';
  if (!nombre.value.trim()) {
    avisar('Falta el nombre', 'Ponle un nombre a la caja.');
    return;
  }
  if (!umbral.value || umbral.value < 1) {
    avisar('Revisa el número', 'Las aprobaciones requeridas deben ser al menos 1.');
    return;
  }
  const quiere = await confirmar(
    '¿Crear la caja?',
    nombre.value.trim() + ' · ' + umbral.value + (umbral.value === 1 ? ' aprobación' : ' aprobaciones') + ' por gasto.'
  );
  if (!quiere) return;
  creando.value = true;
  try {
    const claves = generarClaves();
    const r = await crearCaja(nombre.value.trim(), curso.value.trim(), claves.publica, umbral.value);
    if (!r.datos.ok) {
      if (r.estado === 401) {
        avisar('Falta entrar', 'Para crear cajas hay que entrar como administrador.');
        location.hash = '#/';
      } else {
        falla('No se pudo crear', 'No se pudo crear la caja (' + (r.datos.error || 'error') + ').');
      }
      return;
    }
    const id = r.datos.id;
    localStorage.setItem('caja_secreta_' + id, claves.secreta);
    registrarReciente(id, nombre.value.trim());

    // Activación en la red: fondear y dejar listo el esquema de aprobaciones.
    let activada = false;
    if (await activarCuenta(claves.publica)) {
      try {
        await prepararCaja(claves.publica, claves.secreta, umbral.value);
        activada = true;
      } catch (e) {
        aviso.value = 'La caja quedó fondeada pero su configuración quedó pendiente; actívala desde la vista de la caja.';
      }
    } else {
      aviso.value = 'La caja se creó pero aún no está activada en la red; actívala desde la vista de la caja.';
    }
    creada.value = { id, secreta: claves.secreta, activada };
  } finally {
    creando.value = false;
  }
}

function copiar(texto) {
  navigator.clipboard?.writeText(texto);
}
</script>

<template>
  <div class="pagina">
    <h1 class="titulo">Crear caja nueva</h1>
    <p class="subtitulo">Define el nombre y cuántas aprobaciones pide cada gasto.</p>

    <div v-if="creada" class="card tarjeta">
      <div class="card-body">
        <h5 class="mb-2">Caja creada: {{ nombre }}</h5>
        <p class="mb-1">Número de caja: <strong>{{ creada.id }}</strong></p>
        <p v-if="creada.activada" class="texto-ok mb-2">Activada y lista para usar.</p>
        <p v-else class="texto-aviso mb-2">{{ aviso }}</p>
        <div class="clave-bloque">
          <div class="clave-etiqueta">
            Clave maestra de la caja: es la llave para administrarla (agregar
            miembros, configurarla). Se muestra una sola vez — copiala y
            guárdala en un lugar seguro. Si se pierde, nadie puede administrar la caja.
          </div>
          <div class="clave-mono">{{ creada.secreta }}</div>
          <button class="btn btn-sm btn-outline-secondary mt-2" @click="copiar(creada.secreta)">
            Copiar clave
          </button>
        </div>
        <button class="btn-acento mt-3" @click="irACaja(creada.id)">Ir a la caja</button>
      </div>
    </div>

    <div v-else class="card tarjeta" style="max-width: 420px;">
      <div class="card-body">
        <label class="form-label">Nombre</label>
        <input v-model="nombre" class="form-control mb-2" placeholder="Ej: Caja 4to Medio B" />
        <label class="form-label">Grupo o curso (opcional)</label>
        <input v-model="curso" class="form-control mb-2" placeholder="Ej: 4to Medio B" />
        <label class="form-label">Aprobaciones necesarias para cada gasto</label>
        <input v-model.number="umbral" type="number" min="1" class="form-control mb-3" />
        <button class="btn-acento" :disabled="creando || despertando" @click="crear">
          {{ creando ? 'Creando…' : 'Crear caja' }}
        </button>
      </div>
    </div>

    <p class="mt-3 mb-0">
      <a href="#/" class="texto-secundario">Volver a entrar</a>
    </p>
  </div>
</template>

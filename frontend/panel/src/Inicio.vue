<script setup>
import { ref } from 'vue';
import { crearCaja } from './api';
import { generarClaves, activarCuenta, prepararCaja } from './stellar';
import { confirmar, avisar, falla } from './avisos';

const props = defineProps({ despertando: Boolean });

const idAbrir = ref('');
const nombre = ref('');
const curso = ref('');
const umbral = ref(2);
const creando = ref(false);
const error = ref('');
const aviso = ref('');
const creada = ref(null); // { id, secreta, activada }

function irACaja(id) {
  location.hash = '#/caja/' + id;
}

function abrir() {
  const id = parseInt(idAbrir.value, 10);
  if (!id || id < 1) {
    avisar('Número inválido', 'Ingresa un número de caja válido.');
    return;
  }
  irACaja(id);
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
  error.value = '';
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
        avisar('Falta entrar', 'Para crear cajas hay que entrar como administrador. Ve a Administración.');
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
    <h1 class="titulo">Cajas</h1>
    <p class="subtitulo">
      Una caja junta el dinero de un grupo y cada gasto necesita las aprobaciones
      que ustedes definan. Sin sorpresas: nadie mueve dinero solo.
    </p>

    <div v-if="creada" class="card tarjeta">
      <div class="card-body">
        <h5 class="mb-2">Caja creada: {{ nombre }}</h5>
        <p class="mb-1">Número de caja: <strong>{{ creada.id }}</strong></p>
        <p v-if="creada.activada" class="texto-ok mb-2">Activada y lista para usar.</p>
        <p v-else class="texto-aviso mb-2">{{ aviso }}</p>
        <div class="clave-bloque">
          <div class="clave-etiqueta">Clave maestra de la caja (guárdala, solo se muestra una vez)</div>
          <div class="clave-mono">{{ creada.secreta }}</div>
          <button class="btn btn-sm btn-outline-secondary mt-2" @click="copiar(creada.secreta)">
            Copiar clave
          </button>
        </div>
        <button class="btn-acento mt-3" @click="irACaja(creada.id)">Ir a la caja</button>
      </div>
    </div>

    <div v-else class="fila-tarjetas">
      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Abrir una caja</h5>
          <label class="form-label">Número de caja</label>
          <input
            v-model="idAbrir"
            type="number"
            min="1"
            class="form-control mb-3"
            placeholder="Ej: 2"
            @keyup.enter="abrir"
          />
          <button class="btn-acento" :disabled="despertando" @click="abrir">Abrir</button>
        </div>
      </div>

      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Crear caja nueva</h5>
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
    </div>

    <p v-if="error" class="texto-error mt-3">{{ error }}</p>

    <p class="mt-4 mb-0">
      <a href="#/acceso" class="texto-secundario">Acceso</a>
      <span class="texto-secundario"> &middot; </span>
      <a href="#/admin" class="texto-secundario">Administraci&oacute;n de organizaciones</a>
    </p>
  </div>
</template>

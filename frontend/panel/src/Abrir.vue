<script setup>
import { ref } from 'vue';
import { avisar } from './avisos';

// Abrir una caja por su número. Vista separada: el inicio ya no mezcla acciones.
const props = defineProps({ despertando: Boolean });

const idAbrir = ref('');

function abrir() {
  const id = parseInt(idAbrir.value, 10);
  if (!id || id < 1) {
    avisar('Número inválido', 'Ingresa un número de caja válido.');
    return;
  }
  location.hash = '#/caja/' + id;
}
</script>

<template>
  <div class="pagina">
    <h1 class="titulo">Abrir una caja</h1>
    <p class="subtitulo">Escribe el número de la caja que quieres revisar o gestionar.</p>

    <div class="card tarjeta">
      <div class="card-body">
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

    <p class="mt-3 mb-0">
      <a href="#/" class="texto-secundario">Volver al inicio</a>
    </p>
  </div>
</template>

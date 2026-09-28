<script setup>
import { ref, onMounted } from 'vue';
import { despertar } from './api';
import Inicio from './Inicio.vue';
import Caja from './Caja.vue';

// Ruteo mínimo por hash: #/ -> inicio, #/caja/ID -> detalle. Sin vue-router.
const vista = ref('inicio');
const cajaId = ref(0);
const despertando = ref(true);

function parsearHash() {
  const m = location.hash.match(/^#\/caja\/(\d+)/);
  if (m) {
    cajaId.value = Number(m[1]);
    vista.value = 'caja';
  } else {
    vista.value = 'inicio';
  }
}

onMounted(() => {
  // El backend en Render se "duerme": lo despertamos en segundo plano
  // apenas carga la app, antes de pedir datos reales.
  despertar().finally(() => (despertando.value = false));
  parsearHash();
  window.addEventListener('hashchange', parsearHash);
});
</script>

<template>
  <div v-if="despertando" class="aviso-despertar">
    Conectando con el servicio&hellip; la primera carga puede tardar ~1 minuto.
  </div>
  <Inicio v-if="vista === 'inicio'" :despertando="despertando" />
  <Caja v-else :id="cajaId" :key="cajaId" :despertando="despertando" />
</template>

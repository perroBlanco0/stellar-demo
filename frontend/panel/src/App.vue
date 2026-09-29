<script setup>
import { ref, onMounted } from 'vue';
import { despertar } from './api';
import Inicio from './Inicio.vue';
import Caja from './Caja.vue';
import Admin from './Admin.vue';
import Acceso from './Acceso.vue';
import MisCajas from './MisCajas.vue';

// Ruteo mínimo por hash: #/ -> inicio, #/caja/ID -> detalle,
// #/config/SECCION -> configuración (admins, cajas, usuarios, organizacion).
// Sin vue-router.
const vista = ref('inicio');
const cajaId = ref(0);
const seccionConfig = ref('admins');
const despertando = ref(true);

function parsearHash() {
  const m = location.hash.match(/^#\/caja\/(\d+)/);
  if (m) {
    cajaId.value = Number(m[1]);
    vista.value = 'caja';
  } else if (location.hash.startsWith('#/admin')) {
    // Dirección antigua de Administración: queda como alias de Configuración.
    location.hash = '#/config';
  } else if (location.hash.startsWith('#/config')) {
    const s = location.hash.match(/^#\/config\/(\w+)/);
    seccionConfig.value = s ? s[1] : 'admins';
    vista.value = 'config';
  } else if (location.hash.startsWith('#/acceso')) {
    vista.value = 'acceso';
  } else if (location.hash.startsWith('#/mis-cajas')) {
    vista.value = 'miscajas';
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
  <Admin v-else-if="vista === 'config'" :seccion="seccionConfig" />
  <Acceso v-else-if="vista === 'acceso'" />
  <MisCajas v-else-if="vista === 'miscajas'" />
  <Caja v-else :id="cajaId" :key="cajaId" :despertando="despertando" />
</template>

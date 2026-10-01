<script setup>
import { ref, onMounted } from 'vue';
import { despertar } from './api';
import Acceso from './Acceso.vue';
import Registro from './Registro.vue';
import Recuperar from './Recuperar.vue';
import Abrir from './Abrir.vue';
import Crear from './Crear.vue';
import Caja from './Caja.vue';
import MisCajas from './MisCajas.vue';
import Admin from './Admin.vue';

// Ruteo mínimo por hash. Cada vista vive sola: login, registro,
// recuperar clave, abrir, crear, mis cajas, detalle de caja y config.
// Sin vue-router.
const vista = ref('acceso');
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
  } else if (location.hash.startsWith('#/registro')) {
    vista.value = 'registro';
  } else if (location.hash.startsWith('#/recuperar')) {
    vista.value = 'recuperar';
  } else if (location.hash.startsWith('#/abrir')) {
    vista.value = 'abrir';
  } else if (location.hash.startsWith('#/crear')) {
    vista.value = 'crear';
  } else if (location.hash.startsWith('#/mis-cajas')) {
    vista.value = 'miscajas';
  } else if (location.hash.startsWith('#/acceso')) {
    // Dirección antigua del acceso: el login convencional es la portada.
    location.hash = '#/';
  } else {
    vista.value = 'acceso';
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
  <Acceso v-if="vista === 'acceso'" />
  <Registro v-else-if="vista === 'registro'" />
  <Recuperar v-else-if="vista === 'recuperar'" />
  <Abrir v-else-if="vista === 'abrir'" :despertando="despertando" />
  <Crear v-else-if="vista === 'crear'" :despertando="despertando" />
  <MisCajas v-else-if="vista === 'miscajas'" />
  <Admin v-else-if="vista === 'config'" :seccion="seccionConfig" />
  <Caja v-else :id="cajaId" :key="cajaId" :despertando="despertando" />
</template>

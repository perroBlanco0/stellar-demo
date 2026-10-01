<script setup>
import { ref } from 'vue';
import { crearUsuario } from './api';
import { generarClaves } from './stellar';
import { confirmar, listo, falla, avisar } from './avisos';

// Registro de usuario (miembro de cajas). Vista propia, fuera del login.
const nombre = ref('');
const email = ref('');
const password = ref('');
const cargando = ref(false);

async function registrar() {
  if (!nombre.value.trim() || !email.value.trim() || !password.value) {
    avisar('Faltan datos', 'Completa nombre, correo y contraseña.');
    return;
  }
  const quiere = await confirmar('¿Crear tu cuenta?', 'Te registraremos como ' + nombre.value.trim() + '.');
  if (!quiere) return;
  cargando.value = true;
  try {
    const claves = generarClaves();
    const r = await crearUsuario(nombre.value.trim(), claves.publica, email.value.trim(), password.value);
    if (!r.datos.ok) {
      falla('No se pudo', r.datos.error === 'email_duplicado' || r.datos.error === 'email_ya_registrado'
        ? 'Ese correo ya está registrado.'
        : 'Algo salió mal. Intenta de nuevo.');
      return;
    }
    // La clave de aprobación también queda en este navegador: así la persona
    // aprueba con un clic desde Mis cajas, sin pegar nada.
    localStorage.setItem('clave_aprobacion_' + claves.publica, claves.secreta);
    // Sin recargar: las claves se muestran una sola vez y hay que guardarlas.
    await listo(
      'Cuenta creada',
      '<div style="text-align:left;font-size:14px">' +
        'Tu clave pública identifica tu cuenta; entrégala a quien administre la caja para agregarte como miembro:' +
        '<div class="clave-mono" style="word-break:break-all;margin:6px 0 12px">' + claves.publica + '</div>' +
        'Tu clave de aprobación es la que usarás para aprobar gastos. Guárdala, solo se muestra una vez:' +
        '<div class="clave-mono" style="word-break:break-all;margin:6px 0">' + claves.secreta + '</div>' +
        'Quedó recordada en este navegador, así aprobarás con un clic desde Mis cajas.' +
        ' Ya puedes entrar con tu correo y contraseña.' +
      '</div>',
      false
    );
    location.hash = '#/';
  } finally {
    cargando.value = false;
  }
}
</script>

<template>
  <div class="pagina">
    <h1 class="titulo">Crear cuenta</h1>
    <p class="subtitulo">
      Regístrate una vez y luego pide a un administrador que te agregue como miembro de su caja.
    </p>

    <div class="card tarjeta" style="max-width: 420px; margin: 0 auto;">
      <div class="card-body">
        <label class="form-label">Nombre</label>
        <input v-model="nombre" class="form-control mb-2" placeholder="Ej: Tomas B." />
        <label class="form-label">Correo</label>
        <input v-model="email" type="email" class="form-control mb-2" placeholder="tu@ejemplo.cl" />
        <label class="form-label">Contraseña</label>
        <input v-model="password" type="password" class="form-control mb-3" @keyup.enter="registrar" />
        <button class="btn-acento w-100" :disabled="cargando" @click="registrar">
          {{ cargando ? 'Creando…' : 'Crear cuenta' }}
        </button>
      </div>
    </div>

    <p class="mt-3 mb-0 text-center">
      <a href="#/" class="texto-secundario">Volver a entrar</a>
    </p>
  </div>
</template>

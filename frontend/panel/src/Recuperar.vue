<script setup>
import { ref } from 'vue';
import { recuperarClave, cambiarClave } from './api';
import { confirmar, listo, falla, avisar } from './avisos';

// Recuperar clave por código al correo (sirve para usuarios y admins).
// paso: 1 = pedir el código, 2 = cambiar la clave con el código recibido.
const paso = ref(1);
const email = ref('');
const codigo = ref('');
const password = ref('');
const cargando = ref(false);

function textoError(codigoErr) {
  const mapa = {
    missing_fields: 'Completa todos los campos.',
    codigo_invalido: 'El código no coincide o ya venció. Pide uno nuevo.',
    sin_conexion: 'Sin conexión con el servicio. Intenta de nuevo.',
  };
  return mapa[codigoErr] || (codigoErr ? 'Algo salió mal (' + codigoErr + ').' : 'Algo salió mal. Intenta de nuevo.');
}

async function pedirCodigo() {
  if (!email.value.trim()) {
    avisar('Falta el correo', 'Escribe el correo de tu cuenta.');
    return;
  }
  cargando.value = true;
  try {
    const r = await recuperarClave(email.value.trim());
    if (!r.datos.ok) {
      falla('No se pudo', textoError(r.datos.error));
      return;
    }
    // El backend siempre responde ok para no revelar si el correo existe.
    await listo(
      'Revisa tu correo',
      'Si el correo está registrado te llegó un código de 6 dígitos (vence en 15 minutos).',
      false
    );
    paso.value = 2;
  } finally {
    cargando.value = false;
  }
}

async function cambiar() {
  if (!email.value.trim() || !codigo.value.trim() || !password.value) {
    avisar('Faltan datos', 'Completa correo, código y contraseña nueva.');
    return;
  }
  const quiere = await confirmar('¿Cambiar la clave?', 'Tendrás que entrar de nuevo con la clave nueva.');
  if (!quiere) return;
  cargando.value = true;
  try {
    const r = await cambiarClave(email.value.trim(), codigo.value.trim(), password.value);
    if (!r.datos.ok) {
      falla('No se pudo', textoError(r.datos.error));
      return;
    }
    await listo('Clave actualizada', 'Ya puedes entrar con tu contraseña nueva.', false);
    location.hash = '#/';
  } finally {
    cargando.value = false;
  }
}
</script>

<template>
  <div class="pagina">
    <h1 class="titulo">Recuperar clave</h1>
    <p class="subtitulo">
      {{ paso === 1
        ? 'Te enviamos un código de 6 dígitos al correo de tu cuenta.'
        : 'Escribe el código que llegó a tu correo y elige una contraseña nueva.' }}
    </p>

    <div class="card tarjeta" style="max-width: 420px; margin: 0 auto;">
      <div class="card-body">
        <template v-if="paso === 1">
          <label class="form-label">Correo</label>
          <input v-model="email" type="email" class="form-control mb-3" placeholder="tu@ejemplo.cl" @keyup.enter="pedirCodigo" />
          <button class="btn-acento w-100" :disabled="cargando" @click="pedirCodigo">
            {{ cargando ? 'Enviando…' : 'Enviar código' }}
          </button>
        </template>
        <template v-else>
          <label class="form-label">Correo</label>
          <input v-model="email" type="email" class="form-control mb-2" />
          <label class="form-label">Código</label>
          <input v-model="codigo" class="form-control mb-2" placeholder="123456" />
          <label class="form-label">Contraseña nueva</label>
          <input v-model="password" type="password" class="form-control mb-3" @keyup.enter="cambiar" />
          <button class="btn-acento w-100" :disabled="cargando" @click="cambiar">
            {{ cargando ? 'Cambiando…' : 'Cambiar clave' }}
          </button>
        </template>
      </div>
    </div>

    <p class="mt-3 mb-0 text-center">
      <a href="#/" class="texto-secundario">Volver a entrar</a>
    </p>
  </div>
</template>

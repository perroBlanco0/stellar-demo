<script setup>
import { ref } from 'vue';
import { entrarAdmin, entrarUsuario } from './api';
import { listo, falla } from './avisos';

// Login convencional: un solo formulario correo + clave. Se intenta
// primero como administrador y luego como usuario; el rol decide
// a dónde se entra (#/config o #/mis-cajas).
const email = ref('');
const password = ref('');
const cargando = ref(false);

function textoError(codigo) {
  const mapa = {
    missing_fields: 'Completa todos los campos.',
    credenciales_invalidas: 'Correo o contraseña incorrectos.',
    unauthorized: 'La sesión venció. Entra de nuevo.',
    sin_conexion: 'Sin conexión con el servicio. Intenta de nuevo.',
  };
  return mapa[codigo] || (codigo ? 'Algo salió mal (' + codigo + ').' : 'Algo salió mal. Intenta de nuevo.');
}

async function entrar() {
  if (!email.value.trim() || !password.value) {
    falla('Faltan datos', 'Escribe tu correo y tu contraseña.');
    return;
  }
  cargando.value = true;
  try {
    // 1) ¿Es administrador?
    const rAdmin = await entrarAdmin(email.value.trim(), password.value);
    if (rAdmin.datos.ok) {
      localStorage.setItem('admin_token', rAdmin.datos.token);
      localStorage.setItem(
        'admin_sesion',
        JSON.stringify({
          token: rAdmin.datos.token,
          email: rAdmin.datos.email,
          organizacion_id: rAdmin.datos.organizacion_id,
          rol: rAdmin.datos.rol,
        })
      );
      if (rAdmin.datos.organizacion_nombre) {
        localStorage.setItem('admin_org_nombre', rAdmin.datos.organizacion_nombre);
      }
      await listo('Sesión iniciada', 'Ya puedes administrar tu organización.', false);
      location.hash = '#/config';
      return;
    }
    // 2) ¿Es usuario (miembro de cajas)?
    const rUsuario = await entrarUsuario(email.value.trim(), password.value);
    if (rUsuario.datos.ok) {
      const u = rUsuario.datos.usuario || {};
      localStorage.setItem('usuario_token', rUsuario.datos.token);
      localStorage.setItem(
        'usuario_sesion',
        JSON.stringify({ token: rUsuario.datos.token, id: u.id, nombre: u.nombre, email: u.email, public_key: u.public_key })
      );
      await listo('Sesión iniciada', 'Hola ' + (u.nombre || '') + '.', false);
      location.hash = '#/mis-cajas';
      return;
    }
    falla('No se pudo entrar', textoError(rUsuario.datos.error || rAdmin.datos.error));
  } finally {
    cargando.value = false;
  }
}
</script>

<template>
  <div class="pagina">
    <div class="hero">
      <img class="hero-logo" src="/logo.png" alt="Cosigna" />
      <h1 class="hero-nombre">Cosigna</h1>
      <p class="hero-tagline">Tesorerías colectivas con aprobación compartida</p>
    </div>

    <div class="card tarjeta" style="max-width: 420px; margin: 0 auto;">
      <div class="card-body">
        <h5 class="mb-3">Entrar</h5>
        <label class="form-label">Correo</label>
        <input v-model="email" type="email" class="form-control mb-2" placeholder="tu@ejemplo.cl" @keyup.enter="entrar" />
        <label class="form-label">Contraseña</label>
        <input v-model="password" type="password" class="form-control mb-3" @keyup.enter="entrar" />
        <button class="btn-acento w-100" :disabled="cargando" @click="entrar">
          {{ cargando ? 'Entrando…' : 'Entrar' }}
        </button>
        <p class="mt-3 mb-0 text-center">
          <a href="#/recuperar" class="texto-secundario">¿Olvidaste tu clave?</a>
        </p>
      </div>
    </div>

    <p class="mt-3 mb-0 text-center">
      <a href="#/registro" class="texto-secundario">Crear una cuenta</a>
    </p>

    <div class="card tarjeta mt-4" style="max-width: 420px; margin-left: auto; margin-right: auto;">
      <div class="card-body">
        <h6 class="mb-2">¿Qué es Cosigna?</h6>
        <p class="texto-secundario mb-2" style="font-size: 0.92rem;">
          Una caja compartida para tu curso, club o junta: nadie gasta la plata
          común sin las aprobaciones que ustedes decidan. Todo queda registrado
          y verificable en la red.
        </p>
        <a href="#/caja/4" class="texto-secundario" style="font-size: 0.92rem;">
          Ver una caja real de ejemplo →
        </a>
      </div>
    </div>
  </div>
</template>

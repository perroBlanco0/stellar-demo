<script setup>
import { ref } from 'vue';
import { entrarAdmin, entrarUsuario, crearUsuario } from './api';
import { generarClaves } from './stellar';
import { confirmar, listo, falla, avisar } from './avisos';

// Entrada unificada: administradores entran a #/admin y los usuarios
// (miembros de cajas) a #/mis-cajas. Abajo, el registro de usuarios.
const cargando = ref(false);

// Administrador
const adminEmail = ref('');
const adminPassword = ref('');

// Usuario
const email = ref('');
const password = ref('');

// Registro de usuario
const regNombre = ref('');
const regEmail = ref('');
const regPassword = ref('');

function textoError(codigo) {
  const mapa = {
    missing_fields: 'Completa todos los campos.',
    credenciales_invalidas: 'Correo o contraseña incorrectos.',
    email_duplicado: 'Ese correo ya está registrado.',
    email_ya_registrado: 'Ese correo ya está registrado.',
    unauthorized: 'La sesión venció. Entra de nuevo.',
    sin_conexion: 'Sin conexión con el servicio. Intenta de nuevo.',
  };
  return mapa[codigo] || (codigo ? 'Algo salió mal (' + codigo + ').' : 'Algo salió mal. Intenta de nuevo.');
}

async function entrarComoAdmin() {
  cargando.value = true;
  try {
    const r = await entrarAdmin(adminEmail.value.trim(), adminPassword.value);
    if (!r.datos.ok) {
      falla('No se pudo entrar', textoError(r.datos.error));
      return;
    }
    // Mismo formato de sesión que usa Administración.
    localStorage.setItem('admin_token', r.datos.token);
    localStorage.setItem(
      'admin_sesion',
      JSON.stringify({ token: r.datos.token, email: r.datos.email, organizacion_id: r.datos.organizacion_id })
    );
    await listo('Sesión iniciada', 'Ya puedes administrar tu organización.', false);
    location.hash = '#/admin';
  } finally {
    cargando.value = false;
  }
}

async function entrarComoUsuario() {
  cargando.value = true;
  try {
    const r = await entrarUsuario(email.value.trim(), password.value);
    if (!r.datos.ok) {
      falla('No se pudo entrar', textoError(r.datos.error));
      return;
    }
    const u = r.datos.usuario || {};
    localStorage.setItem('usuario_token', r.datos.token);
    localStorage.setItem(
      'usuario_sesion',
      JSON.stringify({ token: r.datos.token, id: u.id, nombre: u.nombre, email: u.email, public_key: u.public_key })
    );
    await listo('Sesión iniciada', 'Hola ' + (u.nombre || '') + '.', false);
    location.hash = '#/mis-cajas';
  } finally {
    cargando.value = false;
  }
}

async function registrar() {
  if (!regNombre.value.trim() || !regEmail.value.trim() || !regPassword.value) {
    avisar('Faltan datos', 'Completa nombre, correo y contraseña.');
    return;
  }
  const quiere = await confirmar('¿Crear tu cuenta?', 'Te registraremos como ' + regNombre.value.trim() + '.');
  if (!quiere) return;
  cargando.value = true;
  try {
    const claves = generarClaves();
    const r = await crearUsuario(regNombre.value.trim(), claves.publica, regEmail.value.trim(), regPassword.value);
    if (!r.datos.ok) {
      falla('No se pudo', textoError(r.datos.error));
      return;
    }
    email.value = regEmail.value.trim();
    regNombre.value = '';
    regEmail.value = '';
    regPassword.value = '';
    // Sin recargar: las claves se muestran una sola vez y hay que guardarlas.
    await listo(
      'Cuenta creada',
      '<div style="text-align:left;font-size:14px">' +
        'Tu clave pública identifica tu cuenta; entrégala a quien administre la caja para agregarte como miembro:' +
        '<div class="clave-mono" style="word-break:break-all;margin:6px 0 12px">' + claves.publica + '</div>' +
        'Tu clave de aprobación es la que usarás para aprobar gastos. Guárdala, solo se muestra una vez:' +
        '<div class="clave-mono" style="word-break:break-all;margin:6px 0">' + claves.secreta + '</div>' +
        'Ya puedes entrar con tu correo y contraseña.' +
      '</div>',
      false
    );
  } finally {
    cargando.value = false;
  }
}
</script>

<template>
  <div class="pagina">
    <h1 class="titulo">Acceso</h1>
    <p class="subtitulo">
      Entra según tu rol: los administradores manejan su organización y sus cajas;
      los usuarios ven las cajas donde son miembros.
    </p>

    <div class="fila-tarjetas">
      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Soy administrador</h5>
          <label class="form-label">Correo</label>
          <input v-model="adminEmail" type="email" class="form-control mb-2" placeholder="admin@ejemplo.cl" />
          <label class="form-label">Contraseña</label>
          <input
            v-model="adminPassword"
            type="password"
            class="form-control mb-3"
            @keyup.enter="entrarComoAdmin"
          />
          <button class="btn-acento" :disabled="cargando" @click="entrarComoAdmin">
            {{ cargando ? 'Entrando…' : 'Entrar' }}
          </button>
        </div>
      </div>

      <div class="card tarjeta">
        <div class="card-body">
          <h5 class="mb-3">Soy usuario</h5>
          <label class="form-label">Correo</label>
          <input v-model="email" type="email" class="form-control mb-2" placeholder="tu@ejemplo.cl" />
          <label class="form-label">Contraseña</label>
          <input
            v-model="password"
            type="password"
            class="form-control mb-3"
            @keyup.enter="entrarComoUsuario"
          />
          <button class="btn-acento" :disabled="cargando" @click="entrarComoUsuario">
            {{ cargando ? 'Entrando…' : 'Entrar' }}
          </button>
        </div>
      </div>
    </div>

    <div class="card tarjeta">
      <div class="card-body">
        <h5 class="mb-1">¿Aún no tienes cuenta de usuario?</h5>
        <p class="texto-2 mb-3">
          Regístrate una vez y luego pide a un administrador que te agregue como miembro de su caja.
        </p>
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label">Nombre</label>
            <input v-model="regNombre" class="form-control" placeholder="Ej: Tomas B." />
          </div>
          <div class="col-md-3">
            <label class="form-label">Correo</label>
            <input v-model="regEmail" type="email" class="form-control" placeholder="tu@ejemplo.cl" />
          </div>
          <div class="col-md-3">
            <label class="form-label">Contraseña</label>
            <input v-model="regPassword" type="password" class="form-control" />
          </div>
          <div class="col-md-3">
            <button class="btn-acento-outline w-100" :disabled="cargando" @click="registrar">
              Crear cuenta
            </button>
          </div>
        </div>
      </div>
    </div>

    <p class="mt-3 mb-0">
      <a href="#/" class="texto-secundario">Volver al inicio</a>
    </p>
  </div>
</template>

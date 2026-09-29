<script setup>
import { ref } from 'vue';
import { entrarAdmin, entrarUsuario, crearUsuario, recuperarClave, cambiarClave } from './api';
import { generarClaves } from './stellar';
import { confirmar, listo, falla, avisar } from './avisos';

// Entrada unificada: administradores entran a #/config y los usuarios
// (miembros de cajas) a #/mis-cajas. Abajo, recuperar clave y registro.
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

// Recuperar clave por código al correo (sirve para usuarios y admins).
// recPaso: 0 = formulario oculto, 1 = pedir el código, 2 = cambiar la clave.
const recPaso = ref(0);
const recEmail = ref('');
const recCodigo = ref('');
const recPassword = ref('');

function textoError(codigo) {
  const mapa = {
    missing_fields: 'Completa todos los campos.',
    credenciales_invalidas: 'Correo o contraseña incorrectos.',
    codigo_invalido: 'El código no coincide o ya venció. Pide uno nuevo.',
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
      JSON.stringify({
        token: r.datos.token,
        email: r.datos.email,
        organizacion_id: r.datos.organizacion_id,
        rol: r.datos.rol || 'admin',
      })
    );
    await listo('Sesión iniciada', 'Ya puedes administrar tu organización.', false);
    location.hash = '#/config';
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
  } finally {
    cargando.value = false;
  }
}

async function pedirCodigo() {
  if (!recEmail.value.trim()) {
    avisar('Falta el correo', 'Escribe el correo de tu cuenta.');
    return;
  }
  cargando.value = true;
  try {
    const r = await recuperarClave(recEmail.value.trim());
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
    recPaso.value = 2;
  } finally {
    cargando.value = false;
  }
}

async function cambiar() {
  if (!recEmail.value.trim() || !recCodigo.value.trim() || !recPassword.value) {
    avisar('Faltan datos', 'Completa correo, código y contraseña nueva.');
    return;
  }
  const quiere = await confirmar('¿Cambiar la clave?', 'Tendrás que entrar de nuevo con la clave nueva.');
  if (!quiere) return;
  cargando.value = true;
  try {
    const r = await cambiarClave(recEmail.value.trim(), recCodigo.value.trim(), recPassword.value);
    if (!r.datos.ok) {
      falla('No se pudo', textoError(r.datos.error));
      return;
    }
    // Sin recargar: volvemos al login con el correo ya escrito.
    const correo = recEmail.value.trim();
    recPaso.value = 0;
    recEmail.value = '';
    recCodigo.value = '';
    recPassword.value = '';
    email.value = correo;
    adminEmail.value = correo;
    await listo('Clave actualizada', 'Ya puedes entrar con tu contraseña nueva.', false);
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

    <p class="mb-3">
      <a href="#/acceso" class="texto-secundario" @click.prevent="recPaso = 1">
        ¿Olvidaste tu clave?
      </a>
    </p>

    <div v-if="recPaso" class="card tarjeta">
      <div class="card-body">
        <h5 class="mb-1">Recuperar clave</h5>
        <p class="texto-2 mb-3">
          {{ recPaso === 1
            ? 'Te enviamos un código de 6 dígitos al correo de tu cuenta.'
            : 'Escribe el código que llegó a tu correo y elige una contraseña nueva.' }}
        </p>
        <div v-if="recPaso === 1" class="row g-2 align-items-end">
          <div class="col-md-8">
            <label class="form-label">Correo</label>
            <input v-model="recEmail" type="email" class="form-control" placeholder="tu@ejemplo.cl" @keyup.enter="pedirCodigo" />
          </div>
          <div class="col-md-4">
            <button class="btn-acento-outline w-100" :disabled="cargando" @click="pedirCodigo">
              {{ cargando ? 'Enviando…' : 'Enviar código' }}
            </button>
          </div>
        </div>
        <div v-else class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label">Correo</label>
            <input v-model="recEmail" type="email" class="form-control" />
          </div>
          <div class="col-md-3">
            <label class="form-label">Código</label>
            <input v-model="recCodigo" class="form-control" placeholder="123456" />
          </div>
          <div class="col-md-3">
            <label class="form-label">Contraseña nueva</label>
            <input v-model="recPassword" type="password" class="form-control" @keyup.enter="cambiar" />
          </div>
          <div class="col-md-2">
            <button class="btn-acento w-100" :disabled="cargando" @click="cambiar">
              {{ cargando ? 'Cambiando…' : 'Cambiar' }}
            </button>
          </div>
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

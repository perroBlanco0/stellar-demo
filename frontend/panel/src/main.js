import 'bootstrap/dist/css/bootstrap.min.css';
import 'admin-lte/dist/css/adminlte.min.css';
import './estilo.css';

import $ from 'jquery';
import { createApp } from 'vue';
import App from './App.vue';
import { listarCajasOrganizacion, misCajas } from './api';

// jQuery maneja SOLO el chrome del template (sidebar, toggles, enlaces recientes).
// Todo ese DOM vive en index.html, fuera de #app. Vue nunca lo toca.
function pintarLista(lista) {
  const $ul = $('#cajas-recientes').empty();
  if (!lista.length) {
    $ul.append('<div class="sidebar-vacio">Sin cajas por aquí</div>');
    return;
  }
  for (const c of lista) {
    const $a = $('<a class="sidebar-link sidebar-caja"></a>')
      .attr('href', '#/caja/' + c.id)
      .text(c.nombre || ('Caja ' + c.id));
    if (location.hash === '#/caja/' + c.id) $a.addClass('activo');
    $ul.append($a);
  }
}

// Marca activo el enlace del menú que calza con la ruta actual.
function pintarActivos() {
  // '#/config' a secas muestra Administradores (su sección por defecto).
  const hash = (location.hash || '#/') === '#/config' ? '#/config/admins' : (location.hash || '#/');
  $('.sidebar-link').each(function () {
    const href = $(this).attr('href') || '';
    const activo =
      href === '#/'
        ? hash === '#/' || hash === '' || hash === '#'
        : hash === href;
    $(this).toggleClass('activo', activo);
  });
}

// Sin sesión: la lista de siempre, desde localStorage.
function pintarLocal() {
  $('#sidebar-titulo-cajas').text('Cajas recientes');
  pintarLista(JSON.parse(localStorage.getItem('cajas_recientes') || '[]'));
}

// Con sesión el sidebar muestra las cajas del backend; sin sesión,
// las recientes del navegador como antes.
function pintarRecientes() {
  const admin = JSON.parse(localStorage.getItem('admin_sesion') || 'null');
  const usuario = localStorage.getItem('usuario_token');
  if (admin) {
    $('#sidebar-titulo-cajas').text('Cajas de la organización');
    listarCajasOrganizacion(admin.organizacion_id).then((r) => {
      if (r.datos.ok) pintarLista(r.datos.cajas || []);
      else pintarLocal();
    });
  } else if (usuario) {
    $('#sidebar-titulo-cajas').text('Mis cajas');
    misCajas().then((r) => {
      if (r.datos.ok) pintarLista(r.datos.cajas || []);
      else pintarLocal();
    });
  } else {
    pintarLocal();
  }
}

// El mantenedor de organizaciones solo aparece en el menú si la sesión
// guardada es de un super-admin.
function pintarPermisos() {
  const admin = JSON.parse(localStorage.getItem('admin_sesion') || 'null');
  $('#link-organizaciones').toggle(!!admin && admin.rol === 'super');
}

$(function () {
  $('#btn-menu').on('click', function () {
    $('body').toggleClass('sidebar-open');
  });

  // Al navegar: repintar activos y cerrar el menú en móvil.
  $(window).on('hashchange', function () {
    $('body').removeClass('sidebar-open');
    pintarActivos();
    pintarRecientes();
    pintarPermisos();
  });

  // Vue avisa cuando cambia la lista de recientes (al abrir una caja).
  window.addEventListener('cajas-recientes-cambio', pintarRecientes);

  pintarActivos();
  pintarRecientes();
  pintarPermisos();
});

createApp(App).mount('#app');

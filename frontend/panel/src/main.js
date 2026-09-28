import 'bootstrap/dist/css/bootstrap.min.css';
import 'admin-lte/dist/css/adminlte.min.css';
import './estilo.css';

import $ from 'jquery';
import { createApp } from 'vue';
import App from './App.vue';

// jQuery maneja SOLO el chrome del template (sidebar, toggles, enlaces recientes).
// Todo ese DOM vive en index.html, fuera de #app. Vue nunca lo toca.
function pintarRecientes() {
  const lista = JSON.parse(localStorage.getItem('cajas_recientes') || '[]');
  const $ul = $('#cajas-recientes').empty();
  if (!lista.length) {
    $ul.append('<div class="sidebar-vacio">Aún no abres ninguna caja</div>');
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

$(function () {
  $('#btn-menu').on('click', function () {
    $('body').toggleClass('sidebar-open');
  });

  // Al navegar: repintar activos y cerrar el menú en móvil.
  $(window).on('hashchange', function () {
    $('body').removeClass('sidebar-open');
    pintarRecientes();
  });

  // Vue avisa cuando cambia la lista de recientes (al abrir una caja).
  window.addEventListener('cajas-recientes-cambio', pintarRecientes);

  pintarRecientes();
});

createApp(App).mount('#app');

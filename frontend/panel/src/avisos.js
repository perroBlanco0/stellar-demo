// Avisos al usuario con SweetAlert2: una sola puerta para confirmar,
// celebrar o avisar errores. Después de cada acción exitosa se recarga
// la página para que todo quede al día.
import Swal from 'sweetalert2';

const ACENTO = '#2563eb';

// Pregunta antes de una acción. Devuelve true solo si confirma.
export async function confirmar(titulo, texto) {
  const r = await Swal.fire({
    icon: 'question',
    title: titulo,
    text: texto,
    showCancelButton: true,
    confirmButtonText: 'Sí, continuar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: ACENTO,
  });
  return r.isConfirmed;
}

// Acción exitosa: muestra el aviso y recarga la página al cerrar.
// recargar=false para casos donde hay algo que copiar primero.
export async function listo(titulo, html, recargar = true) {
  await Swal.fire({
    icon: 'success',
    title: titulo,
    html: html || undefined,
    confirmButtonText: 'Aceptar',
    confirmButtonColor: ACENTO,
  });
  if (recargar) location.reload();
}

// Pide un texto (renombrar, contraseña nueva). Devuelve el texto o null si cancela.
export async function pedirTexto(titulo, texto, opciones = {}) {
  const r = await Swal.fire({
    icon: 'question',
    title: titulo,
    text: texto,
    input: opciones.password ? 'password' : 'text',
    inputValue: opciones.valor || '',
    inputPlaceholder: opciones.placeholder || '',
    showCancelButton: true,
    confirmButtonText: 'Guardar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: ACENTO,
  });
  return r.isConfirmed ? (r.value || '') : null;
}

// Error de una acción.
export function falla(titulo, texto) {
  Swal.fire({ icon: 'error', title: titulo, text: texto, confirmButtonColor: ACENTO });
}

// Aviso suave (validaciones, cosas pendientes).
export function avisar(titulo, texto) {
  Swal.fire({ icon: 'warning', title: titulo, text: texto, confirmButtonColor: ACENTO });
}

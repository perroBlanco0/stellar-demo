# frontend/panel — Panel de Cajas

Panel web para las "cajas" del demo: gastos con aprobación colectiva.
Vue 3 + Bootstrap 5 + jQuery sobre AdminLTE 4 (MIT). Todo el dato sale del
backend real; nada está hardcodeado.

## Desarrollo

```bash
cd frontend/panel
npm install
npm run dev        # http://localhost:5173
npm run build      # genera dist/
```

## Deploy en Render (Static Site)

- Root Directory: `frontend/panel`
- Build Command: `npm install && npm run build`
- Publish Directory: `dist`
- Auto-deploy: activado (push a `main`)
- CORS: agregar la URL del static site a `CORS_ALLOWED_ORIGIN` en las env
  vars del backend en Render (ej: `https://stellar-demo-panel.onrender.com`).

## Arquitectura (a propósito simple)

- `index.html` — chrome del template (header, sidebar, footer). Es DOM de
  jQuery: Vue nunca lo toca.
- `src/main.js` — jQuery solo cablea el sidebar y la lista de cajas
  recientes (localStorage). Vue se monta en `#app`.
- `src/api.js` — una función por endpoint del contrato del README raíz.
- `src/stellar.js` — la plomería invisible: genera claves, activa la cuenta
  en la red, inscribe aprobadores y firma comprobantes en el navegador
  (SDK via CDN en `index.html`).
- `src/App.vue` — ruteo mínimo por hash (`#/`, `#/caja/ID`) y el aviso de
  cold start (dispara `GET /` al backend apenas carga la app).
- `src/Inicio.vue` — abrir caja por número / crear caja nueva.
- `src/Caja.vue` — fondos, solicitudes de gasto (crear, aprobar, ejecutar)
  y miembros.

Lo que el usuario ve: caja, fondos, solicitudes, aprobaciones, claves.
Lo que nunca ve: Stellar, XDR, wallet, firmas criptográficas.

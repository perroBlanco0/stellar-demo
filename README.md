<div align="center">

<img src="frontend/panel/public/logo.png" alt="Logo de Cosigna" width="140">

# Cosigna

### Tesorerías colectivas donde ninguna persona decide sola

Cajas compartidas sobre **Stellar**, con firmas múltiples, umbrales y trazabilidad anclada en blockchain.

![PHP](https://img.shields.io/badge/PHP-Slim-777BB4?logo=php&logoColor=white)
![Vue](https://img.shields.io/badge/Vue-3-42b883?logo=vuedotjs&logoColor=white)
![Vite](https://img.shields.io/badge/Vite-646CFF?logo=vite&logoColor=white)
![Stellar](https://img.shields.io/badge/Stellar-multisig-000000?logo=stellar&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/DB-PostgreSQL%20%7C%20MySQL%20%7C%20SQLite-336791?logo=postgresql&logoColor=white)
![Render](https://img.shields.io/badge/Deploy-Render-46E3B7?logo=render&logoColor=white)

</div>

---

## 💡 ¿Qué es Cosigna?

Cosigna es una plataforma **multi-tenant** para administrar fondos colectivos, llamados *cajas*. Está pensada para organizaciones, grupos y comunidades que necesitan manejar dinero compartido con reglas claras y sin depender de la confianza en una sola persona.

Cada caja se apoya en las capacidades de **multifirma de Stellar**: los movimientos de dinero se proponen, se firman entre varios miembros y solo se ejecutan cuando se alcanza el **umbral** definido. Todo queda registrado en un historial de eventos con anclaje en blockchain.

## ✨ Qué puedes hacer

- 🏦 **Crear cajas colectivas** con saldos, miembros y reglas propias.
- ✍️ **Proponer, firmar y ejecutar** transacciones con un flujo de aprobación por umbral.
- 🔍 **Trazar cada acción** con un historial de eventos que se puede auditar.
- 🏢 **Operar varias organizaciones** en una misma instalación, con aislamiento entre ellas.
- 🔐 **Gestionar accesos y recuperación** de cuentas con roles de administración y de miembro.
- 📬 **Notificar por correo** a los miembros, con Brevo y Resend como proveedores.
- 🪙 **Probar sin riesgo** en testnet, con un faucet que entrega tokens CUSD de prueba.

## 🔄 Cómo funciona

```
 Propuesta  ──►  Firmas de los miembros  ──►  Umbral alcanzado  ──►  Ejecución en Stellar
     │                                                                        │
     └──────────────────────  Evento registrado y trazable  ◄─────────────────┘
```

1. Un miembro **propone** un movimiento desde la caja.
2. Los demás miembros **firman** desde el panel.
3. Al llegar al **umbral**, la propuesta se **ejecuta** en la red Stellar.
4. Cada paso queda guardado como **evento**.

## 🧱 Arquitectura

| Capa | Tecnología | Rol |
| --- | --- | --- |
| API | PHP con Slim | REST API, autenticación, reglas de negocio e integración con Stellar |
| Panel | Vue 3, Vite, AdminLTE y Bootstrap | Panel de administración y de miembros |
| Datos | PostgreSQL, MySQL o SQLite | Se elige con `DB_DRIVER` |
| Correo | Brevo y Resend | Notificaciones transaccionales |
| Despliegue | Docker en Render | Backend en contenedor y panel servido con un script de Node |

```
stellar-demo/
├── backend/            API en PHP y Slim
│   ├── public/         Punto de entrada de la aplicación
│   ├── src/            Lógica de dominio y correo
│   ├── seed.php        Base SQLite de prueba
│   └── .env.example    Variables de entorno
└── frontend/
    └── panel/          Panel en Vue 3
```

## 🚀 Puesta en marcha

### Requisitos

- PHP con las extensiones `pdo`, el driver de tu base de datos, `openssl` y `curl`
- Node.js y npm

### 1. Configura el entorno

```bash
cd backend
cp .env.example .env
```

Variables principales:

| Variable | Para qué sirve |
| --- | --- |
| `DB_DRIVER` | Motor de base de datos: `pgsql`, `mysql` o `sqlite` |
| `DATABASE_URL` | URI completa de conexión, que Render inyecta con PostgreSQL |
| `CORS_ALLOWED_ORIGIN` | Orígenes permitidos, separados por comas |
| `CUSD_ISSUER_SECRET` y `CUSD_ISSUER_PUBLIC` | Claves de la cuenta emisora del token CUSD |
| `FAUCET_AMOUNT` | Monto de tokens de prueba que entrega el faucet |
| `BREVO_API_KEY` y `RESEND_API_KEY` | Claves de los proveedores de correo |
| `CORREO_DESDE` | Remitente de los correos |
| `SUPER_ADMIN_EMAIL` y `SUPER_ADMIN_PASSWORD` | Cuenta inicial de superadministración |

> ⚠️ Nunca subas tu archivo `.env` ni las claves secretas de Stellar al repositorio.

### 2. Prepara la base de datos

Con **SQLite** no necesitas un servidor de base de datos. Este script crea y llena `backend/data/caja.sqlite`:

```bash
php backend/seed.php
```

Con **PostgreSQL** o **MySQL**, completa las variables de conexión del `.env` y listo.

### 3. Levanta la API

```bash
php -S localhost:8000 -t backend/public
```

### 4. Levanta el panel

```bash
cd frontend/panel
npm install
npm run dev
```

El servidor de Vite redirige todas las llamadas a `/api` hacia el backend local, o hacia el destino que definas en `VITE_API_TARGET`.

## 👑 Superadministración

Si defines `SUPER_ADMIN_EMAIL` y `SUPER_ADMIN_PASSWORD`, la aplicación crea al iniciar la cuenta raíz asociada a la organización **Cosigna**. Esa cuenta puede crear y gestionar otras organizaciones.

## 📚 Documentación

La documentación técnica de la arquitectura, los endpoints y los flujos está en [DeepWiki](https://deepwiki.com/perroBlanco0/stellar-demo/1-overview).

## 📄 Licencia

Consulta el archivo [LICENSE](./LICENSE).

---

<div align="center">

Hecho con ✦ para que el dinero colectivo se maneje entre todos.

</div>

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': {
        // VITE_API_TARGET permite apuntar a un backend local (ej. http://localhost:8000).
        target: process.env.VITE_API_TARGET || 'https://stellar-demo-backend.onrender.com',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, ''),
      },
    },
  },
});

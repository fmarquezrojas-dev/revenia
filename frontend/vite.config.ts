import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

const backendProxy = {
  target: loadEnv('development', '.', 'FACTURLY_').FACTURLY_API_TARGET || 'http://app:8000',
  changeOrigin: false,
}

export default defineConfig({
  plugins: [react()],

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,

    watch: {
      usePolling: true,
      interval: 500,
    },

    proxy: {
      '/api': backendProxy,
      '/sanctum': backendProxy,
      '/login': backendProxy,
      '/logout': backendProxy,
    },
  },
})
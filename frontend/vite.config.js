import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

const allowedHosts = [
  'serviceop-vbstp.ondigitalocean.app',
  'api.serviceop.ca',
  'serviceop.ca',
  'www.serviceop.ca',
  'localhost',
]

export default defineConfig(({ mode }) => ({
  plugins: [react(), tailwindcss()],
  ...(mode === 'production' && {
    define: {
      'import.meta.env.VITE_API_URL': JSON.stringify(
        process.env.VITE_API_URL || 'https://api.serviceop.ca/api',
      ),
      'import.meta.env.VITE_STORAGE_URL': JSON.stringify(
        process.env.VITE_STORAGE_URL || 'https://api.serviceop.ca',
      ),
    },
  }),
  server: {
    allowedHosts,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/storage': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  preview: {
    allowedHosts,
  },
}))

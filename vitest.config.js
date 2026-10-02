import { defineConfig } from 'vitest/config'

// Configuración propia para no cargar el plugin de Laravel ni Tailwind: el
// motor de fórmulas es JS puro y se prueba sin navegador.
export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.js'],
        environment: 'node',
    },
})

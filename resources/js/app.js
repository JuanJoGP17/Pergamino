/**
 * Livewire se carga desde el bundle (y no con @livewireScripts) para poder
 * registrar componentes Alpine propios ANTES de que Alpine arranque. Los
 * layouts usan @livewireScriptConfig en consecuencia.
 */
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm'
import sheetFormulas from './formula/sheet.js'

Alpine.data('sheetFormulas', sheetFormulas)

Livewire.start()

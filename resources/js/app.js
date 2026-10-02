/**
 * Livewire se carga desde el bundle (y no con @livewireScripts) para poder
 * registrar componentes y directivas Alpine propios ANTES de que Alpine
 * arranque. Los layouts usan @livewireScriptConfig en consecuencia.
 */
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm'
import registerSortable from './builder/sortable.js'
import sheetFormulas from './formula/sheet.js'

Alpine.data('sheetFormulas', sheetFormulas)
registerSortable(Alpine)

Livewire.start()

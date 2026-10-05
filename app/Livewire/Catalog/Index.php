<?php

namespace App\Livewire\Catalog;

use App\Domain\Catalog\Catalog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Catálogo público de plantillas (Fase 7): búsqueda, filtro por línea de
 * juego y orden. Los filtros van en la URL, así se puede compartir una
 * búsqueda.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $query = '';

    #[Url(as: 'linea', except: '')]
    public string $line = '';

    #[Url(as: 'orden', except: 'popular')]
    public string $sort = 'popular';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $catalog = app(Catalog::class);
        $sort = array_key_exists($this->sort, Catalog::SORTS) ? $this->sort : 'popular';

        return view('livewire.catalog.index', [
            'templates' => $catalog->search($this->query, $this->line, $sort)->paginate(18),
            'lines' => $catalog->gameLines(),
            'sorts' => Catalog::SORTS,
        ]);
    }
}

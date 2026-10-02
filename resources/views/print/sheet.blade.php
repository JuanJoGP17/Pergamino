{{--
    Hoja para imprimir (§6.4): todas las pestañas, una por página.

    Una sola vista para dos destinos:
      · el navegador (/hojas/{uuid}/imprimir), con las tipografías del tema y
        un botón de imprimir;
      · el PDF (/hojas/{uuid}/pdf) con dompdf, que no entiende CSS Grid ni
        variables CSS: por eso aquí todo son tablas y colores literales, los de
        la paleta CLARA del tema (el papel es blanco).

    Variables: $sheet, $tabs (SheetPrinter), $theme (tema efectivo), $pdf,
    $watermark (texto o null).
--}}
@php
    $c = $theme['colors'];
    $fonts = \App\Domain\Theme\Fonts::class;
    $heading = $theme['typography']['heading'] ?? 'system_serif';
    $body = $theme['typography']['body'] ?? 'system_serif';

    // dompdf solo trae las DejaVu: se elige la de la misma familia.
    $pdfFamily = fn (string $key) => match (true) {
        in_array($key, ['jetbrains_mono', 'special_elite'], true) => '"DejaVu Sans Mono", monospace',
        in_array($key, ['inter', 'bebas_neue', 'system_sans'], true) => '"DejaVu Sans", sans-serif',
        default => '"DejaVu Serif", serif',
    };
    $headingFont = $pdf ? $pdfFamily($heading) : $fonts::stack($heading);
    $bodyFont = $pdf ? $pdfFamily($body) : $fonts::stack($body);
    $transform = ($theme['typography']['heading_transform'] ?? 'none') === 'uppercase' ? 'uppercase' : 'none';
    $templateName = $sheet->schema()->template['name'] ?? '';
    // Las pilas tipográficas van sin escapar dentro del <style> ({!! !!}): con
    // {{ }} las comillas saldrían como &quot; y el CSS dejaría de valer. Son
    // seguras porque salen del catálogo (Fonts), nunca de lo que escribe nadie.
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $sheet->name }} — {{ $templateName }}</title>
    @unless ($pdf)
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @vite(['resources/css/app.css'])
    @endunless
    <style>
        @page { margin: 14mm 12mm; }
        body { margin: 0; background: #fff; color: {{ $c['ink'] }}; font-family: {!! $bodyFont !!}; font-size: 10pt; line-height: 1.3; }
        .page { max-width: 190mm; margin: 0 auto; }
        h1 { margin: 0; font-family: {!! $headingFont !!}; color: {{ $c['accent'] }}; font-size: 20pt; text-transform: {{ $transform }}; }
        .meta { color: {{ $c['muted'] }}; font-size: 8.5pt; margin: 2pt 0 10pt; }
        .tab { page-break-before: always; }
        .tab.first { page-break-before: auto; }
        h2 { font-family: {!! $headingFont !!}; color: {{ $c['accent'] }}; font-size: 13pt; margin: 0 0 6pt; padding-bottom: 2pt;
             border-bottom: 2px solid {{ $c['accent'] }}; text-transform: {{ $transform }}; }
        .section { border: 1px solid {{ $c['border'] }}; background: {{ $c['surface'] }}; margin: 0 0 8pt; padding: 5pt 6pt; }
        h3 { font-family: {!! $headingFont !!}; color: {{ $c['accent'] }}; font-size: 10.5pt; margin: 0 0 3pt; text-transform: {{ $transform }}; }
        .desc { color: {{ $c['muted'] }}; font-size: 8pt; margin: 0 0 3pt; }
        table.sheet-grid { width: 100%; border-collapse: separate; border-spacing: 4pt 3pt; table-layout: fixed; }
        td.cell { vertical-align: top; padding: 1pt 2pt 3pt; border-bottom: 1px solid {{ $c['border'] }}; }
        td.heading { border-bottom: 1px solid {{ $c['muted'] }}; padding-top: 4pt; font-family: {!! $headingFont !!}; font-weight: bold;
                     text-transform: uppercase; color: {{ $c['muted'] }}; font-size: 8.5pt; }
        .lbl { display: block; font-size: 6.8pt; text-transform: uppercase; letter-spacing: 0.3pt; color: {{ $c['muted'] }}; }
        .val { font-size: 10.5pt; white-space: pre-line; }
        .sub { font-size: 8pt; color: {{ $c['accent'] }}; }
        .roll { font-size: 7.5pt; color: {{ $c['muted'] }}; font-family: "DejaVu Sans Mono", monospace; }
        .boxes { font-family: "DejaVu Sans", sans-serif; font-size: 12pt; letter-spacing: 1.5pt; color: {{ $c['accent'] }}; }
        table.data { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-top: 2pt; }
        table.data th { text-align: left; background: {{ $c['shade'] }}; font-size: 7pt; text-transform: uppercase; color: {{ $c['muted'] }}; }
        table.data th, table.data td { border: 1px solid {{ $c['border'] }}; padding: 1.5pt 3pt; }
        td.num { text-align: right; width: 14%; font-weight: bold; color: {{ $c['accent'] }}; }
        td.lvl { width: 22%; color: {{ $c['muted'] }}; font-size: 7.5pt; }
        img.media { max-width: 100%; max-height: 55mm; }
        .watermark { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 54pt; color: #ececec;
                     transform: rotate(-28deg); z-index: -1; font-family: {!! $headingFont !!}; }
        .toolbar { display: flex; gap: 8px; justify-content: flex-end; padding: 10px; font-family: system-ui, sans-serif; font-size: 13px; }
        @media print { .toolbar { display: none; } body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    @unless ($pdf)
        <div class="toolbar">
            <a href="{{ route('sheets.edit', $sheet) }}" class="pg-btn-ghost">← Volver a la hoja</a>
            <a href="{{ route('sheets.pdf', $sheet) }}{{ $watermark ? '?marca=1' : '' }}" class="pg-btn-ghost">Descargar PDF</a>
            <button type="button" class="pg-btn" onclick="window.print()">Imprimir</button>
        </div>
    @endunless

    @if ($watermark)
        <div class="watermark">{{ $watermark }}</div>
    @endif

    <div class="page">
        @foreach ($tabs as $tab)
            <div class="tab {{ $loop->first ? 'first' : '' }}">
                @if ($loop->first)
                    <h1>{{ $sheet->name }}</h1>
                    <p class="meta">{{ $templateName }} · {{ now()->format('d/m/Y') }}</p>
                @endif

                <h2>{{ $tab['label'] }}</h2>

                @foreach ($tab['sections'] as $section)
                    <div class="section">
                        @if ($section['label'])
                            <h3>{{ $section['label'] }}</h3>
                        @endif
                        @if ($section['description'])
                            <p class="desc">{{ $section['description'] }}</p>
                        @endif

                        <table class="sheet-grid">
                            {{-- Las 12 columnas de la rejilla: sin ellas, con table-layout: fixed
                                 y solo colspans, el navegador no sabe repartir el ancho. --}}
                            <colgroup>@for ($i = 0; $i < 12; $i++)<col style="width: 8.333%">@endfor</colgroup>
                            @foreach ($section['rows'] as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        @if ($cell['kind'] === 'heading')
                                            <td class="heading" colspan="12">{{ $cell['label'] }}</td>
                                            @continue
                                        @endif
                                        <td class="cell" colspan="{{ $cell['span'] }}" style="width: {{ round($cell['span'] / 12 * 100, 2) }}%">
                                            <span class="lbl">{{ $cell['label'] }}</span>
                                            @switch($cell['kind'])
                                                @case('boxes')
                                                    <span class="boxes">{{ implode(' ', $cell['boxes']) }}</span>
                                                    @if ($cell['legend']) <div class="desc">{{ $cell['legend'] }}</div> @endif
                                                    @break
                                                @case('table')
                                                    <table class="data">
                                                        <tr>@foreach ($cell['columns'] as $col)<th>{{ $col }}</th>@endforeach</tr>
                                                        @forelse ($cell['rows'] as $r)
                                                            <tr>@foreach ($r as $v)<td>{{ $v }}</td>@endforeach</tr>
                                                        @empty
                                                            <tr><td colspan="{{ count($cell['columns']) }}">&nbsp;</td></tr>
                                                        @endforelse
                                                    </table>
                                                    @break
                                                @case('list')
                                                    <table class="data">
                                                        @foreach ($cell['rows'] as $r)
                                                            <tr><td class="lvl">{{ $r['level'] }}</td><td>{{ $r['label'] }}</td><td class="num">{{ $r['bonus'] }}</td></tr>
                                                        @endforeach
                                                    </table>
                                                    @break
                                                @case('image')
                                                    @if ($cell['src'] ?? null)
                                                        <img class="media" src="{{ $cell['src'] }}" alt="{{ $cell['label'] }}">
                                                    @elseif (! $pdf && ($cell['media'] ?? null))
                                                        <img class="media" src="{{ \App\Domain\Media\MediaUrl::for((int) $cell['media']) }}" alt="{{ $cell['label'] }}">
                                                    @else
                                                        <span class="val">&nbsp;</span>
                                                    @endif
                                                    @break
                                                @default
                                                    <span class="val">{{ $cell['text'] !== '' ? $cell['text'] : ' ' }}</span>
                                                    @if ($cell['sub']) <span class="sub">{{ $cell['sub'] }}</span> @endif
                                            @endswitch
                                            @if ($cell['roll'])
                                                <div class="roll">{{ $cell['roll'] }}</div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</body>
</html>

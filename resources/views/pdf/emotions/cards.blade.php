<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>MindBloom • Tarjetas de emociones</title>
  <style>
    @page { margin: 14mm; }
    body { font-family: DejaVu Sans, sans-serif; color: #111827; }

    .page-title{
      font-size: 14px;
      margin: 0 0 10px 0;
      color: #0f172a;
    }
    .page-sub{
      margin: 0 0 14px 0;
      font-size: 11px;
      color: #475569;
    }

    table.grid { width: 100%; border-collapse: separate; border-spacing: 10px; }
    td.cell { width: 33.33%; vertical-align: top; }

    .card{
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      overflow: hidden;
      background: #ffffff;
    }

    .imgwrap{
      height: 150px;
      background: #f1f5f9;
      position: relative;
    }

    .imgwrap img{
      width: 100%;
      height: 100%;
      object-fit: cover;
      display:block;
    }

    .body{
      padding: 10px 12px 12px 12px;
    }

    .pill{
      display:inline-block;
      font-size: 9px;
      padding: 4px 8px;
      border-radius: 999px;
      background: #eef2ff;
      color: #3730a3;
      margin-bottom: 6px;
    }

    .name{
      font-size: 14px;
      margin: 0 0 4px 0;
      color: #0f172a;
    }

    .subtitle{
      font-size: 10.5px;
      margin: 0;
      color: #334155;
      line-height: 1.35;
      min-height: 28px;
    }

    .accentbar{
      height: 6px;
      width: 100%;
    }

    .footer{
      margin-top: 10px;
      font-size: 9px;
      color: #94a3b8;
      text-align: right;
    }

    .page-break { page-break-after: always; }
  </style>
</head>
<body>

@php
  // Layout por página:
  // 6 => 2 columnas x 3 filas
  // 9 => 3 columnas x 3 filas
  $cols = $perPage === 9 ? 3 : 2;
@endphp

@foreach($pages as $pi => $page)
  <p class="page-title">MindBloom • Tarjetas de emociones</p>
  <p class="page-sub">Imprime, recorta y usa estas tarjetas para identificar y expresar lo que sientes.</p>

  <table class="grid">
    @php
      $items = $page->values();
      $rows = (int) ceil($items->count() / $cols);
      $k = 0;
    @endphp

    @for($r = 0; $r < $rows; $r++)
      <tr>
        @for($c = 0; $c < $cols; $c++)
          <td class="cell">
            @if($k < $items->count())
              @php $e = $items[$k]; @endphp

              <div class="card">
                <div class="accentbar" style="background: {{ $e->accent }};"></div>

                <div class="imgwrap">
                  @if($e->img)
                    <img src="{{ $e->img }}" alt="Emoción {{ $e->name }}">
                  @endif
                </div>

                <div class="body">
                  <div class="pill">MindBloom • emoción</div>
                  <h3 class="name">{{ $e->name }}</h3>
                  <p class="subtitle">{{ $e->subtitle }}</p>
                </div>
              </div>

              @php $k++; @endphp
            @endif
          </td>
        @endfor
      </tr>
    @endfor
  </table>

  <div class="footer">MindBloom — bienestar emocional</div>

  @if($pi < $pages->count() - 1)
    <div class="page-break"></div>
  @endif
@endforeach

</body>
</html>
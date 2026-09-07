<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Emotion;

class PdfController extends Controller
{
    public function emotionCards(Request $request)
    {
        $perPage = (int) $request->query('per_page', 6); // 6 o 9 recomendado
        if (!in_array($perPage, [6, 9], true)) $perPage = 6;

        $emotions = Emotion::query()
            ->orderBy('name')
            ->get();

        // Convertir imágenes a base64 para evitar broncas de rutas/permisos
        $items = $emotions->map(function ($e) {
            $path = $e->image_path;

            // En tu caso guardas en disk public (Storage::url funciona en web)
            // Para PDF, conviene leer el archivo real:
            $absolute = Storage::disk('public')->path(str_replace('public/', '', $path));

            $imgData = null;
            if (is_file($absolute)) {
                $mime = mime_content_type($absolute) ?: 'image/jpeg';
                $imgData = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($absolute));
            }

            return (object) [
                'name' => $e->name,
                'subtitle' => $e->subtitle ?? 'Respira, aquí estás a salvo.',
                'accent' => $e->accent ?? '#B79CFF',
                'img' => $imgData,
            ];
        });

        $pages = $items->chunk($perPage);

        $pdf = Pdf::loadView('pdf.emotions.cards', [
            'pages' => $pages,
            'perPage' => $perPage,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('mindbloom-tarjetas-emociones.pdf');
    }
}
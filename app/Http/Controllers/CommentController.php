<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Comment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class CommentController extends Controller
{
    private function saveToPublicUploads(?UploadedFile $file, string $baseDir): array
    {
        if (!$file) return [null, null, null];

        if (!$file->isValid()) {
            return [null, null, 'No se pudo subir el archivo: ' . $file->getErrorMessage()];
        }

        $mime = strtolower((string) $file->getMimeType());

        $allowedImageMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
        $allowedVideoMimes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];

        $isImage = in_array($mime, $allowedImageMimes, true);
        $isVideo = in_array($mime, $allowedVideoMimes, true);

        if (!$isImage && !$isVideo) {
            return [null, null, "Tipo de archivo no permitido. MIME detectado: {$mime}"];
        }

        $mediaType = $isImage ? 'image' : 'video';

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg' => 'ogg',
            'video/quicktime' => 'mov',
            default => strtolower((string) $file->getClientOriginalExtension()) ?: 'bin',
        };

        $sub = $mediaType === 'image' ? 'images' : 'videos';

        $relativeDir = "uploads/{$baseDir}/{$sub}";
        $targetDir = public_path($relativeDir);

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            return [null, null, "No se puede escribir en: {$targetDir}"];
        }

        $filename = Str::random(28) . '.' . $ext;

        try {
            $file->move($targetDir, $filename);
        } catch (\Throwable $e) {
            return [null, null, "Fallo al mover archivo: " . $e->getMessage()];
        }

        $publicPath = "{$relativeDir}/{$filename}";

        return [$publicPath, $mediaType, null];
    }

    public function store(Request $request)
    {
        $request->validate([
            'post_id' => ['required', 'exists:posts,id'],
            'content' => ['nullable', 'string', 'max:500'],
            'media'   => ['nullable', 'file', 'max:20480'],
        ]);

        $content = $request->filled('content') ? trim((string) $request->input('content')) : null;
        if ($content === '') $content = null;

        $file = $request->file('media');
        $hasFile = $file instanceof UploadedFile;

        if (!$content && !$hasFile) {
            return back()->withErrors(['content' => 'Escribe algo o sube una foto/video.'])->withInput();
        }

        $mediaPath = null;
        $mediaType = null;

        if ($hasFile) {
            [$path, $type, $err] = $this->saveToPublicUploads($file, 'community/comments');
            if ($err) return back()->withErrors(['media' => $err])->withInput();

            $mediaPath = $path;
            $mediaType = $type;
        }

        Comment::create([
            'post_id'    => $request->post_id,
            'user_id'    => auth()->id(),
            'content'    => $content,
            'media_path' => $mediaPath,
            'media_type' => $mediaPath ? $mediaType : null,
        ]);

        return back();
    }

    public function destroy(Comment $comment)
    {
        if ($comment->user_id !== auth()->id()) abort(403);

        if ($comment->media_path) {
            $full = public_path($comment->media_path);
            if (is_file($full)) @unlink($full);
        }

        $comment->delete();

        return back();
    }
}
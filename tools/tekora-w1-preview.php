<?php

// CLI-only, disposable visual export. Never opens a persistent database or writes .env.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$target = $argv[1] ?? null;
$origin = $argv[2] ?? 'http://127.0.0.1:8015';
$url = parse_url($origin);
if (! $target || file_exists($target) || ! in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)
    || ($url['scheme'] ?? '') !== 'http' || isset($url['user']) || isset($url['pass'])
    || isset($url['query']) || isset($url['fragment']) || ! empty($url['path'])) {
    fwrite(STDERR, "Use a NEW export directory and a loopback origin, e.g. php tools/tekora-w1-preview.php <TEMP-directory> http://127.0.0.1:8015\n");
    exit(1);
}
require dirname(__DIR__).'/tests/bootstrap.php';
try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->environment('testing') || config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:' || config('database.connections.sqlite.url')) {
        throw new LogicException('Visual export requires SQLite :memory: in testing.');
    }
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $app['url']->forceRootUrl($origin);
    $app->make(\Illuminate\Foundation\Vite::class)->useHotFile(sys_get_temp_dir().'/tekora-w1-no-hot-'.bin2hex(random_bytes(8)));
    mkdir($target, 0700, true);
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $save = function (string $uri, string $file) use ($kernel, $origin, $target) {
        $response = $kernel->handle(\Illuminate\Http\Request::create($origin.$uri));
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Preview render failed.');
        }
        file_put_contents($target.'/'.$file, $response->getContent());
        file_put_contents($target.'/headers.json', json_encode([
            'Content-Security-Policy' => $response->headers->get('Content-Security-Policy'),
            'X-Content-Type-Options' => $response->headers->get('X-Content-Type-Options'),
        ], JSON_PRETTY_PRINT));
    };
    $save('/', 'empty.html');
    // Explicit synthetic fixtures for screenshots only; none live in production views or seeders.
    foreach ([
        ['Diseño de identidad visual', 'Una identidad clara para darle forma a tu proyecto.', 'Diseño y creatividad', 'diseno-creatividad', 'María Torres', 'Diseñadora de identidad visual'],
        ['Desarrollo de sitios web', 'Un sitio cuidado y funcional para el siguiente paso de tu negocio.', 'Tecnología', 'tecnologia', 'Daniel Ríos', 'Desarrollo web'],
        ['Soporte para tu equipo', 'Diagnóstico y acompañamiento para tus herramientas de trabajo.', 'Soporte técnico', 'soporte-tecnico', 'Lucía Vega', 'Soporte y mantenimiento'],
    ] as [$title, $description, $categoryName, $slug, $name, $headline]) {
        $owner = \App\Models\User::factory()->create();
        $owner->roles()->attach(\App\Models\Role::where('slug', 'provider')->value('id'));
        $category = \App\Models\ServiceCategory::firstOrCreate(['slug' => $slug], ['name' => $categoryName, 'is_active' => true]);
        $profile = $owner->providerProfile()->create(['display_name' => $name, 'headline' => $headline]);
        $profile->services()->create(['title' => $title, 'description' => $description, 'service_category_id' => $category->id, 'modality' => 'remote', 'status' => 'published']);
    }
    $save('/', 'home.html');
    $save('/login', 'login.html');
    $save('/registro', 'register.html');
    $save('/login?role=prof', 'legacy.html');
    file_put_contents($target.'/errors.html', view('login', ['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag([
        'email' => 'Las credenciales proporcionadas no coinciden.',
    ]))])->render());
    \Illuminate\Support\Facades\DB::disconnect();
    echo "Export complete: SQLite :memory: only; synthetic data; static preview has no login/POST backend.\n";
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Visual export failed: '.$exception->getMessage()."\n");
    exit(1);
}

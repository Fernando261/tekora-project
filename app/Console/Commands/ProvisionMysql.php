<?php

namespace App\Console\Commands;

use App\Database\PricingMySqlConnection;
use App\Database\Provisioning\MySqlProvisioner;
use App\Database\Provisioning\ProvisioningFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

final class ProvisionMysql extends Command
{
    protected $signature = 'tekora:mysql:provision {--credentials= : Archivo JSON privado del instalador} {--verify : Solo verificar una instalación sellada}';

    protected $description = 'Instalar o verificar las 39 migraciones aprobadas de Tekora en MySQL local';

    public function handle(MySqlProvisioner $provisioner): int
    {
        $name = MySqlProvisioner::CONNECTION;
        try {
            $credentials = $this->credentials();
            config(["database.connections.{$name}" => [
                'driver' => 'mysql', 'database' => 'tekora', 'host' => 'localhost',
                'unix_socket' => $credentials['socket'], 'username' => 'tekora_migrator', 'password' => $credentials['password'],
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'timezone' => '+00:00', 'strict' => true, 'prefix' => '',
            ]]);
            $db = DB::connection($name);
            if (! $db instanceof PricingMySqlConnection) {
                throw new ProvisioningFailure('Falta el adaptador InnoDB aprobado.');
            }
            $this->info($provisioner->run($db, app('migrator')->setOutput($this->output), (bool) $this->option('verify')));

            return self::SUCCESS;
        } catch (ProvisioningFailure $error) {
            $this->error($error->getMessage());
        } catch (Throwable $error) {
            $native = $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null;
            $suffix = is_int($native) ? " (MySQL {$native})" : '';
            $this->error('Aprovisionamiento detenido'.$suffix.'. Revisar permisos, metadatos y marca de instalación; no reanudar automáticamente.');
        } finally {
            DB::purge($name);
            config(["database.connections.{$name}" => null]);
        }

        return self::FAILURE;
    }

    private function credentials(): array
    {
        $path = $this->option('credentials');
        if (! is_string($path) || $path === '' || ! is_file($path) || is_link($path)
            || (fileperms($path) & 0077) !== 0 || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())
            || filesize($path) > 4096) {
            throw new ProvisioningFailure('Se requiere un archivo JSON propio y privado, modo 0600 o 0400.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_string($data['password'] ?? null) || $data['password'] === ''
            || ! is_string($data['socket'] ?? null) || ! str_starts_with($data['socket'], '/')
            || ! file_exists($data['socket']) || filetype($data['socket']) !== 'socket') {
            throw new ProvisioningFailure('Se requiere contraseña de aprovisionamiento y socket Unix local.');
        }

        return $data;
    }
}

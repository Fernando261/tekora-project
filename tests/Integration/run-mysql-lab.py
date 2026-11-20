"""Provision ONLY a new private disposable Docker lab; no SQL purge or host mounts.
Run after the SQLite validation. Requires the worker built from Dockerfile.w62cb.
The deliberately failing install test reports BLOCKED when historical migrations
reject MySQL; it never skips migrations or attempts downstream retirement then.
"""
import argparse
import io
import json
import os
from pathlib import Path
import secrets
import subprocess
import tarfile
import tempfile
import time

MYSQL_IMAGE = 'mysql@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902'
ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True, help='New private output directory')
    parser.add_argument('--worker-image', default='tekora-w62cb-php:local')
    args = parser.parse_args()
    args.output.mkdir(mode=0o700, parents=False, exist_ok=False)
    env = os.environ.copy()
    for key in ['DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH']:
        env.pop(key, None)
    run = secrets.token_hex(8)
    network = 'w62cb-net-' + run
    label = 'tekora.w62cb.run=' + run
    owned = []
    network_created = False
    summary = {'run': run, 'mysql_image': MYSQL_IMAGE, 'status': 'BLOCKED', 'cleanup': [], 'host_mounts': False,
               'published_ports': False, 'real_env_or_media_imported': False}

    def docker(*command, input=None, check=True):
        result = subprocess.run(['docker', '--host=unix:///var/run/docker.sock', *command],
                                input=input, capture_output=True, env=env)
        if check and result.returncode:
            # Never include command/environment/SQL/inspect payload in errors.
            raise RuntimeError('Docker operation failed: ' + command[0])
        return result

    def inspect_owned(container):
        obj = json.loads(docker('inspect', container).stdout)[0]
        if obj['Config']['Labels'].get('tekora.w62cb.run') != run:
            raise RuntimeError('Container ownership mismatch')
        if set(obj['NetworkSettings']['Networks']) != {network} or obj['HostConfig'].get('Privileged'):
            raise RuntimeError('Unexpected network or privileges')
        if obj['HostConfig']['PortBindings'] or any(v for v in obj['NetworkSettings']['Ports'].values()):
            raise RuntimeError('Published ports forbidden')
        if any(m['Type'] != 'tmpfs' for m in obj['Mounts']):
            raise RuntimeError('Persistent/host mounts forbidden')
        return {'container_id': obj['Id'], 'image_id': obj['Image'], 'hostname': obj['Config']['Hostname'],
                'networks': list(obj['NetworkSettings']['Networks']), 'mount_types': [m['Type'] for m in obj['Mounts']],
                'tmpfs_paths': list(obj['HostConfig'].get('Tmpfs', {})), 'port_bindings': obj['HostConfig']['PortBindings']}

    try:
        image = json.loads(docker('image', 'inspect', MYSQL_IMAGE).stdout)[0]
        summary['mysql_repo_digests'] = image['RepoDigests']
        summary['worker_image_id'] = docker('image', 'inspect', args.worker_image, '--format', '{{.Id}}').stdout.decode().strip()
        docker('network', 'create', '--internal', '--label', label, network)
        network_created = True
        net = json.loads(docker('network', 'inspect', network).stdout)[0]
        if not net['Internal'] or net['Containers']:
            raise RuntimeError('New isolated network not empty/internal')
        summary['internal_network_verified'] = True
        with tempfile.TemporaryDirectory(prefix='tekora-w62cb-secrets-') as secret_dir:
            secret_dir = Path(secret_dir)
            database = 'w62cblab' + run
            user = 'lab_' + run
            password = secrets.token_hex(24)
            root_password = secrets.token_hex(24)
            marker = secrets.token_hex(32)
            envfile = secret_dir / 'mysql.env'
            envfile.write_text(f'MYSQL_ROOT_PASSWORD={root_password}\nMYSQL_DATABASE={database}\nMYSQL_USER={user}\nMYSQL_PASSWORD={password}\n')
            envfile.chmod(0o600)
            mysql = docker('run', '-d', '--name', 'w62cb-mysql-' + run, '--label', label,
                           '--hostname', 'w62cb-mysql-' + run, '--network', network, '--network-alias', 'mysql-lab',
                           '--tmpfs', '/var/lib/mysql:rw,nosuid,noexec,size=1024m', '--env-file', str(envfile),
                           MYSQL_IMAGE, '--skip-log-bin').stdout.decode().strip()
            owned.append(mysql)
            summary['mysql_container'] = inspect_owned(mysql)
            if '/var/lib/mysql' not in summary['mysql_container']['tmpfs_paths']:
                raise RuntimeError('MySQL datadir not ephemeral')
            started = time.monotonic()
            while time.monotonic() - started < 180:
                ready = docker('exec', mysql, 'sh', '-c',
                               'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot --batch --skip-column-names -e "SELECT 1"', check=False)
                if ready.returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError('MySQL readiness timed out; logs not printed to protect credentials')

            def root_sql(sql):
                return docker('exec', '-i', mysql, 'sh', '-c',
                              'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --protocol=socket -uroot --batch --skip-column-names', input=sql.encode()).stdout.decode().strip()

            # Bootstrap marker only, after ownership, network, mount and server checks.
            identity = root_sql('SELECT @@hostname,@@server_uuid,VERSION(),@@default_storage_engine;').split('\t')
            if identity[0] != 'w62cb-mysql-' + run or not identity[2].startswith('8.4.') or identity[3] != 'InnoDB':
                raise RuntimeError('Unexpected server before marker provisioning')
            root_sql(f'USE `{database}`; CREATE TABLE w62cb_identity (marker VARCHAR(64) PRIMARY KEY) ENGINE=InnoDB; INSERT INTO w62cb_identity VALUES (\'{marker}\');')
            summary['server'] = {'hostname': identity[0], 'uuid': identity[1], 'version': identity[2], 'engine': identity[3]}
            worker = docker('run', '-d', '--name', 'w62cb-php-' + run, '--label', label, '--network', network,
                            '-e', 'TEKORA_MYSQL_LAB_ENABLE=1',
                            '-e', 'HTTP_PROXY=', '-e', 'HTTPS_PROXY=', '-e', 'http_proxy=', '-e', 'https_proxy=',
                            args.worker_image, 'sleep', 'infinity').stdout.decode().strip()
            owned.append(worker)
            summary['worker_container'] = inspect_owned(worker)
            net = json.loads(docker('network', 'inspect', network).stdout)[0]
            if set(net['Containers']) != set(owned):
                raise RuntimeError('Unexpected container on private network')
            # Copy only explicitly allowed CODE. Exclusions happen BEFORE any open.
            archive = io.BytesIO()
            count = 0
            with tarfile.open(fileobj=archive, mode='w') as tar:
                for root in ['app', 'config', 'bootstrap', 'routes', 'database/migrations', 'vendor', 'tests/Integration']:
                    for directory, dirs, names in os.walk(ROOT / root, followlinks=False):
                        dirs[:] = [d for d in dirs if not d.startswith('.') and not (Path(directory) / d).is_symlink()]
                        for name in names:
                            path = Path(directory) / name
                            relative = path.relative_to(ROOT).as_posix()
                            if relative.startswith('bootstrap/cache/') or path.is_symlink() or name.startswith('.'):
                                continue
                            if path.suffix not in {'.php', '.json', '.xml', '.xsd'} and relative != 'vendor/phpunit/phpunit/phpunit':
                                continue
                            if relative.startswith(('public/', 'storage/')) or name == '.env' or path.suffix in {'.sqlite', '.db'}:
                                raise RuntimeError('Forbidden input path')
                            info = tar.gettarinfo(str(path), arcname=relative)
                            info.uid = info.gid = 1000
                            info.mode = 0o600
                            with path.open('rb') as handle:
                                tar.addfile(info, handle)
                            count += 1
                path = ROOT / 'composer.json'
                info = tar.gettarinfo(str(path), arcname='composer.json')
                info.uid = info.gid = 1000
                info.mode = 0o600
                with path.open('rb') as handle:
                    tar.addfile(info, handle)
                count += 1
                for directory in ['bootstrap/cache', 'storage/framework/views', 'storage/logs']:
                    info = tarfile.TarInfo(directory)
                    info.type = tarfile.DIRTYPE
                    info.uid = info.gid = 1000
                    info.mode = 0o700
                    tar.addfile(info)
            docker('cp', '-a', '-', worker + ':/app', input=archive.getvalue())
            summary['copied_code_files'] = count
            config = {'run': run, 'host': 'mysql-lab', 'database': database, 'user': user, 'password': password,
                      'marker': marker, 'server_uuid': identity[1], 'server_hostname': identity[0], 'docker_verified': True}
            configfile = secret_dir / 'lab.json'
            configfile.write_text(json.dumps(config))
            configfile.chmod(0o600)
            docker('cp', str(configfile), worker + ':/run/tekora-lab.json')
            docker('exec', '-u', '0', worker, 'chown', '1000:1000', '/run/tekora-lab.json')
            run_result = docker('exec', worker, 'php', '/app/vendor/phpunit/phpunit/phpunit',
                                '-c', '/app/tests/Integration/mysql-phpunit.xml', '--do-not-cache-result',
                                '--log-junit', '/tmp/w62cb-junit.xml', check=False)
            # Tests sanitize database exceptions; no server startup logs or environment dumps.
            (args.output / 'phpunit.txt').write_bytes(run_result.stdout + run_result.stderr)
            for name in ['w62cb-result.json', 'w62cb-junit.xml']:
                docker('cp', worker + ':/tmp/' + name, str(args.output / name), check=False)
            summary['phpunit_exit_code'] = run_result.returncode
            summary['historical_install_worker_exit'] = run_result.returncode
            summary['status'] = 'BLOCKED'
            summary['coverage'] = 'Installation gate and identity negatives only; downstream retirement/restoration not implemented pending historical schema compatibility'
            if run_result.returncode == 0:
                summary['reason'] = 'Installation passed, but downstream acceptance gates remain NOT RUN; not full lab approval'
    except Exception as error:
        summary['status'] = 'BLOCKED'
        summary['reason'] = str(error) if isinstance(error, RuntimeError) else type(error).__name__
    finally:
        for container in reversed(owned):
            obj = json.loads(docker('inspect', container).stdout)[0]
            if obj['Config']['Labels'].get('tekora.w62cb.run') == run:
                result = docker('rm', '-f', container, check=False)
                summary['cleanup'].append({'container_id': container, 'removed': result.returncode == 0})
        if network_created:
            net = json.loads(docker('network', 'inspect', network).stdout)[0]
            if net['Labels'].get('tekora.w62cb.run') == run and not net['Containers']:
                result = docker('network', 'rm', network, check=False)
                summary['cleanup'].append({'network': network, 'removed': result.returncode == 0})
        (args.output / 'isolation.json').write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps({'status': summary['status'], 'output': str(args.output), 'cleanup': summary['cleanup']}))
    return 0 if summary['status'] == 'PASS' else 1


if __name__ == '__main__':
    raise SystemExit(main())

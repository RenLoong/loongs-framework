<?php

declare(strict_types=1);

namespace Loongs\Service;

use Loongs\Container\Container;
use Loongs\Process\ProcessLog;
use Loongs\Rpc\Client\RpcClientInterface;
use Throwable;

/**
 * After AppDiscovery loads rpc.php handlers, push each loongs.json services[]
 * role=sub registration to its main via RpcClient (local transport still uses
 * the RPC protocol — no cross-app class calls).
 *
 * Failures are logged as WARN and do not abort boot.
 */
final class ServiceBoot
{
    public static function registerSubs(Container $container, string $appsPath): void
    {
        if (!$container->has(RpcClientInterface::class) && !$container->has(\Loongs\Rpc\Client\RpcClient::class)) {
            return;
        }
        try {
            /** @var RpcClientInterface $rpc */
            $rpc = $container->make(RpcClientInterface::class);
        } catch (Throwable) {
            return;
        }

        $appsPath = rtrim($appsPath, '/\\');
        foreach (glob($appsPath . '/*/' . Manifest::FILE) ?: [] as $file) {
            $appDir = dirname((string) $file);
            $app = basename($appDir);
            $doc = Manifest::read($appDir);
            if ($doc === null) {
                continue;
            }
            foreach (Manifest::services($doc) as $svc) {
                if ((string) ($svc['role'] ?? '') !== 'sub') {
                    continue;
                }
                $id = strtolower(trim((string) ($svc['id'] ?? '')));
                $main = strtolower(trim((string) ($svc['main'] ?? '')));
                if ($id === '' || $main === '') {
                    continue;
                }
                $method = Manifest::methodPrefix($id) . '.register';
                try {
                    $rpc->call($main, $method, [
                        'app' => $app,
                        'package' => (string) ($doc['package'] ?? ''),
                        'version' => isset($doc['version']) ? (string) $doc['version'] : null,
                        'rpc_service' => strtolower($app),
                    ]);
                } catch (Throwable $e) {
                    ProcessLog::warn(
                        sprintf('register %s as %s sub → %s failed: %s', $app, $id, $main, $e->getMessage()),
                        'service-boot',
                    );
                }
            }
        }
    }
}

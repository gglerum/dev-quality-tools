<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tools;

/** Runs ZAP against the app: Laravel projects get a temporary server on a scratch database. */
final class ZapRunner
{
    public function run(ToolContext $c): int
    {
        $zap = (string) $c->binaries->find('zap.sh');
        $zapCommand = static fn (string $target): array => [$zap, '-cmd', '-autorun', $c->project->path('zap.yaml'), '-dir', sys_get_temp_dir().'/zap-home'];

        $target = $c->project->settings['dast']['target'];
        if ($target !== null) {
            return $c->runner->run($zapCommand($target), ['ZAP_TARGET' => $target]);
        }

        $port = $c->project->settings['dast']['port'];
        $target = 'http://localhost:'.$port;

        return (new ScratchDatabase($c))->with(function (array $env) use ($c, $port, $target, $zapCommand): int {
            $c->runner->run(['php', 'artisan', 'migrate', '--force', '--seed'], $env, $c->report('zap-migrate.txt'));
            // --no-reload: without it `serve` drops variables that are also in .env, and the server would use the dev database
            $server = $c->runner->start(['php', 'artisan', 'serve', '--no-reload', '--port='.$port], $env);
            sleep(3);
            try {
                return $c->runner->run($zapCommand($target), ['ZAP_TARGET' => $target]);
            } finally {
                $server->stop(2);
            }
        });
    }
}

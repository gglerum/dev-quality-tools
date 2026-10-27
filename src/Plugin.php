<?php

declare(strict_types=1);

namespace Gglerum\QualityTools;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Gglerum\QualityTools\Config\Initializer;
use Gglerum\QualityTools\Install\BinaryInstaller;

/**
 * After `composer install` / `composer update` (when the package is installed): download the external tools and,
 * the first time, scan the project and write the configs and hook. Failures only warn; they never break Composer.
 * Set QUALITY_TOOLS_SKIP=1 (for example in CI or Docker builds) to skip all of it.
 */
final class Plugin implements EventSubscriberInterface, PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'setup',
            ScriptEvents::POST_UPDATE_CMD => 'setup',
        ];
    }

    public function setup(Event $event): void
    {
        $io = $event->getIO();
        if (getenv('QUALITY_TOOLS_SKIP') === '1') {
            $io->write('<comment>quality-tools: skipped (QUALITY_TOOLS_SKIP=1)</comment>');

            return;
        }

        try {
            $root = dirname((string) $event->getComposer()->getConfig()->get('vendor-dir'));
            $project = Project::load($root);
            $log = static fn (string $line) => $io->write('  quality-tools: '.$line);

            $io->write('<info>quality-tools: scanning the project and installing the external tools</info>');
            (new Initializer($project))->run(false, $log);
            // ZAP is a large download; `vendor/bin/quality-tools install` fetches it when the DAST category is wanted
            (new BinaryInstaller($project))->installAll($log, getenv('QUALITY_TOOLS_ZAP') === '1');
        } catch (\Throwable $e) {
            $io->writeError('<warning>quality-tools: setup incomplete: '.$e->getMessage().' (run vendor/bin/quality-tools init && vendor/bin/quality-tools install)</warning>');
        }
    }
}

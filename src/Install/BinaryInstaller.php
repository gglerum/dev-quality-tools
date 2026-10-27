<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Install;

use Gglerum\QualityTools\Project;
use Gglerum\QualityTools\Tools\BinaryLocator;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Downloads the tools Composer cannot install into <vendor>/bin-tools. Tools already on the PATH or in
 * vendor/bin are left alone. A failed download is reported but never fails the caller (a composer install must not break).
 */
final class BinaryInstaller
{
    public const GITLEAKS_VERSION = '8.30.1';

    public const TRIVY_VERSION = '0.75.0';

    public const ZAP_VERSION = '2.17.0';

    private readonly BinaryLocator $binaries;

    public function __construct(private readonly Project $project)
    {
        $this->binaries = new BinaryLocator($project);
    }

    /**
     * @param  callable(string): void  $log
     * @return bool true when everything that could be installed was installed
     */
    public function installAll(callable $log, bool $withZap = true): bool
    {
        $ok = true;
        $steps = [
            'gitleaks' => fn (): bool => $this->installGitHubTool('gitleaks', $this->gitleaksUrl(), 'gitleaks_'.self::GITLEAKS_VERSION.'_checksums.txt',
                'https://github.com/gitleaks/gitleaks/releases/download/v'.self::GITLEAKS_VERSION, $log),
            'trivy' => fn (): bool => $this->installGitHubTool('trivy', $this->trivyUrl(), 'trivy_'.self::TRIVY_VERSION.'_checksums.txt',
                'https://github.com/aquasecurity/trivy/releases/download/v'.self::TRIVY_VERSION, $log),
            'semgrep' => fn (): bool => $this->installSemgrep($log),
        ];
        if ($withZap) {
            $steps['zap.sh'] = fn (): bool => $this->installZap($log);
        }

        foreach ($steps as $name => $install) {
            if ($this->binaries->find($name) !== null) {
                $log("present  {$name}");

                continue;
            }
            try {
                $ok = $install() && $ok;
            } catch (\Throwable $e) {
                $detail = $e instanceof ProcessFailedException ? trim($e->getProcess()->getOutput().$e->getProcess()->getErrorOutput()) : $e->getMessage();
                $log("FAILED   {$name}: ".strtok($detail, "\n"));
                $ok = false;
            }
        }

        return $ok;
    }

    /** @param callable(string): void $log */
    private function installGitHubTool(string $name, ?string $asset, string $checksums, string $base, callable $log): bool
    {
        if ($asset === null) {
            $log("skipped  {$name} (unsupported platform ".PHP_OS_FAMILY.'/'.php_uname('m').')');

            return false;
        }
        $dir = $this->project->toolsDir();
        $this->ensureDir($dir);
        $archive = $dir.'/'.$asset;

        $log("download {$name} ({$asset})");
        $this->download($base.'/'.$asset, $archive);
        $this->download($base.'/'.$checksums, $dir.'/'.$checksums);
        $this->verifyChecksum($archive, $asset, $dir.'/'.$checksums);

        $extract = new Process(['tar', '-xzf', $archive, '-C', $dir, $name]);
        $extract->mustRun();
        @unlink($archive);
        @unlink($dir.'/'.$checksums);
        chmod($dir.'/'.$name, 0755);
        $log("installed {$name}");

        return true;
    }

    /** @param callable(string): void $log */
    private function installSemgrep(callable $log): bool
    {
        $python = $this->binaries->find('python3');
        if ($python === null) {
            $log('skipped  semgrep (python3 not found; install it with `pipx install semgrep`)');

            return false;
        }
        $venv = $this->project->toolsDir().'/venv';
        $this->ensureDir($this->project->toolsDir());
        $log('install  semgrep (python venv in '.$venv.')');
        try {
            (new Process([$python, '-m', 'venv', $venv]))->mustRun();
        } catch (ProcessFailedException $e) {
            (new Process(['rm', '-rf', $venv]))->run();

            throw $e;
        }
        (new Process([$venv.'/bin/pip', 'install', '--quiet', 'semgrep'], null, null, null, 600))->mustRun();
        $log('installed semgrep');

        return true;
    }

    /** @param callable(string): void $log */
    private function installZap(callable $log): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $log('skipped  zap (automatic install is Linux only; get it from https://www.zaproxy.org/download/)');

            return false;
        }
        if ($this->binaries->find('java') === null) {
            $log('note     zap needs Java 17+ to run (not found on PATH)');
        }
        $dir = $this->project->toolsDir();
        $this->ensureDir($dir);
        $asset = 'ZAP_'.self::ZAP_VERSION.'_Linux.tar.gz';
        $archive = $dir.'/'.$asset;
        $log("download zap ({$asset}, large)");
        $this->download('https://github.com/zaproxy/zaproxy/releases/download/v'.self::ZAP_VERSION.'/'.$asset, $archive);
        (new Process(['tar', '-xzf', $archive, '-C', $dir]))->mustRun();
        @unlink($archive);
        if (is_dir($dir.'/zap')) {
            (new Process(['rm', '-rf', $dir.'/zap']))->mustRun();
        }
        rename($dir.'/ZAP_'.self::ZAP_VERSION, $dir.'/zap');
        $log('installed zap (no checksum published by ZAP; downloaded over HTTPS from the official release)');

        return true;
    }

    private function gitleaksUrl(): ?string
    {
        $platform = $this->platform(['linux' => 'linux', 'darwin' => 'darwin'], ['x64' => 'x64', 'arm64' => 'arm64']);

        return $platform === null ? null : 'gitleaks_'.self::GITLEAKS_VERSION.'_'.$platform[0].'_'.$platform[1].'.tar.gz';
    }

    private function trivyUrl(): ?string
    {
        $platform = $this->platform(['linux' => 'Linux', 'darwin' => 'macOS'], ['x64' => '64bit', 'arm64' => 'ARM64']);

        return $platform === null ? null : 'trivy_'.self::TRIVY_VERSION.'_'.$platform[0].'-'.$platform[1].'.tar.gz';
    }

    /**
     * @param  array<string, string>  $os
     * @param  array<string, string>  $arch
     * @return array{string, string}|null
     */
    private function platform(array $os, array $arch): ?array
    {
        $osKey = strtolower(PHP_OS_FAMILY);
        $machine = strtolower(php_uname('m'));
        $archKey = in_array($machine, ['x86_64', 'amd64'], true) ? 'x64' : (in_array($machine, ['aarch64', 'arm64'], true) ? 'arm64' : null);

        return isset($os[$osKey], $arch[$archKey ?? '']) ? [$os[$osKey], $arch[$archKey]] : null;
    }

    private function download(string $url, string $target): void
    {
        (new Process(['curl', '--fail', '--silent', '--show-error', '--location', '--output', $target, $url], null, null, null, 900))->mustRun();
    }

    private function verifyChecksum(string $file, string $asset, string $checksumFile): void
    {
        foreach (file($checksumFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$hash, $name] = preg_split('/\s+/', trim($line), 2) + [1 => ''];
            if (ltrim($name, '*') === $asset) {
                if (! hash_equals(strtolower($hash), hash_file('sha256', $file))) {
                    @unlink($file);
                    throw new \RuntimeException("checksum mismatch for {$asset}; download removed");
                }

                return;
            }
        }
        @unlink($file);
        throw new \RuntimeException("no checksum listed for {$asset}; download removed");
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
    }
}

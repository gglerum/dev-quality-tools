# Development

```bash
git clone git@github.com:gglerum/dev-quality-tools.git && cd dev-quality-tools
composer install        # set QUALITY_TOOLS_SKIP=1 if you do not want the plugin's setup to run on this repo
composer test           # PHPUnit
vendor/bin/pint         # format; CI runs `pint --test`
vendor/bin/phpstan analyse src --level=6 --no-progress   # clean at the moment; not in CI yet
```

Tests live in `tests/` and use temporary project directories (`TestCase::project([...])`) created in the system temp
dir and removed in `tearDown`. They never download anything or run the real tools.

## Trying it end to end

Unit tests do not cover the Composer plugin, downloads or the hook. Use a throwaway project that requires the package
by path:

```bash
mkdir /tmp/e2e && cd /tmp/e2e && git init
cat > composer.json <<'JSON'
{"name":"demo/app","require":{"php":"^8.2"},"require-dev":{"gglerum/dev-quality-tools":"*@dev"},
 "repositories":[{"type":"path","url":"/path/to/dev-quality-tools","options":{"symlink":false}}],
 "autoload":{"psr-4":{"Demo\\":"src/"}},
 "config":{"allow-plugins":{"gglerum/dev-quality-tools":true,"infection/extension-installer":true}}}
JSON
mkdir src tests && echo '<?php class A {}' > src/A.php
composer install            # runs the plugin: configs, composer script, hook, downloads
composer analyse sast secrets
git add -A && git commit -m test    # runs the hook
```

`symlink: false` copies the package into `vendor/`, so after changing the package either re-run `composer update` or
copy the changed file into `vendor/gglerum/dev-quality-tools/`. Commit the package first: a path repository of a git
repo reads the branch state.

## Releasing

There are no version tags yet, so consumers require `dev-main`. To release: make sure CI is green, then
`git tag v0.1.0 && git push --tags` (the repo is public, so consumers can then require `^0.1`). To list it on
Packagist, submit the repository URL at packagist.org and enable the GitHub webhook; after that the VCS
repository entry in the README is no longer needed.

## Bumping tool versions

Gitleaks, Trivy and ZAP versions are constants in `src/Install/BinaryInstaller.php`. Release asset names have changed
between versions before, so after a bump run `vendor/bin/quality-tools install` in the scratch project on Linux and
check the checksum step still finds the asset in the `*_checksums.txt` file. The versions currently match the ones the
Retail Safari Dockerfile used.

## Conventions

- PHP 8.2 syntax only; `declare(strict_types=1)` in every file; Pint default preset.
- Types in PHPDoc for arrays (`list<string>`, shapes). Settings shape is documented on `Project`.
- The plugin and the installer must never throw into Composer: catch and print a warning.

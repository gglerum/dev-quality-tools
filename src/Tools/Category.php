<?php

declare(strict_types=1);

namespace Gglerum\QualityTools\Tools;

enum Category: string
{
    case Sast = 'sast';
    case Dast = 'dast';
    case Sca = 'sca';
    case Secrets = 'secrets';
    case Style = 'style';
    case Quality = 'quality';
    case Tests = 'tests';
    case Deploy = 'deploy';

    public function title(): string
    {
        return match ($this) {
            self::Sast => 'Static application security testing and static analysis',
            self::Dast => 'Dynamic application security testing (against the running app)',
            self::Sca => 'Software composition analysis (vulnerable dependencies)',
            self::Secrets => 'Secrets in the code and git history',
            self::Style => 'Formatting, linting and spelling',
            self::Quality => 'Refactoring and type checks',
            self::Tests => 'Tests and mutation testing',
            self::Deploy => 'Deployability checks',
        };
    }
}

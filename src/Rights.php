<?php
declare(strict_types=1);

namespace GlpiPlugin\\Hh;

final class Rights
{
    public const READ = 1;
    public const CREATE = 2;
    public const UPDATE = 4;
    public const APPROVE = 8;
    public const CATALOG = 16;
    public const POINTS = 32;
    public const POINT_VALUE = 64;
    public const ALL = 127;

    public static function can(int $right): bool
    {
        return Session::haveRight('plugin_hh', $right)
            || Session::haveRight('config', UPDATE);
    }

    public static function require(int $right): void
    {
        if (!self::can($right)) {
            Html::displayRightError();
        }
    }
}

<?php
declare(strict_types=1);

namespace GlpiPlugin\Hh;

use Html;
use Session;

/** Centralized authorization checks for native GLPI profile rights. */
final class Rights
{
    public const READ = 1;
    public const CREATE = 2;
    public const UPDATE = 4;
    public const APPROVE = 8;
    public const CATALOG = 16;
    public const POINTS = 32;
    public const POINT_VALUE = 64;
    public const REPORTS = 128;
    public const ALL = 255;

    public static function can(int $right): bool
    {
        return (bool) Session::haveRight('plugin_hh', $right);
    }

    public static function require(int $right): void
    {
        if (!self::can($right)) {
            Html::displayRightError();
        }
    }
}

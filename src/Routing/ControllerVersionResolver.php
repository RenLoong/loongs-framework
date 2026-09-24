<?php

declare(strict_types=1);

namespace Loongs\Routing;

use Loongs\Http\Request;

/**
 * Resolve versioned controller FQCN from request header api-version.
 *
 * Header values like v2 / V2 / 2 → namespace segment V2 inserted before the
 * short class name. Missing versioned class falls back to the registered base.
 */
final class ControllerVersionResolver
{
    /**
     * Normalize api-version header to a namespace segment (e.g. V2), or null.
     * Only v?(\d+) is supported; anything else is ignored (use base class).
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^v?(\d+)$/i', $raw, $m) === 1) {
            return 'V' . $m[1];
        }

        return null;
    }

    /**
     * Insert \{Version}\ before the short class name.
     * App\Website\Controller\IndexController + V2
     *   → App\Website\Controller\V2\IndexController
     */
    public static function versionedClass(string $baseClass, string $versionSegment): string
    {
        $pos = strrpos($baseClass, '\\');
        if ($pos === false) {
            return $versionSegment . '\\' . $baseClass;
        }

        $ns = substr($baseClass, 0, $pos);
        $short = substr($baseClass, $pos + 1);

        return $ns . '\\' . $versionSegment . '\\' . $short;
    }

    /**
     * Resolve the controller class to instantiate for this request.
     * Sets request attributes api_version and controller_class for debugging.
     *
     * @param class-string $baseClass
     * @return class-string
     */
    public static function resolve(Request $request, string $baseClass): string
    {
        $version = self::normalize($request->header('api-version'));
        $request->setAttribute('api_version', $version);

        if ($version === null) {
            $request->setAttribute('controller_class', $baseClass);

            return $baseClass;
        }

        $versioned = self::versionedClass($baseClass, $version);
        if (class_exists($versioned)) {
            $request->setAttribute('controller_class', $versioned);

            return $versioned;
        }

        $request->setAttribute('controller_class', $baseClass);

        return $baseClass;
    }
}

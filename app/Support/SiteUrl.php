<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class SiteUrl
{
    public static function canonical(?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $canonical = strtolower($parts['scheme']).'://';

        if (isset($parts['user'])) {
            $canonical .= $parts['user'];
            $canonical .= isset($parts['pass']) ? ':'.$parts['pass'] : '';
            $canonical .= '@';
        }

        $canonical .= strtolower($parts['host']);
        $canonical .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $canonical .= str_ends_with($path, '/') ? $path : $path.'/';
        $canonical .= isset($parts['query']) ? '?'.$parts['query'] : '';
        $canonical .= isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $canonical;
    }

    public static function matches(?string $left, ?string $right): bool
    {
        $canonical = self::canonical($left);

        return $canonical !== null && $canonical === self::canonical($right);
    }

    public static function first(Builder $query, ?string $url)
    {
        if (! self::canonical($url)) {
            return null;
        }

        return $query->get()->first(fn ($site) => self::matches($site->url, $url));
    }
}

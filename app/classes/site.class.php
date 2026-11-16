<?php

/**
 * Shared page parts for bigz.roundsphere.com: the header (site name, page links, who's signed in, sign out) and the
 * stylesheet link. Styles are in www/site.css, with light and dark colors.
 */
class site
{
    public const SIGN_OUT = '/oauth2callback?logout=https%3A%2F%2Fbigz.roundsphere.com%2F';
    public const PAGES = ['/' => 'Properties', '/cash-flow/' => 'Cash flow', '/upload/' => 'Upload'];

    /** The <head> contents every page shares */
    public static function head(string $title): string
    {
        return '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.htmlspecialchars($title).'</title><link rel="stylesheet" href="/site.css">';
    }

    /** The header bar; $current is the path of the page it's on */
    public static function header(string $current): string
    {
        $links = '';
        foreach (self::PAGES as $path => $label) {
            $links .= '<a href="'.$path.'"'.($path === $current ? ' class="current" aria-current="page"' : '').'>'.htmlspecialchars($label).'</a>';
        }
        $user = $_SERVER['REMOTE_USER'] ?? '';
        return '<header class="site-header"><a class="site-name" href="/">Big Z Properties</a><nav>'.$links.'</nav>'
            .'<span class="site-user">'.htmlspecialchars($user).' <a href="'.self::SIGN_OUT.'">Sign out</a></span></header>';
    }
}

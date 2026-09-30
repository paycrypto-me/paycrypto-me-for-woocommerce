<?php
// Minimal wp_register_* helpers and globals for tests
if (!isset($GLOBALS['wp_registered_scripts'])) {
    $GLOBALS['wp_registered_scripts'] = [];
}
if (!isset($GLOBALS['wp_registered_styles'])) {
    $GLOBALS['wp_registered_styles'] = [];
}

if (!function_exists('wp_register_script')) {
    function wp_register_script($handle, $src, $deps = [], $ver = false, $in_footer = false) {
        $GLOBALS['wp_registered_scripts'][$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer];
    }
}

if (!function_exists('wp_register_style')) {
    function wp_register_style($handle, $src, $deps = [], $ver = false, $media = 'all') {
        $GLOBALS['wp_registered_styles'][$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver, 'media' => $media];
    }
}

if (!function_exists('wp_script_is')) {
    function wp_script_is($handle, $list = 'registered') {
        if ($list === 'registered') {
            return isset($GLOBALS['wp_registered_scripts'][$handle]);
        }
        return false;
    }
}

if (!function_exists('wp_style_is')) {
    function wp_style_is($handle, $list = 'registered') {
        if ($list === 'registered') {
            return isset($GLOBALS['wp_registered_styles'][$handle]);
        }
        return false;
    }
}

if (!function_exists('wp_set_script_translations')) {
    function wp_set_script_translations($handle, $domain, $path = '') { return; }
}

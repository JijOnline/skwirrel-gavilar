<?php
/**
 * Plugin Name:       Skwirrel Sync for Gavilar
 * Description:       One-way sync of products, categories, custom features and attachments from the Skwirrel PIM into a custom post type.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Jij Online
 * License:           GPL-2.0-or-later
 * Text Domain:       skwirrel-gavilar
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('SKWIRREL_GAVILAR_VERSION', '0.1.0');
define('SKWIRREL_GAVILAR_FILE', __FILE__);
define('SKWIRREL_GAVILAR_DIR', __DIR__);

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'JijOnline\\SkwirrelGavilar\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require $path;
        }
    });
}

register_activation_hook(__FILE__, [\JijOnline\SkwirrelGavilar\Plugin::class, 'onActivate']);
register_deactivation_hook(__FILE__, [\JijOnline\SkwirrelGavilar\Plugin::class, 'onDeactivate']);

add_action('plugins_loaded', static function (): void {
    \JijOnline\SkwirrelGavilar\Plugin::boot();
});

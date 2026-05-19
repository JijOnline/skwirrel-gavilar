<?php
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

if (get_option('skwirrel_gavilar_drop_data_on_uninstall')) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}skwirrel_sync_log");

    $options = [
        'skwirrel_gavilar_oauth_token_url',
        'skwirrel_gavilar_api_url',
        'skwirrel_gavilar_client_id',
        'skwirrel_gavilar_client_secret',
        'skwirrel_gavilar_dynamic_selection_id',
        'skwirrel_gavilar_locale_map',
        'skwirrel_gavilar_last_synced_at',
        'skwirrel_gavilar_current_run_id',
        'skwirrel_gavilar_drop_data_on_uninstall',
    ];
    foreach ($options as $opt) {
        delete_option($opt);
    }
    delete_transient('skwirrel_gavilar_access_token');
}

wp_clear_scheduled_hook('skwirrel_gavilar_daily_sync');

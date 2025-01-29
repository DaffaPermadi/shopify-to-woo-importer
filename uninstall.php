<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'stwi_imports';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Hapus semua options terkait
delete_option('stwi_version');
delete_option('stwi_settings');
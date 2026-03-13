<?php
/**
 * Uninstall script for Cloudflare IP Protection
 * Runs when plugin is deleted (not just deactivated)
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove .htaccess rules (in case deactivation failed)
$htaccess_file = ABSPATH . '.htaccess';
$backup_file = ABSPATH . '.htaccess.cloudflare.backup';

if (file_exists($htaccess_file) && is_writable($htaccess_file)) {
    $content = @file_get_contents($htaccess_file);
    
    if ($content !== false) {
        // Remove Cloudflare IP Protection rules
        $new_content = preg_replace(
            '/# BEGIN Cloudflare IP Protection.*?# END Cloudflare IP Protection\s*/s',
            '',
            $content
        );
        
        @file_put_contents($htaccess_file, $new_content, LOCK_EX);
    }
}

// Delete backup file
if (file_exists($backup_file)) {
    @unlink($backup_file);
}

// Delete all plugin options
delete_option('cloudflare_whitelist_ips');
delete_option('cloudflare_ip_last_update');
delete_option('cloudflare_ip_count');
delete_option('cloudflare_ip_error_log');
delete_option('cloudflare_ip_activation_notice');

// Clear scheduled events (if somehow still exist)
wp_clear_scheduled_hook('cloudflare_ip_update_cron');

// Clear all transients
delete_transient('cloudflare_ip_update_lock');
delete_transient('cloudflare_ip_admin_notice');

// Log the uninstallation
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('Cloudflare IP Protection: Plugin uninstalled and all data removed');
}

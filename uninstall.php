<?php
/**
 * Uninstall script for Cloudflare IP Protection
 * Runs when plugin is deleted (not just deactivated)
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Simple logging helper (no global function pollution)
$cf_log = function($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Cloudflare IP Protection Uninstall: ' . $message);
    }
};

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
        
        // Verify preg_replace didn't fail
        if ($new_content === null) {
            $cf_log('Regex replacement failed, keeping original .htaccess content');
            $new_content = $content;
        }
        
        // Only write if content actually changed
        if ($new_content !== $content) {
            $result = @file_put_contents($htaccess_file, $new_content, LOCK_EX);
            
            if ($result !== false) {
                $cf_log('Successfully removed rules from .htaccess');
            } else {
                $cf_log('Failed to write to .htaccess');
            }
        } else {
            $cf_log('No Cloudflare IP Protection rules found in .htaccess');
        }
    } else {
        $cf_log('Failed to read .htaccess file');
    }
} else {
    if (!file_exists($htaccess_file)) {
        $cf_log('.htaccess file not found');
    } else {
        $cf_log('.htaccess file is not writable');
    }
}

// Delete backup file
if (file_exists($backup_file)) {
    $deleted = @unlink($backup_file);
    
    if ($deleted) {
        $cf_log('Backup file deleted');
    } else {
        $cf_log('Failed to delete backup file');
    }
} else {
    $cf_log('Backup file not found');
}

// Delete all plugin options
$options_deleted = 0;
$options = array(
    'cloudflare_whitelist_ips',
    'cloudflare_ip_last_update',
    'cloudflare_ip_count',
    'cloudflare_ip_error_log',
    'cloudflare_ip_activation_notice'
);

foreach ($options as $option) {
    if (delete_option($option)) {
        $options_deleted++;
    }
}

$cf_log("Deleted $options_deleted database options");

// Clear scheduled events (if somehow still exist)
$timestamp = wp_next_scheduled('cloudflare_ip_update_cron');
if ($timestamp) {
    wp_clear_scheduled_hook('cloudflare_ip_update_cron');
    $cf_log('Cleared scheduled cron job');
}

// Clear all transients
delete_transient('cloudflare_ip_update_lock');
delete_transient('cloudflare_ip_admin_notice');
$cf_log('Cleared transients');

// Final log
$cf_log('Plugin uninstall complete - all data removed');

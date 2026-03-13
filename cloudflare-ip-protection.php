<?php
/**
 * Plugin Name: Cloudflare IP Protection
 * Plugin URI: https://github.com/pulpcovers/cloudflare-ip-protection/
 * Description: Automatically updates .htaccess to only allow traffic from Cloudflare IPs with custom whitelist support
 * Version: 1.0
 * Author: PulpCovers
 * License: CC0 1.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class CloudflareIPProtection {
    
    private $htaccess_file;
    private $backup_file;
    private $cloudflare_ipv4_url = 'https://www.cloudflare.com/ips-v4';
    private $cloudflare_ipv6_url = 'https://www.cloudflare.com/ips-v6';
    private $max_log_entries = 50;
    private $update_lock_key = 'cloudflare_ip_update_lock';
    
    public function __construct() {
        $this->htaccess_file = ABSPATH . '.htaccess';
        $this->backup_file = ABSPATH . '.htaccess.cloudflare.backup';
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Schedule daily update
        add_action('cloudflare_ip_update_cron', array($this, 'update_htaccess'));
        
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Add settings link on plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Deactivation warning
        add_action('admin_footer-plugins.php', array($this, 'deactivation_warning_js'));
    }
    
    public function activate() {
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('This plugin requires PHP 7.4 or higher. You are running PHP ' . PHP_VERSION);
        }
        
        // Check if .htaccess exists and is writable
        if (!file_exists($this->htaccess_file)) {
            $this->log_message('.htaccess file does not exist', 'error');
            update_option('cloudflare_ip_activation_notice', 'error:.htaccess file not found!');
            return;
        }
        
        if (!is_writable($this->htaccess_file)) {
            $this->log_message('.htaccess is not writable', 'error');
            update_option('cloudflare_ip_activation_notice', 'error:.htaccess is not writable! Please set permissions to 644.');
            return;
        }
        
        // Schedule daily cron job
        if (!wp_next_scheduled('cloudflare_ip_update_cron')) {
            wp_schedule_event(time(), 'daily', 'cloudflare_ip_update_cron');
        }
        
        // Initialize options
        if (get_option('cloudflare_whitelist_ips') === false) {
            update_option('cloudflare_whitelist_ips', '');
        }
        
        // Run initial update
        $result = $this->update_htaccess();
        
        if ($result) {
            update_option('cloudflare_ip_activation_notice', 'success:Plugin activated and .htaccess updated!');
        } else {
            update_option('cloudflare_ip_activation_notice', 'error:Plugin activated but .htaccess update failed!');
        }
    }
    
    public function deactivate() {
        // Remove cron job
        wp_clear_scheduled_hook('cloudflare_ip_update_cron');
        
        // Remove update lock if exists
        delete_transient($this->update_lock_key);
        
        // Always remove rules on deactivation
        $this->remove_htaccess_rules();
        
        $this->log_message('Plugin deactivated, rules removed from .htaccess', 'info');
    }
    
    public function admin_notices() {
        // Check for activation notice
        $activation_notice = get_option('cloudflare_ip_activation_notice');
        if ($activation_notice) {
            list($type, $message) = explode(':', $activation_notice, 2);
            $class = $type === 'error' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
            delete_option('cloudflare_ip_activation_notice');
        }
        
        // Check for transient notices
        $notice = get_transient('cloudflare_ip_admin_notice');
        if ($notice) {
            list($type, $message) = explode(':', $notice, 2);
            
            if ($type === 'error') {
                $class = 'notice-error';
            } elseif ($type === 'warning') {
                $class = 'notice-warning';
            } else {
                $class = 'notice-success';
            }
            
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
            delete_transient('cloudflare_ip_admin_notice');
        }
    }
    
    public function deactivation_warning_js() {
        $plugin_file = plugin_basename(__FILE__);
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('tr[data-plugin="<?php echo esc_js($plugin_file); ?>"] .deactivate a').on('click', function(e) {
                var message = '⚠️ WARNING: Deactivating Cloudflare IP Protection will:\n\n' +
                    '• Remove ALL IP access restrictions from .htaccess\n' +
                    '• Your site will be accessible from ANY IP address\n' +
                    '• Settings and logs will be kept for reactivation\n\n' +
                    'Make sure you have:\n' +
                    '✓ Cloudflare proxy enabled (orange cloud), OR\n' +
                    '✓ FTP access ready in case of issues\n\n' +
                    'Continue with deactivation?';
                
                if (!confirm(message)) {
                    e.preventDefault();
                    return false;
                }
            });
        });
        </script>
        <?php
    }
    
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=cloudflare-ip-protection">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Cloudflare IP Protection',
            'Cloudflare IPs',
            'manage_options',
            'cloudflare-ip-protection',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Handle form submissions
        if (isset($_POST['update_now']) && check_admin_referer('cf_ip_update')) {
            if (get_transient($this->update_lock_key)) {
                echo '<div class="notice notice-warning is-dismissible"><p>⚠️ Update already in progress. Please wait...</p></div>';
            } else {
                $result = $this->update_htaccess();
                if ($result) {
                    echo '<div class="notice notice-success is-dismissible"><p>✅ Cloudflare IPs updated successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>❌ Failed to update. Check error log below.</p></div>';
                }
            }
        }
        
        if (isset($_POST['save_whitelist']) && check_admin_referer('cf_whitelist_save')) {
            $whitelist = sanitize_textarea_field($_POST['whitelist_ips']);
            
            // Validate IPs before saving
            $validation_result = $this->validate_whitelist_ips($whitelist);
            
            if ($validation_result['valid']) {
                update_option('cloudflare_whitelist_ips', $whitelist);
                $update_result = $this->update_htaccess();
                
                if ($update_result) {
                    echo '<div class="notice notice-success is-dismissible"><p>✅ Whitelist saved and .htaccess updated!</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>❌ Whitelist saved but .htaccess update failed!</p></div>';
                }
                
                if (!empty($validation_result['warnings'])) {
                    echo '<div class="notice notice-warning is-dismissible"><p>⚠️ <strong>Saved with warnings:</strong><br>' . implode('<br>', array_map('esc_html', $validation_result['warnings'])) . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>❌ <strong>Invalid IP addresses found:</strong><br>' . implode('<br>', array_map('esc_html', $validation_result['errors'])) . '</p></div>';
            }
        }
        
        if (isset($_POST['clear_logs']) && check_admin_referer('cf_clear_logs')) {
            delete_option('cloudflare_ip_error_log');
            echo '<div class="notice notice-success is-dismissible"><p>✅ Error log cleared!</p></div>';
        }
        
        if (isset($_POST['restore_backup']) && check_admin_referer('cf_restore_backup')) {
            $result = $this->restore_backup();
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>✅ Backup restored successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>❌ Failed to restore backup!</p></div>';
            }
        }
        
        if (isset($_POST['create_backup']) && check_admin_referer('cf_create_backup')) {
            $result = $this->create_backup();
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>✅ Backup created successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>❌ Failed to create backup!</p></div>';
            }
        }
        
        $last_update = get_option('cloudflare_ip_last_update');
        $ip_count = get_option('cloudflare_ip_count', 0);
        $whitelist = get_option('cloudflare_whitelist_ips', '');
        $backup_exists = file_exists($this->backup_file);
        $backup_time = $backup_exists ? filemtime($this->backup_file) : false;
        
        ?>
        <div class="wrap">
            <h1>🛡️ Cloudflare IP Protection</h1>
            
            <div class="notice notice-warning inline" style="border-left-width: 4px; padding: 12px;">
                <p><strong>⚠️ Important:</strong> Deactivating or uninstalling this plugin will <strong>remove all protection rules</strong> from your .htaccess file. 
                Make sure you:</p>
                <ul style="margin-left: 20px;">
                    <li>✓ Have Cloudflare proxy enabled (orange cloud) on all DNS records</li>
                    <li>✓ OR have your own IP in the whitelist before deactivating</li>
                    <li>✓ OR have FTP access ready in case you get locked out</li>
                </ul>
            </div>
            
            <div class="card">
                <h2>Status</h2>
                <table class="form-table">
                    <tr>
                        <th>Last Update:</th>
                        <td><?php echo $last_update ? date('Y-m-d H:i:s', $last_update) : 'Never'; ?></td>
                    </tr>
                    <tr>
                        <th>Cloudflare IP Ranges:</th>
                        <td><?php echo $ip_count; ?> ranges</td>
                    </tr>
                    <tr>
                        <th>Whitelisted IPs:</th>
                        <td><?php echo $this->count_whitelist_ips($whitelist); ?> custom IPs</td>
                    </tr>
                    <tr>
                        <th>.htaccess Location:</th>
                        <td><code><?php echo esc_html($this->htaccess_file); ?></code></td>
                    </tr>
                    <tr>
                        <th>File Writable:</th>
                        <td><?php 
                            if (is_writable($this->htaccess_file)) {
                                echo '<span style="color:green;">✅ Yes</span>';
                            } else {
                                echo '<span style="color:red;">❌ No - Please set permissions to 644</span>';
                            }
                        ?></td>
                    </tr>
                    <tr>
                        <th>Backup Status:</th>
                        <td><?php 
                            if ($backup_exists) {
                                echo '<span style="color:green;">✅ Exists (Created: ' . date('Y-m-d H:i:s', $backup_time) . ')</span>';
                            } else {
                                echo '<span style="color:orange;">⚠️ No backup found</span>';
                            }
                        ?></td>
                    </tr>
                    <tr>
                        <th>Next Auto-Update:</th>
                        <td><?php 
                            $next_cron = wp_next_scheduled('cloudflare_ip_update_cron');
                            echo $next_cron ? date('Y-m-d H:i:s', $next_cron) : 'Not scheduled';
                        ?></td>
                    </tr>
                    <tr>
                        <th>PHP Version:</th>
                        <td><?php echo PHP_VERSION; ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="card">
                <h2>Actions</h2>
                <form method="post" style="display:inline-block; margin-right:10px;">
                    <?php wp_nonce_field('cf_ip_update'); ?>
                    <button type="submit" name="update_now" class="button button-primary">🔄 Update Cloudflare IPs Now</button>
                </form>
                
                <form method="post" style="display:inline-block; margin-right:10px;">
                    <?php wp_nonce_field('cf_create_backup'); ?>
                    <button type="submit" name="create_backup" class="button">💾 Create Backup</button>
                </form>
                
                <?php if ($backup_exists): ?>
                <form method="post" style="display:inline-block;" onsubmit="return confirm('Are you sure you want to restore from backup? This will overwrite your current .htaccess file.');">
                    <?php wp_nonce_field('cf_restore_backup'); ?>
                    <button type="submit" name="restore_backup" class="button">♻️ Restore Backup</button>
                </form>
                <?php endif; ?>
                
                <p class="description">The plugin automatically updates daily. Manual updates are logged.</p>
            </div>
            
            <div class="card">
                <h2>IP Whitelist</h2>
                <p>Add custom IP addresses or ranges that should always have access, even if not from Cloudflare. Enter one IP per line.</p>
                <form method="post">
                    <?php wp_nonce_field('cf_whitelist_save'); ?>
                    <textarea name="whitelist_ips" rows="10" style="width:100%; font-family:monospace;" placeholder="Examples:&#10;192.168.1.1&#10;203.0.113.0/24&#10;10.0.0.1&#10;# Comments start with #"><?php echo esc_textarea($whitelist); ?></textarea>
                    <p class="description">
                        <strong>Examples:</strong><br>
                        • Single IP: <code>192.168.1.1</code><br>
                        • IP Range (CIDR): <code>192.168.1.0/24</code><br>
                        • Comments: Lines starting with <code>#</code> are ignored<br>
                        • Use cases: Your office IP, monitoring services (UptimeRobot, Pingdom), backup servers, etc.
                    </p>
                    <button type="submit" name="save_whitelist" class="button button-primary">💾 Save Whitelist & Update .htaccess</button>
                </form>
            </div>
            
            <div class="card">
                <h2>Error Log</h2>
                <?php $this->display_error_log(); ?>
                <form method="post" style="margin-top:15px;">
                    <?php wp_nonce_field('cf_clear_logs'); ?>
                    <button type="submit" name="clear_logs" class="button">🗑️ Clear Log</button>
                </form>
            </div>
            
            <div class="card">
                <h2>How It Works</h2>
                <ul>
                    <li>✅ Fetches current Cloudflare IP ranges from official sources</li>
                    <li>✅ Updates .htaccess to only allow HTTP/HTTPS traffic from these IPs</li>
                    <li>✅ Adds your custom whitelist IPs to allowed list</li>
                    <li>✅ Creates automatic backups before each update</li>
                    <li>✅ FTP traffic (port 21) is unaffected</li>
                    <li>✅ Automatically updates daily via WordPress cron</li>
                    <li>✅ Validates all IP addresses before applying</li>
                    <li>✅ Logs all operations for troubleshooting</li>
                </ul>
                
                <h3>🔴 Deactivation & Uninstallation</h3>
                <ul>
                    <li><strong>Deactivate:</strong> Removes all .htaccess rules, keeps settings and logs</li>
                    <li><strong>Uninstall/Delete:</strong> Removes rules, deletes all settings, removes backup file</li>
                </ul>
                
                <h3>⚠️ Safety Tips</h3>
                <ul>
                    <li>Always test on a staging site first</li>
                    <li>Keep a manual backup of your .htaccess file</li>
                    <li>Add your office/home IP to the whitelist if you manage the site without Cloudflare proxy</li>
                    <li>If locked out, access via FTP and restore the backup file</li>
                    <li>Backup file location: <code><?php echo esc_html($this->backup_file); ?></code></li>
                </ul>
            </div>
            
            <div class="card">
                <h2>Current .htaccess Rules</h2>
                <textarea readonly style="width:100%; height:300px; font-family:monospace; font-size:12px; background:#f5f5f5;"><?php 
                    echo esc_textarea($this->get_current_rules()); 
                ?></textarea>
            </div>
            
            <style>
                .card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
                .card h2 { margin-top: 0; }
                .card h3 { margin-top: 20px; }
                .error-log-entry { padding: 8px; margin: 5px 0; background: #f0f0f0; border-left: 4px solid #666; font-family: monospace; font-size: 12px; }
                .error-log-entry.error { border-left-color: #dc3232; background: #fef7f7; }
                .error-log-entry.warning { border-left-color: #ffb900; background: #fffbf0; }
                .error-log-entry.success { border-left-color: #46b450; background: #f0fff4; }
                .error-log-entry.info { border-left-color: #00a0d2; background: #f0f8ff; }
                .error-log-container { max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fafafa; }
            </style>
        </div>
        <?php
    }
    
    private function validate_whitelist_ips($whitelist) {
        $result = array(
            'valid' => true,
            'errors' => array(),
            'warnings' => array()
        );
        
        if (empty($whitelist)) {
            return $result;
        }
        
        $lines = explode("\n", $whitelist);
        $line_number = 0;
        
        foreach ($lines as $line) {
            $line_number++;
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || $this->starts_with($line, '#')) {
                continue;
            }
            
            // Check for invalid characters first
            if (!preg_match('/^[0-9a-fA-F:.\\/]+$/', $line)) {
                $result['valid'] = false;
                $result['errors'][] = "Line $line_number: Contains invalid characters: $line";
                continue;
            }
            
            // Validate IP or CIDR
            if (!$this->is_valid_ip_or_cidr($line)) {
                $result['valid'] = false;
                $result['errors'][] = "Line $line_number: Invalid IP or CIDR format: $line";
            }
        }
        
        return $result;
    }
    
    private function is_valid_ip_or_cidr($ip) {
        // Check if it's a CIDR notation
        if (strpos($ip, '/') !== false) {
            list($address, $netmask) = explode('/', $ip, 2);
            
            // Validate IP part
            if (!filter_var($address, FILTER_VALIDATE_IP)) {
                return false;
            }
            
            // Validate netmask
            $netmask = intval($netmask);
            $max_bits = (strpos($address, ':') !== false) ? 128 : 32; // IPv6 : IPv4
            
            if ($netmask < 0 || $netmask > $max_bits) {
                return false;
            }
            
            return true;
        }
        
        // Check if it's a regular IP
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    private function starts_with($haystack, $needle) {
        // PHP 7.x compatible version
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
    
    private function count_whitelist_ips($whitelist) {
		if (empty($whitelist)) {
			return 0;
		}
		$lines = explode("\n", $whitelist);
		$count = 0;
		
		foreach ($lines as $line) {
			$line = trim($line);
			// Only count valid IPs
			if (!empty($line) && !$this->starts_with($line, '#') && preg_match('/^[0-9a-fA-F:.\\/]+$/', $line)) {
				$count++;
			}
		}
		
		return $count;
	}
    
    private function display_error_log() {
        $log = get_option('cloudflare_ip_error_log', array());
        
        if (empty($log)) {
            echo '<p><em>No errors logged yet. This is good! 🎉</em></p>';
            return;
        }
        
        // Reverse to show newest first
        $log = array_reverse($log);
        
        echo '<div class="error-log-container">';
        foreach ($log as $entry) {
            $type = isset($entry['type']) ? $entry['type'] : 'info';
            $timestamp = isset($entry['time']) ? date('Y-m-d H:i:s', $entry['time']) : 'Unknown';
            $message = isset($entry['message']) ? $entry['message'] : 'No message';
            
            echo sprintf(
                '<div class="error-log-entry %s"><strong>[%s]</strong> <span style="text-transform:uppercase;">%s</span> - %s</div>',
                esc_attr($type),
                esc_html($timestamp),
                esc_html($type),
                esc_html($message)
            );
        }
        echo '</div>';
    }
    
    private function log_message($message, $type = 'error') {
        $log = get_option('cloudflare_ip_error_log', array());
        
        $log[] = array(
            'time' => time(),
            'type' => $type,
            'message' => $message
        );
        
        // Keep only last X entries
        if (count($log) > $this->max_log_entries) {
            $log = array_slice($log, -$this->max_log_entries);
        }
        
        update_option('cloudflare_ip_error_log', $log);
        
        // Also log to PHP error log for critical errors
        if ($type === 'error') {
            error_log('Cloudflare IP Protection: ' . $message);
        }
    }
    
    private function create_backup() {
        if (!file_exists($this->htaccess_file)) {
            $this->log_message('Cannot create backup: .htaccess file not found', 'error');
            return false;
        }
        
        $result = @copy($this->htaccess_file, $this->backup_file);
        
        if ($result) {
            $this->log_message('Backup created successfully', 'success');
        } else {
            $this->log_message('Failed to create backup', 'error');
        }
        
        return $result;
    }
    
    private function restore_backup() {
        if (!file_exists($this->backup_file)) {
            $this->log_message('Cannot restore: Backup file not found', 'error');
            return false;
        }
        
        if (!is_writable($this->htaccess_file)) {
            $this->log_message('Cannot restore: .htaccess is not writable', 'error');
            return false;
        }
        
        $result = @copy($this->backup_file, $this->htaccess_file);
        
        if ($result) {
            $this->log_message('Backup restored successfully', 'success');
        } else {
            $this->log_message('Failed to restore backup', 'error');
        }
        
        return $result;
    }
    
    public function update_htaccess() {
        // Check for update lock (prevent concurrent updates)
        if (get_transient($this->update_lock_key)) {
            $this->log_message('Update already in progress, skipping', 'warning');
            return false;
        }
        
        // Set lock (expires in 5 minutes)
        set_transient($this->update_lock_key, true, 300);
        
        $this->log_message('Starting IP update process', 'info');
        
        // Create backup before update
        $this->create_backup();
        
        // Fetch Cloudflare IPs
        $ipv4_list = $this->fetch_cloudflare_ips($this->cloudflare_ipv4_url);
        $ipv6_list = $this->fetch_cloudflare_ips($this->cloudflare_ipv6_url);
        
        if (empty($ipv4_list)) {
            $this->log_message('Failed to fetch IPv4 list from Cloudflare', 'error');
            delete_transient($this->update_lock_key);
            return false;
        }
        
        $this->log_message('Fetched ' . count($ipv4_list) . ' IPv4 ranges and ' . count($ipv6_list) . ' IPv6 ranges', 'success');
        
        // Get whitelist IPs
        $whitelist = get_option('cloudflare_whitelist_ips', '');
        $whitelist_ips = array();
        
        if (!empty($whitelist)) {
            $lines = explode("\n", $whitelist);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !$this->starts_with($line, '#') && preg_match('/^[0-9a-fA-F:.\\/]+$/', $line)) {
                    $whitelist_ips[] = $line;
                }
            }
            
            if (!empty($whitelist_ips)) {
                $this->log_message('Added ' . count($whitelist_ips) . ' whitelisted IPs', 'info');
            }
        }
        
        // Generate .htaccess rules
        $rules = $this->generate_htaccess_rules($ipv4_list, $ipv6_list, $whitelist_ips);
        
        // Update .htaccess
        $success = $this->insert_htaccess_rules($rules);
        
        if ($success) {
            update_option('cloudflare_ip_last_update', time());
            update_option('cloudflare_ip_count', count($ipv4_list) + count($ipv6_list));
            $this->log_message('.htaccess updated successfully', 'success');
        } else {
            $this->log_message('.htaccess update failed - check file permissions', 'error');
            // Attempt to restore backup
            $this->restore_backup();
        }
        
        // Release lock
        delete_transient($this->update_lock_key);
        
        return $success;
    }
    
    private function fetch_cloudflare_ips($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => true,
            'user-agent' => 'WordPress/Cloudflare-IP-Protection'
        ));
        
        if (is_wp_error($response)) {
            $this->log_message('Failed to fetch IPs from ' . $url . ': ' . $response->get_error_message(), 'error');
            return array();
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log_message('Received HTTP ' . $code . ' from ' . $url, 'warning');
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        
        if (empty($body)) {
            $this->log_message('Empty response from ' . $url, 'error');
            return array();
        }
        
        $ips = array_filter(array_map('trim', explode("\n", $body)));
        
        // Validate IPs
        $valid_ips = array();
        foreach ($ips as $ip) {
            if ($this->is_valid_ip_or_cidr($ip)) {
                $valid_ips[] = $ip;
            } else {
                $this->log_message('Invalid IP from Cloudflare list: ' . $ip, 'warning');
            }
        }
        
        return $valid_ips;
    }
    
    private function generate_htaccess_rules($ipv4_list, $ipv6_list, $whitelist_ips = array()) {
        $rules = array();
        $rules[] = "# BEGIN Cloudflare IP Protection";
        $rules[] = "# Auto-generated by Cloudflare IP Protection plugin";
        $rules[] = "# Last updated: " . date('Y-m-d H:i:s');
        $rules[] = "# Do not edit manually - changes will be overwritten";
        $rules[] = "";
        $rules[] = "Order Deny,Allow";
        $rules[] = "Deny from all";
        $rules[] = "";
        
        // Add whitelist IPs first
        if (!empty($whitelist_ips)) {
            $rules[] = "# Custom Whitelist IPs (" . count($whitelist_ips) . " entries)";
            foreach ($whitelist_ips as $ip) {
                $rules[] = "Allow from " . $ip;
            }
            $rules[] = "";
        }
        
        $rules[] = "# Cloudflare IPv4 (" . count($ipv4_list) . " ranges)";
        foreach ($ipv4_list as $ip) {
            $rules[] = "Allow from " . $ip;
        }
        
        if (!empty($ipv6_list)) {
            $rules[] = "";
            $rules[] = "# Cloudflare IPv6 (" . count($ipv6_list) . " ranges)";
            foreach ($ipv6_list as $ip) {
                $rules[] = "Allow from " . $ip;
            }
        }
        
        $rules[] = "";
        $rules[] = "# END Cloudflare IP Protection";
        
        return implode("\n", $rules);
    }
    
    private function insert_htaccess_rules($rules) {
        if (!file_exists($this->htaccess_file)) {
            $this->log_message('.htaccess file not found at ' . $this->htaccess_file, 'error');
            return false;
        }
        
        if (!is_writable($this->htaccess_file)) {
            $this->log_message('.htaccess file is not writable. Set permissions to 644', 'error');
            return false;
        }
        
        // Check file size
        $file_size = @filesize($this->htaccess_file);
        if ($file_size > 1048576) { // 1MB
            $this->log_message('.htaccess file is very large (' . size_format($file_size) . '), proceed with caution', 'warning');
        }
        
        // Read current content
        $htaccess_content = @file_get_contents($this->htaccess_file);
        
        if ($htaccess_content === false) {
            $this->log_message('Failed to read .htaccess file', 'error');
            return false;
        }
        
        // Remove old rules if they exist
        $htaccess_content = $this->remove_rules_from_content($htaccess_content);
        
        // Add new rules at the top (before WordPress rules)
        $new_content = $rules . "\n\n" . $htaccess_content;
        
        // Write to file
        $result = @file_put_contents($this->htaccess_file, $new_content, LOCK_EX);
        
        if ($result === false) {
            $this->log_message('Failed to write to .htaccess file', 'error');
            return false;
        }
        
        // Verify the write was successful
        clearstatcache(true, $this->htaccess_file);
        $verify_content = @file_get_contents($this->htaccess_file);
        
        if ($verify_content !== $new_content) {
            $this->log_message('Write verification failed - content mismatch', 'error');
            return false;
        }
        
        return true;
    }
    
    private function remove_htaccess_rules() {
        if (!file_exists($this->htaccess_file)) {
            $this->log_message('.htaccess file not found, cannot remove rules', 'warning');
            return false;
        }
        
        if (!is_writable($this->htaccess_file)) {
            $this->log_message('.htaccess file is not writable, cannot remove rules', 'error');
            return false;
        }
        
        $htaccess_content = @file_get_contents($this->htaccess_file);
        
        if ($htaccess_content === false) {
            $this->log_message('Failed to read .htaccess file for rule removal', 'error');
            return false;
        }
        
        $new_content = $this->remove_rules_from_content($htaccess_content);
        
        $result = @file_put_contents($this->htaccess_file, $new_content, LOCK_EX);
        
        if ($result !== false) {
            $this->log_message('Removed protection rules from .htaccess on plugin deactivation', 'info');
        } else {
            $this->log_message('Failed to remove rules from .htaccess', 'error');
        }
        
        return $result !== false;
    }
    
    private function remove_rules_from_content($content) {
        return preg_replace(
            '/# BEGIN Cloudflare IP Protection.*?# END Cloudflare IP Protection\s*/s',
            '',
            $content
        );
    }
    
    private function get_current_rules() {
        if (!file_exists($this->htaccess_file)) {
            return 'No .htaccess file found';
        }
        
        $content = @file_get_contents($this->htaccess_file);
        
        if ($content === false) {
            return 'Unable to read .htaccess file';
        }
        
        preg_match(
            '/# BEGIN Cloudflare IP Protection.*?# END Cloudflare IP Protection/s',
            $content,
            $matches
        );
        
        return isset($matches[0]) ? $matches[0] : 'No Cloudflare IP rules found in .htaccess';
    }
}

// Initialize plugin
new CloudflareIPProtection();

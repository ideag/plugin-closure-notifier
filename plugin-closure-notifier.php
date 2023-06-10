<?php
/**
 * Plugin Closure Notifier
 *
 * @package           PluginClosureNotifier
 * @author            Arūnas Liuiza
 * @copyright         2023 Arūnas Liuiza
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin 
 * Plugin Name: Plugin Closure Notifier
 * Plugin URI: https://github.com/ideag/plugin-closure-notifier
 * Description: Displays plugins that were closed in wp.org plugin repository more prominently, to alert site administrators about potential security issues.
 * Version: 1.0.0
 * Author: Arūnas Liuiza
 * Author URI: https://arunas.co/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: plugin-closure-notifier
 * Domain Path: /languages
 */

add_action( 'plugins_loaded', 'pcn' );

function pcn() {
    return PCN::get_instance();
}

class PCN {
    protected static $instance = null;
    protected static $initialised = false;

    public static function get_instance( $init = true ) {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        if ( $init && ! self::$initialised ) {
            self::$instance->init();
        }
        return self::$instance;
    }
    
    public function init() {
        add_action( 'load-plugins.php', [ $this, 'closed_rows' ], 20 );
        add_action( 'admin_print_styles-plugins.php', [ $this, 'style_fixes' ] );

        add_action('admin_menu', [ $this, 'update_count_in_menus' ], 100 );

        // Update closed statuses regularly.
        add_action( 'load-plugins.php', [ $this, '_maybe_update_closed_status' ], 20 );
        add_action( 'load-update.php', [ $this, '_maybe_update_closed_status' ], 20 );
        add_action( 'load-update-core.php', [ $this, '_maybe_update_closed_status' ], 20 );
        add_action( 'admin_init', [ $this, '_maybe_update_closed_status' ], 20 );
        add_action( 'wp_update_plugins', [ $this, 'update_closed_status' ], 20 );
    }

    public function closed_rows() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        $current = get_site_transient( 'pcn_closed' );
        $plugins = $current->closed ?? [];
    
        foreach ( $plugins as $plugin_file => $message ) {
            add_action( "after_plugin_row_{$plugin_file}", [ $this, 'closed_row' ], 10, 2 );
        }
    }

    public function closed_row( $file, $plugin_data ) {
        $plugins_allowedtags = array(
            'a'       => array(
                'href'  => array(),
                'title' => array(),
            ),
            'abbr'    => array( 'title' => array() ),
            'acronym' => array( 'title' => array() ),
            'code'    => array(),
            'em'      => array(),
            'strong'  => array(),
        );
    
        $plugin_name = wp_kses( $plugin_data['Name'], $plugins_allowedtags );
        $plugin_slug = dirname( plugin_basename( $file ) );

        $current = get_site_transient( 'pcn_closed' );
        $plugins = $current->closed ?? [];    
        $message = $plugins[ $file ] ?? '';
        if ( ! $message ) {
            return;
        }

        $wp_list_table = _get_list_table(
            'WP_Plugins_List_Table',
            array(
                'screen' => get_current_screen(),
            )
        );
    
        if ( is_network_admin() ) {
            $active_class = is_plugin_active_for_network( $file ) ? ' active' : '';
        } else {
            $active_class = is_plugin_active( $file ) ? ' active' : '';
        }
    
        printf(
            '<tr class="plugin-update-tr%s plugin-closed-tr" id="%s" data-slug="%s" data-plugin="%s">' .
            '<td colspan="%s" class="plugin-update colspanchange">' .
            '<div class="update-message notice inline %s notice-alt"><p>',
            $active_class,
            esc_attr( $plugin_slug . '-update' ),
            esc_attr( $plugin_slug ),
            esc_attr( $file ),
            esc_attr( $wp_list_table->get_column_count() ),
            'notice-error'
        );

        echo $message;
    
        echo '</p></div></td></tr>';
    
    }

    public function style_fixes() {
        echo '<style>.plugins tr:has(+ tr.plugin-closed-tr).inactive > th, .plugins tr:has(+ tr.plugin-closed-tr).inactive > td, .plugins tr:has(+ tr.plugin-closed-tr).active > th, .plugins tr:has(+ tr.plugin-closed-tr).active > td {-webkit-box-shadow: none; -moz-box-shadow: none; box-shadow: none;}</style>' . PHP_EOL;
    }
   	
    public function update_count_in_menus(){
        global $menu,$submenu;

        $current = get_site_transient( 'pcn_closed' );
        $count = count( $current->closed ?? [] );
        if ( 0 === $count ) {
            return;
        }

        $count = sprintf(
            '<span class="update-plugins count-%1$s"><span class="closed-count" title="%3$s">%2$s</span></span>',
            $count,
            number_format_i18n( $count ),
            __( 'Closed plugins', 'plugin-closure-notices' )
        );

        $menu[65][0] .= $count;        
    }

    public function update_closed_status() {
        $plugins = get_plugins();
        $current = get_site_transient( 'update_plugins' );   
        $live_and_well = array_merge( array_keys( $current->response ), array_keys( $current->no_update ) );

        $current = new StdClass();
        $current->closed = [];
 
        foreach ( $plugins as $plugin_file => $plugin_data ) {
            if ( in_array( $plugin_file, $live_and_well ) ) {
                continue;
            }
            if ( isset( $plugin_data['UpdateURI'] ) && ! empty( $plugin_data['UpdateURI'] )  ) {
                continue;   
            }
            $plugin_slug = dirname( plugin_basename( $plugin_file ) );
            $message = $this->get_plugin_notice( $plugin_slug );
            if ( $message ) {
                $current->closed[ $plugin_file ] = $message;
            }
        }
        $current->last_checked = time();
        set_site_transient( 'pcn_closed', $current );
    }

    public function _maybe_update_closed_status() {
        $current = get_site_transient( 'pcn_closed' );

        if ( isset( $current->last_checked )
            && 12 * HOUR_IN_SECONDS > ( time() - $current->last_checked )
        ) {
            return;
        }
    
        $this->update_closed_status();
    }

    protected function get_plugin_notice( $plugin_slug ) {
        $url = "https://wordpress.org/plugins/{$plugin_slug}/";
        $body = wp_remote_retrieve_body( wp_remote_get( $url ) );
        if ( ! $body ) {
            return '';
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( $body );
        libxml_use_internal_errors( false );
        $xpath = new DOMXpath($doc);

        $notices = $xpath->query('//*[@id="tab-description"]/*[contains(@class, "plugin-notice")]');
        $message = [];

        foreach ( $notices as $notice ) {
            $message[] = $notice->nodeValue;
        }

        if ( $message ) {
            $message = implode( '', $message );
        }

        return $message;
    }
}

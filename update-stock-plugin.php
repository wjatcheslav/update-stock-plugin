<?php
/**
 * Plugin Name: Update Stock Plugin
 * Plugin URI: http://php4you.info
 * Description: Simple plugin that will allow the uploading of an XLSX file and updating of the products on the website accordingly to it (in or out of stock).
 * Version: 0.0.1
 * Author: Wjatcheslav
 * Author URI: http://php4you.info
 *
 * @package UpdateStockPlugin
 * @author Wjatcheslav
 */

if ( preg_match( '#' . basename( __FILE__ ) . '#', $_SERVER['PHP_SELF'] ) ) {
	die( 'You are not allowed to call this page directly.' );
}
require_once( dirname( __FILE__ ) . '/update-stock-plugin.config.php' );
$updateStock = new UpdateStock();

class  UpdateStock {
	var $version, $path, $url;

	function __construct() {
		$this->version = '0.0.1';
		$this->path    = plugin_dir_path( __FILE__ );
		$this->url     = plugins_url( '', __FILE__ );
		$this->setup_application();
		register_activation_hook( __FILE__, 'UpdateStock::updatestock_set_options' );
		register_deactivation_hook( __FILE__, 'UpdateStock::updatestock_unset_options' );
	}

	function updatestock_set_options() {
		add_option( 'usp_update_log' );
		add_option( 'usp_progress_log' );
	}

	function updatestock_unset_options() {
		update_option( 'usp_update_log', '' );
		update_option( 'usp_progress_log', '' );
		delete_option( 'usp_update_log' );
		delete_option( 'usp_progress_log' );
	}

	function myrequire_dir( $dir ) {
		if ( is_dir( $dir ) ) {
			if ( $dh = opendir( $dir ) ) {
				while ( ( $file = readdir( $dh ) ) !== false ) {
					if ( $file[0] == '_' && ( filetype( $dir . $file ) == 'file' ) ) {
						require_once( $dir . $file );
					}
				}
				echo $file;
				closedir( $dh );
			}
			if ( $dh = opendir( $dir ) ) {
				while ( ( $file = readdir( $dh ) ) !== false ) {
					if ( $file[0] !== '_' && $file[0] != '.' && ( filetype( $dir . $file ) == 'file' ) ) {
						require_once( $dir . $file );
					}
				}
				closedir( $dh );
			}
		}
	}

	function setup_application() {
		// Load controllers
		$this->myrequire_dir( $this->path . 'controllers/' );
		$this->settings = new updateStockSettings( $this );
	}
}

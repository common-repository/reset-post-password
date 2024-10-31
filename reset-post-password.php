<?php

/**
 * Plugin Name: Reset Post Password
 * Plugin URI: https://wp360.pro/
 * Description: Reset post automatically after X days
 * Author: Sebastian Pisula
 * Author URI: mailto:sebastian.pisula@gmail.com
 * Version: 1.0.0
 * Domain Path: /languages
 * Text Domain: reset-post-password
 */

namespace wp360\Plugin;

class Reset_Post_Password {
	/** @var string */
	private $dir;

	/** @var string */
	private $file;

	/** @var string */
	private $basename;

	/**
	 * Reset_Post_Password constructor.
	 *
	 * @param string $file
	 */
	public function __construct( $file ) {
		$this->file     = $file;
		$this->dir      = dirname( $file );
		$this->basename = plugin_basename( $file );

		add_action( 'plugins_loaded', [ $this, 'plugins_loaded' ] );

		add_action( 'save_post', [ $this, 'save_post' ], 30 );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		add_action( 'admin_post_wp360-reset-passwords', [ $this, 'admin_post_reset_passwords' ] );
		add_action( 'wp360_reset_passwords', [ $this, 'reset_passwords' ], 30 );

		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

		register_activation_hook( $this->file, [ $this, 'activation' ] );
	}

	/**
	 * Hook after plugins loaded
	 */
	public function plugins_loaded() {
		load_plugin_textdomain( 'reset-post-password', false, dirname( $this->basename ) . '/languages' );
	}

	/**
	 * Hook after activation plugin
	 */
	public function activation() {
		if ( ! wp_next_scheduled( 'wp360_reset_post_passwords' ) ) {
			wp_schedule_event( time(), 'hourly', 'wp360_reset_passwords' );
		}
	}

	/**
	 * Register scripts and styles
	 *
	 * @param string $hook_suffix
	 */
	public function admin_enqueue_scripts( $hook_suffix ) {
		$screen = get_current_screen();

		if ( $screen->base != 'post' ) {
			return;
		}

		$interval = '';

		if ( isset( $_GET['post'] ) ) {
			$_interval = $this->get_post_interval( $_GET['post'] );

			if ( $_interval > 0 ) {
				$interval = (int) $_interval;
			}
		}

		$file = 'assets/js/app.js';
		$ver  = filemtime( $this->get_plugin_path( $file ) );
		wp_enqueue_script( 'wp360-reset-post-password', $this->get_plugin_url( $file ), [], $ver, 1 );

		$vars = [
			'post_password_interval' => $interval,
			'l10n'                   => [
				'reset_password_label' => _x( 'Reset password interval (days):', 'Label for reset password label', 'reset-post-password' ),
			]
		];

		wp_localize_script( 'wp360-reset-post-password', '__jsVars', $vars );
	}

	/**
	 * Reset post password action
	 */
	public function reset_passwords() {
		$args = [
			'nopaging'     => true,
			'post_type'    => 'any',
			'has_password' => true,
		];

		if ( wp_doing_cron() ) {
			$args['meta_key']     = '_date_reset_password';
			$args['meta_value']   = date_i18n( 'Y-m-d H:i:s' );
			$args['meta_compare'] = '<';
			$args['meta_type']    = 'DATETIME';
		}

		$posts = new \WP_Query( $args );

		while ( $posts->have_posts() ) {
			$posts->the_post();

			$password = wp_generate_password();

			$data = [ 'ID' => get_the_ID(), 'post_password' => $password ];

			$post_id = wp_update_post( $data );

			if ( $post_id ) {
				$this->update_post_password_date( $post_id );

				$subject = sprintf( _x( 'Password changed - %s', 'E-Mail Subject', 'reset-post-password' ), get_the_title() );
				$message = sprintf( _x( 'New password for post %s is: <code>%s</code>', 'E-Mail Body', 'reset-post-password' ), get_the_title(), $password );

				wp_mail( get_option( 'admin_email' ), $subject, $message );
			}
		}
	}

	/**
	 * Hook after post saved
	 *
	 * @param int $post_id
	 */
	public function save_post( $post_id ) {

		if ( ! get_post_field( 'post_password', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['post_password_interval'] ) ) {
			return;
		}

		//Disable cron if empty
		if ( empty( $_POST['post_password_interval'] ) ) {
			delete_post_meta( $post_id, '_date_reset_password' );
			delete_post_meta( $post_id, '_post_password_interval' );
		}

		$post_password_interval = (int) $_POST['post_password_interval'];

		if ( $post_password_interval <= 0 ) {
			return;
		}

		$before_post_password_interval = $this->get_post_interval( $post_id );

		//update post password interval
		update_post_meta( $post_id, '_post_password_interval', $post_password_interval );

		if ( $before_post_password_interval !== $post_password_interval ) {
			$this->update_post_password_date( $post_id );
		}
	}

	/**
	 * Add menu item
	 */
	public function admin_menu() {
		add_management_page( '', _x( 'Reset protected posts passwords', 'Admin Menu Link', 'reset-post-password' ), 'manage_options', wp_nonce_url( 'admin-post.php?action=wp360-reset-passwords', 'wp360-reset-passwords-nonce' ) );
	}

	/**
	 * Reset post passwords
	 */
	public function admin_post_reset_passwords() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Cheatin&#8217; uh?' );
		}

		check_admin_referer( 'wp360-reset-passwords-nonce' );

		$this->reset_passwords();

		$this->redirect( _x( 'Passwords have been reset', 'Message for admin after reset passwords', 'reset-post-password' ), 'success', wp_get_referer() );
	}

	/**
	 * Redirect to URL
	 *
	 * @param string $info
	 * @param string $type
	 * @param string $url
	 */
	public function redirect( $info, $type = 'error', $url = '' ) {
		$url = add_query_arg(
			array(
				'type' => $type,
				'info' => urlencode( $info )
			), $url );

		wp_redirect( $url );
		die();
	}

	/**
	 * Admin notice
	 */
	public function admin_notices() {
		if ( isset( $_GET['info'] ) && isset( $_GET['type'] ) && in_array( $_GET['type'], [ 'success', 'error' ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $_GET['type'] ) . '"><p>' . esc_html( $_GET['info'] ) . '</p></div>';
		}
	}

	/**
	 * Get plugin url to file
	 *
	 * @param string $file
	 *
	 * @return string
	 */
	public function get_plugin_url( $file = '' ) {
		return plugins_url( $file, $this->file );
	}

	/**
	 * Get plugin path
	 *
	 * @param string $path
	 *
	 * @return string
	 */
	public function get_plugin_path( $path = '' ) {
		return wp_normalize_path( plugin_dir_path( $this->file ) . '/' . $path );
	}

	/**
	 * Update post password
	 *
	 * @param int $post_id
	 *
	 * @return bool|int
	 */
	private function update_post_password_date( $post_id ) {
		$post_password_interval = $this->get_post_interval( $post_id );

		$time = current_time( 'timestamp' ) + $post_password_interval * 24 * 60 * 60;

		return update_post_meta( $post_id, '_date_reset_password', date_i18n( 'Y-m-d H:i:s', $time ) );
	}

	/**
	 * Get post interval
	 *
	 * @param int $post_id
	 *
	 * @return int
	 */
	private function get_post_interval( $post_id ) {
		return (int) get_post_meta( $post_id, '_post_password_interval', 1 );
	}
}

new Reset_Post_Password( __FILE__ );
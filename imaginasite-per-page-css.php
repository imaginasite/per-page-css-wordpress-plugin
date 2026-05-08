<?php
/**
 * Plugin Name: Imaginasite Per Page CSS
 * Plugin URI: https://www.imaginasite.com/per-page-css-wordpress-plugin
 * Description: Adds a CSS style editing field in pages and posts, automatically injected into the head tag with live preview for Gutenberg editor.
 * Version: 1.2.0
 * Author: Anis MK
 * Author URI: https://www.imaginasite.com
 * Text Domain: imaginasite-per-page-css
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Main Plugin Class
 */
class Imaginasite_Per_Page_CSS_Plugin
{
	// Meta key used to store CSS in the post_meta table
	const META_KEY = '_imaginasite_per_page_css';

	/**
	 * Constructor: Define all WordPress hooks
	 */
	public function __construct()
	{
		add_action('init', array($this, 'register_meta'));
		add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		add_action('add_meta_boxes', array($this, 'register_classic_metabox'));
		add_action('save_post', array($this, 'save_classic_metabox'));
		add_action('wp_head', array($this, 'print_css_in_head'), 99);
	}

	/**
	 * Permission check helper: only administrators with 'manage_options' can use the editor
	 */
	private function is_allowed()
	{
		return current_user_can('manage_options');
	}

	/**
	 * Register the custom meta field for all supported post types
	 * Enabled 'show_in_rest' to allow Gutenberg to read/write this field
	 */
	public function register_meta()
	{
		$post_types = $this->get_supported_post_types();

		foreach ($post_types as $post_type) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type' => 'string',
					'single' => true,
					'show_in_rest' => true,
					'sanitize_callback' => array($this, 'sanitize_css'),
					'auth_callback' => function () {
						return current_user_can('manage_options');
					},
				)
			);
		}
	}

	/**
	 * Enqueue JavaScript and CSS assets for the Gutenberg Block Editor
	 */
	public function enqueue_editor_assets()
	{
		if (!$this->is_allowed()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$post_type = $screen ? $screen->post_type : '';

		if (!in_array($post_type, $this->get_supported_post_types(), true)) {
			return;
		}

		// Initialize WordPress core code editor settings (CodeMirror) for CSS
		$settings = wp_enqueue_code_editor(array('type' => 'text/css'));

		wp_enqueue_script(
			'imaginasite-per-page-css',
			plugins_url('assets/js/editor.js', __FILE__),
			array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose'),
			'1.2.0',
			true
		);

		$panel_title = ($post_type === 'page') ? __('Specific CSS for this page', 'imaginasite-per-page-css') : __('Specific CSS for this post', 'imaginasite-per-page-css');

		$script_data = array(
			'settings' => $settings !== false ? $settings : null,
			'i18n' => array(
				'disabled' => __('Syntax highlighting disabled in your profile.', 'imaginasite-per-page-css'),
				'js_error' => __('JS Error: ', 'imaginasite-per-page-css'),
				'timeout' => __('Timeout: wp.codeEditor unavailable', 'imaginasite-per-page-css'),
				'panel_title' => $panel_title,
				'diagnostic' => __('DIAGNOSTIC:\n', 'imaginasite-per-page-css'),
				'status' => __('- Status: ', 'imaginasite-per-page-css'),
				'wp_codeeditor' => __('- wp.codeEditor: ', 'imaginasite-per-page-css'),
				'container' => __('- Container: ', 'imaginasite-per-page-css')
			)
		);

		wp_add_inline_script(
			'imaginasite-per-page-css',
			'window.imaginasitePerPageCssData = ' . wp_json_encode($script_data) . ';',
			'before'
		);
	}

	/**
	 * Enqueue assets for both Classic Editor and custom Gutenberg implementations (like WooCommerce)
	 */
	public function enqueue_admin_assets($hook)
	{
		if (!$this->is_allowed()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$post_type = $screen ? $screen->post_type : '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// Attempt to determine post type on various admin hooks
		if (!$post_type && isset($_GET['post'])) {
			$post_type = get_post_type(intval(wp_unslash($_GET['post'])));
		} elseif (!$post_type && isset($_GET['post_type'])) {
			$post_type = sanitize_key(wp_unslash($_GET['post_type']));
		}

		// Detect WooCommerce new product editor (SPA)
		if (strpos($hook, 'wc-admin') !== false || (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'wc-admin')) {
			if (isset($_GET['path']) && strpos(sanitize_text_field(wp_unslash($_GET['path'])), '/product') !== false) {
				$post_type = 'product';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$is_classic_edit = ($hook === 'post.php' || $hook === 'post-new.php');

		// Enqueue CodeMirror if we are on a supported editing screen
		if ($is_classic_edit || in_array($post_type, $this->get_supported_post_types(), true)) {
			$settings = wp_enqueue_code_editor(array('type' => 'text/css'));
			if (false !== $settings && $is_classic_edit) {
				wp_add_inline_script(
					'code-editor',
					sprintf(
						'jQuery(document).ready(function($){ 
							if($("#page_post_specific_css_field").length) { 
								wp.codeEditor.initialize("page_post_specific_css_field", %s); 
							} 
						});',
						wp_json_encode($settings)
					)
				);
			}
		}
	}

	/**
	 * Register the metabox for the Classic Editor
	 */
	public function register_classic_metabox()
	{
		if (!$this->is_allowed()) {
			return;
		}

		foreach ($this->get_supported_post_types() as $post_type) {
			$title = ($post_type === 'page') ? __('Specific CSS for this page', 'imaginasite-per-page-css') : __('Specific CSS for this post', 'imaginasite-per-page-css');
			add_meta_box(
				'page_post_specific_css_box',
				$title,
				array($this, 'render_classic_metabox'),
				$post_type,
				'side',
				'low',
				array('__back_compat_meta_box' => true)
			);
		}
	}

	/**
	 * Render the Classic Editor metabox HTML
	 */
	public function render_classic_metabox($post)
	{
		wp_nonce_field('page_post_specific_css_action', 'page_post_specific_css_nonce');
		$value = get_post_meta($post->ID, self::META_KEY, true);
		?>
		<textarea id="page_post_specific_css_field" name="page_post_specific_css_field"
			style="width:100%;min-height:200px;font-family:monospace;"><?php echo esc_textarea($value); ?></textarea>
		<?php
	}

	/**
	 * Save the CSS from the Classic Editor metabox
	 */
	public function save_classic_metabox($post_id)
	{
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		$nonce = isset($_POST['page_post_specific_css_nonce']) ? sanitize_text_field(wp_unslash($_POST['page_post_specific_css_nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'page_post_specific_css_action')) {
			return;
		}

		if (!$this->is_allowed()) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if (isset($_POST['page_post_specific_css_field'])) {
			update_post_meta(
				$post_id,
				self::META_KEY,
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$this->sanitize_css(wp_unslash($_POST['page_post_specific_css_field']))
			);
		}
	}

	/**
	 * Inject the custom CSS into the public site <head>
	 */
	public function print_css_in_head()
	{
		if (is_admin() || !is_singular()) {
			return;
		}

		$post_id = get_queried_object_id();
		$css = get_post_meta($post_id, self::META_KEY, true);

		if (!empty($css)) {
			// Ensure no HTML tags are present for late escaping, preventing XSS injection
			$css = wp_strip_all_tags($css);

			// Minify CSS: remove comments
			$css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
			// Remove spaces around syntax characters
			$css = preg_replace('/\s*([\{\}\:\;\,\>])\s*/', '$1', $css);
			// Remove remaining extra spaces and newlines
			$css = trim(preg_replace('/\s+/', ' ', $css));


			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "<style id=\"imaginasite-per-page-css\">" . $css . "</style>";
		}
	}

	/**
	 * Sanitize CSS input by stripping scripts and dangerous tags
	 */
	public function sanitize_css($css)
	{
		$css = is_string($css) ? trim($css) : '';

		// Advanced XSS protection: strip all HTML tags entirely.
		// CSS should never contain HTML tags. This effectively prevents <script> or </style> injection.
		$css = wp_strip_all_tags($css);

		$blocked = array(
			'#@import#i',
			'#expression\\s*\\(#i',
			'#javascript:#i',
			'#</style#i',
		);

		return preg_replace($blocked, '', $css);
	}

	/**
	 * Get all public post types that support the editor
	 */
	private function get_supported_post_types()
	{
		return array_filter(
			get_post_types(array('show_ui' => true), 'names'),
			function ($pt) {
				return post_type_supports($pt, 'editor');
			}
		);
	}
}

// Start the plugin
new Imaginasite_Per_Page_CSS_Plugin();

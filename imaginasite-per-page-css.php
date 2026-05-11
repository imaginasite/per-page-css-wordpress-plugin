<?php
/**
 * Plugin Name: Imaginasite Per Page CSS
 * Plugin URI: https://www.imaginasite.com/per-page-css-wordpress-plugin
 * Description: Adds a CSS style editing field in pages and posts, automatically injected into the head tag with live preview for Gutenberg editor.
 * Version: 1.2.7
 * Author: Anis MK
 * Author URI: https://www.imaginasite.com
 * Text Domain: imaginasite-per-page-css
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access to the file.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Main Plugin Class.
 */
class Imaginasite_Per_Page_CSS_Plugin
{
	// Meta key used to store CSS in the post_meta table.
	const META_KEY = '_imaginasite_per_page_css';

	/**
	 * Constructor: define all WordPress hooks.
	 */
	public function __construct()
	{
		add_action('init', array($this, 'register_meta'));
		add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		add_action('add_meta_boxes', array($this, 'register_classic_metabox'));
		add_action('save_post', array($this, 'save_classic_metabox'));
		add_action('wp_head', array($this, 'print_css_in_head'), 99);

		// Prevent Gutenberg/REST and classic editor updates from storing invalid CSS.
		add_filter('add_post_metadata', array($this, 'prevent_invalid_css_meta_update'), 10, 5);
		add_filter('update_post_metadata', array($this, 'prevent_invalid_css_meta_update'), 10, 5);
	}

	/**
	 * Permission check helper.
	 *
	 * Only administrators with both 'manage_options' and 'unfiltered_html' can use the CSS editor.
	 */
	private function is_allowed()
	{
		return current_user_can('manage_options') && current_user_can('unfiltered_html');
	}

	/**
	 * Prevent adding/updating metadata if the CSS is invalid.
	 *
	 * This prevents Gutenberg/REST from overwriting valid CSS with invalid CSS.
	 *
	 * @param null|bool $check       Whether to allow updating metadata for the given type.
	 * @param int       $object_id   Object ID.
	 * @param string    $meta_key    Meta key.
	 * @param mixed     $meta_value  Meta value.
	 * @param mixed     $prev_value  Previous value for update_post_metadata, unique flag for add_post_metadata.
	 *
	 * @return null|bool
	 */
	public function prevent_invalid_css_meta_update($check, $object_id, $meta_key, $meta_value, $prev_value)
	{
		if (self::META_KEY !== $meta_key) {
			return $check;
		}

		if (!$this->is_allowed()) {
			return false;
		}

		$css = $this->sanitize_css($meta_value);

		// Allow intentional empty CSS deletion.
		if ('' === $css) {
			return $check;
		}

		$validation = $this->validate_css($css);

		if (is_wp_error($validation)) {
			return false;
		}

		return $check;
	}

	/**
	 * Register the custom meta field for all supported post types.
	 *
	 * 'show_in_rest' allows Gutenberg to read/write this field.
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
						return $this->is_allowed();
					},
				)
			);
		}
	}

	/**
	 * Enqueue JavaScript and CSS assets for the Gutenberg Block Editor.
	 */
	public function enqueue_editor_assets()
	{
		if (!$this->is_allowed()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		// Never load assets on Site Editor/FSE screens.
		if ($screen && (false !== strpos($screen->id, 'site-editor') || 'site-editor' === $screen->base)) {
			return;
		}

		$post_type = $screen ? $screen->post_type : '';

		if (!in_array($post_type, $this->get_supported_post_types(), true)) {
			return;
		}

		// Initialize WordPress core code editor settings (CodeMirror) for CSS.
		$settings = wp_enqueue_code_editor(array('type' => 'text/css'));

		wp_enqueue_script(
			'imaginasite-per-page-css',
			plugins_url('assets/js/editor.js', __FILE__),
			array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose', 'wp-notices'),
			'1.2.7',
			true
		);

		$panel_title = ('page' === $post_type)
			? __('Specific CSS for this page', 'imaginasite-per-page-css')
			: __('Specific CSS for this post', 'imaginasite-per-page-css');

		$script_data = array(
			'settings' => false !== $settings ? $settings : null,
			'i18n' => array(
				'disabled' => __('Syntax highlighting disabled in your profile.', 'imaginasite-per-page-css'),
				'js_error' => __('JS Error: ', 'imaginasite-per-page-css'),
				'timeout' => __('Timeout: wp.codeEditor unavailable', 'imaginasite-per-page-css'),
				'panel_title' => $panel_title,
				'diagnostic' => __('DIAGNOSTIC:\n', 'imaginasite-per-page-css'),
				'status' => __('- Status: ', 'imaginasite-per-page-css'),
				'wp_codeeditor' => __('- wp.codeEditor: ', 'imaginasite-per-page-css'),
				'container' => __('- Container: ', 'imaginasite-per-page-css'),
				'css_invalid' => __('The CSS contains a syntax error or unsupported syntax. Please check missing braces, brackets, comments, @import, javascript:, expression(), behavior:, or -moz-binding.', 'imaginasite-per-page-css'),
			),
		);

		wp_add_inline_script(
			'imaginasite-per-page-css',
			'window.imaginasitePerPageCssData = ' . wp_json_encode($script_data) . ';',
			'before'
		);
	}

	/**
	 * Enqueue assets for both Classic Editor and custom Gutenberg implementations (like WooCommerce).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets($hook)
	{
		if (!$this->is_allowed()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$post_type = $screen ? $screen->post_type : '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		// Attempt to determine post type on various admin hooks.
		if (!$post_type && isset($_GET['post'])) {
			$post_type = get_post_type(absint(wp_unslash($_GET['post'])));
		} elseif (!$post_type && isset($_GET['post_type'])) {
			$post_type = sanitize_key(wp_unslash($_GET['post_type']));
		}

		// Detect WooCommerce new product editor (SPA).
		if (false !== strpos($hook, 'wc-admin') || (isset($_GET['page']) && 'wc-admin' === sanitize_text_field(wp_unslash($_GET['page'])))) {
			if (isset($_GET['path']) && false !== strpos(sanitize_text_field(wp_unslash($_GET['path'])), '/product')) {
				$post_type = 'product';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$is_classic_edit = ('post.php' === $hook || 'post-new.php' === $hook);

		if (in_array($post_type, $this->get_supported_post_types(), true)) {
			$settings = wp_enqueue_code_editor(array('type' => 'text/css'));

			if (false !== $settings && $is_classic_edit) {
				wp_add_inline_script(
					'code-editor',
					sprintf(
						'jQuery(document).ready(function($){if($("#page_post_specific_css_field").length){wp.codeEditor.initialize("page_post_specific_css_field", %s);}});',
						wp_json_encode($settings)
					)
				);
			}
		}
	}

	/**
	 * Register the metabox for the Classic Editor.
	 */
	public function register_classic_metabox()
	{
		if (!$this->is_allowed()) {
			return;
		}

		foreach ($this->get_supported_post_types() as $post_type) {
			$title = ('page' === $post_type)
				? __('Specific CSS for this page', 'imaginasite-per-page-css')
				: __('Specific CSS for this post', 'imaginasite-per-page-css');

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
	 * Render the Classic Editor metabox HTML.
	 *
	 * @param WP_Post $post Current post object.
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
	 * Save the CSS from the Classic Editor metabox.
	 *
	 * @param int $post_id Current post ID.
	 */
	public function save_classic_metabox($post_id)
	{
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (wp_is_post_revision($post_id)) {
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

		$post_type = get_post_type($post_id);

		if (!in_array($post_type, $this->get_supported_post_types(), true)) {
			return;
		}

		if (isset($_POST['page_post_specific_css_field'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_css = wp_unslash($_POST['page_post_specific_css_field']);
			$css = $this->sanitize_css($raw_css);

			if ('' !== trim((string) $raw_css) && is_wp_error($this->validate_css($css))) {
				return;
			}

			update_post_meta(
				$post_id,
				self::META_KEY,
				wp_slash($css)
			);
		}
	}

	/**
	 * Inject the custom CSS into the public site <head>.
	 */
	public function print_css_in_head()
	{
		if (is_admin() || !is_singular()) {
			return;
		}

		$post_id = get_queried_object_id();
		$css = get_post_meta($post_id, self::META_KEY, true);
		$css = $this->sanitize_css($css);

		if (empty($css)) {
			return;
		}

		$validation = $this->validate_css($css);

		if (is_wp_error($validation)) {
			return;
		}

		echo "\n<style id=\"imaginasite-per-page-css\">\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is normalized and validated before output.
		echo $css;
		echo "\n</style>\n";
	}

	/**
	 * Validate CSS to prevent markup/script injection and unsupported dangerous CSS patterns.
	 *
	 * @param string $css CSS code.
	 *
	 * @return true|WP_Error
	 */
	private function validate_css($css)
	{
		$errors = new WP_Error();

		if (preg_match('#</?\w+#', $css)) {
			$errors->add('illegal_markup', __('Markup is not allowed in CSS.', 'imaginasite-per-page-css'));
		}

		if (substr_count($css, '{') !== substr_count($css, '}')) {
			$errors->add('imbalanced_curly_brackets', __('CSS contains imbalanced curly brackets.', 'imaginasite-per-page-css'));
		}

		if (substr_count($css, '[') !== substr_count($css, ']')) {
			$errors->add('imbalanced_square_brackets', __('CSS contains imbalanced square brackets.', 'imaginasite-per-page-css'));
		}

		if (preg_match('#/\*[^*]*(?:\*(?!/)[^*]*)*$#', $css)) {
			$errors->add('unterminated_comment', __('CSS contains an unterminated comment.', 'imaginasite-per-page-css'));
		}

		if (preg_match('#expression\s*\(|javascript\s*:|vbscript\s*:|@import\b|behavior\s*:|-moz-binding\b#i', $css)) {
			$errors->add('unsafe_css', __('This CSS contains unsafe or unsupported syntax.', 'imaginasite-per-page-css'));
		}

		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Normalize CSS input before saving and output.
	 *
	 * Validation is handled separately to avoid silently replacing invalid CSS with an empty value.
	 *
	 * @param mixed $css CSS input.
	 *
	 * @return string
	 */
	public function sanitize_css($css)
	{
		$css = is_string($css) ? trim($css) : '';
		$css = wp_check_invalid_utf8($css);
		$css = str_replace("\0", '', $css);

		return $css;
	}

	/**
	 * Get all public post types that support the editor.
	 *
	 * @return string[]
	 */
	private function get_supported_post_types()
	{
		$post_types = get_post_types(
			array(
				'public' => true,
				'show_ui' => true,
			),
			'names'
		);

		$excluded_fse_types = array(
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_block',
		);

		return array_filter(
			$post_types,
			function ($pt) use ($excluded_fse_types) {
				return post_type_supports($pt, 'editor') && !in_array($pt, $excluded_fse_types, true);
			}
		);
	}
}

// Start the plugin.
new Imaginasite_Per_Page_CSS_Plugin();

<?php
/**
 * Plugin Name: Imaginasite Per Page CSS
 * Plugin URI: https://www.imaginasite.com/per-page-css-wordpress-plugin
 * Description: Adds a CSS editing field to posts, pages, custom post types, and block templates, with automatic frontend injection and live preview support.
 * Version: 1.5.2
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
	 * Current block template being rendered.
	 *
	 * @var WP_Block_Template|null
	 */
	private $current_block_template = null;

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
		add_filter('get_block_template', array($this, 'capture_current_block_template'), 10, 3);
		add_action('rest_api_init', array($this, 'register_template_rest_field'));

		// Prevent Gutenberg/REST and classic editor updates from storing invalid CSS.
		add_filter('add_post_metadata', array($this, 'prevent_invalid_css_meta_update'), 10, 5);
		add_filter('update_post_metadata', array($this, 'prevent_invalid_css_meta_update'), 10, 5);
	}

	/**
	 * Capture the current block template being rendered.
	 *
	 * @param WP_Block_Template|null $template      The resolved block template.
	 * @param string                 $id             Theme-relative template ID.
	 * @param string                 $template_type  The template type (e.g., 'wp_template', 'wp_template_part').
	 *
	 * @return WP_Block_Template|null
	 */
	public function capture_current_block_template($template, $id, $template_type)
	{
		if ('wp_template' === $template_type && $template && !empty($template->wp_id)) {
			$this->current_block_template = $template;
		}

		return $template;
	}

	/**
	 * Register a manual REST API field for wp_template to support meta saving.
	 * wp_template does not natively support custom-fields via the REST API.
	 */
	public function register_template_rest_field()
	{
		register_rest_field(
			'wp_template',
			self::META_KEY,
			array(
				'get_callback'    => function ($template) {
					$wp_id = $this->get_template_wp_id($template);
					if (!$wp_id) {
						return '';
					}
					return get_post_meta($wp_id, self::META_KEY, true);
				},
				'update_callback' => function ($value, $template) {
					$wp_id = $this->get_template_wp_id($template);

					if (!$wp_id) {
						return false;
					}

					if (!$this->is_allowed() || !current_user_can('edit_post', $wp_id)) {
						return new WP_Error(
							'rest_forbidden',
							__('Sorry, you are not allowed to do that.'),
							array('status' => rest_authorization_required_code())
						);
					}

					$css = $this->sanitize_css($value);

					if ('' !== trim((string) $value) && is_wp_error($this->validate_css($css))) {
						return new WP_Error('invalid_css', __('Invalid CSS.', 'imaginasite-per-page-css'), array('status' => 400));
					}

					update_post_meta($wp_id, self::META_KEY, wp_slash($css));
					return true;
				},
				'schema'          => array(
					'type'    => 'string',
					'context' => array('view', 'edit'),
				),
			)
		);
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
		$post_types = $this->get_content_post_types();

		foreach ($post_types as $post_type) {
			register_post_meta(
				$post_type,
				self::META_KEY,
				array(
					'type' => 'string',
					'single' => true,
					'show_in_rest' => true,
					'sanitize_callback' => array($this, 'sanitize_css'),
					'auth_callback' => function ($allowed, $meta_key, $post_id) {
						$post_id = absint($post_id);

						return $post_id
							&& $this->is_allowed()
							&& current_user_can('edit_post', $post_id);
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



		$post_type = $screen ? $screen->post_type : '';

		if (!$post_type && $screen && (false !== strpos($screen->id, 'site-editor') || 'site-editor' === $screen->base)) {
			$post_type = 'wp_template';
		}

		if (!in_array($post_type, $this->get_supported_post_types(), true)) {
			return;
		}

		// Initialize WordPress core code editor settings (CodeMirror) for CSS.
		$settings = wp_enqueue_code_editor(array('type' => 'text/css'));

		$dependencies = array('wp-plugins', 'wp-editor', 'wp-element', 'wp-data', 'wp-compose');

		if ($screen && (false !== strpos($screen->id, 'site-editor') || 'site-editor' === $screen->base)) {
			$dependencies[] = 'wp-edit-site';
		} else {
			$dependencies[] = 'wp-edit-post';
		}

		wp_enqueue_script(
			'imaginasite-per-page-css',
			plugins_url('assets/js/editor.js', __FILE__),
			$dependencies,
			'1.5.2',
			true
		);

		if ('wp_template' === $post_type) {
			$panel_title = __('Specific CSS for this template', 'imaginasite-per-page-css');
		} elseif ('page' === $post_type) {
			$panel_title = __('Specific CSS for this page', 'imaginasite-per-page-css');
		} else {
			$panel_title = __('Specific CSS for this post', 'imaginasite-per-page-css');
		}

		$script_data = array(
			'meta_key' => self::META_KEY,
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
				'css_invalid' => __('Invalid or unsupported CSS syntax detected. Please check your code.', 'imaginasite-per-page-css'),
				'template_notice' => __('This CSS applies only when this template renders the current page. Unsaved theme templates must be saved first.', 'imaginasite-per-page-css'),
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

		$is_classic_edit = ('post.php' === $hook || 'post-new.php' === $hook);

		if (!$is_classic_edit) {
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if (!in_array($post_type, $this->get_content_post_types(), true)) {
			return;
		}

		$settings = wp_enqueue_code_editor(array('type' => 'text/css'));

		if (false !== $settings) {
			wp_add_inline_script(
				'code-editor',
				sprintf(
					'jQuery(document).ready(function($){if($("#page_post_specific_css_field").length){wp.codeEditor.initialize("page_post_specific_css_field", %s);}});',
					wp_json_encode($settings)
				)
			);
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

		foreach ($this->get_content_post_types() as $post_type) {
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

		if (!in_array($post_type, $this->get_content_post_types(), true)) {
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
		if (is_admin()) {
			return;
		}

		$css_parts = array();

		if (is_singular()) {
			$post_id = get_queried_object_id();

			if ($post_id) {
				$post_css = get_post_meta($post_id, self::META_KEY, true);
				$post_css = $this->sanitize_css($post_css);

				if (!empty($post_css) && !is_wp_error($this->validate_css($post_css))) {
					$css_parts[] = "/* Imaginasite Per Page CSS: content */\n" . $post_css;
				}
			}
		}

		$template_id = $this->get_current_template_post_id();

		if ($template_id) {
			$template_css = get_post_meta($template_id, self::META_KEY, true);
			$template_css = $this->sanitize_css($template_css);

			if (!empty($template_css) && !is_wp_error($this->validate_css($template_css))) {
				$css_parts[] = "/* Imaginasite Per Page CSS: template */\n" . $template_css;
			}
		}

		if (empty($css_parts)) {
			return;
		}

		echo "\n<style id=\"imaginasite-per-page-css\">\n";
		echo str_ireplace('</style', '<\/style', implode("\n\n", $css_parts)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "\n</style>\n";
	}

	private function get_current_template_post_id()
	{
		if (
			$this->current_block_template &&
			!empty($this->current_block_template->wp_id)
		) {
			return absint($this->current_block_template->wp_id);
		}

		if (!function_exists('get_block_template') || !function_exists('get_stylesheet')) {
			return 0;
		}

		$theme = get_stylesheet();
		$candidates = array();

		if (is_front_page()) {
			$candidates[] = 'front-page';
		}

		if (is_home()) {
			$candidates[] = 'home';
		}

		if (is_page()) {
			$candidates[] = 'page';
		}

		if (is_singular()) {
			$post_type = get_post_type();

			if ($post_type) {
				$candidates[] = 'single-' . $post_type;
			}

			$candidates[] = 'single';
			$candidates[] = 'singular';
		}

		if (is_archive()) {
			$candidates[] = 'archive';
		}

		if (is_search()) {
			$candidates[] = 'search';
		}

		if (is_404()) {
			$candidates[] = '404';
		}

		$candidates[] = 'index';

		foreach (array_unique($candidates) as $slug) {
			$template = get_block_template($theme . '//' . $slug, 'wp_template');

			if ($template && !empty($template->wp_id)) {
				return absint($template->wp_id);
			}
		}

		return 0;
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
	 * Get the list of content post types (posts, pages, etc.) that support our CSS field.
	 *
	 * @return string[]
	 */
	private function get_content_post_types()
	{
		$post_types = get_post_types(
			array(
				'public' => true,
				'show_ui' => true,
			),
			'names'
		);

		$excluded_fse_types = array(
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

	/**
	 * Get all supported post types, including FSE templates.
	 *
	 * @return string[]
	 */
	private function get_supported_post_types()
	{
		$supported = $this->get_content_post_types();
		$supported[] = 'wp_template';

		return array_unique($supported);
	}

	/**
	 * Robustly extract the WordPress post ID from a template object or array.
	 *
	 * @param mixed $template Template data from REST API.
	 *
	 * @return int
	 */
	private function get_template_wp_id($template)
	{
		if (is_array($template) && !empty($template['wp_id'])) {
			return absint($template['wp_id']);
		}

		if (is_object($template) && !empty($template->wp_id)) {
			return absint($template->wp_id);
		}

		if (is_object($template) && !empty($template->ID)) {
			return absint($template->ID);
		}

		return 0;
	}
}

// Start the plugin.
new Imaginasite_Per_Page_CSS_Plugin();

/**
 * Initialize GitHub Updater for automatic updates.
 */
if (is_admin()) {
	require_once plugin_dir_path(__FILE__) . 'includes/github-updater.php';
	new Imaginasite_GitHub_Updater(__FILE__, 'imaginasite/per-page-css-wordpress-plugin');
}

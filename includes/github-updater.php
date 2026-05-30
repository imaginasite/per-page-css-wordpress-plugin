<?php
/**
 * GitHub Updater Class.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Imaginasite_GitHub_Updater
{
	/**
	 * Path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin basename, for example: imaginasite-per-page-css/imaginasite-per-page-css.php.
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug/directory, for example: imaginasite-per-page-css.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * GitHub repository name, for example: imaginasite/per-page-css-wordpress-plugin.
	 *
	 * @var string
	 */
	private $github_repo;

	/**
	 * Cached GitHub API response.
	 *
	 * @var object|false|null
	 */
	private $github_response;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Path to the main plugin file.
	 * @param string $github_repo GitHub repository name (e.g., 'username/repo').
	 */
	public function __construct($plugin_file, $github_repo)
	{
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename($plugin_file);
		$this->slug            = dirname($this->plugin_basename);
		$this->github_repo     = $github_repo;

		if ('.' === $this->slug || '' === $this->slug) {
			$this->slug = basename($this->plugin_basename, '.php');
		}

		add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
		add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
		add_filter('upgrader_source_selection', array($this, 'upgrader_source_selection'), 10, 4);
	}

	/**
	 * Get repository info from GitHub API.
	 *
	 * @return object|false
	 */
	private function get_repository_info()
	{
		if (null !== $this->github_response) {
			return $this->github_response;
		}

		$transient_key = 'imaginasite_per_page_css_gh_release';
		$cached = get_transient($transient_key);

		if (false !== $cached) {
			$this->github_response = $cached;
			return $this->github_response;
		}

		$url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";

		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept' => 'application/vnd.github.v3+json',
			),
		);

		$response = wp_remote_get($url, $args);

		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
			$this->github_response = false;
			return false;
		}

		$this->github_response = json_decode(wp_remote_retrieve_body($response));

		if ($this->github_response) {
			set_transient($transient_key, $this->github_response, 12 * HOUR_IN_SECONDS);
		}

		return $this->github_response;
	}

	/**
	 * Get the best download URL for the release.
	 *
	 * Prefer an uploaded release asset named after the plugin slug. Fall back to the
	 * GitHub-generated zipball URL if no matching release asset exists.
	 *
	 * @param object $release GitHub release object.
	 *
	 * @return string
	 */
	private function get_download_url($release)
	{
		if (!empty($release->assets) && is_array($release->assets)) {
			$first_zip_asset = '';

			foreach ($release->assets as $asset) {
				if (empty($asset->name) || empty($asset->browser_download_url)) {
					continue;
				}

				$asset_name = strtolower($asset->name);

				if (strtolower($this->slug . '.zip') === $asset_name) {
					return $asset->browser_download_url;
				}

				if ('.zip' === substr($asset_name, -4) && '' === $first_zip_asset) {
					$first_zip_asset = $asset->browser_download_url;
				}
			}

			if ($first_zip_asset) {
				return $first_zip_asset;
			}
		}

		return !empty($release->zipball_url) ? $release->zipball_url : '';
	}

	/**
	 * Get plugin icons for WordPress update screens and plugin details popup.
	 *
	 * Local files are preferred. If they are not available yet, fall back to the
	 * icon files from the GitHub release tag.
	 *
	 * Expected local paths:
	 * - assets/icon-128x128.png
	 * - assets/icon-256x256.png
	 * - assets/icon.svg (optional)
	 *
	 * @param object|null $release GitHub release object.
	 *
	 * @return array
	 */
	private function get_icons($release = null)
	{
		$icons      = array();
		$asset_path = plugin_dir_path($this->plugin_file) . 'assets/';
		$asset_url  = plugins_url('assets/', $this->plugin_file);

		if (file_exists($asset_path . 'icon-128x128.png')) {
			$icons['1x'] = $asset_url . 'icon-128x128.png';
		}

		if (file_exists($asset_path . 'icon-256x256.png')) {
			$icons['2x']      = $asset_url . 'icon-256x256.png';
			$icons['default'] = $asset_url . 'icon-256x256.png';
		}

		if (file_exists($asset_path . 'icon.svg')) {
			$icons['svg'] = $asset_url . 'icon.svg';
		}

		if (empty($icons) && $release && !empty($release->tag_name)) {
			$remote_asset_url = sprintf(
				'https://raw.githubusercontent.com/%s/%s/assets/',
				$this->github_repo,
				rawurlencode($release->tag_name)
			);

			$icons = array(
				'1x'      => $remote_asset_url . 'icon-128x128.png',
				'2x'      => $remote_asset_url . 'icon-256x256.png',
				'default' => $remote_asset_url . 'icon-256x256.png',
			);
		}

		if (empty($icons['default']) && !empty($icons['1x'])) {
			$icons['default'] = $icons['1x'];
		}

		return $icons;
	}

	/**
	 * Check for updates and modify the update transient.
	 *
	 * @param object $transient Update transient.
	 *
	 * @return object
	 */
	public function check_update($transient)
	{
		if (empty($transient->checked)) {
			return $transient;
		}

		$release = $this->get_repository_info();

		if (!$release || empty($release->tag_name)) {
			return $transient;
		}

		// Remove leading 'v' from version if present.
		$remote_version = ltrim($release->tag_name, 'v');
		$plugin_data    = get_plugin_data($this->plugin_file);
		$local_version  = !empty($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';
		$download_url   = $this->get_download_url($release);

		if ($download_url && version_compare($remote_version, $local_version, '>')) {
			$obj              = new stdClass();
			$obj->id          = "https://github.com/{$this->github_repo}";
			$obj->slug        = $this->slug;
			$obj->plugin      = $this->plugin_basename;
			$obj->new_version = $remote_version;
			$obj->url         = "https://github.com/{$this->github_repo}";
			$obj->package     = $download_url;
			$obj->icons       = $this->get_icons($release);

			$transient->response[$this->plugin_basename] = $obj;
		}

		return $transient;
	}

	/**
	 * Provide plugin details for the update popup.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 *
	 * @return false|object|array
	 */
	public function plugin_popup($result, $action, $args)
	{
		if ('plugin_information' !== $action || empty($args->slug)) {
			return $result;
		}

		if ($args->slug !== $this->slug && $args->slug !== $this->plugin_basename) {
			return $result;
		}

		$release = $this->get_repository_info();

		if (!$release) {
			return $result;
		}

		$plugin_data  = get_plugin_data($this->plugin_file);
		$download_url = $this->get_download_url($release);

		$res                = new stdClass();
		$res->name          = $plugin_data['Name'];
		$res->slug          = $this->slug;
		$res->version       = !empty($release->tag_name) ? ltrim($release->tag_name, 'v') : $plugin_data['Version'];
		$res->author        = $plugin_data['Author'];
		$res->homepage      = $plugin_data['PluginURI'];
		$res->download_link = $download_url;
		$res->icons         = $this->get_icons($release);

		$res->sections = array(
			'description' => $plugin_data['Description'],
			'changelog'   => isset($release->body) ? wp_kses_post(nl2br($release->body)) : '',
		);

		return $res;
	}

	/**
	 * Rename the extracted folder to match the plugin slug.
	 *
	 * GitHub zipballs often contain a directory named 'repo-name-hash'.
	 * We need to ensure the plugin stays in its correct directory.
	 *
	 * @param string      $source        File source location.
	 * @param string      $remote_source Remote file source location.
	 * @param WP_Upgrader $upgrader      WP_Upgrader instance.
	 * @param array       $hook_extra    Extra arguments passed to hooked filters.
	 *
	 * @return string
	 */
	public function upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra = null)
	{
		global $wp_filesystem;

		// Ensure we are only targeting this specific plugin update.
		if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->plugin_basename) {
			$expected_dir = trailingslashit($remote_source) . $this->slug;

			if (untrailingslashit($source) !== $expected_dir) {
				if ($wp_filesystem->exists($expected_dir)) {
					$wp_filesystem->delete($expected_dir, true);
				}

				if ($wp_filesystem->move($source, $expected_dir, true)) {
					return trailingslashit($expected_dir);
				}
			}
		}

		return $source;
	}
}

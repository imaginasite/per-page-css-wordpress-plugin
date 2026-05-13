<?php
/**
 * GitHub Updater Class.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Imaginasite_GitHub_Updater
{
	private $plugin_file;
	private $slug;
	private $github_repo;
	private $github_response;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Path to the main plugin file.
	 * @param string $github_repo GitHub repository name (e.g., 'username/repo').
	 */
	public function __construct($plugin_file, $github_repo)
	{
		$this->plugin_file = $plugin_file;
		$this->slug        = plugin_basename($plugin_file);
		$this->github_repo = $github_repo;

		add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
		add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
		add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
	}

	/**
	 * Get repository info from GitHub API.
	 */
	private function get_repository_info()
	{
		if (null !== $this->github_response) {
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
			return false;
		}

		$this->github_response = json_decode(wp_remote_retrieve_body($response));

		return $this->github_response;
	}

	/**
	 * Check for updates and modify the update transient.
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
		$local_version  = get_plugin_data($this->plugin_file)['Version'];

		if (version_compare($remote_version, $local_version, '>')) {
			$obj              = new stdClass();
			$obj->slug        = $this->slug;
			$obj->new_version = $remote_version;
			$obj->url         = "https://github.com/{$this->github_repo}";
			$obj->package     = $release->zipball_url;
			$obj->plugin      = $this->slug;

			$transient->response[$this->slug] = $obj;
		}

		return $transient;
	}

	/**
	 * Provide plugin details for the update popup.
	 */
	public function plugin_popup($result, $action, $args)
	{
		if ('plugin_information' !== $action || !isset($args->slug) || $args->slug !== $this->slug) {
			return $result;
		}

		$release = $this->get_repository_info();

		if (!$release) {
			return $result;
		}

		$plugin_data = get_plugin_data($this->plugin_file);

		$res = new stdClass();
		$res->name         = $plugin_data['Name'];
		$res->slug         = $this->slug;
		$res->version      = ltrim($release->tag_name, 'v');
		$res->author       = $plugin_data['Author'];
		$res->homepage     = $plugin_data['PluginURI'];
		$res->download_link = $release->zipball_url;

		$res->sections = array(
			'description' => $plugin_data['Description'],
			'changelog'   => isset($release->body) ? wp_kses_post(nl2br($release->body)) : '',
		);

		return $res;
	}

	/**
	 * Perform actions after the plugin has been installed.
	 *
	 * GitHub zipballs often contain a directory named 'repo-name-hash'.
	 * We need to ensure the plugin stays in its correct directory.
	 */
	public function after_install($response, $hook_extra, $result)
	{
		global $wp_filesystem;

		$plugin_dir = WP_PLUGIN_DIR . '/' . dirname($this->slug);
		$wp_filesystem->move($result['destination'], $plugin_dir);
		$result['destination'] = $plugin_dir;

		return $result;
	}
}

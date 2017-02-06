<?php

namespace Drupal\wildcat_os;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\State\StateInterface;
use Drupal\wildcat_os\Exception\BadFlavorException;

/**
 * Helper class to get information from the wildcat.flavor.yml files.
 */
class WildcatOsFlavor implements WildcatOsFlavorInterface {

  /**
   * The path to the Drupal root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * The path to the site's configuration (e.g. sites/default).
   *
   * @var string
   */
  protected $sitePath;

  /**
   * The state backend.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  protected $flavor;

  /**
   * Constructs a new WildcatOsWildcatOsFlavor.
   *
   * @param string $app_root
   *   The path to the Drupal root.
   * @param string $site_path
   *   The path to the site's configuration (e.g. sites/default).
   * @param \Drupal\Core\State\StateInterface $state
   *   The state backend.
   */
  public function __construct($app_root, $site_path, StateInterface $state) {
    $this->appRoot = (string) $app_root;
    $this->sitePath = (string) $site_path;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function get($rebuild = FALSE) {
    // If needed, get the cached flavor (from state), when not rebuilding.
    if (!$rebuild && !isset($this->flavor)) {
      $this->flavor = $this->state->get('wildcat_os.flavor') ?? [];
    }

    // When rebuilding or no cached flavor, parse the wildcat.flavor.yml files.
    if ($rebuild || empty($this->flavor)) {
      $this->flavor = $this->discoverFlavor();
      $this->state->set('wildcat_os.flavor', $this->flavor);
    }

    return $this->flavor;
  }

  /**
   * Returns the flavor definition after processing wildcat.flavor.yml files.
   *
   * @return array
   *   The array containing the flavor definition.
   */
  protected function discoverFlavor() {
    // There can be up to two files with overrides for each site:
    // - an "installation" flavor, located in the /sites/ folder, which
    //   includes overrides that apply to all sites in this installation,
    // - a "site" flavor, located in the site path (i.e. /sites/default/),
    //   which can override the definition provided for all sites.
    $install = $this->loadFlavorFile($this->appRoot . '/sites');
    $site = $this->loadFlavorFile($this->sitePath);

    // Use, by default, the 'wildcat' base flavor.
    $base = 'wildcat';
    // But use the base flavor from the 'installation' file, if there is one.
    $base = strtolower($install['base_flavor']) ?: $base;
    // But use the base flavor from the 'site' file, if there is one.
    $base = strtolower($site['base_flavor']) ?: $base;
    $base = $this->getBaseFlavor($base);

    // Add the wildcat module to the requires, it is always required.
    $require = ['wildcat'];
    // Add modules required in the 'installation' file to the requires.
    $require = array_merge($require, $install['modules']['require']);
    // Add modules required in the 'site' file to the requires.
    $require = array_merge($require, $site['modules']['require']);

    // Add base flavor modules to the recommends.
    $recommend = $base['modules'];
    // Add modules recommended in the 'installation' file to the recommends.
    $recommend = array_merge($recommend, $install['modules']['recommend']);
    // Add modules recommended in the 'site' file to the recommends.
    $recommend = array_merge($recommend, $site['modules']['recommend']);
    // Remove modules excluded in the 'installation' file from the recommends.
    $recommend = array_diff($recommend, $install['modules']['exclude']);
    // Remove modules excluded in the 'site' file from the recommends.
    $recommend = array_diff($recommend, $site['modules']['exclude']);
    // Remove any required modules from the recommends.
    $recommend = array_diff($recommend, $require);

    // Use, by default, 'seven' as the admin theme.
    $theme_admin = $base['theme_admin'];
    // But use the admin theme from the 'installation' file, if there is one.
    $theme_admin = $install['theme_admin'] ?: $theme_admin;
    // But use the admin theme from the 'site' file, if there is one.
    $theme_admin = $site['theme_admin'] ?: $theme_admin;

    // Use, by default, 'float_left' as the default theme.
    $theme_default = $base['theme_default'];
    // But use the default theme from the 'installation' file, if there is one.
    $theme_default = $install['theme_default'] ?: $theme_default;
    // But use the default theme from the 'site' file, if there is one.
    $theme_default = $site['theme_default'] ?: $theme_default;

    // Use, as default, the front page as the post installation redirect.
    $redirect = [
      'path' => $base['post_install_redirect_path'],
      'options' => [],
    ];
    // But use the redirect from the 'installation' file, if there is one.
    if (!empty($install['post_install_redirect']['path'])) {
      $redirect = $install['post_install_redirect'];
    }
    // But use the redirect from the 'site' file, if there is one.
    if (!empty($site['post_install_redirect']['path'])) {
      $redirect = $site['post_install_redirect'];
    }

    $install_mode = $base['install_mode'];
    // But use the redirect from the 'installation' file, if there is one.
    if (!empty($install['install_mode']) && is_string($install['install_mode'])) {
      $install_mode = $install['install_mode'];
    }
    // But use the redirect from the 'site' file, if there is one.
    if (!empty($site['install_mode']) && is_string($site['install_mode'])) {
      $install_mode = $site['install_mode'];
    }

    return [
      'modules' => [
        'require' => $require,
        'recommend' => $recommend,
      ],
      'theme_admin' => $theme_admin,
      'theme_default' => $theme_default,
      'post_install_redirect' => $redirect,
      'install_mode' => $install_mode,
    ];
  }

  /**
   * Returns the base flavor definition.
   *
   * @param string $base
   *   The name of the base flavor. (If the given base flavor does not exist,
   *   the 'wildcat' base flavor is used.)
   *
   * @return array
   *   An array containing the definition.
   */
  protected function getBaseFlavor($base) {
    if ($base != 'standard' && $base != 'minimal') {
      $base = 'wildcat';
    }
    $filename = dirname(__DIR__) . "/wildcat.base_flavor.{$base}.yml";

    return $this->loadYaml($filename);
  }

  /**
   * Returns the decoded YAML from a file.
   *
   * @param string $filename
   *   The name and path of the file that needs to be decoded.
   *
   * @return mixed
   *   An array containing the decoded data. If the file was not found an empty
   *   array is returned.
   *
   * @throws \Drupal\wildcat_os\Exception\BadFlavorException
   *   When the file was found but could not be processed.
   */
  protected function loadYaml($filename) {
    $yaml = [];

    if (file_exists($filename)) {
      // Load and read the file.
      $file_contents = file_get_contents($filename);
      if (!$file_contents) {
        $msg = "Found {$filename}, but could read it.";
        throw new BadFlavorException($msg);
      }

      // If the file is not empty, try to decode the YAML.
      if (!empty($file_contents)) {
        try {
          $yaml = (array) Yaml::decode($file_contents);
        }
        catch (\Exception $e) {
          $msg = "Found {$filename}, but could not parse YAML.";
          throw new BadFlavorException($msg, $e->getCode(), $e);
        }
      }
    }

    return $yaml;
  }

  /**
   * Parses a wildcat.flavor.yml file and returns its flavor definition.
   *
   * @param string $dir
   *   The full path to the folder containing the wildcat.flavor.yml file.
   *
   * @return array
   *   An array containing the definition found in the wildcat.flavor.yml file.
   *   If the wildcat.flavor.yml does not exist an empty array is returned.
   */
  protected function loadFlavorFile($dir) {
    $raw = $this->loadYaml((string) $dir . '/wildcat.flavor.yml');
    $flavor = [
      'base_flavor' => '',
      'modules' => [
        'require' => [],
        'recommend' => [],
        'exclude' => [],
      ],
      'theme_admin' => '',
      'theme_default' => '',
      'post_install_redirect' => [
        'path' => '',
        'options' => [],
      ],
      'install_mode' => '',
    ];

    // Process the found definition.
    if (!empty($raw)) {
      // Only pass on 'base_flavor' if it is a non-empty string.
      if (!empty($raw['base_flavor']) && is_string($raw['base_flavor'])) {
        $flavor['base_flavor'] = $raw['base_flavor'];
      }

      // Pass on required, recommended, and exclude modules.
      if (!empty($raw['modules']) && is_array($raw['modules'])) {
        $find_modules = function ($modules) {
          // Ignore this entry completely when it is not an array.
          if (!is_array($modules)) {
            return [];
          }

          // Ignore any array item for this entry that is not a string.
          return array_filter($modules, function ($module) {
            return is_string($module);
          });
        };

        foreach (['require', 'recommend', 'exclude'] as $type) {
          if (!empty($raw['modules'][$type])) {
            $flavor['modules'][$type] = $find_modules($raw['modules'][$type]);
          }
        }
      }

      // Only pass on 'theme_admin' if it is a non-empty string.
      if (!empty($raw['theme_admin']) && is_string($raw['theme_admin'])) {
        $flavor['theme_admin'] = $raw['theme_admin'];
      }

      // Only pass on 'theme_default' if it is a non-empty string.
      if (!empty($raw['theme_default']) && is_string($raw['theme_default'])) {
        $flavor['theme_default'] = $raw['theme_default'];
      }

      // Only pass on 'post_install_redirect' if it is a non-empty array.
      if (!empty($raw['post_install_redirect']) && is_array($raw['post_install_redirect'])) {
        $redirect = $raw['post_install_redirect'];
        // Only pass on the redirect 'path' if it is a non-empty string.
        if (!empty($redirect['path']) && is_string($redirect['path'])) {
          $flavor['post_install_redirect']['path'] = $redirect['path'];
        }
        // Only pass on the redirect 'options' if it is a non-empty array.
        if (!empty($redirect['options']) && is_array($redirect['options'])) {
          $flavor['post_install_redirect']['options'] = $redirect['options'];
        }
      }

      // Only pass on the 'install_mode' if it is a non-empty string.
      if (!empty($raw['install_mode']) && is_string($raw['install_mode'])) {
        $flavor['install_mode'] = $raw['install_mode'];
      }
    }

    return $flavor;
  }

}

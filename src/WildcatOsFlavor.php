<?php

namespace Drupal\wildcat_os;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\State\StateInterface;
use Drupal\wildcat_os\Exception\BadFlavorException;

/**
 * Helper class to get information from the wildcat.flavor.yml files.
 *
 * Inspired by Drupal\lightning\Extender in drupal/lightning:
 * http://cgit.drupalcode.org/lightning/tree/src/Extender.php?h=8.x-2.x
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
   * @param \SplString $app_root
   *   The path to the Drupal root.
   * @param \SplString $site_path
   *   The path to the site's configuration (e.g. sites/default).
   * @param \Drupal\Core\State\StateInterface $state
   *   The state backend.
   */
  public function __construct(\SplString $app_root, \SplString $site_path, StateInterface $state) {
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
      $this->discoverFlavor();
    }

    return $this->flavor;
  }

  /**
   * Returns the flavor definition after processing wildcat.flavor.yml files.
   *
   * @throws BadFlavorException
   *   When a wildcat.flavor.yml file was found but could not be processed.
   */
  protected function discoverFlavor() {
    // There can be up to two files with overrides for each site:
    // - an "installation" flavor, located in the /sites/ folder, which
    //   includes overrides that apply to all sites in this installation,
    // - a "site" flavor, located in the site path (i.e. /sites/default/),
    //   which can override the definition provided for all sites.
    $install = $this->loadFlavorFile($this->appRoot . '/sites');
    $site = $this->loadFlavorFile($this->sitePath);

    // Use, by default, the 'standard' base flavor.
    $base = 'standard';
    // But use the base flavor from the 'installation' file, if there is one.
    $base = strtolower($install['base_flavor']) ?: $base;
    // But use the base flavor from the 'site' file, if there is one.
    $base = strtolower($site['base_flavor']) ?: $base;

    // Add the wildcat module to the requires, it is always required.
    $require = ['wildcat'];
    // Add modules required in the 'installation' file to the requires.
    $require = array_merge($require, $install['modules']['require']);
    // Add modules required in the 'site' file to the requires.
    $require = array_merge($require, $site['modules']['require']);

    // Add base flavor modules to the recommends.
    $recommend = ($base === 'minimal') ? [] : [
      'wildcat_content_role',
      'wildcat_landing_page',
      'wildcat_layout',
      'wildcat_media',
      'wildcat_media_document',
      'wildcat_media_image',
      'wildcat_media_instagram',
      'wildcat_media_twitter',
      'wildcat_media_video',
    ];
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
    $theme_admin = 'seven';
    // But use the admin theme from the 'installation' file, if there is one.
    $theme_admin = $install['theme_admin'] ?: $theme_admin;
    // But use the admin theme from the 'site' file, if there is one.
    $theme_admin = $site['theme_admin'] ?: $theme_admin;

    // Use, by default, 'float_left' as the default theme.
    $theme_default = 'float_left';
    // But use the default theme from the 'site' file, if there is one.
    $theme_default = $site['theme_default'] ?: $theme_default;

    // Use, as default, the front page as the post installation redirect.
    $redirect = '<front>';
    // But use the redirect from the 'installation' file, if there is one.
    $redirect = $install['post_install_redirect'] ?: $redirect;
    // But use the redirect from the 'site' file, if there is one.
    $redirect = $site['post_install_redirect'] ?: $redirect;

    $this->flavor = [
      'modules' => [
        'require' => $require,
        'recommend' => $recommend,
      ],
      'theme_admin' => $theme_admin,
      'theme_default' => $theme_default,
      'post_install_redirect' => $redirect,
    ];
  }

  /**
   * Parses a wildcat.flavor.yml file and returns its flavor definition.
   *
   * @param $dir
   *   The full path to the folder containing the wildcat.flavor.yml file.
   *
   * @return array
   *   An array containing the definition found in the wildcat.flavor.yml file.
   *   If the wildcat.flavor.yml does not exist an empty array is returned.
   *
   * @throws BadFlavorException
   *   When a wildcat.flavor.yml file was found but could not be processed.
   */
  protected function loadFlavorFile($dir) {
    $flavor = [
      'base_flavor' => '',
      'modules' => [
        'require' => [],
        'recommend' => [],
        'exclude' => [],
      ],
      'theme_admin' => '',
      'theme_default' => '',
      'post_install_redirect' => '',
    ];

    $filename = (string) $dir . '/wildcat.flavor.yml';
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
          $raw = (array) Yaml::decode($file_contents);
        }
        catch (\Exception $e) {
          $msg = "Found {$filename}, but could not parse YAML.";
          throw new BadFlavorException($msg, $e->getCode(), $e);
        }
      }

      // Process the found definition.
      if (!empty($raw)) {
        // Only pass on 'base_flavor' if it is a non-empty string.
        if (empty($raw['base_flavor']) && is_string($raw['base_flavor'])) {
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
            return array_filter($modules, function($module) {
              return is_string($module);
            });
          };

          foreach (['require', 'recommend', 'exclude'] as $type) {
            $flavor['modules'][$type] = $find_modules($raw['modules'][$type]);
          }
        }

        // Only pass on 'theme_admin' if it is a non-empty string.
        if (!empty($raw['theme_admin'] && is_string($raw['theme_admin']))) {
          $flavor['theme_admin'] = $raw['theme_admin'];
        }

        // Only pass on 'theme_default' if it is a non-empty string.
        if (!empty($raw['theme_default']) && is_string($raw['theme_default'])) {
          $flavor['theme_default'] = $raw['theme_default'];
        }

        // Only pass on 'post_install_redirect' if it is a non-empty string.
        if (!empty($raw['post_install_redirect']) && is_string($raw['post_install_redirect'])) {
          $flavor['post_install_redirect'] = $raw['post_install_redirect'];
        }
      }
    }

    return $flavor;
  }

}

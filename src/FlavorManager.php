<?php

namespace Drupal\wildcat_os;

use Drupal\Component\Serialization\Yaml;
use Drupal\wildcat_os\Exception\BadFlavorException;
use Drupal\wildcat_os\Exception\InvalidBaseFlavorException;

/**
 * Helper class to get information from a site's wildcat.flavor.yml file.
 */
class FlavorManager implements FlavorManagerInterface {

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
   * The available base flavors.
   *
   * @var array
   */
  protected $baseFlavors;

  /**
   * The modules that should be enabled.
   *
   * @var string[]
   *
   * @see \Drupal\wildcat_os\FlavorManager::getModules()
   */
  protected $modules;

  /**
   * The URL of the path to redirect to after install.
   *
   * @var \Drupal\Core\Url|bool
   *
   * @see \Drupal\wildcat_os\FlavorManager::getPostInstallRedirect()
   */
  protected $postInstallRedirect;

  /**
   * The name of the administrative theme.
   *
   * @var string|bool
   *
   * @see \Drupal\wildcat_os\FlavorManager::getThemeAdmin()
   */
  protected $themeAdmin;

  /**
   * The name of the default theme.
   *
   * @var string|bool
   *
   * @see \Drupal\wildcat_os\FlavorManager::getThemeDefault()
   */
  protected $themeDefault;

  /**
   * Indicates whether the wildcat.flavor.yml files were loaded.
   *
   * @var bool
   */
  protected $yamlNeedsProcessing = TRUE;

  /**
   * Constructs a new FlavorManager.
   *
   * @param \SplString $app_root
   *   The path to the Drupal root.
   * @param \SplString $site_path
   *   The path to the site's configuration (e.g. sites/default).
   */
  public function __construct(\SplString $app_root, \SplString $site_path) {
    $this->appRoot = (string) $app_root;
    $this->sitePath = (string) $site_path;
  }

  /**
   * {@inheritdoc}
   *
   * @throws BadFlavorException
   *   If a flavor definition file was found but cannot be processed.
   */
  public function getModules() {
    if ($this->yamlNeedsProcessing) {
      $this->addFlavor();
    }

    return $this->modules;
  }

  /**
   * {@inheritdoc}
   *
   * @throws BadFlavorException
   *   If a flavor definition file was found but cannot be processed.
   */
  public function getPostInstallRedirect() {
    if ($this->yamlNeedsProcessing) {
      $this->addFlavor();
    }

    return $this->postInstallRedirect;
  }

  /**
   * {@inheritdoc}
   *
   * @throws BadFlavorException
   *   If a flavor definition file was found but cannot be processed.
   */
  public function getThemeAdmin() {
    if ($this->yamlNeedsProcessing) {
      $this->addFlavor();
    }

    return $this->themeDefault;
  }

  /**
   * {@inheritdoc}
   *
   * @throws BadFlavorException
   *   If a flavor definition file was found but cannot be processed.
   */
  public function getThemeDefault() {
    if ($this->yamlNeedsProcessing) {
      $this->addFlavor();
    }

    return $this->themeDefault;
  }

  /**
   * Discovers and loads any wildcat.flavor.yml files.
   *
   * @throws \Drupal\wildcat_os\Exception\BadFlavorException
   *   When a wildcat.flavor.yml was found, but could not be parsed.
   */
  protected function addFlavor() {
    // There is no reason to process the YAML files more than once.
    if (!$this->yamlNeedsProcessing) {
      return;
    }

    try {
      $this->yamlNeedsProcessing = FALSE;

      $base = $this->getBaseFlavor();
      $modules = [
        'require' => [],
        'recommend' => [],
        'exclude' => [],
      ];
      $redirect = NULL;
      $theme_admin = NULL;
      $theme_default = NULL;

      // There can be up to two files with overrides for each site:
      // - an "installation" flavor, located in the /sites/ folder, which
      //   includes overrides that apply to all sites in this installation,
      // - a "site" flavor, located in the site path (i.e. /sites/default/),
      //   which can override the definition provided for all sites.
      $flavor_files = [
        $this->appRoot . '/sites/wildcat.flavor.yml',
        $this->sitePath . '/wildcat.flavor.yml',
      ];

      // First look for and load the "installation" file, then look for and
      // load the "site" file so that it can override the installation "flavor".
      foreach ($flavor_files as $filename) {
        if (file_exists($filename)) {
          $yaml = [];
          // Load the file.
          $file_contents = file_get_contents($filename);
          if (!$file_contents) {
            $msg = "Reading contents of {$filename} failed.";
            throw new BadFlavorException($msg);
          }

          // If the file is not empty, try to decode the YAML.
          if (!empty($file_contents)) {
            try {
              $yaml = (array) Yaml::decode($file_contents);
            }
            catch (\Exception $e) {
              $msg = "Parsing YAML in '{$filename}' failed.";
              throw new BadFlavorException($msg, $e->getCode(), $e);
            }
          }

          // Look for any base flavor overrides:
          // - the base flavor defined for the "site" will be used, if found,
          // - the base flavor defined for the "installation" will be used, if
          //   an "installation" definition is found but not one for the "site".
          // An exception is thrown when an unknown base flavor is requested.
          if (!empty($yaml['base_flavor'])) {
            try {
              $base = $this->getBaseFlavor((string) $yaml['base_flavor']);
            }
            catch (InvalidBaseFlavorException $e) {
              $msg = $e->getMessage() . " (Request found in {$filename}.)";
              throw new InvalidBaseFlavorException($msg, $e->getCode(), $e);
            }
          }

          // Look for and collect all modules overrides.
          if (!empty($yaml['modules'])) {
            foreach (['require', 'recommend', 'exclude'] as $type) {
              if (!empty($yaml['modules'][$type])) {
                $found = (array) $yaml['modules'][$type];
                $modules[$type] = array_merge($modules[$type], $found);
              }
            }
          }

          // Look for post-installation redirect overrides:
          // - the redirect defined for the "site" will be used, if found,
          // - the redirect defined for the "installation" will be used, if
          //   an "installation" definition is found but not one for the "site".
          if (!empty($yaml['post_install_redirect'])) {
            $redirect = (string) $yaml['post_install_redirect'];
          }

          // Look for any administrative theme overrides:
          // - the admin theme defined for the "site" will be used, if found,
          // - the admin theme defined for the "installation" will be used, if
          //   an "installation" definition is found but not one for the "site".
          if (!empty($yaml['theme_admin'])) {
            $theme_admin = (string) $yaml['theme_admin'];
          }

          // Look for any default theme overrides:
          // - the default theme defined for the "site" will be used, if found,
          // - the default theme defined for the "installation" will be used, if
          //   an "installation" definition is found but not one for the "site".
          if (!empty($yaml['theme_default'])) {
            $theme_default = (string) $yaml['theme_default'];
          }
        }
      }

      // Set the modules that need to be enabled. First, the recommended modules
      // are added, that way the ones marked as excluded can be removed next.
      $this->modules = $base['modules']['recommend'];
      if (!empty($modules['require'])) {
        $this->modules = array_merge($this->modules, $modules['require']);
      }
      // Next, if needed, remove any modules that are marked as excluded.
      if (!empty($this->modules) && !empty($base['modules']['exclude'])) {
        $this->modules = array_diff($this->modules, $base['modules']['exclude']);
      }
      if (!empty($this->modules) && !empty($modules['exclude'])) {
        $this->modules = array_diff($this->modules, $modules['exclude']);
      }
      // Now, add the required modules.
      $this->modules = array_merge($this->modules, $base['modules']['require']);
      if (!empty($modules['require'])) {
        $this->modules = array_merge($this->modules, $modules['require']);
      }

      // Set the post-installation redirect.
      $this->postInstallRedirect = $redirect ?? $base['post_install_redirect'];

      // Set the admin theme.
      $this->themeAdmin = $theme_admin ?? $base['theme_admin'];

      // Set the default theme.
      $this->themeDefault = $theme_default ?? $base['theme_default'];

    }
    catch (InvalidBaseFlavorException $e) {
      throw new BadFlavorException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Returns the available base flavor definitions.
   *
   * @param string $base_flavor
   *   Optional name of a base flavor. If no base flavor is specified, the
   *   definition of the default 'standard' definition will be returned.
   *   Currently available base flavors are: 'standard' and 'minimal'.
   *
   * @return array
   *   A keyed array containing a base flavor definition.
   *
   * @throws \Drupal\wildcat_os\Exception\InvalidBaseFlavorException
   *   When the requested base flavor does not exists (i.e. if something other
   *   that either 'standard' or 'minimal' was requested).
   */
  protected function getBaseFlavor($base_flavor = '') {
    $base_flavors = [];
    $base_flavor = (string) $base_flavor;

    // Define the 'minimal' base flavor:
    // - Require the 'wildcat' modules (enabling cannot be prevented
    //   by using wildcat.flavor.yml files),
    // - By default, use 'seven' as administrative theme (another admin theme
    //   will be used if one is defined in a wildcat.flavor.yml file),
    // - By default, use 'wildcat' as default theme (another default theme will
    //   be used if one is defined in a wildcat.flavor.yml file),
    // - By default, use '<front>' as post-install redirect (another redirect
    //   will be used if one is defined in a wildcat.flavor.yml file).
    $base_flavors['minimal'] = [
      'modules' => [
        'required' => ['wildcat'],
        'optional' => [],
        'disabled' => [],
      ],
      'theme_admin' => 'seven',
      'theme_default' => 'wildcat',
      'post_install_redirect' => '<front>',
    ];

    // Define the 'standard' base flavor:
    // - use the required modules, themes, and redirect defined for 'minimal',
    // - also enable, by default, all included, non-experimental Wildcat
    //   modules (enabling these optional modules can be prevented by adding
    //   them under 'modules_disabled in a wildcat.flavor.yml file).
    $base_flavors['standard'] = $base_flavors['minimal'];
    $base_flavors['standard']['modules']['optional'] = [
      'wildcat_landing_page',
      'wildcat_media',
      'wildcat_media_document',
      'wildcat_media_image',
      'wildcat_media_instagram',
      'wildcat_media_twitter',
      'wildcat_media_video',
    ];

    // If no base flavor was requested, return the 'standard' base flavor.
    if (empty($base_flavor)) {
      return $base_flavors['standard'];
    }

    // Do not proceed if the requested base flavor is not available.
    if (!isset($base_flavors[$base_flavor])) {
      $msg = "Base flavor {$base_flavor} is not available.";
      throw new InvalidBaseFlavorException($msg);
    }

    // If an available base flavor was requested, return that base flavor.
    return $base_flavors[$base_flavor];
  }

}

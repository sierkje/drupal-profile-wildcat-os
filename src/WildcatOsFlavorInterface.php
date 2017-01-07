<?php

/**
 * @file
 * WildcatOsFlavorInterface.php
 *
 * @todo Add better file description.
 */
namespace Drupal\wildcat_os;

use Drupal\wildcat_os\Exception\BadFlavorException;

/**
 * Helper class to get information from the wildcat.flavor.yml files.
 *
 * Inspired by Drupal\lightning\Extender in drupal/lightning:
 * http://cgit.drupalcode.org/lightning/tree/src/Extender.php?h=8.x-2.x
 */
interface WildcatOsFlavorInterface {

  /**
   * Returns the flavor definition for this site.
   *
   * @param bool $rebuild
   *   Optional boolean, indicating whether the list of required modules needs
   *   to be rebuild. Defaults to FALSE.
   *
   * @return array
   *   An array with the flavor definition, the array has the following keys:
   *   - 'modules', an array containing two sub-arrays with the following keys:
   *     - 'require', an array with the names of modules that are required and
   *       cannot be uninstalled,
   *     - 'recommend', an array with the names of modules that are recommended
   *       to be enabled but may be uninstalled,
   *   - 'theme_admin', the recommended administrative theme of the site,
   *   - 'theme_default', the recommended default theme of the site,
   *   - 'post_install_redirect', the page the user is redirected to when the
   *     installation of is complete (only relevant during site installation.)
   *
   * @throws \Drupal\wildcat_os\Exception\BadFlavorException
   *   When a wildcat.flavor.yml file was found but could not be processed.
   */
  public function get($rebuild = FALSE);

}

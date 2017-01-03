<?php

namespace Drupal\wildcat_os;

use Drupal\wildcat_os\Exception\BadFlavorException;

/**
 * Provides an interface for adding custom flavors to Wildcat.
 *
 * Inspired by: Drupal\lightning\Extender in drupal/lightning.
 *
 * @see \Drupal\wildcat_os\FlavorManager
 * @see http://cgit.drupalcode.org/lightning/tree/src/Extender.php?h=8.x-2.x
 */
interface FlavorManagerInterface {

  /**
   * Returns the custom flavor modules that should be enabled.
   *
   * @return string[]
   *   An array of machine names of all modules that should be enabled.
   */
  public function getModules();

  /**
   * Returns the URL to redirect to after the installation has finished.
   *
   * @return \Drupal\Core\Url
   *   An object that holds information about a URL.
   */
  public function getPostInstallRedirect();

  /**
   * Returns the theme that should be enabled and used as administrative theme.
   *
   * @return string
   *   The machine name of an admin theme.
   */
  public function getThemeAdmin();

  /**
   * Returns the theme that should be enabled and used as default theme.
   *
   * @return string
   *   The machine name of a theme.
   */
  public function getThemeDefault();

}

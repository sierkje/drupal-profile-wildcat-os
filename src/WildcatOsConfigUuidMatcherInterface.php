<?php

namespace Drupal\wildcat_os;

/**
 * Provides an interface for matching UUIDs of active and synced config objects.
 */
interface WildcatOsConfigUuidMatcherInterface {

  /**
   * The $config_directories key for sync directory.
   */
  const CONFIG_SYNC_DIRECTORY = 'sync';

  /**
   * Matches the UUID of an active config object to the synced UUID.
   *
   * @param string $key
   *   The key of the config object that will be synced.
   */
  public function matchUuids($key);

}

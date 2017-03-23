<?php

namespace Drupal\wildcat_os;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Default matcher for UUIDs of active and synced config objects.
 */
class WildcatOsConfigUuidMatcher implements WildcatOsConfigUuidMatcherInterface {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The sync configuration object.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $syncStorage;

  /**
   * Constructs a new WildcatOsConfigUuidMatcher object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Config\StorageInterface $sync_storage
   *   The sync configuration object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StorageInterface $sync_storage) {
    $this->configFactory = $config_factory;
    $this->syncStorage = $sync_storage;
  }

  /**
   * {@inheritdoc}
   */
  public function matchUuids($key) {
    $active_config = $this->configFactory->getEditable($key);
    $sync_config = $this->syncStorage->read($key);

    if (!$active_config || empty($active_config->get('uuid'))) {
      return;
    }

    if (!$sync_config || empty($sync_config['uuid'])) {
      return;
    }

    $active_config
      ->set('uuid', $sync_config['uuid'])
      ->save();
  }

}

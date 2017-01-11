<?php

namespace Drupal\wildcat_os;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Prevents modules required in wildcat.flavor.yml files from being uninstalled.
 */
class WildcatOsUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  /**
   * The Wildcat OS flavor manager service.
   *
   * @var \Drupal\wildcat_os\WildcatOsFlavorInterface
   */
  protected $flavorManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity query for node.
   *
   * @var \Drupal\Core\Entity\Query\QueryInterface
   */
  protected $entityQuery;

  /**
   * Constructs a new WildcatOsUninstallValidator.
   *
   * @param \Drupal\wildcat_os\WildcatOsFlavorInterface $flavor_manager
   *   The Wildcat OS flavor manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(WildcatOsFlavorInterface $flavor_manager, ModuleHandlerInterface $module_handler, TranslationInterface $string_translation) {
    $this->flavorManager = $flavor_manager;
    $this->moduleHandler = $module_handler;
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\wildcat_os\Exception\BadFlavorException
   */
  public function validate($module) {
    $reasons = [];

    if (in_array($module, $this->flavorManager->get()['modules']['require'])) {
      $name = $this->moduleHandler->getName($module);
      $reasons[] = $this->t('@name is required by Wildcat OS, uninstalling this module is not allowed.', [
        '@name' => $name,
      ]);
    }

    return $reasons;
  }

}

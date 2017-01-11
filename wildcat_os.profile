<?php

/**
 * @file
 * Defines the Wildcat install screen by modifying the install form.
 *
 * Inspired by lightning.profile in drupal/lightning.
 * @see http://cgit.drupalcode.org/lightning/tree/lightning.profile?h=8.x-2.x
 */

use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Implements hook_install_tasks().
 */
function wildcat_os_install_tasks() {
  $run_task = INSTALL_TASK_RUN_IF_NOT_COMPLETED;
  $skip_task = INSTALL_TASK_SKIP;
  $flavor = \Drupal::state()->get('wildcat_os.flavor') ?: [];

  $require_title = t('Add some flavor');
  $has_required = !empty($flavor['modules']['require']);
  $recommend_title = $has_required ? t('Add some more flavor') : $require_title;
  $has_recommended = !empty($flavor['modules']['recommend']);

  return [
    'wildcat_os_get_flavor' => [
      'display' => FALSE,
    ],
    'wildcat_os_install_required_modules' => [
      'display_name' => $require_title,
      'display' => TRUE,
      'run' => $has_required ? $run_task : $skip_task,
      'type' => 'batch',
    ],
    'wildcat_os_install_recommended_modules' => [
      'display_name' => $recommend_title,
      'display' => TRUE,
      'run' => $has_recommended ? $run_task : $skip_task,
      'type' => 'batch',
    ],
    'wildcat_os_install_themes' => [
      'display' => FALSE,
    ],
  ];
}

/**
 * Implements hook_install_tasks_alter().
 */
function wildcat_os_install_tasks_alter(array &$tasks) {
  // We do not know the themes yet when Drupal wants to install them, so we need
  // to do this later.
  $tasks['install_profile_themes']['run'] = INSTALL_TASK_SKIP;
  $tasks['install_profile_themes']['display'] = FALSE;

  // Use a custom redirect callback, in case a custom redirect is specified.
  $tasks['install_finished'] = [
    'function' => 'wildcat_os_redirect',
    'display_name' => 'Install complete',
    'display' => TRUE,
  ];

  // Install flavor modules and themes immediately after profile is installed.
  $sorted_tasks = [];
  $wildcat_task_keys = [
    'wildcat_os_get_flavor',
    'wildcat_os_install_required_modules',
    'wildcat_os_install_recommended_modules',
    'wildcat_os_install_themes',
  ];
  foreach ($tasks as $key => $task) {
    if (!in_array($key, $wildcat_task_keys)) {
      $sorted_tasks[$key] = $task;
    }
    if ($key === 'install_install_profile') {
      foreach ($wildcat_task_keys as $wildcat_task_key) {
        $sorted_tasks[$wildcat_task_key] = $tasks[$wildcat_task_key];
      }
    }
  }
  $tasks = $sorted_tasks;
}

/**
 * Install task callback.
 *
 * Collects the flavor information.
 */
function wildcat_os_get_flavor() {
  /** @var \Drupal\wildcat_os\WildcatOsFlavorInterface $flavor */
  $flavor = \Drupal::service('wildcat_os.flavor');
  $flavor->get(TRUE);
}

/**
 * Install task callback.
 *
 * Installs flavor required modules via a batch process.
 *
 * @param array $install_state
 *   An array of information about the current installation state.
 *
 * @return array
 *   The batch definition.
 */
function wildcat_os_install_required_modules(array &$install_state) {
  if (!$flavor = \Drupal::state()->get('wildcat_os.flavor')) {
    return [];
  }

  $modules = $flavor['modules']['require'];
  $batch = _wildcat_os_install_modules($modules, $install_state);
  $batch['title'] = t('Adding flavor: installing required modules');

  return $batch;
}

/**
 * Install task callback.
 *
 * Installs flavor recommended modules via a batch process.
 *
 * @param array $install_state
 *   An array of information about the current installation state.
 *
 * @return array
 *   The batch definition.
 */
function wildcat_os_install_recommended_modules(array &$install_state) {
  if (!$flavor = \Drupal::state()->get('wildcat_os.flavor')) {
    return [];
  }

  $modules = $flavor['modules']['recommend'];
  $batch = _wildcat_os_install_modules($modules, $install_state);
  $batch['title'] = t('Adding flavor: installing recommended modules');

  return $batch;
}

/**
 * Returns the batch definitions for the module install task callbacks.
 *
 * Installs flavor modules via a batch process.
 *
 * @param array $modules
 *   An array of modules that either required or recommended for this flavor.
 * @param array $install_state
 *   An array of information about the current installation state.
 *
 * @return array
 *   The batch definition.
 *
 * @see wildcat_os_install_required_modules()
 * @see wildcat_os_install_recommended_modules()
 * @see install_profile_modules()
 */
function _wildcat_os_install_modules(array $modules, &$install_state) {
  if (empty($modules)) {
    return [];
  }

  $installed_modules = \Drupal::config('core.extension')->get('module') ?: [];
  // Do not pass on already installed modules.
  $modules = array_filter($modules, function ($module) use ($installed_modules) {
    return !isset($installed_modules[$module]);
  });
  \Drupal::state()->set('install_profile_modules', $modules);

  return install_profile_modules($install_state);
}

/**
 * Install task callback.
 *
 * Install themes and sets theme settings.
 *
 * @param array $install_state
 *   The current install state.
 */
function wildcat_os_install_themes(array &$install_state) {
  if (!$flavor = \Drupal::state()->get('wildcat_os.flavor')) {
    return;
  }

  if (!empty($flavor['theme_admin']) || !empty($flavor['theme_default'])) {
    $theme_config = \Drupal::configFactory()->getEditable('system.theme');
    $install_state['profile_info']['themes'] = [];

    foreach (['theme_admin', '$theme_default'] as $theme) {
      if (!empty($flavor[$theme])) {
        $install_state['profile_info']['themes'][] = $flavor[$theme];
        $theme_config->set('admin', $flavor[$theme]);
      }
    }

    $theme_config->save(TRUE);
    install_profile_themes($install_state);
  }

  if (\Drupal::moduleHandler()->moduleExists('node')) {
    \Drupal::configFactory()->getEditable('node.settings')
      ->set('use_admin_theme', TRUE)
      ->save(TRUE);
  }
}

/**
 * Install task callback.
 *
 * Redirects the user to a particular URL after installation.
 *
 * @return array
 *   A renderable array with a success message and a redirect header, if the
 *   extender is configured with one.
 */
function wildcat_os_redirect() {
  if (!$flavor = \Drupal::state()->get('wildcat_os.flavor')) {
    return [];
  }

  $redirect = $flavor['post_install_redirect'];
  $redirect['path'] = "internal:/{$redirect['path']}";
  $proceed_text = t('You can proceed to your site now');
  $proceed_url = Url::fromUri($redirect['path'], $redirect['options']);
  // Explicitly set the base URL, if not previously set, to prevent weird
  // redirection snafus.
  if (empty($proceed_url->getOption('base_url'))) {
    $proceed_url->setOption('base_url', $GLOBALS['base_url']);
  }
  $proceed_url->setAbsolute(TRUE);
  $proceed = Link::fromTextAndUrl($proceed_text, $proceed_url)->toString();

  return [
    '#title' => t('Start organizing!'),
    'info' => [
      '#prefix' => '<p>',
      '#suffix' => '</p>',
      '#markup' => t('Your site is ready to go! @proceed.', [
        '@wildcat' => 'Wildcat-flavored',
        '@proceed' => $proceed,
      ]),
    ],
  ];
}

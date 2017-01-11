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
  return [
    'wildcat_os_get_flavor' => [
      'display' => FALSE,
    ],
    'wildcat_os_install_required_modules' => [
      'display_name' => t('Adding flavor: required modules'),
      'display' => TRUE,
      'type' => 'batch',
    ],
    'wildcat_os_install_recommended_modules' => [
      'display_name' => t('Adding flavor: recommended modules'),
      'display' => TRUE,
      'type' => 'batch',
    ],
    'wildcat_os_install_themes' => [
      'display_name' => t('Adding flavor: themes'),
      'display' => TRUE,
    ],
    'wildcat_os_set_theme_settings' => [
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
  $tasks['install_finished']['function'] = 'wildcat_os_redirect';

  // Install flavor modules and themes immediately after profile is installed.
  $sorted_tasks = [];
  $module_key = 'wildcat_os_install_modules';
  $theme_key = 'wildcat_os_install_themes';
  foreach ($tasks as $key => $task) {
    if (!in_array($key, [$module_key, $theme_key])) {
      $sorted_tasks[$key] = $task;
    }
    if ($key === 'install_profile') {
      $sorted_tasks[$module_key] = $tasks[$module_key];
      $sorted_tasks[$theme_key] = $tasks[$theme_key];
    }
  }
  $tasks = $sorted_tasks;
}

/**
 * Install task callback.
 *
 * Collects the flavor information.
 *
 * @param array $install_state
 *   The current install state.
 */
function wildcat_os_get_flavor(array &$install_state) {
  /** @var \Drupal\wildcat_os\WildcatOsFlavorInterface $flavor */
  $flavor = \Drupal::service('wildcat_os.flavor');
  $install_state['wildcat_os_flavor'] = $flavor->get()['modules'];;
}

/**
 * Install task callback.
 *
 * Installs flavor modules via a batch process.
 *
 * @param array $install_state
 *   An array of information about the current installation state.
 *
 * @return array
 *   The batch definition.
 */
function wildcat_os_install_required_modules(array &$install_state) {
  $modules = $install_state['wildcat_os_flavor']['modules']['require'];

  return _wildcat_os_install_modules($modules, $install_state);
}

/**
 * Install task callback.
 *
 * Installs flavor modules via a batch process.
 *
 * @param array $install_state
 *   An array of information about the current installation state.
 *
 * @return array
 *   The batch definition.
 */
function wildcat_os_install_recommended_modules(array &$install_state) {
  $modules = $install_state['wildcat_os_flavor']['modules']['recommend'];

  return _wildcat_os_install_modules($modules, $install_state);
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
  $modules = array_filter($modules, function($module) use ($installed_modules) {
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
  $theme_admin = $install_state['wildcat_os_flavor']['theme_admin'];
  $theme_default = $install_state['wildcat_os_flavor']['theme_default'];
  if (!empty($theme_admin) || !empty($theme_default)) {
    $theme_config = \Drupal::configFactory()->getEditable('system.theme');

    if (!empty($theme_admin)) {
      $install_state['profile_info']['themes'][] = $theme_admin;
      $theme_config->set('admin', $theme_admin);
    }

    if (!empty($theme_default)) {
      $install_state['profile_info']['themes'][] = $theme_default;
      $theme_config->set('default', $theme_default);
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
 * @param array $install_state
 *   The current install state.
 *
 * @return array
 *   A renderable array with a success message and a redirect header, if the
 *   extender is configured with one.
 */
function wildcat_os_redirect(array &$install_state) {
  $redirect = $install_state['wildcat_os_flavor']['post_install_redirect'];
  $redirect['path'] = "internal:/{$redirect['path']}";
  $link_text = t('you can proceed to your site now');
  $link_url = Url::fromUri($redirect['path'], $redirect['options']);

  // Explicitly set the base URL, if not previously set, to prevent weird
  // redirection snafus.
  $base_url = $link_url->getOption('base_url');
  if (empty($base_url)) {
    $link_url->setOption('base_url', $GLOBALS['base_url']);
  }

  // The installer doesn't make it easy (possible?) to return a redirect
  // response, so set a redirection META tag in the output.
  $redirect_meta = [
    '#tag' => 'meta',
    '#attributes' => [
      'http-equiv' => 'refresh',
      'content' => "0;url={$link_url->toString()}",
    ],
  ];

  return [
    '#title' => t('Start organizing!'),
    'info' => [
      '#prefix' => '<p>',
      '#suffix' => '</p>',
      '#markup' => t('Your <em>@wildcat</em> site is ready to go!', [
        '@wildcat' => 'Wildcat-flavored',
      ]),
    ],
    'proceed_link' => [
      '#prefix' => '<p>',
      '#suffix' => '</p>',
      '#markup' => t('If you are not redirected in 5 seconds, @proceed_link.', [
        '@proceed_link' => Link::fromTextAndUrl($link_text, $link_url),
      ]),
      '#attached' => [
        'http_header' => [['Cache-Control', 'no-cache']],
        'html_head' => [[$redirect_meta, 'meta_redirect']],
      ],
    ],
  ];
}

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
    'wildcat_os_install_modules' => [
      'display_name' => t('Adding flavor: install modules'),
      'display' => TRUE,
      'type' => 'batch',
    ],
    'wildcat_os_install_themes' => [
      'display_name' => t('Adding flavor: install themes'),
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

  $already_installed = $install_state['profile_info']['dependencies'];
  $modules = $flavor->get()['modules'];
  $modules['require'] = array_diff($modules['require'], $already_installed);
  $modules['recommend'] = array_diff($modules['recommend'], $already_installed);
  $install_state['wildcat_modules'] = $modules;

  $themes[] = $flavor->get()['theme_admin'];
  $install_state['wildcat_theme_admin'] = $flavor->get()['theme_admin'];
  $themes[] = $flavor->get()['theme_default'];
  $install_state['wildcat_theme_default'] = $flavor->get()['theme_default'];
  $install_state['profile_info']['themes'] = array_unique($themes);

  $install_state['wildcat_redirect'] = $flavor->get()['post_install_redirect'];
}

/**
 * Install task callback.
 *
 * Installs flavor modules via a batch process.
 *
 * @param $install_state
 *   An array of information about the current installation state.
 *
 * @return
 *   The batch definition.
 */
function wildcat_os_install_modules(array &$install_state) {
  $files = system_rebuild_module_data();
  $modules = array_merge($install_state['wildcat_modules']['require'], $install_state['wildcat_modules']['recommend']);

  // Always install required modules first. Respect the dependencies between
  // the modules.
  $required = array();
  $non_required = array();

  // Ensure that flavor required modules are recognized as required.
  foreach ($install_state['wildcat_modules']['require'] as $module) {
    $files[$module]->info['required'] = TRUE;
  }

  // Add modules that other modules depend on.
  foreach ($modules as $module) {
    if ($files[$module]->requires) {
      $modules = array_merge($modules, array_keys($files[$module]->requires));
    }
  }
  $modules = array_unique($modules);
  foreach ($modules as $module) {
    if (!empty($files[$module]->info['required'])) {
      $required[$module] = $files[$module]->sort;
    }
    else {
      $non_required[$module] = $files[$module]->sort;
    }
  }
  arsort($required);
  arsort($non_required);

  $operations = array();
  foreach ($required + $non_required as $module => $weight) {
    $operations[] = array('_install_module_batch', array($module, $files[$module]->info['name']));
  }
  $batch = array(
    'operations' => $operations,
    'title' => t('Adding some flavor: installing modules.'),
    'error_message' => t('The installation has encountered an error.'),
  );
  return $batch;
}
/**
 * Install task callback.
 *
 * Sets theme settings, including default and admin theme.
 *
 * @param array $install_state
 *   The current install state.
 */
function wildcat_os_set_theme_settings(array &$install_state) {
  $config_factory = \Drupal::configFactory();
  $config_factory->getEditable('system.theme')
    ->set('admin', $install_state['wildcat_theme_admin'])
    ->set('default', $install_state['wildcat_theme_default'])
    ->save(TRUE);
  $config_factory->getEditable('node.settings')
    ->set('use_admin_theme', TRUE)
    ->save(TRUE);
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
  $link_text = t('you can proceed to your site now');
  $link_url = Url::fromUri($install_state['wildcat_redirect']);

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

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
    'wildcat_os_pick_flavor' => [
      // @todo Does this need a form? So users can disable optional modules now?
      'display' => FALSE,
    ],
  ];
}

/**
 * Implements hook_install_tasks_alter().
 */
function wildcat_os_install_tasks_alter(array &$tasks) {
  // First rearrange the order the install tasks, because the list of modules
  // and themes that should be installed is available when using our 'flavor
  // picking' task (in other words: install_profile_modules() should be called
  // AFTER wildcat_os_pick_flavor() is called).
  $flavor_picking_task = $tasks['wildcat_os_pick_flavor'];
  unset($tasks['wildcat_os_pick_flavor']);
  $original_tasks = $tasks;
  $new_tasks = [];
  foreach ($original_tasks as $task_name => $task_info) {
    if ($task_name === 'install_profile_modules') {
      $new_tasks['wildcat_os_pick_flavor'] = $flavor_picking_task;
    }
    $task[$task_name] = $task_info;
  }
  $tasks = $new_tasks;

  // Use a custom redirect callback, in case a custom redirect is specified.
  $tasks['install_finished']['function'] = 'wildcat_os_redirect';
}

/**
 * Install task callback.
 *
 * Prepares batch job to install the enabled Wildcat extensions.
 *
 * @param array $install_state
 *   The current install state.
 */
function wildcat_os_pick_flavor(array &$install_state) {
  /** @var \Drupal\wildcat_os\WildcatOsFlavorInterface $flavor */
  $flavor = \Drupal::service('wildcat_os.flavor');

  $modules = $install_state['profile_info']['dependencies'];
  $modules = array_merge($modules, $flavor->get()['modules']['require']);
  $modules = array_merge($modules, $flavor->get()['modules']['recommend']);
  $install_state['profile_info']['dependencies'] = $modules;
  // Remove 'system', before setting state, as in install_base_system().
  $modules = array_diff($modules, ['system']);
  \Drupal::state()->set('install_profile_modules', $modules);

  $themes[] = $flavor->get()['theme_admin'];
  $themes[] = $flavor->get()['theme_default'];
  $install_state['profile_info']['themes'] = array_unique($themes);

  $install_state['wildcat_redirect'] = $flavor->get()['post_install_redirect'];
}

/**
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
  // The installer doesn't make it easy (possible?) to return a redirect
  // response, so set a redirection META tag in the output.
  $redirect_meta = [
    '#tag' => 'meta',
    '#attributes' => [
      'http-equiv' => 'refresh',
      'content' => "0;url={$install_state['wildcat_redirect']}",
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

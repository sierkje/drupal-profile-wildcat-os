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
use Drupal\wildcat_os\WildcatOsFlavor;

/**
 * Implements hook_install_tasks().
 */
function wildcat_os_install_tasks() {
  return [
    'wildcat_os_pick_flavor' => [
      // @todo Does this need a form? So users can disable optional modules now?
      'display' => FALSE,
    ],
    'wildcat_os_install_themes' => [
      'function' => 'install_profile_themes',
    ]
  ];
}

/**
 * Implements hook_install_tasks_alter().
 */
function wildcat_os_install_tasks_alter(array &$tasks) {
  // We do not know the theme yet, so we do this later.
  $tasks['install_profile_themes']['run'] = INSTALL_TASK_SKIP;
  $tasks['install_profile_themes']['display'] = FALSE;

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
  // $flavor = \Drupal::service('wildcat_os.flavor');
  $app_root = \Drupal::service('app.root');
  $site_path = \Drupal::service('site.path');
  $state = \Drupal::service('state');
  $flavor = new WildcatOsFlavor($app_root, $site_path, $state);

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
  $link_url = Url::fromUserInput($install_state['wildcat_redirect']);
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

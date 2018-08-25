<?php
/**
 * @file
 * Contains \Drupal\footermap\Plugin\Block\FootermapBlock.
 */

namespace Drupal\footermap\Plugin\Block;

use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Menu\InaccessibleMenuLink;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provide a footer-based site map block based on menu items.
 *
 * @Block(
 *   id = "footermap_block",
 *   admin_label = @Translation("Footermap"),
 *   category = @Translation("Sitemap"),
 *   module = "footermap"
 * )
 */
class FootermapBlock extends BlockBase implements ContainerFactoryPluginInterface, FootermapInterface {

  /**
   * @var array
   */
  protected $mapref;

  /**
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuTree;

  /**
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected $menuLinkManager;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Construct with dependencies injected.
   *
   * @param $configuration
   *   The configuration array.
   * @param $plugin_id
   *   The block plugin id.
   * @param $plugin_definition
   *   The block plugin definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The Entity Manager service
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_tree
   *   The MenuLinkTree service.
   * @param \Drupal\Core\Menu\MenuLinkManagerInterface $menu_link_manager
   *   The MenuLinkManager service
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The Logger Channel Factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, MenuLinkTreeInterface $menu_tree, MenuLinkManagerInterface $menu_link_manager, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityManager = $entity_manager;
    $this->menuTree = $menu_tree;
    $this->menuLinkManager = $menu_link_manager;
    $this->logger = $logger_factory->get('footermap');
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account, $return_as_object = FALSE) {
    if ($return_as_object) {
      return AccessResultNeutral::allowed();
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.manager'),
      $container->get('menu.link_tree'),
      $container->get('plugin.manager.menu.link'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $settings = parent::defaultConfiguration();
    $settings['footermap_recurse_limit'] = 0;
    $settings['footermap_display_heading'] = 1;
    $settings['footermap_avail_menus'] = array();
    $settings['footermap_top_menu'] = '';
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return array('languages');
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['footermap_recurse_limit'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Recurse Limit'),
      '#description' => $this->t('Limit the depth of menu items to display. The default is 0, unlimited. This is useful if you have a deep hierarchy of child menu items that you do not want to display in the footermap.'),
      '#size' => 3,
      '#max_length' => 3,
      '#element_validate' => array('form_validate_number'),
      '#min' => 0,
      '#default_value' => $this->configuration['footermap_recurse_limit'],
    );

    $form['footermap_display_heading'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Enable Menu Heading'),
      '#description' => $this->t('This will enable the menu-name label (e.g. Navigation, Footer) to be displayed as the heading above each menu column. This is nice if you have your menus setup in distinct blocks or controlled via the recurse-limit property above.'),
      '#options' => array($this->t('No'), $this->t('Yes')),
      '#default_value' => $this->configuration['footermap_display_heading'],
    );

    $form['footermap_top_menu'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Top-level Menu'),
      '#description' => $this->t('Set the plugin ID to use for the top-level menu. This may be useful if you want a footer map of menu links deep within a menu instead of pulling from each menu. The default is to use from avail-menus below.'),
      '#default_value' => $this->configuration['footermap_top_menu'],
    );

    $form['footermap_avail_menus'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Available menus'),
      '#description' => $this->t('Select which top-level menus to include in this footer site map.'),
      '#options' => $this->getMenus(),
      '#default_value' => $this->configuration['footermap_avail_menus'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   * Implementation of \Drupal\block\BlockBase::blockSubmit().
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration = $form_state->getValues();
  }

  /**
   * {@inheritdoc}
   * Implementation of \Drupal\block\BlockInterface::build().
   */
  public function build() {
    $build = array(
      '#theme' => 'footermap',
      '#title' => $this->configuration['label_display'] ? $this->configuration['label'] : '',
      '#block' => $this,
      '#attributes' => array(
        'class' => array('footermap', 'footermap--' . $this->getPluginId()),
      ),
      '#attached' => array(
        'library' => array(
          'footermap/footermap',
        ),
      ),
    );

    try {
      $build['#footermap'] = $this->buildMap();
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildMap() {
    $this->mapref = array();

    // Assemble all of the configuration necessary to build footer map.
    $col_index = 1;
    $depth = $this->configuration['footermap_recurse_limit'] == 0 ? null : $this->configuration['footermap_recurse_limit'];
    $top_menu_plugin_id = $this->configuration['footermap_top_menu'] == '' ? false : $this->configuration['footermap_top_menu'];
    $menus = $this->getMenus($top_menu_plugin_id);
    $parameters = new MenuTreeParameters();

    // Set the maximum depth if not unlimited.
    if ($this->configuration['footermap_recurse_limit']) {
      $parameters->setMaxDepth($depth);
    }
    $parameters->onlyEnabledLinks();
    $parameters->excludeRoot();
    // Set root if top menu plugin id set.
    if ($top_menu_plugin_id && !empty($menus)) {
      $parameters->setRoot($top_menu_plugin_id);
    }


    // Menu link manipulator using anonymous session.
    $manipulators = array(
      array(
        'callable' => 'footermap.anonymous_tree_manipulator:checkAccess',
      ),
    );

    foreach ($menus as $menu_name => $menu) {
      // Loop through every menu.
      if (isset($this->configuration['footermap_avail_menus'][$menu_name]) && $this->configuration['footermap_avail_menus'][$menu_name] === $menu_name) {
        // Only build site map for available menus.
        $menu_name_class = str_replace('_', '-', $menu_name);
        $tree = $this->menuTree->load($menu_name, $parameters);
        $tree = $this->menuTree->transform($tree, $manipulators);

        if (!empty($tree)) {
          $this->mapref[$menu_name] = array(
            '#theme' => 'footermap_header',
            '#title' => $menu, // check_plain() during render.
            '#title_display' => $this->configuration['footermap_display_heading'] ? 'visible' : 'hidden',
            '#menu_name' => $menu_name,
            '#attributes' => array(
              'class' => array('footermap-header', 'footermap-header--' . $menu_name_class),
            ),
          );

          $this->buildMenu($tree, $this->mapref[$menu_name]);
        }

        $col_index++;
      }
    }

    return $this->mapref;
  }

  /**
   * {@inheritdoc}
   */
  public function buildMenu(&$tree, &$mapref) {

    foreach ($tree as $key => $item) {
      /** @var \Drupal\Core\Menu\MenuLinkInterface $link */
      $link = $item->link;
      $link_title = $link->getTitle();

      if ($link->isEnabled() && !empty($link_title) && !($link instanceof InaccessibleMenuLink)) {
        // Mapref reference becomes child.
        if (isset($mapref['#theme']) && $mapref['#theme'] == 'footermap_header') {
          $child = &$mapref['#items'];
        }
        else {
          $child = &$mapref;
        }

        // Get the menu link entity language, but necessary to load menu link
        // content entity which is kind of expensive.
        if (strpos($link->getPluginId(), 'menu_link_content') === 0) {
          list(, $uuid) = explode(':', $link->getPluginId(), 2);
          $entity = $this->entityManager->loadEntityByUuid('menu_link_content', $uuid);
        }

        $child['menu-' . $key] = array(
          '#theme' => 'footermap_item',
          '#title' => $link->getTitle(),
          '#url' => $link->getUrlObject(),
          '#attributes' => array(
            'class' => array('footermap-item', 'footermap-item--depth-' . $item->depth),
          ),
          '#level' => $item->depth,
          '#weight' => $link->getWeight(),
        );
      }

      if ($item->hasChildren) {
        $child['menu-' . $key]['#attributes']['class'][] = 'footermap-item--haschildren';
        $this->buildMenu($item->subtree, $child['menu-' . $key]['#children']);
      }
    }
  }

  /**
   * Get the menus from config storage.
   *
   * @param $plugin_id
   *   (Optional) A plugin id for a menu link to use as the top of the menu
   *   tree hierarchy.
   *
   * @return array
   *   An associative array of menus keyed by menu id (string) and menu label.
   */
  protected function getMenus($plugin_id = NULL) {
    $options = array();

    // Fetch the menu link by plugin id instead and return that as the menu,
    // but use the menu name as the key and the menu link title as the value.
    if (isset($plugin_id) && $plugin_id) {
      $item = $this->menuLinkManager->getDefinition($plugin_id, FALSE);

      if (!$item) {
        return $options;
      }

      return array($item['menu_name'] => $item['title']);
    }

    $controller = $this->entityManager->getStorage('menu');
    if ($menus = $controller->loadMultiple()) {
      foreach ($menus as $menu_name => $menu) {
        $options[$menu_name] = $menu->label();
      }
      asort($options);
    }

    return $options;
  }
}

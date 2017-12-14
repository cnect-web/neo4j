<?php

namespace Drupal\neo4j_entity\Plugin\Block;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\Block\Annotation\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\neo4j_entity\Helper;
use GraphAware\Neo4j\Client\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @Block(
 *   id = "neo4j_entity_recommendation",
 *   admin_label = @Translation("Entity recommendation block"),
 * )
 */
class Recommendation extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * @var Client
   */
  protected $client;

  /**
   * @var EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var EntityTypeBundleInfoInterface
   */
  protected $entityBundleManager;

  /**
   * @var Request
   */
  protected $request;

  public function __construct(
      array $configuration,
      $plugin_id,
      $plugin_definition,
      Client $client,
      EntityTypeManagerInterface $entityManager,
      EntityTypeBundleInfoInterface $bundleManager,
      Request $request) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
    $this->entityTypeManager = $entityManager;
    $this->entityBundleManager = $bundleManager;
    $this->request = $request;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var Client $client */
    $client = $container->get('neo4j.client');

    /** @var EntityTypeManagerInterface $entityManager */
    $entityManager = $container->get('entity_type.manager');

    /** @var EntityTypeBundleInfoInterface $bundleManager */
    $bundleManager = $container->get('entity_type.bundle.info');

    /** @var RequestStack $requestStack */
    $requestStack = $container->get('request_stack');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $client,
      $entityManager,
      $bundleManager,
      $requestStack->getCurrentRequest()
    );
  }

  public function build() {
    $config = $this->getConfiguration();

    $list = [];

    $route = $this->request->attributes->get('_route');
    $entityType = Helper::entityTypeFromRoute($route);

    if ($entityType) {
      /** @var EntityInterface $entity */
      $entity = $this->request->attributes->get($entityType);
      $key = "{$entityType}::{$entity->bundle()}";
      if (!empty($config['source_types'][$key])) {
        $targets = array_keys(array_filter($config['target_types']));
        $list = $this->listRelatedContent($entity, $targets);
      }
    }

    $links = [];
    foreach ($list as $e) {
      $links[] = $e->toLink()->toRenderable();
    }

    return [
      'links' => [
        '#theme' => 'item_list',
        '#items' => $links,
      ],
      '#cache' => [
        //'contexts' => ['user', 'url'],
      ],
    ];
  }

  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    $types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $def) {
      $id = $def->id();
      $label = $def->getLabel();
      foreach ($this->entityBundleManager->getBundleInfo($id) as $bundlename => $bundleinfo) {
        $types["{$id}::{$bundlename}"] = $this->t('%entity / %bundle', [
          '%entity' => $label,
          '%bundle' => $bundleinfo['label'],
        ]);
      }
    }

    ksort($types, SORT_STRING);

    $form['source_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Display this block on the following entity pages'),
      '#options' => $types,
      '#default_value' => isset($config['source_types']) ? $config['source_types'] : [],
    ];

    $form['target_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Use the following entity types'),
      '#options' => $types,
      '#default_value' => isset($config['source_types']) ? $config['target_types'] : [],
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    foreach (['source_types', 'target_types'] as $conf) {
      $this->setConfigurationValue($conf, $form_state->getValue($conf));
    }
  }

  /**
   * @param EntityInterface $entity
   * @param array $targets
   * @return EntityInterface[]
   */
  protected function listRelatedContent(EntityInterface $entity, array $targets) {
    $res = $this->client->run("
      MATCH (p:Page)<-[:OF]-(:Visit)-[:SEEN]->(pe:Entity { entity_type: {entity_type}, entity_id: {entity_id} })
      MATCH (p)<-[:OF]-(:Visit)-[prev:PREV*..10]-(:Visit)-[:SEEN]->(e:Entity)
      WHERE ANY (t IN {targets} WHERE t = e.entity_type+'::'+e.bundle) AND NOT e = pe
      RETURN DISTINCT e.entity_type AS entity_type, e.entity_id AS entity_id, avg(size(prev)) AS distance
      ORDER BY distance ASC
      LIMIT 10
    ", [
      'entity_type' => $entity->getEntityType()->id(),
      'entity_id' => $entity->id(),
      'targets' => $targets,
    ]);

    $entities = [];
    $order = [];

    foreach ($res->records() as $record) {
      $entity_type = $record->get('entity_type');
      $entity_id = $record->get('entity_id');

      $entities[$entity_type][] = $entity_id;
      $order[] = [$entity_type, $entity_id];
    }

    $loadedEntities = [];

    foreach ($entities as $type => $ids) {
      $loadedEntities[$type] = $this->entityTypeManager->getStorage($type)->loadMultiple($ids);
    }

    $list = [];

    foreach ($order as $item) {
      if (($entity = $loadedEntities[$item[0]][$item[1]])) {
        $list[] = $entity;
      }
    }

    return $list;
  }

}

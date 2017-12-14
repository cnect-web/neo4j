<?php

namespace Drupal\neo4j_entity;

use Drupal\Core\Entity\EntityInterface;
use GraphAware\Bolt\Exception\IOException;
use GraphAware\Neo4j\Client\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class PageLogger implements EventSubscriberInterface {

  /**
   * @var Client
   */
  protected $client;

  /**
   * @var LoggerInterface
   */
  protected $log;

  public function __construct(Client $client, LoggerInterface $loggerFactory) {
    $this->client = $client;
    $this->log = $loggerFactory;
  }

  public function onRequest(KernelEvent $event) {
    $route = $event->getRequest()->attributes->get('_route');
    if ($route) {
      if (($entityName = Helper::entityTypeFromRoute($route))) {
        $entity = $event->getRequest()->attributes->get($entityName);
        $id = $event->getRequest()->attributes->get('_neo4j_page');
        if ($id) {
          try {
            $this->logEntity($entity, $id);
          } catch (IOException $ex) {
            $this->log->error($ex->getMessage());
          }
        }
      }
    }
  }

  protected function logEntity(EntityInterface $entity, $id) {
    $entity_type = (string) $entity->getEntityType()->id();
    $bundle = $entity->bundle();
    $entity_id = $entity->id();

    $this->client->run("
      MERGE (e:Entity { entity_type: {entity_type}, bundle: {bundle}, entity_id: {entity_id} })
      WITH e
      MATCH (v:Visit) WHERE id(v) = {page}
      CREATE (v)-[:SEEN]->(e)
    ", [
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'entity_id' => $entity_id,
      'page' => $id,
    ]);
  }

  public static function getSubscribedEvents() {
    $events = [];

    $events[KernelEvents::REQUEST][] = ['onRequest', -100];

    return $events;
  }
}

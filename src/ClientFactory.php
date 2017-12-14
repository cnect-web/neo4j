<?php

namespace Drupal\neo4j;

use Drupal\Core\Config\ConfigFactory;
use GraphAware\Neo4j\Client\ClientBuilder;

class ClientFactory {

  /**
   * @var ConfigFactory
   */
  protected $conf;

  public function __construct(ConfigFactory $conf) {
    $this->conf = $conf;
  }

  /**
   * @return \GraphAware\Neo4j\Client\Client|null
   */
  public function create() {
    $config = ['client_class' => Neo4jBuilderClient::class];
    $builder = ClientBuilder::create($config);

    $connection = $this->conf->get("neo4j.connection")->get('connection');

    if ($connection) {
      $builder->addConnection("default", $connection);

      return $builder->build();
    }

    return NULL;
  }
}

<?php

namespace Drupal\neo4j_page;

use Drupal\Core\Session\AccountProxyInterface;
use GraphAware\Bolt\Exception\IOException;
use GraphAware\Neo4j\Client\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class Logger implements EventSubscriberInterface {

  /**
   * @var Client
   */
  protected $client;

  /**
   * @var AccountProxyInterface
   */
  protected $currentUser;

  /**
   * @var LoggerInterface
   */
  protected $log;

  public function __construct(Client $client, AccountProxyInterface $currentUser, LoggerInterface $loggerFactory) {
    $this->client = $client;
    $this->currentUser = $currentUser;
    $this->log = $loggerFactory;
  }

  public function onRequest(KernelEvent $event) {
    if ($event->getRequest()->request->get('_drupal_ajax') || $event->getRequest()->headers->get('X-Requested-With') === 'XMLHttpRequest') {
      return;
    }
    $method = $event->getRequest()->getMethod();
    $requestUri = $event->getRequest()->getRequestUri();
    $ip = $event->getRequest()->getClientIp();
    $referer = $this->getReferer($event->getRequest());

    try {
      $id = $this->logPageVisit($method, $requestUri, $ip, $referer);

      $event->getRequest()->attributes->set('_neo4j_page', $id);
    } catch (IOException $ex) {
      $this->log->error($ex->getMessage());
    }
  }

  protected function logPageVisit($method, $requestUri, $ip, $referer) {
    $uid = $this->currentUser->id();
    $roles = $this->currentUser->getRoles();
    $previous = $this->previousVisit($uid, $ip, $referer);

    $connect = $previous ? "
      WITH v
      MATCH (pv:Visit) WHERE id(pv) = {previous}
      CREATE (v)-[:PREV]->(pv)
    " : "";
    $res = $this->client->run("
      MERGE (u:User { uid: {uid}, roles: {roles} })
      MERGE (p:Page { requestUri: {requestUri}, method: {method} })
      CREATE (u)-[:VISIT]->(v:Visit { ip: {ip}, requestTime: {requestTime} })-[:OF]->(p)
      {$connect}
      WITH v
      RETURN id(v) AS id
    ", [
      'requestUri' => $requestUri,
      'method' => $method,
      'uid' => $uid,
      'ip' => $ip,
      'roles' => $roles,
      'requestTime' => REQUEST_TIME,
      'previous' => $previous,
    ]);

    return $res->firstRecord()->get('id');
  }

  protected function previousVisit($uid, $ip, $referer) {
    if (!$referer) {
      return NULL;
    }

    $res = $this->client->run("
      MATCH (u:User { uid: {uid} })-[:VISIT]->(v:Visit { ip: {ip} })-[:OF]->(p:Page { requestUri: {uri} })
      RETURN id(v) AS id
      ORDER BY v.request_time DESC
      LIMIT 1
    ", [
      'uid' => $uid,
      'ip' => $ip,
      'uri' => $referer,
    ]);

    if (($rec = $res->firstRecord())) {
      return $rec->get('id');
    }

    return NULL;
  }

  protected function getReferer(Request $request) {
    global $base_url;

    $referer = $request->headers->get("Referer");
    if ($referer && stripos($referer, $base_url) === 0) {
      return substr($referer, strlen($base_url));
    }

    return NULL;
  }

  public static function getSubscribedEvents() {
    $events = [];

    $events[KernelEvents::REQUEST][] = ['onRequest', -50];

    return $events;
  }
}

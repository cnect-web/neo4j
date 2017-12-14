<?php

namespace Drupal\neo4j_entity;

class Helper {

  /**
   * @param string $route
   * @return string|null
   */
  public static function entityTypeFromRoute($route) {
    $matches = [];

    if (preg_match('/^entity\.([0-9a-z_]+)\.canonical$/i', $route, $matches) && count($matches) >= 2) {
      return $matches[1];
    }

    return NULL;
  }

}

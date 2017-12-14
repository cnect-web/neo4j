<?php

namespace Drupal\neo4j\Controller;

use Drupal\Core\Controller\ControllerBase;

class Settings extends ControllerBase {

  public function adminSettings() {
    return $this->formBuilder()->getForm('Drupal\neo4j\Form\AdminConfig');
  }

}

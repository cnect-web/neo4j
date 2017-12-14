<?php

namespace Drupal\neo4j\Form;

use Behat\Mink\Exception\Exception;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GraphAware\Neo4j\Client\Client;
use GraphAware\Neo4j\Client\ClientBuilder;

class AdminConfig extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['neo4j.connection'];
  }

  public function getFormId() {
    return 'neo4j_admin_config_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $connection = $this->config('neo4j.connection')->get('connection');
    $parts = parse_url($connection);

    $form['protocol'] = [
      '#title' => $this->t('Protocol'),
      '#type' => 'radios',
      '#default_value' => !empty($parts['scheme']) ? $parts['scheme'] : 'bolt',
      '#options' => [
        'http' => 'http',
        'bolt' => 'bolt',
      ],
      '#required' => TRUE,
    ];

    $form['user'] = [
      '#title' => $this->t('Username'),
      '#type' => 'textfield',
      '#default_value' => isset($parts['user']) ? $parts['user'] : '',
      '#required' => TRUE,
    ];

    $form['pass'] = [
      '#title' => $this->t('Password'),
      '#type' => 'password',
      '#default_value' => isset($parts['pass']) ? $parts['pass'] : '',
      '#required' => TRUE,
    ];

    $form['host'] = [
      '#title' => $this->t('Host'),
      '#type' => 'textfield',
      '#default_value' => isset($parts['host']) ? $parts['host'] : '',
      '#required' => TRUE,
    ];

    $form['port'] = [
      '#title' => $this->t('Port'),
      '#type' => 'textfield',
      '#default_value' => isset($parts['port']) ? $parts['port'] : '',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    static $defaultPorts = [
      'http' => 7474,
      'bolt' => 7687,
    ];
    $protocol = $form_state->getValue('protocol');
    $user = $form_state->getValue('user');
    $pass = $form_state->getValue('pass');
    $host = $form_state->getValue('host');
    $port = $form_state->getValue('port');

    if (!$port || !is_numeric($port)) {
      $port = isset($defaultPorts[$protocol]) ? $defaultPorts[$protocol] : '';
    }

    $port = ":{$port}";

    $connection = "{$protocol}://{$user}:{$pass}@{$host}{$port}";

    $result = NULL;
    $errorMessage = NULL;
    try {
      $client = ClientBuilder::create()
        ->addConnection('default', $connection)
        ->build();

      $result = $client->run('MATCH (n) RETURN n LIMIT 0');
    } catch (Exception $e) {
      $errorMessage = $e->getMessage();
    }
    if (!$errorMessage) {
      $errorMessage = $this->t("Failed to connect");
    }

    if ($result) {
      $form_state->set('connection', $connection);
    }
    else {
      $form_state->setErrorByName('', $errorMessage);
    }

    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $connection = $form_state->get('connection');

    $this->config('neo4j.connection')->set('connection', $connection)->save();

    parent::submitForm($form, $form_state);
  }
}

<?php
namespace Drupal\event_database_push\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Settings for Event Database push module.
 */
class SettingsForm extends ConfigFormBase {
  /**
   * Drupal\Core\Entity\EntityManager definition.
   *
   * @var \Drupal\Core\Entity\EntityManager
   */
  protected $entityManager;

  /**
   * Drupal\Core\Entity\EntityFieldManager definition.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  public function __construct(ConfigFactoryInterface $config_factory, EntityManager $entity_manager, EntityFieldManager $entity_field_manager) {
    parent::__construct($config_factory);
    $this->entityManager = $entity_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'event_database_push_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('event_database_push.settings');

    $form['api'] = [
      '#type' => 'fieldset',
      '#title' => t('API'),
      '#tree' => TRUE,

          'url' => [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => $this->t('Url'),
        '#default_value' => $config->get('api.url'),
        '#description' => $this->t('The Event database API url'),
      ],
      'username' => [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => $this->t('Username'),
        '#default_value' => $config->get('api.username'),
        '#description' => $this->t('The Event database API username'),
      ],
      'password' => [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => $this->t('Password'),
        '#default_value' => $config->get('api.password'),
        '#description' => $this->t('The Event database API password'),
      ],
    ];

    $form['mapping'] = [
      '#type' => 'fieldset',
      '#title' => t('Mapping'),
      '#tree' => TRUE,

      'content_types' => [
        '#type' => 'textarea',
        '#rows' => 30,
        '#required' => TRUE,
        '#title' => $this->t('Content types'),
        '#default_value' => $config->get('mapping.content_types'),
        '#description' => $this->t('YAML'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    try {
      $value = $form_state->getValue(['mapping', 'content_types']);
      Yaml::parse($value);
    } catch (ParseException $ex) {
      $form_state->setError($form['mapping']['content_types'], $this->t('Content types must be valid YAML (@message)', ['@message' => $ex->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('event_database_push.settings');

    $config->set('api.url', $form_state->getValue(['api', 'url']));
    $config->set('api.username', $form_state->getValue(['api', 'username']));
    $config->set('api.password', $form_state->getValue(['api', 'password']));

    $config->set('mapping.content_types', $form_state->getValue(['mapping', 'content_types']));

    $config->save();

    return parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'event_database_push.settings',
    ];
  }
}

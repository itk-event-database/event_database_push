<?php

namespace Drupal\event_database_push\Service;

// phpcs:ignore
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\File\FileUrlGenerator;
use Itk\EventDatabaseClient\ObjectTransformer\ValueHandler as BaseValueHandler;

/**
 * Value handler class.
 */
class ValueHandler extends BaseValueHandler {

  /**
   * {@inheritdoc}
   */
  public function __construct(protected FileUrlGenerator $fileUrlGenerator) {
  }

  /**
   * Get a value.
   *
   * @param ?NodeInterface $item
   *   The node to get a value for.
   * @param string $path
   *   The path to get value by.
   *
   * @return array|bool|mixed|string|null
   *   The value.
   *
   * @throws \Exception
   */
  public function getValue($item, $path) {
    if ($item instanceof NodeInterface) {
      return $this->getValueByPath($item, $path);
    }
    return NULL;
  }

  /**
   * Get a node value by path.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A node.
   * @param string $path
   *   The path.
   * @param bool $failOnError
   *   Whether to fail.
   *
   * @return mixed
   *   Serialized value.
   *
   * @throws \Exception
   *
   * @todo There must be a built in way to do this!
   */
  private function getValueByPath(NodeInterface $node, $path, bool $failOnError = FALSE) {
    $steps = preg_split('/\.|(\[\d+\])/', $path, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

    $value = $node;
    foreach ($steps as $step) {
      try {
        if (preg_match('/\[(\d+)\]/', $step, $matches)) {
          $item = $value->get($matches[1]);
          $value = $item ? $item->get('entity')->getValue() : NULL;
        }
        else {
          $value = $value->get($step);
        }
      }
      catch (\Exception $exception) {
        $value = NULL;
      }
      if ($value === NULL) {
        if ($failOnError) {
          throw new \Exception('Invalid path: ' . $path);
        }
        break;
      }
    }

    return $this->getSerializedValueByType($value);
  }

  /**
   * Get serialized field value.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<FieldItemInterface> $field
   *   The field.
   *
   * @return mixed
   *   The serialized value.
   */
  private function getSerializedValueByType(FieldItemListInterface $field): mixed {
    $value = NULL;

    $fieldDefinition = $field->getFieldDefinition();

    switch ($fieldDefinition->getType()) {
      case 'image':
        $file = File::load($field->target_id);
        if ($file) {
          $uri = $file->getFileUri();
          $value = empty($uri) ? NULL : $this->fileUrlGenerator->generateAbsoluteString($uri);
        }
        break;

      case 'link':
        $value = $field->getValue()[0]['uri'];
        break;

      case 'entity_reference':
        $id_array = $field->getValue();
        $target_type = $fieldDefinition->getItemDefinition()->getSetting('target_type');
        $ids = [];
        foreach ($id_array as $array) {
          foreach (array_values($array) as $id) {
            $ids[] = $id;
          };
        }

        if ('node' == $target_type) {
          $entities = Node::loadMultiple($ids);

          if (1 < count($entities)) {
            $value = [];
            foreach ($entities as $entity) {
              $value[] = $entity->getTitle();
            }
          }
          elseif (1 == count($entities)) {
            $entity = array_shift($entities);
            $value = $entity->getTitle();
          }
          else {
            $value = NULL;
          }

        }
        elseif ('taxonomy_term' == $target_type) {
          $entities = Term::loadMultiple($ids);

          foreach ($entities as $entity) {
            $value[] = $entity->getName();
          }

        }

        break;

      case 'daterange':
        $valuesArray = $field->getValue();
        foreach ($valuesArray as $v) {
          $value[] = [
            'startDate' => $v['value'],
            'endDate' => $v['end_value'],
          ];
        }
        break;

      case 'boolean':
        $valuesArray = $field->getValue();

        if (1 == count($valuesArray)) {
          $value = $valuesArray[0]['value'] ? TRUE : FALSE;
        }
        elseif (1 < count($valuesArray)) {
          $value = [];
          foreach ($valuesArray as $v) {
            $value[] = $v['value'] ? TRUE : FALSE;
          }
        }
        break;

      case 'string':
        $fieldName = $field->getName();
        switch ($fieldName) {
          case 'field_partner_organizers':
            $fieldValues = $field->getValue();
            foreach ($fieldValues as $v) {
              $value[] = ['name' => $v['value']];
            }
            break;

          case 'field_organiser':
            $value = ['name' => $field->getValue()[0]['value']];
            break;

          default:
            $valuesArray = $field->getValue();

            if (1 == count($valuesArray)) {
              $value = $valuesArray[0]['value'];
            }
            elseif (1 < count($valuesArray)) {
              $value = [];
              foreach ($valuesArray as $v) {
                $value[] = $v['value'];
              }
            }
            break;
        }
        break;

      default:
        $valuesArray = $field->getValue();

        if (1 == count($valuesArray)) {
          $value = $valuesArray[0]['value'];
        }
        elseif (1 < count($valuesArray)) {
          $value = [];
          foreach ($valuesArray as $v) {
            $value[] = $v['value'];
          }
        }
    }

    return $value;
  }

  /**
   * Make a URL absolute.
   *
   * @param string $value
   *   A uri.
   *
   * @return string
   *   An absolute url.
   */
  public function makeUrlAbsolute($value): string {
    return $this->fileUrlGenerator->generateAbsoluteString($value);
  }

}

<?php

namespace Drupal\event_database_push\Service;

use Drupal\node\NodeInterface;
use Itk\EventDatabaseClient\ObjectTransformer\ValueHandler as BaseValueHandler;

class ValueHandler extends BaseValueHandler {
  public function getValue($item, $path) {
    if ($item instanceof NodeInterface) {
      return $this->getValueByPath($item, $path);
    }
    return null;
  }

  /**
   * Get a node value by path.
   *
   * @TODO: There must be a built in way to do this!
   *
   * @param \Drupal\node\NodeInterface $node
   * @param $path
   * @return mixed
   */
  private function getValueByPath(NodeInterface $node, $path, $failOnError = false) {
    $steps = preg_split('/\.|(\[\d+\])/', $path, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

    $value = $node;
    foreach ($steps as $step) {
      try {
        if (preg_match('/\[(\d+)\]/', $step, $matches)) {
          $item = $value->get($matches[1]);
          $value = $item ? $item->get('entity')->getValue() : null;
        }
        else {
          $value = $value->get($step);
        }
      } catch (\Exception $exception) {
        $value = NULL;
      }
      if ($value === null) {
        if ($failOnError) {
          throw new \Exception('Invalid path: ' . $path);
        }
        break;
      }
    }

    return $value ? $value->value : null;
  }

  public function makeUrlAbsolute($value) {
    return file_create_url($value);
  }
}

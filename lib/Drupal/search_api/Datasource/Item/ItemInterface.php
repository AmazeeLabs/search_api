<?php
/**
 * @file
 * Contains \Drupal\search_api\Datasource\Item\ItemInterface.
 */

namespace Drupal\search_api\Datasource\Item;

use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\TranslatableInterface;

/**
 * Interface which describes a datasource item.
 */
interface ItemInterface extends ComplexDataInterface, TranslatableInterface { }

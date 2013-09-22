<?php

namespace Drupal\search_api\Plugin\SearchApi\Service;

use Drupal\Core\Annotation\Translation;
use Drupal\search_api\Annotation\Service;
use Drupal\search_api\Service\ServicePluginBase;

/**
 * @Service(
 *   id = "search_api_test_service",
 *   label = @Translation("Test service"),
 *   description = @Translation("Dummy service implementation")
 * )
 */
class TestService extends ServicePluginBase {
  public function buildConfigurationForm(array $form, array &$form_state) {

  }
  public function defaultConfiguration() {

  }
  public function getConfiguration() {

  }
  public function setConfiguration(array $configuration) {

  }
  public function submitConfigurationForm(array &$form, array &$form_state) {

  }
  public function validateConfigurationForm(array &$form, array &$form_state) {

  }
  public function addIndex(\Drupal\search_api\Index\IndexInterface $index) {

  }
  public function deleteAllItems(\Drupal\search_api\Index\IndexInterface $index) {

  }
  public function deleteItems(\Drupal\search_api\Index\IndexInterface $index, array $ids) {

  }
  public function hasIndex(\Drupal\search_api\Index\IndexInterface $index) {

  }
  public function indexItems(\Drupal\search_api\Index\IndexInterface $index, array $items) {

  }
  public function postInstanceConfigurationCreate() {

  }
  public function postInstanceConfigurationDelete() {

  }
  public function postInstanceConfigurationUpdate() {

  }
  public function preInstanceConfigurationCreate() {

  }
  public function preInstanceConfigurationDelete() {

  }
  public function preInstanceConfigurationUpdate() {

  }
  public function removeIndex(\Drupal\search_api\Index\IndexInterface $index) {

  }
  public function supportsFeature($feature) {

  }
  public function updateIndex(\Drupal\search_api\Index\IndexInterface $index) {

  }
}

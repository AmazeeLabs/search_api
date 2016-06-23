<?php

namespace Drupal\search_api\Query;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility;

/**
 * Provides a standard implementation for a Search API query.
 */
class Query implements QueryInterface {

  use StringTranslationTrait;

  /**
   * The index on which the query will be executed.
   *
   * @var \Drupal\search_api\IndexInterface
   */
  protected $index;

  /**
   * The index's ID.
   *
   * Used when serializing, to avoid serializing the index, too.
   *
   * @var string|null
   */
  protected $indexId;

  /**
   * The search results.
   *
   * @var \Drupal\search_api\Query\ResultSetInterface
   */
  protected $results;

  /**
   * The result cache service.
   *
   * @var \Drupal\search_api\Query\ResultsCacheInterface
   */
  protected $resultsCache;

  /**
   * The parse mode to use for fulltext search keys.
   *
   * @var string
   *
   * @see \Drupal\search_api\Query\QueryInterface::parseModes()
   */
  protected $parseMode = 'terms';

  /**
   * The language codes which should be searched by this query.
   *
   * @var string[]|null
   */
  protected $languages;

  /**
   * The search keys.
   *
   * If NULL, this will be a filter-only search.
   *
   * @var mixed
   */
  protected $keys;

  /**
   * The unprocessed search keys, as passed to the keys() method.
   *
   * @var mixed
   */
  protected $origKeys;

  /**
   * The fulltext fields that will be searched for the keys.
   *
   * @var array
   */
  protected $fields;

  /**
   * The root condition group associated with this query.
   *
   * @var \Drupal\search_api\Query\ConditionGroupInterface
   */
  protected $conditionGroup;

  /**
   * The sorts associated with this query.
   *
   * @var array
   */
  protected $sorts = array();

  /**
   * Information about whether the query has been aborted or not.
   *
   * @var \Drupal\Component\Render\MarkupInterface|string|true|null
   */
  protected $aborted;

  /**
   * Options configuring this query.
   *
   * @var array
   */
  protected $options;

  /**
   * The tags set on this query.
   *
   * @var string[]
   */
  protected $tags = array();

  /**
   * Flag for whether preExecute() was already called for this query.
   *
   * @var bool
   */
  protected $preExecuteRan = FALSE;

  /**
   * Flag for whether execute() was already called for this query.
   *
   * @var bool
   */
  protected $executed = FALSE;

  /**
   * Constructs a Query object.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index the query should be executed on.
   * @param \Drupal\search_api\Query\ResultsCacheInterface $results_cache
   *   The results cache that should be used for this query.
   * @param array $options
   *   (optional) Associative array of options configuring this query. See
   *   \Drupal\search_api\Query\QueryInterface::setOption() for a list of
   *   options that are recognized by default.
   *
   * @throws \Drupal\search_api\SearchApiException
   *   Thrown if a search on that index (or with those options) won't be
   *   possible.
   */
  public function __construct(IndexInterface $index, ResultsCacheInterface $results_cache, array $options = array()) {
    if (!$index->status()) {
      $index_label = $index->label();
      throw new SearchApiException("Can't search on index '$index_label' which is disabled.");
    }
    $this->index = $index;
    $this->results = new ResultSet($this);
    $this->resultsCache = $results_cache;
    $this->options = $options + array(
      'conjunction' => 'AND',
      'search id' => __CLASS__,
    );
    $this->conditionGroup = $this->createConditionGroup('AND');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(IndexInterface $index, ResultsCacheInterface $results_cache, array $options = array()) {
    return new static($index, $results_cache, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function parseModes() {
    $modes['direct'] = array(
      'name' => $this->t('Direct query'),
      'description' => $this->t("Don't parse the query, just hand it to the search server unaltered. Might fail if the query contains syntax errors in regard to the specific server's query syntax."),
    );
    $modes['single'] = array(
      'name' => $this->t('Single term'),
      'description' => $this->t('The query is interpreted as a single keyword, maybe containing spaces or special characters.'),
    );
    $modes['terms'] = array(
      'name' => $this->t('Multiple terms'),
      'description' => $this->t('The query is interpreted as multiple keywords separated by spaces. Keywords containing spaces may be "quoted". Quoted keywords must still be separated by spaces.'),
    );
    // @todo Add fourth mode for complicated expressions, e.g.: »"vanilla ice" OR (love NOT hate)«
    return $modes;
  }

  /**
   * {@inheritdoc}
   */
  public function getParseMode() {
    return $this->parseMode;
  }

  /**
   * {@inheritdoc}
   */
  public function setParseMode($parse_mode) {
    $this->parseMode = $parse_mode;
    return $this;
  }

  /**
   * Parses search keys input by the user according to the given parse mode.
   *
   * @param string|array|null $keys
   *   The keywords to parse.
   *
   * @return array|null|string
   *   The parsed keywords, in the format defined by
   *   \Drupal\search_api\Query\QueryInterface::getKeys().
   *
   * @see \Drupal\search_api\Query\QueryInterface::parseModes()
   */
  protected function parseKeys($keys) {
    if ($keys === NULL || is_array($keys)) {
      return $keys;
    }
    $keys = '' . $keys;
    switch ($this->parseMode) {
      case 'direct':
        return $keys;

      case 'single':
        return array('#conjunction' => $this->options['conjunction'], $keys);

      case 'terms':
        $ret = explode(' ', $keys);
        $quoted = FALSE;
        $str = '';
        foreach ($ret as $k => $v) {
          if (!$v) {
            continue;
          }
          if ($quoted) {
            if (substr($v, -1) == '"') {
              $v = substr($v, 0, -1);
              $str .= ' ' . $v;
              $ret[$k] = $str;
              $quoted = FALSE;
            }
            else {
              $str .= ' ' . $v;
              unset($ret[$k]);
            }
          }
          elseif ($v[0] == '"') {
            $len = strlen($v);
            if ($len > 1 && $v[$len - 1] == '"') {
              $ret[$k] = substr($v, 1, -1);
            }
            else {
              $str = substr($v, 1);
              $quoted = TRUE;
              unset($ret[$k]);
            }
          }
        }
        if ($quoted) {
          $ret[] = $str;
        }
        $ret['#conjunction'] = $this->options['conjunction'];
        return array_filter($ret);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getLanguages() {
    return $this->languages;
  }

  /**
   * {@inheritdoc}
   */
  public function setLanguages(array $languages = NULL) {
    $this->languages = $languages;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function createConditionGroup($conjunction = 'AND', array $tags = array()) {
    return new ConditionGroup($conjunction, $tags);
  }

  /**
   * {@inheritdoc}
   */
  public function keys($keys = NULL) {
    $this->origKeys = $keys;
    if (isset($keys)) {
      $this->keys = $this->parseKeys($keys);
    }
    else {
      $this->keys = NULL;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setFulltextFields(array $fields = NULL) {
    $this->fields = $fields;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addConditionGroup(ConditionGroupInterface $condition_group) {
    $this->conditionGroup->addConditionGroup($condition_group);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addCondition($field, $value, $operator = '=') {
    $this->conditionGroup->addCondition($field, $value, $operator);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function sort($field, $order = self::SORT_ASC) {
    $order = strtoupper(trim($order)) == self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    $this->sorts[$field] = $order;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function range($offset = NULL, $limit = NULL) {
    $this->options['offset'] = $offset;
    $this->options['limit'] = $limit;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function abort($error_message = NULL) {
    $this->aborted = isset($error_message) ? $error_message : TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function wasAborted() {
    return $this->aborted !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAbortMessage() {
    return is_bool($this->aborted) ? $this->aborted : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if ($this->hasExecuted()) {
      return $this->results;
    }

    $this->executed = TRUE;

    // Check for aborted status both before and after calling preExecute().
    if ($this->shouldAbort()) {
      return $this->results;
    }

    // Prepare the query for execution by the server.
    $this->preExecute();

    if ($this->shouldAbort()) {
      return $this->results;
    }

    // Execute query.
    $this->index->getServerInstance()->search($this);

    // Postprocess the search results.
    $this->postExecute();

    return $this->results;
  }

  /**
   * Determines whether the query should be aborted.
   *
   * Also prepares the result set if the query should be aborted.
   *
   * @return bool
   *   TRUE if the query should be aborted, FALSE otherwise.
   */
  protected function shouldAbort() {
    if (!$this->wasAborted() && $this->languages !== array()) {
      return FALSE;
    }
    $this->postExecute();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function preExecute() {
    // Make sure to only execute this once per query.
    if (!$this->preExecuteRan) {
      $this->preExecuteRan = TRUE;

      // Preprocess query.
      $this->index->preprocessSearchQuery($this);

      // Let modules alter the query.
      $hooks = array('search_api_query');
      foreach ($this->tags as $tag) {
        $hooks[] = "search_api_query_$tag";
      }
      \Drupal::moduleHandler()->alter($hooks, $this);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postExecute() {
    // Postprocess results.
    $this->index->postprocessSearchResults($this->results);

    // Let modules alter the results.
    $hooks = array('search_api_results');
    foreach ($this->tags as $tag) {
      $hooks[] = "search_api_results_$tag";
    }
    \Drupal::moduleHandler()->alter($hooks, $this->results);

    // Store the results in the static cache.
    $this->resultsCache->addResults($this->results);
  }

  /**
   * {@inheritdoc}
   */
  public function hasExecuted() {
    return $this->executed;
  }

  /**
   * {@inheritdoc}
   */
  public function getResults() {
    return $this->results;
  }

  /**
   * {@inheritdoc}
   */
  public function getIndex() {
    return $this->index;
  }

  /**
   * {@inheritdoc}
   */
  public function &getKeys() {
    return $this->keys;
  }

  /**
   * {@inheritdoc}
   */
  public function getOriginalKeys() {
    return $this->origKeys;
  }

  /**
   * {@inheritdoc}
   */
  public function &getFulltextFields() {
    return $this->fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getConditionGroup() {
    return $this->conditionGroup;
  }

  /**
   * {@inheritdoc}
   */
  public function &getSorts() {
    return $this->sorts;
  }

  /**
   * {@inheritdoc}
   */
  public function getOption($name, $default = NULL) {
    return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
  }

  /**
   * {@inheritdoc}
   */
  public function setOption($name, $value) {
    $old = $this->getOption($name);
    $this->options[$name] = $value;
    return $old;
  }

  /**
   * {@inheritdoc}
   */
  public function &getOptions() {
    return $this->options;
  }

  /**
   * {@inheritdoc}
   */
  public function addTag($tag) {
    $this->tags[$tag] = $tag;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasTag($tag) {
    return isset($this->tags[$tag]);
  }

  /**
   * {@inheritdoc}
   */
  public function hasAllTags() {
    return !array_diff_key(array_flip(func_get_args()), $this->tags);
  }

  /**
   * {@inheritdoc}
   */
  public function hasAnyTag() {
    return (bool) array_intersect_key(array_flip(func_get_args()), $this->tags);
  }

  /**
   * {@inheritdoc}
   */
  public function &getTags() {
    return $this->tags;
  }

  /**
   * Implements the magic __sleep() method to avoid serializing the index.
   */
  public function __sleep() {
    $this->indexId = $this->index->id();
    $keys = get_object_vars($this);
    unset($keys['index'], $keys['resultsCache'], $keys['stringTranslation']);
    return array_keys($keys);
  }

  /**
   * Implements the magic __wakeup() method to reload the query's index.
   */
  public function __wakeup() {
    if (!isset($this->index) && !empty($this->indexId) && \Drupal::hasContainer()) {
      $this->index = \Drupal::entityTypeManager()
        ->getStorage('search_api_index')
        ->load($this->indexId);
      unset($this->indexId);
    }
  }

  /**
   * Implements the magic __toString() method to simplify debugging.
   */
  public function __toString() {
    $ret = 'Index: ' . $this->index->id() . "\n";
    $ret .= 'Keys: ' . str_replace("\n", "\n  ", var_export($this->origKeys, TRUE)) . "\n";
    if (isset($this->keys)) {
      $ret .= 'Parsed keys: ' . str_replace("\n", "\n  ", var_export($this->keys, TRUE)) . "\n";
      $ret .= 'Searched fields: ' . (isset($this->fields) ? implode(', ', $this->fields) : '[ALL]') . "\n";
    }
    if (isset($this->languages)) {
      $ret .= 'Searched languages: ' . implode(', ', $this->languages) . "\n";
    }
    if ($conditions = (string) $this->conditionGroup) {
      $conditions = str_replace("\n", "\n  ", $conditions);
      $ret .= "Conditions:\n  $conditions\n";
    }
    if ($this->sorts) {
      $sorts = array();
      foreach ($this->sorts as $field => $order) {
        $sorts[] = "$field $order";
      }
      $ret .= 'Sorting: ' . implode(', ', $sorts) . "\n";
    }
    $options = $this->sanitizeOptions($this->options);
    $options = str_replace("\n", "\n  ", var_export($options, TRUE));
    $ret .= 'Options: ' . $options . "\n";
    return $ret;
  }

  /**
   * Sanitizes an array of options in a way that plays nice with var_export().
   *
   * @param array $options
   *   An array of options.
   *
   * @return array
   *   The sanitized options.
   */
  protected function sanitizeOptions(array $options) {
    foreach ($options as $key => $value) {
      if (is_object($value)) {
        $options[$key] = 'object (' . get_class($value) . ')';
      }
      elseif (is_array($value)) {
        $options[$key] = $this->sanitizeOptions($value);
      }
    }
    return $options;
  }

}

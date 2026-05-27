<?php

namespace Drupal\rs_product_import\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\TermInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for importing legacy product nodes.
 */
class ProductImportCommands extends DrushCommands {

  /**
   * Term roots used to disambiguate repeated legacy category IDs.
   */
  private const SOURCE_ROOTS = [
    'ckt' => [
      'Запчасти Dongfeng',
      'Запчасти Shacman',
      'Запчасти двигателей Cummins',
      'Запчасти двигателей Weichai',
    ],
    'urc_ymz' => [
      'Двигатели ЯМЗ',
      'Запчасти ЯМЗ',
      'Ремкомплекты',
      'Топливная аппаратура ЯЗДА',
      'Радиаторы и отопители ШААЗ',
      'Прочее',
    ],
  ];

  /**
   * Cached taxonomy term lookup by field_old_id.
   *
   * @var array<string, \Drupal\taxonomy\TermInterface[]>
   */
  protected array $termsByOldId = [];

  /**
   * Existing product lookup keyed by old ID and catalog number.
   *
   * Value 0 means the key is duplicated and should not be auto-updated.
   *
   * @var array<string, int>
   */
  protected array $existingProductNidsByKey = [];

  /**
   * Number of duplicate old ID/catalog number keys in existing products.
   */
  protected int $existingProductDuplicateKeys = 0;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Imports product nodes from data/products.json.
   *
   * @command rs-product-import:import
   * @aliases rspi
   * @option file JSON file path. Defaults to module data/products.json.
   * @option limit Maximum number of products to process.
   * @option offset Number of products to skip from the beginning.
   * @option update Update existing nodes found by old ID and catalog number.
   * @option dry-run Validate and print counters without saving nodes.
   * @option log-every Print progress every N products.
   * @option stop-after Stop after processing N products in this run.
   * @option resume Continue from the last saved import progress offset.
   * @option reset-progress Clear saved import progress before running.
   * @option state-file Progress state JSON file path. Defaults to Drupal temporary directory.
   */
  public function import(array $options = [
    'file' => NULL,
    'limit' => NULL,
    'offset' => 0,
    'update' => TRUE,
    'dry-run' => FALSE,
    'log-every' => 100,
    'stop-after' => NULL,
    'resume' => FALSE,
    'reset-progress' => FALSE,
    'state-file' => NULL,
  ]): void {
    $state_file = $this->progressStateFile($options);
    if (!empty($options['reset-progress'])) {
      $this->clearProgressState($state_file);
      $this->output()->writeln("Import progress reset: {$state_file}");
    }
    if (!empty($options['resume'])) {
      $state = $this->readProgressState($state_file);
      if (isset($state['next_offset'])) {
        $options['offset'] = (int) $state['next_offset'];
        $this->output()->writeln("Resuming import from offset {$options['offset']} using {$state_file}");
      }
      else {
        $this->output()->writeln("No saved progress found in {$state_file}; starting from offset " . (int) $options['offset']);
      }
    }

    $base_offset = max(0, (int) ($options['offset'] ?? 0));
    $products = $this->loadProducts($options);
    $this->buildTermLookup();
    $this->buildExistingProductLookup();

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $missing_categories = 0;
    $dry_run = (bool) $options['dry-run'];
    $allow_update = (bool) $options['update'];
    $log_every = max(1, (int) ($options['log-every'] ?? 100));
    $stop_after = $options['stop-after'] !== NULL ? max(0, (int) $options['stop-after']) : NULL;
    $processed = 0;

    $this->output()->writeln('RS product import started.');
    $this->output()->writeln('Base offset: ' . $base_offset);
    $this->output()->writeln('Products selected: ' . count($products));
    $this->output()->writeln('Dry run: ' . ($dry_run ? 'yes' : 'no'));
    $this->output()->writeln('Progress state file: ' . $state_file);

    foreach ($products as $index => $product) {
      if ($stop_after !== NULL && $processed >= $stop_after) {
        $this->output()->writeln("Stopped by --stop-after={$stop_after}.");
        break;
      }

      $processed++;
      $absolute_index = $base_offset + $index;
      $next_offset = $absolute_index + 1;
      $label = $this->productDebugLabel($product, $absolute_index);
      if ($processed === 1 || $processed % $log_every === 0) {
        $this->output()->writeln("[{$processed}/" . count($products) . "] processing {$label}");
      }

      if (empty($product['title']) || empty($product['source']) || empty($product['source_id'])) {
        $this->output()->writeln("[{$processed}] skipped invalid product {$label}");
        $skipped++;
        $this->writeProgressState($state_file, $next_offset, $label, 'skipped_invalid');
        continue;
      }

      $this->output()->writeln("[{$processed}] loading existing node {$label}");
      $node = $this->loadExistingProduct($product);
      if ($node && !$allow_update) {
        $this->output()->writeln("[{$processed}] skipped existing node nid=" . $node->id() . " {$label}");
        $skipped++;
        $this->writeProgressState($state_file, $next_offset, $label, 'skipped_existing');
        continue;
      }

      $is_new = !$node;
      if (!$node) {
        $node = Node::create([
          'type' => 'product',
          'uid' => 0,
          'promote' => 1,
          'sticky' => 0,
        ]);
      }

      $this->output()->writeln("[{$processed}] resolving categories {$label}");
      $category_ids = $this->resolveCategoryTargetIds($product);
      if (!$category_ids && !empty($product['canonical_category_old_ids'])) {
        $missing_categories++;
      }

      $this->output()->writeln("[{$processed}] filling node fields {$label}; categories=" . count($category_ids));
      $this->fillProductNode($node, $product, $category_ids);

      if (!$dry_run) {
        $this->output()->writeln("[{$processed}] saving node {$label}");
        $node->save();
        $this->output()->writeln("[{$processed}] saved node nid=" . $node->id() . " {$label}");
        $this->writeProgressState($state_file, $next_offset, $label, $is_new ? 'created' : 'updated', (int) $node->id());
      }
      else {
        $this->output()->writeln("[{$processed}] dry-run passed {$label}");
        $this->writeProgressState($state_file, $next_offset, $label, 'dry_run');
      }

      $is_new ? $created++ : $updated++;
    }

    $this->printImportSummary($processed, $created, $updated, $skipped, $missing_categories, $dry_run);
  }

  /**
   * Reports how many JSON products can be found as Drupal product nodes.
   *
   * @command rs-product-import:status
   * @option file JSON file path. Defaults to module data/products.json.
   * @option limit Maximum number of products to check.
   * @option offset Number of products to skip from the beginning.
   */
  public function status(array $options = [
    'file' => NULL,
    'limit' => NULL,
    'offset' => 0,
  ]): void {
    $products = $this->loadProducts($options);
    $storage = $this->entityTypeManager->getStorage('node');
    $found = 0;
    $missing = 0;
    $duplicates = 0;
    $first_missing = [];
    $first_duplicates = [];

    foreach ($products as $product) {
      if (empty($product['source_id']) || empty($product['article'])) {
        $missing++;
        continue;
      }

      $nids = $storage->getQuery()
        ->condition('type', 'product')
        ->condition('field_old_id', (int) $product['source_id'])
        ->condition('field_cat_number', (string) $product['article'])
        ->accessCheck(FALSE)
        ->execute();

      $count = count($nids);
      if ($count === 1) {
        $found++;
      }
      elseif ($count > 1) {
        $duplicates++;
        if (count($first_duplicates) < 10) {
          $first_duplicates[] = ($product['import_key'] ?? $product['source_id']) . ' / ' . $product['article'] . ' => ' . implode(',', $nids);
        }
      }
      else {
        $missing++;
        if (count($first_missing) < 10) {
          $first_missing[] = ($product['import_key'] ?? $product['source_id']) . ' / ' . $product['article'];
        }
      }
    }

    $this->output()->writeln('RS product import status.');
    $this->output()->writeln('Products checked: ' . count($products));
    $this->output()->writeln("Found: {$found}");
    $this->output()->writeln("Missing: {$missing}");
    $this->output()->writeln("Duplicates: {$duplicates}");
    $this->printList('First missing:', $first_missing);
    $this->printList('First duplicates:', $first_duplicates);
  }

  /**
   * Loads and slices product data.
   */
  protected function loadProducts(array $options): array {
    $module_path = \Drupal::service('extension.list.module')->getPath('rs_product_import');
    $file = $options['file'] ?: DRUPAL_ROOT . '/' . $module_path . '/data/products.json';
    if (!file_exists($file)) {
      throw new \RuntimeException("Products JSON not found: {$file}");
    }

    $products = json_decode(file_get_contents($file), TRUE);
    if (!is_array($products)) {
      throw new \RuntimeException("Cannot decode products JSON: {$file}");
    }

    $offset = max(0, (int) ($options['offset'] ?? 0));
    $limit = $options['limit'] !== NULL ? max(0, (int) $options['limit']) : NULL;
    return ($offset || $limit !== NULL) ? array_slice($products, $offset, $limit) : $products;
  }

  /**
   * Builds a lookup of catalog taxonomy terms keyed by field_old_id.
   */
  protected function buildTermLookup(): void {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = $storage->getQuery()
      ->condition('vid', 'catalog')
      ->accessCheck(FALSE)
      ->execute();

    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $storage->loadMultiple($tids);
    foreach ($terms as $term) {
      if (!$term->hasField('field_old_id') || $term->get('field_old_id')->isEmpty()) {
        continue;
      }
      $old_id = (string) $term->get('field_old_id')->value;
      $this->termsByOldId[$old_id][] = $term;
    }
  }

  /**
   * Loads an existing product for safe re-runs.
   */
  protected function loadExistingProduct(array $product): ?Node {
    if (empty($product['source_id'])) {
      return NULL;
    }

    $key = $this->existingProductKey($product['source_id'], $product['article'] ?? '');
    if ($key === '' || empty($this->existingProductNidsByKey[$key])) {
      return NULL;
    }

    return $this->entityTypeManager->getStorage('node')->load($this->existingProductNidsByKey[$key]);
  }

  /**
   * Builds a fast existing product lookup to avoid one EntityQuery per row.
   */
  protected function buildExistingProductLookup(): void {
    $this->output()->writeln('Building existing product lookup...');

    $database = \Drupal::database();
    $query = $database->select('node_field_data', 'n');
    $query->innerJoin('node__field_old_id', 'old_id', 'old_id.entity_id = n.nid');
    $query->innerJoin('node__field_cat_number', 'cat_number', 'cat_number.entity_id = n.nid');
    $query->fields('n', ['nid']);
    $query->fields('old_id', ['field_old_id_value']);
    $query->fields('cat_number', ['field_cat_number_value']);
    $query->condition('n.type', 'product');
    $query->condition('old_id.deleted', 0);
    $query->condition('cat_number.deleted', 0);

    $rows = 0;
    foreach ($query->execute() as $row) {
      $rows++;
      $key = $this->existingProductKey($row->field_old_id_value, $row->field_cat_number_value);
      if ($key === '') {
        continue;
      }
      if (isset($this->existingProductNidsByKey[$key])) {
        if ($this->existingProductNidsByKey[$key] !== 0) {
          $this->existingProductDuplicateKeys++;
        }
        $this->existingProductNidsByKey[$key] = 0;
        continue;
      }
      $this->existingProductNidsByKey[$key] = (int) $row->nid;
    }

    $this->output()->writeln('Existing product rows scanned: ' . $rows);
    $this->output()->writeln('Existing product unique lookup keys: ' . count(array_filter($this->existingProductNidsByKey)));
    $this->output()->writeln('Existing product duplicate lookup keys: ' . $this->existingProductDuplicateKeys);
  }

  /**
   * Builds the existing product lookup key.
   */
  protected function existingProductKey($old_id, $article): string {
    $old_id = trim((string) $old_id);
    $article = trim((string) $article);
    if ($old_id === '' || $article === '') {
      return '';
    }
    return $old_id . '|' . mb_strtoupper($article);
  }

  /**
   * Fills product node fields from a normalized product row.
   */
  protected function fillProductNode(Node $node, array $product, array $category_ids): void {
    $title = (string) $product['title'];
    $node->setTitle($title);
    $this->setIfFieldExists($node, 'status', (int) ($product['status'] ?? 1));
    $this->setIfFieldExists($node, 'field_title', $title);
    $this->setIfFieldExists($node, 'field_display_title', $product['display_title'] ?: $title);
    $this->setIfFieldExists($node, 'field_old_id', (int) ($product['source_id'] ?? 0));
    $this->setIfFieldExists($node, 'field_cat_number', (string) ($product['article'] ?? ''));
    $this->setIfFieldExists($node, 'field_brand', (string) ($product['brand'] ?? ''));
    $this->setIfFieldExists($node, 'field_stock_available', (int) ($product['stock'] ?? 0));
    $this->setIfFieldExists($node, 'field_not_for_sale', (int) ($product['not_for_sale'] ?? 0));
    $this->setIfFieldExists($node, 'field_catalog', $this->targetIdValues($category_ids));
    $this->setIfFieldExists($node, 'field_parameters_list', '0');

    if ($node->hasField('body')) {
      $node->set('body', [
        'value' => (string) ($product['description'] ?? ''),
        'format' => 'full_html',
        'summary' => '',
      ]);
    }
    if ($node->hasField('field_parameters')) {
      $node->set('field_parameters', [
        'value' => (string) ($product['parameters'] ?? ''),
        'format' => 'full_html',
        'summary' => '',
      ]);
    }
    if ($node->hasField('field_features')) {
      $node->set('field_features', [
        'value' => (string) ($product['features'] ?? ''),
        'format' => 'full_html',
      ]);
    }
    if ($node->hasField('field_applicability')) {
      $node->set('field_applicability', [
        'value' => (string) ($product['applicability'] ?? ''),
        'format' => 'full_html',
      ]);
    }
    if ($node->hasField('field_models_applicability')) {
      $node->set('field_models_applicability', $this->stringValues($product['models_applicability'] ?? []));
    }
    if ($node->hasField('field_marks')) {
      $node->set('field_marks', $this->stringValues($product['marks'] ?? []));
    }

    $price = $this->formatDecimal((float) ($product['price'] ?? 0));
    $weight = $this->formatDecimal((float) ($product['weight'] ?? 0));
    $this->setIfFieldExists($node, 'model', (string) ($product['article'] ?: ($product['import_key'] ?? '0')));
    $this->setIfFieldExists($node, 'cost', ['value' => '0.00000']);
    $this->setIfFieldExists($node, 'price', ['value' => $price]);
    $this->setIfFieldExists($node, 'shippable', 1);
    $this->setIfFieldExists($node, 'weight', ['value' => $weight, 'units' => 'kg']);
    $this->setIfFieldExists($node, 'dimensions', ['length' => '0', 'width' => '0', 'height' => '0', 'units' => 'mm']);
    $this->setIfFieldExists($node, 'pkg_qty', 1);
    $this->setIfFieldExists($node, 'default_qty', 1);
    $this->setIfFieldExists($node, 'field_weight_for_sort', (int) round((float) ($product['weight'] ?? 0) * 1000));
    $this->setIfFieldExists($node, 'field_is_exist_img', !empty($product['images']) ? 1 : 0);
  }

  /**
   * Resolves category term IDs and all their ancestors.
   */
  protected function resolveCategoryTargetIds(array $product): array {
    $source = (string) ($product['source'] ?? '');
    $target_ids = [];

    foreach (($product['canonical_category_old_ids'] ?? []) as $old_id) {
      $term = $this->findTermByOldId((string) $old_id, $source);
      if (!$term) {
        continue;
      }
      foreach ($this->termWithAncestors($term) as $tid) {
        $target_ids[$tid] = $tid;
      }
    }

    return array_values($target_ids);
  }

  /**
   * Finds a taxonomy term by old ID with source-aware disambiguation.
   */
  protected function findTermByOldId(string $old_id, string $source): ?TermInterface {
    $terms = $this->termsByOldId[$old_id] ?? [];
    if (!$terms) {
      return NULL;
    }
    if (count($terms) === 1) {
      return reset($terms);
    }

    $source_roots = self::SOURCE_ROOTS[$source] ?? [];
    foreach ($terms as $term) {
      $root = $this->rootName($term);
      if ($source_roots && in_array($root, $source_roots, TRUE)) {
        return $term;
      }
      if ($source === 'rs-ural' && !$this->isImportedExternalRoot($root)) {
        return $term;
      }
    }

    return reset($terms);
  }

  /**
   * Returns the term ID plus all parent term IDs.
   */
  protected function termWithAncestors(TermInterface $term): array {
    $ids = [];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $current = $term;
    while ($current instanceof TermInterface) {
      $ids[] = (int) $current->id();
      $parent_id = (int) ($current->get('parent')->target_id ?? 0);
      if (!$parent_id) {
        break;
      }
      $current = $storage->load($parent_id);
    }
    return $ids;
  }

  /**
   * Gets the top-level root name for a term.
   */
  protected function rootName(TermInterface $term): string {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $current = $term;
    while ($current instanceof TermInterface) {
      $parent_id = (int) ($current->get('parent')->target_id ?? 0);
      if (!$parent_id) {
        return $current->label();
      }
      $parent = $storage->load($parent_id);
      if (!$parent instanceof TermInterface) {
        return $current->label();
      }
      $current = $parent;
    }
    return '';
  }

  /**
   * Checks whether a root belongs to CKT or URC imported categories.
   */
  protected function isImportedExternalRoot(string $root): bool {
    foreach (self::SOURCE_ROOTS as $roots) {
      if (in_array($root, $roots, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  protected function setIfFieldExists(Node $node, string $field_name, $value): void {
    if ($node->hasField($field_name)) {
      $node->set($field_name, $value);
    }
  }

  protected function targetIdValues(array $ids): array {
    return array_map(static fn($id): array => ['target_id' => (int) $id], $ids);
  }

  protected function stringValues(array $values): array {
    return array_map(static fn($value): array => ['value' => $value], array_filter($values, 'strlen'));
  }

  protected function formatDecimal(float $value): string {
    return number_format($value, 5, '.', '');
  }

  protected function printImportSummary(int $processed, int $created, int $updated, int $skipped, int $missing_categories, bool $dry_run): void {
    $this->output()->writeln('');
    $this->output()->writeln('RS product import finished.');
    $this->output()->writeln("Products processed: {$processed}");
    $this->output()->writeln("Created: {$created}");
    $this->output()->writeln("Updated: {$updated}");
    $this->output()->writeln("Skipped: {$skipped}");
    $this->output()->writeln("Products with unresolved categories: {$missing_categories}");
    if ($dry_run) {
      $this->output()->writeln('Dry run: no nodes were saved.');
    }
  }

  protected function printList(string $title, array $items): void {
    if (!$items) {
      return;
    }
    $this->output()->writeln('');
    $this->output()->writeln($title);
    foreach ($items as $item) {
      $this->output()->writeln('- ' . $item);
    }
  }

  /**
   * Gets the import progress state file path.
   */
  protected function progressStateFile(array $options): string {
    if (!empty($options['state-file'])) {
      return (string) $options['state-file'];
    }
    $temporary = \Drupal::service('file_system')->realpath('temporary://');
    if (!$temporary) {
      $temporary = sys_get_temp_dir();
    }
    return rtrim($temporary, '/\\') . DIRECTORY_SEPARATOR . 'rs_product_import_progress.json';
  }

  /**
   * Reads the saved import progress state.
   */
  protected function readProgressState(string $state_file): array {
    if (!file_exists($state_file)) {
      return [];
    }
    $state = json_decode((string) file_get_contents($state_file), TRUE);
    return is_array($state) ? $state : [];
  }

  /**
   * Writes the saved import progress state.
   */
  protected function writeProgressState(string $state_file, int $next_offset, string $label, string $status, ?int $nid = NULL): void {
    $directory = dirname($state_file);
    if (!is_dir($directory)) {
      mkdir($directory, 0775, TRUE);
    }
    $state = [
      'next_offset' => $next_offset,
      'last_label' => $label,
      'last_status' => $status,
      'last_nid' => $nid,
      'updated_at' => date(DATE_ATOM),
    ];
    file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Clears the saved import progress state.
   */
  protected function clearProgressState(string $state_file): void {
    if (file_exists($state_file)) {
      unlink($state_file);
    }
  }

  /**
   * Builds a compact label for progress/debug output.
   */
  protected function productDebugLabel(array $product, int $index): string {
    $source = $product['source'] ?? '?';
    $source_id = $product['source_id'] ?? '?';
    $article = $product['article'] ?? '';
    $title = $product['title'] ?? '';
    $title = mb_substr((string) $title, 0, 80);
    return "#{$index} {$source}:{$source_id} article={$article} title=\"{$title}\"";
  }

}

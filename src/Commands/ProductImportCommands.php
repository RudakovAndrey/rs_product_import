<?php

namespace Drupal\rs_product_import\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
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
   * @option save-retries Retry node save this many times on database lock timeout.
   * @option save-retry-sleep Seconds to wait between save retries.
   * @option import-images Download and attach product images from the images array.
   * @option image-directory Public/private stream wrapper directory for downloaded images.
   * @option image-timeout HTTP timeout in seconds for each image.
   * @option source Import only products from this source, for example urc_ymz.
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
    'save-retries' => 3,
    'save-retry-sleep' => 5,
    'import-images' => TRUE,
    'image-directory' => 'public://imported-products',
    'image-timeout' => 15,
    'source' => NULL,
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
    $save_retries = max(0, (int) ($options['save-retries'] ?? 3));
    $save_retry_sleep = max(1, (int) ($options['save-retry-sleep'] ?? 5));
    $import_images = (bool) $options['import-images'];
    $image_directory = (string) ($options['image-directory'] ?? 'public://imported-products');
    $image_timeout = max(1, (int) ($options['image-timeout'] ?? 15));
    $processed = 0;

    $this->output()->writeln('RS product import started.');
    $this->output()->writeln('Base offset: ' . $base_offset);
    $this->output()->writeln('Products selected: ' . count($products));
    if (!empty($options['source'])) {
      $this->output()->writeln('Source filter: ' . (string) $options['source']);
    }
    $this->output()->writeln('Dry run: ' . ($dry_run ? 'yes' : 'no'));
    $this->output()->writeln('Import images: ' . ($import_images ? 'yes' : 'no'));
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
      if (!$category_ids && $this->productHasCategoryIds($product)) {
        $missing_categories++;
      }

      $this->output()->writeln("[{$processed}] filling node fields {$label}; categories=" . count($category_ids));
      $this->fillProductNode($node, $product, $category_ids, $import_images, $image_directory, $image_timeout, $label);

      if (!$dry_run) {
        $this->output()->writeln("[{$processed}] saving node {$label}");
        $this->saveNodeWithRetries($node, $label, $save_retries, $save_retry_sleep);
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
   * @option source Check only products from this source, for example urc_ymz.
   */
  public function status(array $options = [
    'file' => NULL,
    'limit' => NULL,
    'offset' => 0,
    'source' => NULL,
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
   * Prints field differences between product nodes with equal catalog numbers.
   *
   * @command rs-product-import:duplicate-diff
   * @aliases rspidd
   * @option cat-number Check only this catalog number.
   * @option limit Maximum number of duplicate catalog numbers to print. Use 0 for all.
   * @option fields Comma-separated fields to compare, or all. Defaults to key product fields.
   * @option output Write the report to this file path instead of the terminal.
   */
  public function duplicateDiff(array $options = [
    'cat-number' => NULL,
    'limit' => 50,
    'fields' => NULL,
    'output' => NULL,
  ]): void {
    $groups = $this->loadDuplicateCatalogNumberGroups($options);
    if (!$groups) {
      $this->output()->writeln('No duplicate product catalog numbers found.');
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $lines = [];
    $lines[] = 'Duplicate catalog numbers: ' . count($groups);

    foreach ($groups as $cat_number => $nids) {
      /** @var \Drupal\node\Entity\Node[] $nodes */
      $nodes = $storage->loadMultiple($nids);
      $fields = $this->duplicateDiffFields($nodes, (string) ($options['fields'] ?? ''));
      $differences = $this->nodeFieldDifferences($nodes, $fields);

      $lines[] = '';
      $lines[] = "Catalog number: {$cat_number}";
      $lines[] = 'NIDs: ' . implode(', ', array_keys($nodes));
      foreach ($nodes as $node) {
        $old_id = $node->hasField('field_old_id') && !$node->get('field_old_id')->isEmpty() ? $node->get('field_old_id')->value : '';
        $lines[] = '  nid=' . $node->id() . ' old_id=' . $old_id . ' title="' . $node->label() . '"';
      }

      if (!$differences) {
        $lines[] = '  No differences in compared fields.';
        continue;
      }

      $lines[] = '  Differences:';
      foreach ($differences as $field_name => $values) {
        $lines[] = "  - {$field_name}:";
        foreach ($values as $nid => $value) {
          $lines[] = '      nid=' . $nid . ': ' . $value;
        }
      }
    }

    $this->writeDuplicateDiffReport($lines, (string) ($options['output'] ?? ''));
  }

  /**
   * Deletes exact duplicate product nodes with equal old ID and catalog number.
   *
   * @command rs-product-import:delete-exact-duplicates
   * @aliases rspided
   * @option dry-run Print what would be deleted without deleting nodes.
   * @option limit Maximum duplicate groups to process. Use 0 for all.
   * @option output Write the report to this file path.
   */
  public function deleteExactDuplicates(array $options = [
    'dry-run' => TRUE,
    'limit' => 50,
    'output' => NULL,
  ]): void {
    $dry_run = (bool) ($options['dry-run'] ?? TRUE);
    $groups = $this->loadExactDuplicateGroups($options);
    $storage = $this->entityTypeManager->getStorage('node');
    $lines = [];
    $deleted = 0;
    $kept = 0;
    $skipped = 0;

    $lines[] = 'Exact duplicate groups: ' . count($groups);
    $lines[] = 'Dry run: ' . ($dry_run ? 'yes' : 'no');

    foreach ($groups as $group) {
      /** @var \Drupal\node\Entity\Node[] $nodes */
      $nodes = $storage->loadMultiple($group['nids']);
      $duplicate_sets = $this->exactDuplicateNodeSets($nodes);
      if (!$duplicate_sets) {
        $differences = $this->nodeFieldDifferences($nodes, $this->exactDuplicateCompareFields());
        $skipped++;
        $lines[] = '';
        $lines[] = 'Skipped changed group old_id=' . $group['old_id'] . ' cat_number=' . $group['cat_number'] . ' nids=' . implode(',', $group['nids']);
        $lines[] = 'Changed fields: ' . implode(', ', array_keys($differences));
        continue;
      }

      $lines[] = '';
      $lines[] = 'Duplicate group old_id=' . $group['old_id'] . ' cat_number=' . $group['cat_number'];
      foreach ($duplicate_sets as $set_nids) {
        $keep_nid = min($set_nids);
        $delete_nids = array_values(array_diff($set_nids, [$keep_nid]));
        $kept++;
        $deleted += count($delete_nids);

        $lines[] = 'Keep nid=' . $keep_nid . '; delete nids=' . implode(',', $delete_nids);

        if (!$dry_run) {
          foreach ($delete_nids as $nid) {
            if (isset($nodes[$nid])) {
              $nodes[$nid]->delete();
            }
          }
        }
      }
    }

    $lines[] = '';
    $lines[] = 'Groups processed: ' . count($groups);
    $lines[] = 'Groups deleted from: ' . $kept;
    $lines[] = 'Groups skipped because fields differ: ' . $skipped;
    $lines[] = 'Nodes ' . ($dry_run ? 'to delete' : 'deleted') . ': ' . $deleted;

    $this->writeDuplicateDiffReport($lines, (string) ($options['output'] ?? ''));
  }

  /**
   * Imports original CKT and URC taxonomy trees without restructuring.
   *
   * @command rs-product-import:import-taxonomy
   * @aliases rspit
   * @option file JSON file path. Defaults to module data/taxonomy_terms_original.json.
   * @option limit Maximum number of terms to process.
   * @option offset Number of terms to skip from the beginning.
   * @option update Update existing terms found by old ID, name, and parent.
   * @option dry-run Validate and print counters without saving terms.
   * @option log-every Print progress every N terms.
   */
  public function importTaxonomy(array $options = [
    'file' => NULL,
    'limit' => NULL,
    'offset' => 0,
    'update' => TRUE,
    'dry-run' => FALSE,
    'log-every' => 100,
  ]): void {
    $items = $this->loadJsonItems($options, 'taxonomy_terms_original.json');
    $term_map = [];
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $missing_parents = 0;
    $dry_run = (bool) $options['dry-run'];
    $allow_update = (bool) $options['update'];
    $log_every = max(1, (int) ($options['log-every'] ?? 100));
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $this->output()->writeln('RS taxonomy import started.');
    $this->output()->writeln('Terms selected: ' . count($items));
    $this->output()->writeln('Dry run: ' . ($dry_run ? 'yes' : 'no'));

    foreach ($items as $index => $item) {
      $source = (string) ($item['source'] ?? '');
      $old_tid = (string) ($item['old_tid'] ?? '');
      $parent_old_tid = (string) ($item['parent_old_tid'] ?? '0');
      $name = trim((string) ($item['name'] ?? ''));
      $label = "#{$index} {$source}:{$old_tid} {$name}";

      if ($index === 0 || ($index + 1) % $log_every === 0) {
        $this->output()->writeln('[' . ($index + 1) . '/' . count($items) . "] processing {$label}");
      }

      if ($source === '' || $old_tid === '' || $name === '') {
        $skipped++;
        $this->output()->writeln("skipped invalid term {$label}");
        continue;
      }

      $parent_target_id = 0;
      if ($parent_old_tid !== '' && $parent_old_tid !== '0') {
        $parent_key = "{$source}:{$parent_old_tid}";
        if (empty($term_map[$parent_key])) {
          $missing_parents++;
          $skipped++;
          $this->output()->writeln("missing parent {$parent_key}; skipped {$label}");
          continue;
        }
        $parent_target_id = $term_map[$parent_key];
      }

      $existing_tid = $this->findExistingCatalogTerm($name, $parent_target_id, $old_tid);
      if ($existing_tid && !$allow_update) {
        $term_map["{$source}:{$old_tid}"] = $existing_tid;
        $skipped++;
        continue;
      }

      /** @var \Drupal\taxonomy\TermInterface $term */
      $term = $existing_tid ? $storage->load($existing_tid) : Term::create([
        'vid' => 'catalog',
        'langcode' => (string) ($item['langcode'] ?? 'ru'),
      ]);

      $this->fillCatalogTerm($term, $item, $parent_target_id);

      if (!$dry_run) {
        $term->save();
        $term_map["{$source}:{$old_tid}"] = (int) $term->id();
      }
      else {
        $term_map["{$source}:{$old_tid}"] = $existing_tid ?: -($index + 1);
      }

      $existing_tid ? $updated++ : $created++;
    }

    $this->output()->writeln('');
    $this->output()->writeln('RS taxonomy import finished.');
    $this->output()->writeln('Terms processed: ' . count($items));
    $this->output()->writeln("Created: {$created}");
    $this->output()->writeln("Updated: {$updated}");
    $this->output()->writeln("Skipped: {$skipped}");
    $this->output()->writeln("Missing parents: {$missing_parents}");
    if ($dry_run) {
      $this->output()->writeln('Dry run: no terms were saved.');
    }
  }

  /**
   * Updates node body values from a phpMyAdmin node__body JSON export.
   *
   * @command rs-product-import:update-body
   * @aliases rspib
   * @option file JSON file path. Defaults to module data/node__body.json.
   * @option limit Maximum number of body rows to process.
   * @option offset Number of body rows to skip from the beginning.
   * @option dry-run Validate and print counters without saving nodes.
   * @option log-every Print progress every N body rows.
   * @option check-bundle Skip rows when the JSON bundle differs from the loaded node bundle.
   * @option save-retries Retry node save this many times on database lock timeout.
   * @option save-retry-sleep Seconds to wait between save retries.
   */
  public function updateBody(array $options = [
    'file' => NULL,
    'limit' => NULL,
    'offset' => 0,
    'dry-run' => FALSE,
    'log-every' => 50,
    'check-bundle' => TRUE,
    'save-retries' => 3,
    'save-retry-sleep' => 5,
  ]): void {
    $items = $this->loadBodyItems($options);
    $storage = $this->entityTypeManager->getStorage('node');
    $dry_run = (bool) ($options['dry-run'] ?? FALSE);
    $check_bundle = (bool) ($options['check-bundle'] ?? TRUE);
    $log_every = max(1, (int) ($options['log-every'] ?? 50));
    $save_retries = max(0, (int) ($options['save-retries'] ?? 3));
    $save_retry_sleep = max(1, (int) ($options['save-retry-sleep'] ?? 5));

    $processed = 0;
    $updated = 0;
    $unchanged = 0;
    $skipped = 0;
    $missing = 0;
    $bundle_mismatch = 0;
    $missing_body_field = 0;

    $this->output()->writeln('RS body update started.');
    $this->output()->writeln('Rows selected: ' . count($items));
    $this->output()->writeln('Dry run: ' . ($dry_run ? 'yes' : 'no'));
    $this->output()->writeln('Check bundle: ' . ($check_bundle ? 'yes' : 'no'));

    foreach ($items as $index => $item) {
      $processed++;
      $entity_id = (int) ($item['entity_id'] ?? 0);
      $bundle = (string) ($item['bundle'] ?? '');
      $label = "#{$index} nid={$entity_id} bundle={$bundle}";

      if ($processed === 1 || $processed % $log_every === 0) {
        $this->output()->writeln("[{$processed}/" . count($items) . "] processing {$label}");
      }

      if ($entity_id <= 0 || !array_key_exists('body_value', $item)) {
        $skipped++;
        $this->output()->writeln("[{$processed}] skipped invalid row {$label}");
        continue;
      }

      /** @var \Drupal\node\Entity\Node|null $node */
      $node = $storage->load($entity_id);
      if (!$node) {
        $missing++;
        $this->output()->writeln("[{$processed}] missing node {$label}");
        continue;
      }

      if ($check_bundle && $bundle !== '' && $node->bundle() !== $bundle) {
        $bundle_mismatch++;
        $this->output()->writeln("[{$processed}] skipped bundle mismatch {$label}; actual=" . $node->bundle());
        continue;
      }

      if (!$node->hasField('body')) {
        $missing_body_field++;
        $this->output()->writeln("[{$processed}] skipped node without body field {$label}");
        continue;
      }

      $body_value = $this->normalizeBodyValue((string) ($item['body_value'] ?? ''));
      $body_summary = (string) ($item['body_summary'] ?? '');
      $body_format = (string) ($item['body_format'] ?? 'full_html');
      if ($body_format === '') {
        $body_format = 'full_html';
      }

      $current = $node->get('body')->first();
      $current_value = $current ? (string) ($current->value ?? '') : '';
      $current_summary = $current ? (string) ($current->summary ?? '') : '';
      $current_format = $current ? (string) ($current->format ?? '') : '';

      if ($current_value === $body_value && $current_summary === $body_summary && $current_format === $body_format) {
        $unchanged++;
        continue;
      }

      $node->set('body', [
        'value' => $body_value,
        'summary' => $body_summary,
        'format' => $body_format,
      ]);

      if (!$dry_run) {
        $this->saveNodeWithRetries($node, $label, $save_retries, $save_retry_sleep);
      }
      $updated++;
    }

    $this->output()->writeln('');
    $this->output()->writeln('RS body update finished.');
    $this->output()->writeln("Rows processed: {$processed}");
    $this->output()->writeln(($dry_run ? 'Would update' : 'Updated') . ": {$updated}");
    $this->output()->writeln("Unchanged: {$unchanged}");
    $this->output()->writeln("Skipped invalid rows: {$skipped}");
    $this->output()->writeln("Missing nodes: {$missing}");
    $this->output()->writeln("Bundle mismatches: {$bundle_mismatch}");
    $this->output()->writeln("Nodes without body field: {$missing_body_field}");
    if ($dry_run) {
      $this->output()->writeln('Dry run: no nodes were saved.');
    }
  }

  /**
   * Loads and slices product data.
   */
  protected function loadProducts(array $options): array {
    return $this->loadJsonItems($options, 'products.json');
  }

  /**
   * Loads and slices body export data.
   */
  protected function loadBodyItems(array $options): array {
    return $this->loadJsonItems($options, 'node__body.json', TRUE);
  }

  /**
   * Normalizes legacy body HTML before saving it into Drupal.
   */
  protected function normalizeBodyValue(string $body_value): string {
    $body_value = str_replace('\\"', '"', $body_value);
    $body_value = preg_replace('#https?://rs-ural\.ru/files/#i', '/sites/default/files/', $body_value) ?? $body_value;
    $body_value = preg_replace('#(?<!/sites/default)/files/#', '/sites/default/files/', $body_value) ?? $body_value;
    return str_replace('/sites/default/sites/default/files/', '/sites/default/files/', $body_value);
  }

  /**
   * Loads and slices a module data JSON file.
   */
  protected function loadJsonItems(array $options, string $default_file, bool $allow_phpmyadmin_header = FALSE): array {
    $module_path = \Drupal::service('extension.list.module')->getPath('rs_product_import');
    $file = $options['file'] ?: DRUPAL_ROOT . '/' . $module_path . '/data/' . $default_file;
    if (!file_exists($file)) {
      throw new \RuntimeException("JSON file not found: {$file}");
    }

    $contents = file_get_contents($file);
    if ($allow_phpmyadmin_header) {
      $json_start = strpos($contents, '[');
      if ($json_start !== FALSE) {
        $contents = substr($contents, $json_start);
      }
    }

    $items = json_decode($contents, TRUE);
    if (!is_array($items)) {
      throw new \RuntimeException("Cannot decode JSON file: {$file}");
    }

    if (!empty($options['source'])) {
      $source = (string) $options['source'];
      $items = array_values(array_filter($items, static fn(array $item): bool => ($item['source'] ?? '') === $source));
    }

    $offset = max(0, (int) ($options['offset'] ?? 0));
    $limit = $options['limit'] !== NULL ? max(0, (int) $options['limit']) : NULL;
    return ($offset || $limit !== NULL) ? array_slice($items, $offset, $limit) : $items;
  }

  /**
   * Loads duplicate product catalog number groups.
   */
  protected function loadDuplicateCatalogNumberGroups(array $options): array {
    $database = \Drupal::database();
    $query = $database->select('node_field_data', 'n');
    $query->innerJoin('node__field_cat_number', 'cat', 'cat.entity_id = n.nid AND cat.deleted = 0');
    $query->addField('cat', 'field_cat_number_value', 'cat_number');
    $query->addExpression('COUNT(n.nid)', 'qty');
    $query->condition('n.type', 'product');
    $query->isNotNull('cat.field_cat_number_value');
    $query->condition('cat.field_cat_number_value', '', '<>');
    if (!empty($options['cat-number'])) {
      $query->condition('cat.field_cat_number_value', (string) $options['cat-number']);
    }
    $query->groupBy('cat.field_cat_number_value');
    $query->having('COUNT(n.nid) > 1');
    $query->orderBy('qty', 'DESC');
    $query->orderBy('cat_number', 'ASC');
    $limit = (int) ($options['limit'] ?? 50);
    if (empty($options['cat-number']) && $limit > 0) {
      $query->range(0, $limit);
    }

    $groups = [];
    foreach ($query->execute() as $row) {
      $cat_number = (string) $row->cat_number;
      $nid_query = $database->select('node_field_data', 'n');
      $nid_query->innerJoin('node__field_cat_number', 'cat', 'cat.entity_id = n.nid AND cat.deleted = 0');
      $nid_query->fields('n', ['nid']);
      $nid_query->condition('n.type', 'product');
      $nid_query->condition('cat.field_cat_number_value', $cat_number);
      $nid_query->orderBy('n.nid', 'ASC');
      $groups[$cat_number] = array_map('intval', $nid_query->execute()->fetchCol());
    }
    return $groups;
  }

  /**
   * Loads duplicate groups by old ID and catalog number.
   */
  protected function loadExactDuplicateGroups(array $options): array {
    $database = \Drupal::database();
    $query = $database->select('node_field_data', 'n');
    $query->innerJoin('node__field_old_id', 'old_id', 'old_id.entity_id = n.nid AND old_id.deleted = 0');
    $query->innerJoin('node__field_cat_number', 'cat', 'cat.entity_id = n.nid AND cat.deleted = 0');
    $query->addField('old_id', 'field_old_id_value', 'old_id');
    $query->addField('cat', 'field_cat_number_value', 'cat_number');
    $query->addExpression('COUNT(n.nid)', 'qty');
    $query->condition('n.type', 'product');
    $query->isNotNull('old_id.field_old_id_value');
    $query->isNotNull('cat.field_cat_number_value');
    $query->condition('cat.field_cat_number_value', '', '<>');
    $query->groupBy('old_id.field_old_id_value');
    $query->groupBy('cat.field_cat_number_value');
    $query->having('COUNT(n.nid) > 1');
    $query->orderBy('qty', 'DESC');
    $query->orderBy('old_id', 'ASC');
    $query->orderBy('cat_number', 'ASC');
    $limit = (int) ($options['limit'] ?? 50);
    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $groups = [];
    foreach ($query->execute() as $row) {
      $nid_query = $database->select('node_field_data', 'n');
      $nid_query->innerJoin('node__field_old_id', 'old_id', 'old_id.entity_id = n.nid AND old_id.deleted = 0');
      $nid_query->innerJoin('node__field_cat_number', 'cat', 'cat.entity_id = n.nid AND cat.deleted = 0');
      $nid_query->fields('n', ['nid']);
      $nid_query->condition('n.type', 'product');
      $nid_query->condition('old_id.field_old_id_value', $row->old_id);
      $nid_query->condition('cat.field_cat_number_value', $row->cat_number);
      $nid_query->orderBy('n.nid', 'ASC');
      $nids = array_map('intval', $nid_query->execute()->fetchCol());
      if (count($nids) > 1) {
        $groups[] = [
          'old_id' => (string) $row->old_id,
          'cat_number' => (string) $row->cat_number,
          'nids' => $nids,
        ];
      }
    }
    return $groups;
  }

  /**
   * Fields that must match before exact duplicate deletion.
   */
  protected function exactDuplicateCompareFields(): array {
    return [
      'title',
      'status',
      'field_title',
      'field_display_title',
      'field_cat_number',
      'field_brand',
      'field_stock_available',
      'field_not_for_sale',
      'field_catalog',
      'body',
      'field_parameters',
      'field_applicability',
      'field_models_applicability',
      'field_marks',
      'model',
      'price',
      'cost',
      'weight',
      'dimensions',
      'uc_product_image',
      'field_is_exist_img',
    ];
  }

  /**
   * Finds identical node subsets inside one old ID/catalog number group.
   */
  protected function exactDuplicateNodeSets(array $nodes): array {
    $sets = [];
    foreach ($nodes as $node) {
      $values = [];
      foreach ($this->exactDuplicateCompareFields() as $field_name) {
        $values[$field_name] = $this->nodeCompareValue($node, $field_name);
      }
      $signature = sha1(json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      $sets[$signature][] = (int) $node->id();
    }

    return array_values(array_filter($sets, static fn(array $nids): bool => count($nids) > 1));
  }

  /**
   * Writes a duplicate diff report to output or to a file.
   */
  protected function writeDuplicateDiffReport(array $lines, string $output): void {
    $report = implode(PHP_EOL, $lines) . PHP_EOL;
    if ($output === '') {
      $this->output()->write($report);
      return;
    }

    $directory = dirname($output);
    if ($directory !== '.' && !is_dir($directory)) {
      mkdir($directory, 0775, TRUE);
    }
    file_put_contents($output, $report);
    $this->output()->writeln("Duplicate diff report written: {$output}");
  }

  /**
   * Returns field names to compare for duplicate products.
   */
  protected function duplicateDiffFields(array $nodes, string $fields_option): array {
    if ($fields_option === 'all') {
      $fields = [];
      foreach ($nodes as $node) {
        foreach ($node->getFieldDefinitions() as $field_name => $definition) {
          if (!$definition->isComputed()) {
            $fields[$field_name] = $field_name;
          }
        }
      }
      return array_values($fields);
    }

    if ($fields_option !== '') {
      return array_filter(array_map('trim', explode(',', $fields_option)));
    }

    return [
      'title',
      'status',
      'field_old_id',
      'field_title',
      'field_display_title',
      'field_brand',
      'field_stock_available',
      'field_not_for_sale',
      'field_catalog',
      'body',
      'field_parameters',
      'field_applicability',
      'field_models_applicability',
      'field_marks',
      'model',
      'price',
      'cost',
      'weight',
      'dimensions',
      'uc_product_image',
      'field_is_exist_img',
    ];
  }

  /**
   * Builds a map of changed field values for a set of nodes.
   */
  protected function nodeFieldDifferences(array $nodes, array $fields): array {
    $differences = [];
    foreach ($fields as $field_name) {
      $values = [];
      foreach ($nodes as $node) {
        $values[(int) $node->id()] = $this->nodeCompareValue($node, $field_name);
      }
      if (count(array_unique($values)) > 1) {
        $differences[$field_name] = $values;
      }
    }
    return $differences;
  }

  /**
   * Normalizes a node field value for readable comparison.
   */
  protected function nodeCompareValue(Node $node, string $field_name): string {
    if ($field_name === 'title') {
      return $this->shortValue($node->label());
    }
    if ($field_name === 'status') {
      return $node->isPublished() ? '1' : '0';
    }
    if (!$node->hasField($field_name)) {
      return '[field missing]';
    }
    if ($node->get($field_name)->isEmpty()) {
      return '[empty]';
    }
    $value = $node->get($field_name)->getValue();
    return $this->shortValue(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Shortens long values for terminal output.
   */
  protected function shortValue(string $value): string {
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strlen($value) > 500 ? mb_substr($value, 0, 500) . '...' : $value;
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
   * Finds an existing catalog term by exact old ID, name, and parent.
   */
  protected function findExistingCatalogTerm(string $name, int $parent_target_id, string $old_tid): ?int {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'catalog',
      'name' => $name,
      'field_old_id' => (int) $old_tid,
      'parent' => $parent_target_id,
    ]);
    return count($terms) === 1 ? (int) reset($terms)->id() : NULL;
  }

  /**
   * Fills catalog taxonomy term fields for the legacy tree import.
   */
  protected function fillCatalogTerm(TermInterface $term, array $item, int $parent_target_id): void {
    $term->setName((string) $item['name']);
    $term->set('parent', ['target_id' => $parent_target_id]);
    $term->set('weight', (int) ($item['weight'] ?? 0));
    $term->set('status', (int) ($item['status'] ?? 1));
    $this->setIfTermFieldExists($term, 'field_old_id', (int) ($item['old_tid'] ?? 0));
    $this->setIfTermFieldExists($term, 'field_product_view_type', 'table');
    $this->setIfTermFieldExists($term, 'field_show_products', 'term_tree');
    $this->setIfTermFieldExists($term, 'field_subterm_view_type', 'tile_mini');
  }

  /**
   * Sets a taxonomy term field only if it exists.
   */
  protected function setIfTermFieldExists(TermInterface $term, string $field_name, $value): void {
    if ($term->hasField($field_name)) {
      $term->set($field_name, $value);
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
   * Saves a node, retrying transient database lock timeouts.
   */
  protected function saveNodeWithRetries(Node $node, string $label, int $retries, int $sleep): void {
    $attempt = 0;
    while (TRUE) {
      try {
        $node->save();
        return;
      }
      catch (DatabaseExceptionWrapper $exception) {
        if (!$this->isLockTimeoutException($exception) || $attempt >= $retries) {
          throw $exception;
        }
        $attempt++;
        $this->output()->writeln("[save retry {$attempt}/{$retries}] database lock timeout for {$label}; sleeping {$sleep}s");
        sleep($sleep);
      }
    }
  }

  /**
   * Checks whether an exception is a MySQL lock wait timeout/deadlock.
   */
  protected function isLockTimeoutException(DatabaseExceptionWrapper $exception): bool {
    $message = $exception->getMessage();
    return strpos($message, '1205 Lock wait timeout') !== FALSE
      || strpos($message, '1213 Deadlock found') !== FALSE
      || strpos($message, 'Lock wait timeout exceeded') !== FALSE;
  }

  /**
   * Fills product node fields from a normalized product row.
   */
  protected function fillProductNode(Node $node, array $product, array $category_ids, bool $import_images, string $image_directory, int $image_timeout, string $label): void {
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
    $this->setIfFieldExists($node, 'model', $this->productModelValue($product));
    $this->setIfFieldExists($node, 'cost', ['value' => '0.00000']);
    $this->setIfFieldExists($node, 'price', ['value' => $price]);
    $this->setIfFieldExists($node, 'shippable', 1);
    $this->setIfFieldExists($node, 'weight', ['value' => $weight, 'units' => 'kg']);
    $this->setIfFieldExists($node, 'dimensions', ['length' => '0', 'width' => '0', 'height' => '0', 'units' => 'mm']);
    $this->setIfFieldExists($node, 'pkg_qty', 1);
    $this->setIfFieldExists($node, 'default_qty', 1);
    $this->setIfFieldExists($node, 'field_weight_for_sort', (int) round((float) ($product['weight'] ?? 0) * 1000));
    $this->setIfFieldExists($node, 'field_is_exist_img', !empty($product['images']) ? 1 : 0);
    if ($import_images && $node->hasField('uc_product_image') && !empty($product['images'])) {
      $this->attachProductImages($node, $product, $image_directory, $image_timeout, $label);
    }
  }

  /**
   * Returns a short value for the Ubercart model/SKU column.
   */
  protected function productModelValue(array $product): string {
    $article = trim(preg_replace('/\s+/u', ' ', (string) ($product['article'] ?? '')) ?? '');
    if ($article !== '' && mb_strlen($article) <= 32) {
      return $article;
    }

    $fallback = trim((string) ($product['import_key'] ?? ''));
    if ($fallback === '') {
      $source = trim((string) ($product['source'] ?? 'product'));
      $source_id = trim((string) ($product['source_id'] ?? '0'));
      $fallback = "{$source}:{$source_id}";
    }

    return mb_substr($fallback, 0, 32);
  }

  /**
   * Resolves original category term IDs and all their ancestors.
   */
  protected function resolveCategoryTargetIds(array $product): array {
    $source = (string) ($product['source'] ?? '');
    $target_ids = [];

    foreach ($this->productOriginalCategoryOldIds($product) as $old_id) {
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
   * Returns original category IDs from product JSON.
   */
  protected function productOriginalCategoryOldIds(array $product): array {
    if (!empty($product['old_category_ids']) && is_array($product['old_category_ids'])) {
      return $product['old_category_ids'];
    }
    return $product['canonical_category_old_ids'] ?? [];
  }

  /**
   * Checks whether a product row has category IDs.
   */
  protected function productHasCategoryIds(array $product): bool {
    return !empty($this->productOriginalCategoryOldIds($product));
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

  /**
   * Downloads and attaches product images if the field is still empty.
   */
  protected function attachProductImages(Node $node, array $product, string $directory, int $timeout, string $label): void {
    if (!$node->get('uc_product_image')->isEmpty()) {
      return;
    }

    $file_system = \Drupal::service('file_system');
    $file_system->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);

    $values = [];
    $title = (string) ($product['title'] ?? $node->label());
    foreach (($product['images'] ?? []) as $delta => $url) {
      $url = trim((string) $url);
      if ($url === '') {
        continue;
      }
      $file = $this->downloadProductImage($url, $directory, $product, $delta, $timeout, $label);
      if (!$file) {
        continue;
      }
      $values[] = [
        'target_id' => $file->id(),
        'alt' => $title,
        'title' => $title,
      ];
    }

    if ($values) {
      $node->set('uc_product_image', $values);
      $this->setIfFieldExists($node, 'field_is_exist_img', 1);
    }
  }

  /**
   * Downloads a single product image and returns a managed file entity.
   */
  protected function downloadProductImage(string $url, string $directory, array $product, int $delta, int $timeout, string $label): ?File {
    $uri = $this->productImageUri($url, $directory, $product, $delta);
    $existing = $this->loadFileByUri($uri);
    if ($existing) {
      return $existing;
    }

    try {
      $response = \Drupal::httpClient()->get($url, [
        'timeout' => $timeout,
        'http_errors' => FALSE,
        'headers' => [
          'User-Agent' => 'RS Product Import/1.0',
        ],
      ]);
      if ($response->getStatusCode() >= 400) {
        $this->output()->writeln('[image skipped] HTTP ' . $response->getStatusCode() . " {$url} {$label}");
        return NULL;
      }
      $data = (string) $response->getBody();
      if ($data === '') {
        $this->output()->writeln("[image skipped] empty response {$url} {$label}");
        return NULL;
      }
      file_put_contents($uri, $data);
    }
    catch (\Throwable $exception) {
      $this->output()->writeln('[image skipped] ' . $exception->getMessage() . " {$url} {$label}");
      return NULL;
    }

    $file = File::create([
      'uri' => $uri,
      'status' => 1,
      'uid' => 0,
    ]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Builds a stable destination URI for a product image.
   */
  protected function productImageUri(string $url, string $directory, array $product, int $delta): string {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], TRUE)) {
      $extension = 'jpg';
    }
    $source = preg_replace('/[^a-z0-9_-]+/i', '_', (string) ($product['source'] ?? 'source'));
    $source_id = preg_replace('/[^a-z0-9_-]+/i', '_', (string) ($product['source_id'] ?? '0'));
    $hash = substr(sha1($url), 0, 12);
    return rtrim($directory, '/\\') . '/' . $source . '-' . $source_id . '-' . $delta . '-' . $hash . '.' . $extension;
  }

  /**
   * Loads a managed file entity by URI.
   */
  protected function loadFileByUri(string $uri): ?File {
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
    return $files ? reset($files) : NULL;
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
    $suffix = '';
    if (!empty($options['source'])) {
      $suffix = '_' . preg_replace('/[^a-z0-9_-]+/i', '_', (string) $options['source']);
    }
    return rtrim($temporary, '/\\') . DIRECTORY_SEPARATOR . "rs_product_import_progress{$suffix}.json";
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

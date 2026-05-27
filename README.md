# RS Product Import

Drupal 9/10 custom module with Drush commands for importing legacy product nodes from a prepared JSON file.

## Install

Put this repository into:

```bash
web/modules/custom/rs_product_import
```

Enable the module:

```bash
drush en rs_product_import -y
drush cr
```

## Data File

Place product data here:

```text
data/products.json
```

The default command expects this file. You can also pass a custom path:

```bash
drush rs-product-import:import --file=/absolute/path/products.json
```

## Import

Test without saving:

```bash
drush rs-product-import:import --limit=10 --dry-run
```

Import first 10:

```bash
drush rs-product-import:import --limit=10
```

Import everything:

```bash
drush rs-product-import:import
```

Check import status:

```bash
drush rs-product-import:status
```

## Notes

- Products are matched on reruns by `field_old_id` plus `field_cat_number`.
- Product categories are resolved by taxonomy term `field_old_id`.
- Repeated old category IDs are disambiguated by source root categories.
- The importer attaches the target category and its ancestors to keep parent categories populated after tree changes.
- Cross-group fields are intentionally not touched.

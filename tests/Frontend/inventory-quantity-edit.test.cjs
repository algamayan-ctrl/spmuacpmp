const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const form = read('resources/views/inventory/form.blade.php');
const styles = read('resources/views/inventory/partials/form-styles.blade.php');
const retired = read('resources/views/inventory/partials/quantity-interactions.blade.php');
const controller = read('app/Http/Controllers/InventoryController.php');
const service = read('app/Services/InventoryService.php');

test('edit physical stock is displayed in two read-only columns', () => {
  assert.match(form, /Current Total Quantity/);
  assert.match(form, /Master Condition/);
  assert.doesNotMatch(form, /new_total_quantity|original_total_quantity|New Total Quantity|data-inventory-total-quantity|quantity-interactions/);
  assert.match(styles, /\.inventory-form-stock-values \{[^}]*grid-template-columns: repeat\(2,/);
  assert.doesNotMatch(styles, /inventory-form-stock-values\.is-editable|inventory-form-quantity-label/);
  assert.doesNotMatch(retired, /<script|addEventListener/);
  assert.match(form, /name="total_quantity"/); // creation still accepts initial stock
  assert.match(form, /name="condition_code"/);
});

test('metadata endpoint rejects retired fields and cannot write stock', () => {
  assert.match(controller, /'new_total_quantity' => \['missing'\]/);
  assert.match(controller, /'original_total_quantity' => \['missing'\]/);
  const method = service.split('public function updateItemDetails(')[1].split('public function recordAdjustment(')[0];
  assert.doesNotMatch(method, /INVENTORY_QUANTITY_CORRECTION|\$newTotal|\$expectedTotal|->total_quantity\s*=/);
  assert.match(method, /unset\(\$data\['total_quantity'\], \$data\['condition_code'\]/);
  assert.match(method, /lockForUpdate/);
  assert.match(method, /\$committed > 0/);
});

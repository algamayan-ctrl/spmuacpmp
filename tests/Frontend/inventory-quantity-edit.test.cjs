const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../../resources/views/inventory/partials/quantity-interactions.blade.php'), 'utf8').replace(/<\/?script>/g, '');

function editor(value = '6') {
  let onInput;
  const input = {value, dataset: {currentQuantity: '6', unit: 'Piece', unitPlural: 'Pieces'}, addEventListener: (event, fn) => {onInput = fn;}};
  const message = {textContent: ''};
  vm.runInNewContext(script, {document: {querySelector: selector => selector === '[data-inventory-total-quantity]' ? input : message}});
  return {message, change: value => {input.value = value; onInput();}};
}

test('quantity helper describes increases, decreases and unchanged stock in units', () => {
  const field = editor();
  assert.equal(field.message.textContent, 'No quantity change.');
  field.change('8');
  assert.equal(field.message.textContent, 'Change: +2 Pieces');
  field.change('5');
  assert.equal(field.message.textContent, 'Change: −1 Piece');
  field.change('0');
  assert.equal(field.message.textContent, 'Change: −6 Pieces');
  field.change('6');
  assert.equal(field.message.textContent, 'No quantity change.');
});

test('invalid, empty, fractional and oversized totals do not display a misleading change', () => {
  const field = editor();
  for (const value of ['', '-1', '2.5', '1000000000', '<img src=x>']) {
    field.change(value);
    assert.equal(field.message.textContent, 'Enter a whole quantity of 0 or more.');
  }
});

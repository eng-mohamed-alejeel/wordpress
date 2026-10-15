document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('adc-branches-form');
  if (!form) return;
  const rows = document.getElementById('adc-branch-rows');
  let index = rows.children.length;
  document.getElementById('adc-add-branch').addEventListener('click', () => {
    rows.insertAdjacentHTML('beforeend', document.getElementById('adc-branch-template').innerHTML.replaceAll('__INDEX__', String(index++)));
    rows.lastElementChild.querySelector('input[type=text]').focus();
  });
  rows.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button) return;
    const row = button.closest('.adc-branch-editor');
    if (button.hasAttribute('data-branch-remove')) row.remove();
    if (button.hasAttribute('data-branch-up') && row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling);
    if (button.hasAttribute('data-branch-down') && row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row);
  });
});

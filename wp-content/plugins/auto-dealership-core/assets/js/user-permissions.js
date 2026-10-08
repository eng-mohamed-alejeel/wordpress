/* Keep role defaults visible; enable individual choices only in custom mode. */
document.querySelectorAll('.adc-user-permissions').forEach((section) => {
  const toggle = section.querySelector('[name="adc_permissions_custom"]');
  if (!toggle) return;
  const initialCustom = toggle.checked;
  const permissionInputs = [...section.querySelectorAll('input[name="adc_permissions[]"]')];
  const initialChoices = new Map(permissionInputs.map((input) => [input.value, input.checked]));
  const reason = section.closest('form')?.querySelector('[name="adc_permissions_reason"]');
  const update = () => {
    section.querySelectorAll('fieldset').forEach((group) => {
      group.disabled = !toggle.checked;
    });
  };
  const role = section.closest('form')?.querySelector('select[name="role"]');
  if (role && typeof adcRolePermissions !== 'undefined') {
    const defaults = () => {
      const protectedRole = adcRolePermissions.protectedRoles.includes(role.value);
      toggle.disabled = protectedRole;
      if (protectedRole) toggle.checked = false;
      const keys = new Set(Object.values(adcRolePermissions.roles[role.value]?.groups || {}).flat().map((item) => item.key));
      if (!toggle.checked) section.querySelectorAll('[name="adc_permissions[]"]').forEach((input) => { input.checked = keys.has(input.value); });
      update();
      section.dispatchEvent(new Event('adc-defaults-changed'));
    };
    role.addEventListener('change', defaults);
    toggle.addEventListener('change', () => { if (!toggle.checked) defaults(); });
    defaults();
  }
  toggle.addEventListener('change', update);
  const requireReason = () => {
    if (reason) reason.required = toggle.checked !== initialCustom || (toggle.checked && permissionInputs.some((input) => input.checked !== initialChoices.get(input.value)));
  };
  section.addEventListener('change', (event) => {
    if (event.target === toggle) permissionInputs.forEach((input) => { input.closest('label').dataset.custom = toggle.checked ? '1' : '0'; });
    if (permissionInputs.includes(event.target)) event.target.closest('label').dataset.custom = '1';
    requireReason();
  });
  requireReason();
  update();
});

const branchSelect = document.querySelector('#adc_branch_ids');
const primaryBranch = document.querySelector('#adc_branch_id');
const branchReason = document.querySelector('[name="adc_branch_reason"]');
if (branchSelect && primaryBranch && branchReason) {
  const state = () => JSON.stringify([primaryBranch.value, [...branchSelect.selectedOptions].map((option) => option.value).sort()]);
  const initial = state();
  const update = () => { branchReason.required = state() !== initial; };
  branchSelect.addEventListener('change', update); primaryBranch.addEventListener('change', update);
}
/* Each creation form has its own role selector (including multisite forms). */
if (typeof adcRolePermissions !== 'undefined' && adcRolePermissions.title) {
  document.querySelectorAll('select[name="role"]').forEach((select, index) => {
    const preview = document.createElement('section');
    preview.className = 'adc-role-defaults';
    preview.id = `adc-role-defaults-${index}`;
    preview.setAttribute('aria-live', 'polite');
    preview.setAttribute('aria-atomic', 'true');
    select.setAttribute('aria-describedby', [select.getAttribute('aria-describedby'), preview.id].filter(Boolean).join(' '));
    select.insertAdjacentElement('afterend', preview);
    const update = () => {
      preview.replaceChildren();
      const role = adcRolePermissions.roles[select.value];
      preview.hidden = !role;
      if (!role) return;
      const title = document.createElement('h3');
      title.textContent = `${adcRolePermissions.title}: ${select.selectedOptions[0]?.textContent || role.name}`;
      preview.append(title);
      const note = document.createElement('p');
      note.className = 'description';
      note.textContent = adcRolePermissions.note;
      preview.append(note);
      const entries = Object.entries(role.groups);
      if (!entries.length) {
        const empty = document.createElement('p');
        empty.textContent = adcRolePermissions.empty;
        preview.append(empty);
      }
      entries.forEach(([name, permissions]) => {
        const group = document.createElement('div');
        const heading = document.createElement('h4');
        heading.textContent = name;
        group.append(heading);
        const list = document.createElement('ul');
        permissions.forEach((permission) => {
          const item = document.createElement('li');
          item.textContent = permission.label;
          list.append(item);
        });
        group.append(list);
        preview.append(group);
      });
    };
    select.addEventListener('change', update);
    update();
  });
}

document.querySelectorAll('[name="clone_source"]').forEach((select) => {
  select.addEventListener('change', () => {
    const form = select.closest('form');
    form.querySelector('[name="clone_loaded"]').value = '1';
    const keys = new Set(Object.values(adcRolePermissions.roles[select.value]?.groups || {}).flat().map((item) => item.key));
    form.querySelectorAll('input[name="caps[]"]').forEach((input) => { input.checked = keys.has(input.value); });
    form.dispatchEvent(new Event('change', { bubbles: true }));
  });
});

if (typeof adcPermissionFilters !== 'undefined') {
  document.querySelectorAll('.adc-user-permissions, .adc-permission-report').forEach((section) => {
    const labels = [...section.querySelectorAll('.adc-permission-grid > label')];
    const rows = [...section.querySelectorAll('tbody tr[data-granted]')];
    if (!labels.length && !rows.length) return;
    const toolbar = document.createElement('div'); toolbar.className = 'adc-permission-filters';
    const searchLabel = document.createElement('label'); searchLabel.textContent = adcPermissionFilters.search + ' ';
    const search = document.createElement('input'); search.type = 'search'; searchLabel.append(search); toolbar.append(searchLabel);
    const filter = document.createElement('select'); filter.setAttribute('aria-label', adcPermissionFilters.all);
    for (const key of ['all', 'granted', 'denied', 'custom']) { const option = document.createElement('option'); option.value = key; option.textContent = adcPermissionFilters[key]; filter.append(option); }
    toolbar.append(filter); section.prepend(toolbar);
    const update = () => {
      for (const item of [...labels, ...rows]) {
        const input = item.querySelector('input[type="checkbox"]');
        const granted = input ? input.checked : item.dataset.granted === '1';
        const custom = item.dataset.custom === '1';
        const textMatches = item.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase());
        item.hidden = !textMatches || (filter.value === 'granted' && !granted) || (filter.value === 'denied' && granted) || (filter.value === 'custom' && !custom);
      }
      section.querySelectorAll('.adc-permission-group').forEach((group) => { group.hidden = [...group.querySelectorAll('.adc-permission-grid > label')].every((label) => label.hidden); });
    };
    search.addEventListener('input', update); filter.addEventListener('change', update); section.addEventListener('change', update);
    section.addEventListener('adc-defaults-changed', update);
    update();
  });
}

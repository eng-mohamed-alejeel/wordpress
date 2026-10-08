/* Keep role defaults visible; enable individual choices only in custom mode. */
document.querySelectorAll('.adc-user-permissions').forEach((section) => {
  const toggle = section.querySelector('[name="adc_permissions_custom"]');
  if (!toggle) return;
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
    };
    role.addEventListener('change', defaults);
    toggle.addEventListener('change', () => { if (!toggle.checked) defaults(); });
    defaults();
  }
  toggle.addEventListener('change', update);
  update();
});
/* Each creation form has its own role selector (including multisite forms). */
if (typeof adcRolePermissions !== 'undefined') {
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

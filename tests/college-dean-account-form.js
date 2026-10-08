// Run: node tests/college-dean-account-form.js
// Exercise the account page's actual form, default-role, edit, and payload handlers.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../administrator/manage-accounts.php'), 'utf8');
const script = source.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
new vm.Script(script);
const roles = ['admin', 'scheduler', 'professor', 'program_chair', 'college_dean', 'registrar', 'VPAA', 'DI'];
const elements = new Map();
const handlers = new Map();
const document = {};
const element = selector => {
  if (!elements.has(selector)) elements.set(selector, { value: '', options: [] });
  return elements.get(selector);
};
for (const mode of ['add', 'edit']) {
  for (const role of roles) element(`#${mode}_role_${role}`).value = role;
}
const $ = (target, attrs) => {
  let items;
  const group = typeof target === 'string' && target.match(/^#(add|edit)AccountForm \.account-role-checkbox(:checked)?$/);
  if (group) items = roles.map(role => element(`#${group[1]}_role_${role}`)).filter(item => !group[2] || item.checked);
  else items = [attrs || (typeof target === 'string' ? element(target) : target)];
  const api = {
    on(event, selector, handler) { if (target === document) handlers.set(event + ' ' + selector, handler); return api; },
    val(value) { if (arguments.length === 0) return items[0].value; items.forEach(item => item.value = value); return api; },
    prop(key, value) { items.forEach(item => item[key] = value); return api; },
    attr(key) { return items[0][key]; },
    data(key) { return items[0][key]; },
    toggle(value) { items.forEach(item => item.visible = value); return api; },
    empty() { items[0].options = []; return api; },
    append(option) { items[0].options.push(option); return api; },
    map(callback) { return { get: () => items.map(item => callback.call(item)) }; },
    each() { return api; },
    is() { return Boolean(items[0].checked); }
  };
  return api;
};
$.trim = value => String(value ?? '').trim();
const context = vm.createContext({ $, document, window: {}, Swal: { fire: () => Promise.resolve({}) } });
vm.runInContext(script.replace(/\/\/ INITIAL LOAD[\s\S]*?(?=\$\(window\)\.on)/, ''), context);
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
for (const mode of ['add', 'edit']) {
  check(source.includes(`id="${mode}_role_college_dean"`) && source.includes(`id="${mode}_college_dean_college_id"`), `${mode} form exposes College Dean and its own college.`);
  context.setSelectedRoles(mode, roles);
  context.buildPrimaryRoleOptions(mode, 'college_dean');
  context.toggleProgramChairCollegeField(mode, roles, '2');
  context.toggleCollegeDeanCollegeField(mode, roles, '1');
  element(`#${mode}_username`).value = 'Test Account';
  element(`#${mode}_email`).value = 'test@sksu.edu.ph';
  element(`#${mode}_college_ids`).value = ['2', '3'];
  element(`#${mode}_default_college_id`).value = '3';
  const payload = context.buildAccountPayload(mode);
  check(payload.roles.length === 8 && payload.primary_role === 'college_dean', 'All eight roles retain College Dean as the selected default.');
  check(payload.program_chair_college_id === '2' && payload.college_dean_college_id === '1' && payload.default_college_id === '3', 'Chair, Dean, and Scheduler assignments stay independent.');
  check(element(`#${mode}_college_dean_college_wrapper`).visible && element(`#${mode}_college_dean_college_id`).required, 'College Dean requires its assignment only when selected.');
  context.setSelectedRoles(mode, ['program_chair']);
  context.buildPrimaryRoleOptions(mode, 'college_dean');
  context.toggleCollegeDeanCollegeField(mode, ['program_chair'], '1');
  check(!element(`#${mode}_college_dean_college_wrapper`).visible && !element(`#${mode}_college_dean_college_id`).required && element(`#${mode}_college_dean_college_id`).value === '', 'Removing Dean hides and clears its field.');
  check(context.buildAccountPayload(mode).primary_role === 'program_chair' && !('college_dean_college_id' in context.buildAccountPayload(mode)), 'Removing Dean restores a valid default and omits its scope from the payload.');
}
context.toggleFacultyLinkField = context.toggleCollegeAccessFields = context.toggleRegistrarCampusField = context.showAccountModal = () => {};
const edit = handlers.get('click .btnEditAccount');
edit.call({ id: '10', username: 'Shared Account', email: 'shared@sksu.edu.ph', status: 'active',
  'data-roles': JSON.stringify(['program_chair', 'college_dean']), 'data-primary-role': 'college_dean',
  'data-program-chair-college': '2', 'data-college-dean-college': '1', 'data-college-ids': '[]' });
const restored = context.buildAccountPayload('edit');
check(restored.primary_role === 'college_dean' && restored.program_chair_college_id === '2' && restored.college_dean_college_id === '1', 'Opening Edit Account restores both roles and distinct college assignments.');
const normalized = context.normalizeAccountRecord({ id: 10, role_payload: [{ role: 'program_chair' }, { role: 'college_dean', is_primary: true }], primary_role: 'college_dean', program_chair_college_id: 2, college_dean_college_id: 1, college_dean_college_code: 'CCS', college_dean_college_label: 'CCS - College of Computer Studies' });
check(normalized.collegeDeanCollegeValue === '1' && normalized.programChairCollegeValue === '2' && normalized.collegeText.includes('College Dean'), 'The rendered account record preserves the independent Dean scope.');
check(context.roleLabel('college_dean') === 'College Dean', 'College Dean has a readable role label.');
console.log(`College Dean account form: ${checks} checks passed.`);

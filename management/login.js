'use strict';
const form = document.getElementById('managementLoginForm');
const button = document.getElementById('managementGoogleLogin');
const available = !button.disabled;
form.addEventListener('submit', event => {
  if (button.disabled) { event.preventDefault(); return; }
  button.disabled = true;
  button.querySelector('span').textContent = 'Opening Google…';
});
window.addEventListener('pageshow', () => {
  button.disabled = !available;
  button.querySelector('span').textContent = 'Continue with Google';
});

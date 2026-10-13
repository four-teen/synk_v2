'use strict';
document.getElementById('studentLoginForm').addEventListener('submit', function () {
  const button = document.getElementById('studentGoogleLogin');
  if (button.disabled) return;
  button.disabled = true;
  button.querySelector('span').textContent = 'Opening Google…';
});
window.addEventListener('pageshow', function () {
  const button = document.getElementById('studentGoogleLogin');
  if (button.querySelector('span').textContent === 'Opening Google…') {
    button.disabled = false;
    button.querySelector('span').textContent = 'Continue with Google';
  }
});

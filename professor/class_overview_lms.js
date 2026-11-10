(function () {
  'use strict';
  document.querySelectorAll('[data-planned-tool]').forEach(button => button.addEventListener('click',() => {
    window.synkLmsFeedback.info(button.dataset.purpose + ' This feature is planned and is not available yet.',button.dataset.title);
  }));
})();

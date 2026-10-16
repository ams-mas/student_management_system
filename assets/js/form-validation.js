(function () {
  'use strict';
  function messageFor(field) {
    if (field.validity.valueMissing) return 'تکمیل این خانه الزامی است.';
    if (field.validity.typeMismatch && field.type === 'email') return 'لطفاً یک نشانی ایمیل معتبر وارد کنید.';
    if (field.validity.typeMismatch && field.type === 'url') return 'لطفاً یک نشانی معتبر وارد کنید.';
    if (field.validity.rangeUnderflow) return 'مقدار واردشده از حداقل کمتر است.';
    if (field.validity.rangeOverflow) return 'مقدار واردشده از حداکثر بیشتر است.';
    if (field.validity.stepMismatch) return 'لطفاً مقدار را با گام مجاز وارد کنید.';
    if (field.validity.tooLong) return 'متن واردشده بیش از حد مجاز است.';
    if (field.validity.patternMismatch) return 'قالب مقدار واردشده درست نیست.';
    return field.validationMessage || 'لطفاً مقدار واردشده را بررسی کنید.';
  }
  function clearMessage(field) {
    field.removeAttribute('aria-invalid');
    var id = field.getAttribute('aria-describedby');
    if (id) {
      var ids = id.split(/\s+/).filter(function (item) {
        var node = document.getElementById(item);
        return node && !node.classList.contains('field-validation-message');
      });
      if (ids.length) field.setAttribute('aria-describedby', ids.join(' '));
      else field.removeAttribute('aria-describedby');
    }
    var parent = field.parentElement;
    if (!parent) return;
    var old = parent.querySelector(':scope > .field-validation-message');
    if (old) old.remove();
  }
  function showMessage(field) {
    if (!field || field.disabled || field.type === 'hidden' || field.validity.valid) {
      if (field) clearMessage(field);
      return;
    }
    clearMessage(field);
    field.setAttribute('aria-invalid', 'true');
    var error = document.createElement('small');
    error.className = 'field-validation-message';
    error.id = 'field-error-' + Math.random().toString(36).slice(2, 10);
    error.setAttribute('role', 'alert');
    error.textContent = messageFor(field);
    field.insertAdjacentElement('afterend', error);
    var describedBy = field.getAttribute('aria-describedby');
    field.setAttribute('aria-describedby', (describedBy ? describedBy + ' ' : '') + error.id);
  }
  document.addEventListener('invalid', function (event) {
    showMessage(event.target);
  }, true);
  document.addEventListener('input', function (event) {
    var field = event.target;
    if (field && field.matches('input,select,textarea') && field.getAttribute('aria-invalid') === 'true' && field.validity.valid) clearMessage(field);
  }, true);
  document.addEventListener('change', function (event) {
    var field = event.target;
    if (field && field.matches('input,select,textarea') && field.getAttribute('aria-invalid') === 'true' && field.validity.valid) clearMessage(field);
  }, true);
  document.addEventListener('blur', function (event) {
    var field = event.target;
    if (field && field.matches('input,select,textarea') && field.required && field.value.trim() !== '' && !field.validity.valid) showMessage(field);
  }, true);
})();
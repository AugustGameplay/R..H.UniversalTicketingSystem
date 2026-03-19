/**
 * rhr-toast.js — Lightweight toast notification system
 *
 * Usage:
 *   rhrToast('Ticket created successfully!', 'success');
 *   rhrToast('Something went wrong.', 'error');
 *   rhrToast(['Error 1', 'Error 2'], 'error');
 */
(function () {
  'use strict';

  var DURATION_MS = 10000; // 10 seconds

  var ICONS = {
    success: '<i class="fa-solid fa-check"></i>',
    error:   '<i class="fa-solid fa-xmark"></i>',
    warning: '<i class="fa-solid fa-exclamation"></i>',
    info:    '<i class="fa-solid fa-info"></i>',
  };

  function getContainer() {
    var c = document.getElementById('rhr-toast-container');
    if (!c) {
      c = document.createElement('div');
      c.id = 'rhr-toast-container';
      c.className = 'rhr-toast-container';
      c.setAttribute('aria-live', 'polite');
      document.body.appendChild(c);
    }
    return c;
  }

  function dismiss(el) {
    if (el._dismissed) return;
    el._dismissed = true;
    el.classList.add('rhr-toast--out');
    setTimeout(function () { el.remove(); }, 400);
  }

  /**
   * @param {string|string[]} message - Text or array of messages
   * @param {'success'|'error'|'warning'|'info'} type
   */
  window.rhrToast = function (message, type) {
    type = type || 'success';

    var el = document.createElement('div');
    el.className = 'rhr-toast rhr-toast--' + type;
    el.setAttribute('role', 'alert');

    // Icon
    var icon = document.createElement('span');
    icon.className = 'rhr-toast__icon';
    icon.innerHTML = ICONS[type] || ICONS.info;
    el.appendChild(icon);

    // Text
    var text = document.createElement('span');
    text.className = 'rhr-toast__text';

    if (Array.isArray(message)) {
      var ul = document.createElement('ul');
      message.forEach(function (m) {
        var li = document.createElement('li');
        li.textContent = m;
        ul.appendChild(li);
      });
      text.appendChild(ul);
    } else {
      text.textContent = message;
    }
    el.appendChild(text);

    // Close button
    var btn = document.createElement('button');
    btn.className = 'rhr-toast__close';
    btn.setAttribute('aria-label', 'Close');
    btn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    btn.addEventListener('click', function () { dismiss(el); });
    el.appendChild(btn);

    getContainer().appendChild(el);

    // Auto-dismiss
    setTimeout(function () { dismiss(el); }, DURATION_MS);
  };

  // Auto-init: convert any existing .rhr-toast-init divs to toasts
  document.addEventListener('DOMContentLoaded', function () {
    var inits = document.querySelectorAll('[data-rhr-toast]');
    inits.forEach(function (el) {
      var msg = el.getAttribute('data-rhr-toast');
      var type = el.getAttribute('data-rhr-toast-type') || 'success';

      // Check for list items
      var lis = el.querySelectorAll('li');
      if (lis.length > 0) {
        var messages = [];
        lis.forEach(function (li) { messages.push(li.textContent.trim()); });
        rhrToast(messages, type);
      } else {
        rhrToast(msg, type);
      }
      el.remove();
    });

    // Clean up URL parameters so toasts don't reappear on F5 reload
    if (window.history && window.history.replaceState) {
      var url = new URL(window.location.href);
      var params = url.searchParams;
      var changed = false;
      var cleanKeys = ['created', 'updated', 'deleted', 'pass_updated'];
      cleanKeys.forEach(function(k) {
        if (params.has(k)) {
          params.delete(k);
          changed = true;
        }
      });
      if (changed) {
        window.history.replaceState({}, document.title, url.toString());
      }
    }
  });
})();

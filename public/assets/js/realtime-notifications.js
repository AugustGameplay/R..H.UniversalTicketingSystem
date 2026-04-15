/**
 * realtime-notifications.js
 * ─────────────────────────────────────────────────────
 * Polls the server for new tickets and notifies admin/superadmin users via:
 *   1. In-app toast (rhrToast)
 *   2. Audio notification sound
 *   3. Browser title bar flash
 *
 * Works over HTTP — no HTTPS required.
 */
(function () {
  'use strict';

  var lastId = null;
  var POLL_INTERVAL = 10000; // 10 seconds
  var originalTitle = document.title;
  var titleFlashTimer = null;

  // ── Notification Sound (short beep, base64-encoded) ──
  // This is a tiny WAV file so no external dependency needed
  var audioCtx = null;
  function playNotificationSound() {
    try {
      if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      }
      // Play two short ascending tones
      var now = audioCtx.currentTime;

      // First tone
      var osc1 = audioCtx.createOscillator();
      var gain1 = audioCtx.createGain();
      osc1.connect(gain1);
      gain1.connect(audioCtx.destination);
      osc1.type = 'sine';
      osc1.frequency.value = 587.33; // D5
      gain1.gain.setValueAtTime(0.3, now);
      gain1.gain.exponentialRampToValueAtTime(0.01, now + 0.15);
      osc1.start(now);
      osc1.stop(now + 0.15);

      // Second tone (higher)
      var osc2 = audioCtx.createOscillator();
      var gain2 = audioCtx.createGain();
      osc2.connect(gain2);
      gain2.connect(audioCtx.destination);
      osc2.type = 'sine';
      osc2.frequency.value = 880; // A5
      gain2.gain.setValueAtTime(0.3, now + 0.15);
      gain2.gain.exponentialRampToValueAtTime(0.01, now + 0.4);
      osc2.start(now + 0.15);
      osc2.stop(now + 0.4);
    } catch (e) {
      // Audio not supported, silently fail
    }
  }

  // ── Title Bar Flashing ──
  function startTitleFlash(ticketCount) {
    stopTitleFlash();
    var show = true;
    var flashMsg = '🔔 ' + ticketCount + ' New Ticket' + (ticketCount > 1 ? 's' : '') + '!';
    titleFlashTimer = setInterval(function () {
      document.title = show ? flashMsg : originalTitle;
      show = !show;
    }, 1000);
  }

  function stopTitleFlash() {
    if (titleFlashTimer) {
      clearInterval(titleFlashTimer);
      titleFlashTimer = null;
      document.title = originalTitle;
    }
  }

  // Stop flashing when user focuses the window
  window.addEventListener('focus', stopTitleFlash);

  // ── Fetch Notifications ──
  function fetchNotifications(isInit) {
    var url = isInit
      ? './api/notifications.php?init=1'
      : './api/notifications.php?last_id=' + lastId;

    fetch(url)
      .then(function (response) {
        if (!response.ok) throw new Error('Network error');
        return response.json();
      })
      .then(function (data) {
        if (data.status === 'denied') {
          // Not an admin — stop polling
          return;
        }

        if (data.status === 'ok') {
          if (isInit) {
            lastId = data.latest_id;
          } else if (data.tickets && data.tickets.length > 0) {
            var newTickets = data.tickets;

            // 1. Show toast for each new ticket
            newTickets.forEach(function (ticket) {
              var type = ticket.type || ticket.category || 'General';
              var creator = ticket.creator_name || 'A user';
              var area = ticket.area || 'Unknown';

              var messages = [
                'Ticket #' + ticket.id_ticket + ' created!',
                'Type: ' + type,
                'By: ' + creator + ' (' + area + ')'
              ];

              if (window.rhrToast) {
                window.rhrToast(messages, 'info');
              }
            });

            // 2. Play sound
            playNotificationSound();

            // 3. Flash title
            startTitleFlash(newTickets.length);

            lastId = data.latest_id;
          }
        }
      })
      .catch(function (err) {
        console.error('Notification poll error:', err);
      });
  }

  // ── Initialize ──
  document.addEventListener('DOMContentLoaded', function () {
    // Start polling
    fetchNotifications(true);
    setInterval(function () {
      fetchNotifications(false);
    }, POLL_INTERVAL);
  });
})();

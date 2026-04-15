/**
 * realtime-notifications.js
 * Polls the notifications API to check for new tickets.
 *
 * FIX: Notification.requestPermission() MUST be triggered by a user gesture
 * (e.g. a button click). Browsers silently ignore auto-calls on page load.
 * Additionally, the Notification API requires a SECURE CONTEXT (https://)
 * in production. It only works on http:// for localhost.
 */
(function () {
    'use strict';

    let lastId = null;
    const POLL_INTERVAL = 10000; // 10 seconds

    /* ------------------------------------------------------------------ */
    /*  Diagnóstico inicial en consola                                      */
    /* ------------------------------------------------------------------ */
    if (!('Notification' in window)) {
        console.warn('[Notif] Este navegador no soporta la API de Notificaciones.');
    } else if (!window.isSecureContext) {
        console.warn(
            '[Notif] ⚠️  La página NO está en un contexto seguro (HTTPS / localhost).\n' +
            '         Las notificaciones nativas del navegador están bloqueadas por el navegador.\n' +
            '         Solución: habilitar SSL (HTTPS) en el servidor.'
        );
    } else {
        console.info('[Notif] Contexto seguro ✔  |  Permiso actual:', Notification.permission);
    }

    /* ------------------------------------------------------------------ */
    /*  Solicitar permiso — debe llamarse desde un gesto del usuario        */
    /* ------------------------------------------------------------------ */
    /**
     * Pide permiso de notificaciones al usuario.
     * Devuelve una Promise que resuelve con el nuevo estado del permiso.
     * @returns {Promise<string>}
     */
    window.requestNotifPermission = function () {
        if (!('Notification' in window)) {
            return Promise.resolve('unsupported');
        }
        if (!window.isSecureContext) {
            console.error('[Notif] No se puede pedir permiso: la página no usa HTTPS.');
            return Promise.resolve('insecure');
        }
        if (Notification.permission === 'granted') {
            return Promise.resolve('granted');
        }
        return Notification.requestPermission().then(function (result) {
            console.info('[Notif] Permiso de notificaciones:', result);
            // Actualizar el ícono del botón en el sidebar
            _updateBellBtn(result);
            return result;
        });
    };

    /* ------------------------------------------------------------------ */
    /*  Actualizar apariencia del botón campana                             */
    /* ------------------------------------------------------------------ */
    function _updateBellBtn(permission) {
        const btn = document.getElementById('btnNotifPermission');
        if (!btn) return;

        btn.classList.remove('notif-granted', 'notif-denied', 'notif-default', 'notif-insecure');

        if (!window.isSecureContext) {
            btn.classList.add('notif-insecure');
            btn.title = 'Notificaciones no disponibles (requiere HTTPS)';
            btn.querySelector('i').className = 'fa-solid fa-bell-slash';
            return;
        }

        switch (permission) {
            case 'granted':
                btn.classList.add('notif-granted');
                btn.title = 'Notificaciones activas ✔';
                btn.querySelector('i').className = 'fa-solid fa-bell';
                break;
            case 'denied':
                btn.classList.add('notif-denied');
                btn.title = 'Notificaciones bloqueadas — desbloquea en la config del navegador';
                btn.querySelector('i').className = 'fa-solid fa-bell-slash';
                break;
            default:
                btn.classList.add('notif-default');
                btn.title = 'Clic para activar notificaciones de escritorio';
                btn.querySelector('i').className = 'fa-regular fa-bell';
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Polling de la API                                                   */
    /* ------------------------------------------------------------------ */
    function fetchNotifications(isInit) {
        const url = isInit
            ? './api/notifications.php?init=1'
            : './api/notifications.php?last_id=' + lastId;

        fetch(url)
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function (data) {
                if (data.status !== 'ok') return;

                if (isInit) {
                    lastId = data.latest_id;
                    console.info('[Notif] Polling iniciado. Last ticket ID:', lastId);
                    return;
                }

                if (!data.tickets || data.tickets.length === 0) return;

                data.tickets.forEach(function (ticket) {
                    const type    = ticket.type || ticket.category || 'General';
                    const creator = ticket.creator_name || 'A user';
                    const area    = ticket.area || 'Unknown';

                    /* --- Toast interno --- */
                    if (window.rhrToast) {
                        window.rhrToast([
                            'Ticket #' + ticket.id_ticket + ' created!',
                            'Type: ' + type,
                            'By: ' + creator + ' (' + area + ')'
                        ], 'info');
                    }

                    /* --- Notificación nativa del navegador --- */
                    if ('Notification' in window && Notification.permission === 'granted' && window.isSecureContext) {
                        try {
                            const n = new Notification('New Ticket #' + ticket.id_ticket, {
                                body  : 'Type: ' + type + '\nBy: ' + creator + ' (Area: ' + area + ')',
                                icon  : './assets/img/isotopo.png',
                                badge : './assets/img/isotopo.png'
                            });
                            setTimeout(function () { n.close(); }, 6000);
                        } catch (e) {
                            console.error('[Notif] Error al crear notificación:', e);
                        }
                    }
                });

                lastId = data.latest_id;
            })
            .catch(function (err) {
                console.error('[Notif] Error en fetch:', err);
            });
    }

    /* ------------------------------------------------------------------ */
    /*  Inicialización al cargar el DOM                                     */
    /* ------------------------------------------------------------------ */
    document.addEventListener('DOMContentLoaded', function () {
        // Estado inicial del botón campana (sin pedir permiso automáticamente)
        if ('Notification' in window) {
            _updateBellBtn(Notification.permission);
        }

        // Arrancar el polling
        fetchNotifications(true);
        setInterval(function () { fetchNotifications(false); }, POLL_INTERVAL);
    });

})();

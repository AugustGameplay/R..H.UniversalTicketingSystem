/**
 * realtime-notifications.js
 * Polls the notifications API to check for new tickets.
 */
(function() {
    'use strict';

    let lastId = null;
    const POLL_INTERVAL = 10000; // 10 seconds

    // Pedir permiso al navegador para notificaciones push si aún no se tiene
    if ('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
        Notification.requestPermission();
    }

    function fetchNotifications(isInit = false) {
        const url = isInit ? './api/notifications.php?init=1' : `./api/notifications.php?last_id=${lastId}`;

        fetch(url)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.status === 'ok') {
                    if (isInit) {
                        lastId = data.latest_id;
                    } else if (data.tickets && data.tickets.length > 0) {
                        data.tickets.forEach(ticket => {
                            const type = ticket.type || ticket.category || 'General';
                            const creator = ticket.creator_name || 'A user';
                            const area = ticket.area || 'Unknown';
                            
                            // 1. Mostrar tu Toast interno
                            const messages = [
                                `Ticket #${ticket.id_ticket} created!`,
                                `Type: ${type}`,
                                `By: ${creator} (${area})`
                            ];

                            if (window.rhrToast) {
                                window.rhrToast(messages, 'info'); 
                            }

                            // 2. Mostrar la notificación nativa del navegador
                            if ('Notification' in window && Notification.permission === 'granted') {
                                const notifTitle = `New Ticket #${ticket.id_ticket}`;
                                const notifOptions = {
                                    body: `Type: ${type}\nBy: ${creator} (Area: ${area})`,
                                    icon: './assets/img/isotopo.png',
                                    badge: './assets/img/isotopo.png'
                                };
                                const sysNotification = new Notification(notifTitle, notifOptions);
                                
                                // Opcional: Cerrar sola después de 5-6 segundos si el sistema operativo no lo hace
                                setTimeout(() => sysNotification.close(), 6000);
                            }
                        });
                        lastId = data.latest_id;
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching notifications:', error);
            });
    }

    // Initialize by getting the latest ID, then poll
    document.addEventListener('DOMContentLoaded', () => {
        // Confirmar permisos on load
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
        
        fetchNotifications(true);
        setInterval(() => fetchNotifications(false), POLL_INTERVAL);
    });
})();

<?php
/**
 * Helpers Globales
 * Centraliza las funciones repetidas de formateo, UI y sanitización.
 */

if (!function_exists('esc')) {
    function esc($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ui_status_label')) {
    function ui_status_label(string $db): string {
        return match (strtolower($db)) {
            'pendiente', 'pending'      => 'Pending',
            'en proceso', 'in progress' => 'In Progress',
            'resuelto', 'resolved'      => 'Resolved',
            'cerrado', 'closed'         => 'Closed',
            default                     => ucfirst($db),
        };
    }
}

if (!function_exists('ui_status_class')) {
    function ui_status_class(string $db): string {
        return match (strtolower($db)) {
            'pendiente', 'pending'        => 'st-open',
            'en proceso', 'in progress', 'progress' => 'st-progress',
            'resuelto', 'resolved'        => 'st-done',
            'cerrado', 'closed', 'canceled' => 'st-cancel',
            default                       => 'st-open',
        };
    }
}

if (!function_exists('ui_prio_label')) {
    function ui_prio_label(string $prio): string {
        return match (strtolower($prio)) {
            'baja', 'low'                => 'Low',
            'media', 'medium'            => 'Medium',
            'alta', 'high'               => 'High',
            'urgente', 'urgent'          => 'Urgent',
            'alta/media', 'high/medium'  => 'High/Medium',
            default                      => ucfirst($prio),
        };
    }
}

if (!function_exists('ui_prio_class')) {
    function ui_prio_class(string $prio): string {
        return match (strtolower($prio)) {
            'baja', 'low'       => 'prio-low',
            'media', 'medium'   => 'prio-medium',
            'alta', 'high'      => 'prio-high',
            'urgente', 'urgent' => 'prio-urgent',
            default             => 'prio-medium',
        };
    }
}

// Alias compatibles con history.php
if (!function_exists('getStatusEn')) {
    function getStatusEn(string $st): string {
        return ui_status_label($st);
    }
}

if (!function_exists('getPriorityEn')) {
    function getPriorityEn(string $pri): string {
        return ui_prio_label($pri);
    }
}

if (!function_exists('getTypeEn')) {
    function getTypeEn($t): string {
        return match(strtolower((string)$t)) {
            'falla', 'fault'       => 'Fault',
            'solicitud', 'request' => 'Request',
            default                => (string)$t
        };
    }
}

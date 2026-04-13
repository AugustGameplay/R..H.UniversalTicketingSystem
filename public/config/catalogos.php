<?php
// Centralized Catalog for Areas, Categories, and Types
// Replaces hardcoded HTML and frontend JS in generarTickets.php and regex scraping in ticket_edit.php

$CATALOGO_AREAS = [
    'Accounting',
    'Corporate',
    'HR',
    'Managers',
    'Marketing and IT',
    'Recruiters',
    'Workers Comp'
];

$CATALOGO_FALLAS = [
    [
        'category' => 'Hardware',
        'label'    => 'Computer / PC',
        'icon'     => 'fa-solid fa-desktop',
        'types'    => [
            ['icon' => 'fa-solid fa-power-off',          'label' => 'Does not turn on'],
            ['icon' => 'fa-solid fa-gauge',              'label' => 'Very slow / freezing'],
            ['icon' => 'fa-solid fa-fire',               'label' => 'Overheating / turns off randomly'],
            ['icon' => 'fa-solid fa-volume-high',        'label' => 'Making strange noises'],
            ['icon' => 'fa-solid fa-skull-crossbones',   'label' => 'Blue screen / crashing'],
            ['icon' => 'fa-solid fa-rotate-right',       'label' => 'Keeps restarting'],
            ['icon' => 'fa-solid fa-circle-question',    'label' => 'Other issue', 'is_other' => true],
        ]
    ],
    [
        'category' => 'Hardware',
        'label'    => 'Monitor',
        'icon'     => 'fa-solid fa-tv',
        'types'    => [
            ['icon' => 'fa-solid fa-power-off',          'label' => 'Does not turn on'],
            ['icon' => 'fa-solid fa-bolt',               'label' => 'Flickering / flashing'],
            ['icon' => 'fa-solid fa-plug',               'label' => 'HDMI / VGA / cable issue'],
            ['icon' => 'fa-solid fa-expand',             'label' => 'Incorrect resolution'],
            ['icon' => 'fa-solid fa-eye-slash',          'label' => 'No display / black screen'],
            ['icon' => 'fa-solid fa-bars',               'label' => 'Lines / spots on screen'],
            ['icon' => 'fa-solid fa-circle-question',    'label' => 'Other issue', 'is_other' => true],
        ]
    ],
    [
        'category' => 'Hardware',
        'label'    => 'Printer',
        'icon'     => 'fa-solid fa-print',
        'types'    => [
            ['icon' => 'fa-solid fa-fill-drip',          'label' => 'Out of ink / toner'],
            ['icon' => 'fa-solid fa-file-circle-xmark',  'label' => 'Out of paper / paper jam'],
            ['icon' => 'fa-solid fa-link-slash',         'label' => 'Not connecting (USB/Network/WiFi)'],
            ['icon' => 'fa-solid fa-ban',                'label' => 'Not printing / stuck in queue'],
            ['icon' => 'fa-solid fa-file-circle-exclamation', 'label' => 'Printing cut off / bad format'],
            ['icon' => 'fa-solid fa-circle-question',    'label' => 'Other issue', 'is_other' => true],
        ]
    ],
    [
        'category' => 'Network',
        'label'    => 'Network / Internet',
        'icon'     => 'fa-solid fa-wifi',
        'types'    => [
            ['icon' => 'fa-solid fa-wifi',               'label' => 'No internet connection'],
            ['icon' => 'fa-solid fa-gauge',              'label' => 'Very slow connection'],
            ['icon' => 'fa-solid fa-ethernet',           'label' => 'Network cable unplugged/damaged'],
            ['icon' => 'fa-solid fa-server',             'label' => 'Cannot access server / VPN'],
            ['icon' => 'fa-solid fa-globe',              'label' => 'Certain websites not loading'],
            ['icon' => 'fa-solid fa-circle-question',    'label' => 'Other issue', 'is_other' => true],
        ]
    ],
    [
        'category' => 'Software',
        'label'    => 'App / Software',
        'icon'     => 'fa-solid fa-cubes',
        'types'    => [
            ['icon' => 'fa-solid fa-triangle-exclamation', 'label' => 'Error opening application'],
            ['icon' => 'fa-solid fa-bug',                'label' => 'App crashing / closing unexpectedly'],
            ['icon' => 'fa-solid fa-lock',               'label' => 'No access / permission denied'],
            ['icon' => 'fa-solid fa-download',           'label' => 'Need software installed'],
            ['icon' => 'fa-solid fa-rotate',             'label' => 'Pending / forced update'],
            ['icon' => 'fa-solid fa-circle-question',    'label' => 'Other issue', 'is_other' => true],
        ]
    ],
    [
        'category' => 'Email',
        'label'    => 'Email / Access',
        'icon'     => 'fa-solid fa-envelope-circle-check',
        'types'    => [
            ['icon' => 'fa-solid fa-key',                'label' => 'Forgot password'],
            ['icon' => 'fa-solid fa-user-lock',          'label' => 'Account locked'],
            ['icon' => 'fa-solid fa-paper-plane',        'label' => 'Cannot send or receive emails'],
            ['icon' => 'fa-solid fa-id-badge',           'label' => 'Need access to new system'],
            ['icon' => 'fa-solid fa-shield-halved',      'label' => 'Suspected compromised account'],
            ['icon' => 'fa-solid fa-circle-question',    'label' => 'Other issue', 'is_other' => true],
        ]
    ],
    [
        'category' => 'Hardware',
        'label'    => 'Keyboard / Mouse',
        'icon'     => 'fa-solid fa-keyboard',
        'types'    => [
            ['icon' => 'fa-solid fa-keyboard',           'label' => 'Keys not responding'],
            ['icon' => 'fa-solid fa-computer-mouse',     'label' => 'Mouse not moving / clicking'],
            ['icon' => 'fa-solid fa-battery-quarter',    'label' => 'Battery dead (wireless)'],
            ['icon' => 'fa-solid fa-circle-exclamation', 'label' => 'Device not recognized'],
            ['icon' => 'fa-solid fa-circle-question',    'label' => 'Other issue', 'is_other' => true],
        ]
    ],
    [
        'category' => 'Hardware',
        'label'    => 'Phone / VoIP',
        'icon'     => 'fa-solid fa-phone-office', // Note: font-awesome doesn't always have phone-office, but generating same as HTML
        'types'    => [
            ['icon' => 'fa-solid fa-phone-slash',        'label' => 'No dial tone / cannot call'],
            ['icon' => 'fa-solid fa-microphone-slash',   'label' => 'No audio during calls'],
            ['icon' => 'fa-solid fa-signal',             'label' => 'Disconnected from VoIP network'],
            ['icon' => 'fa-solid fa-power-off',          'label' => 'Will not turn on / frozen'],
            ['icon' => 'fa-solid fa-circle-question',    'label' => 'Other issue', 'is_other' => true],
        ]
    ],
    [
        'category' => 'General',
        'label'    => 'Other',
        'icon'     => 'fa-solid fa-circle-question',
        'types'    => [
            ['icon' => 'fa-solid fa-wrench',             'label' => 'Unlisted hardware failure'],
            ['icon' => 'fa-solid fa-comment-dots',       'label' => 'General request / inquiry', 'is_other' => true],
        ]
    ]
];

// Helper to provide a flat array of all possible issue types
function get_catalogo_types_flat(): array {
    global $CATALOGO_FALLAS;
    $flat = [];
    foreach ($CATALOGO_FALLAS as $cat) {
        foreach ($cat['types'] as $t) {
            $flat[] = $t['label'];
        }
    }
    return array_unique($flat);
}

// Helper to return types mapped by their main Category (Hardware, Network, etc.)
function get_catalogo_types_by_category(): array {
    global $CATALOGO_FALLAS;
    $byCat = [];
    foreach ($CATALOGO_FALLAS as $cat) {
        $cName = $cat['category'];
        if (!isset($byCat[$cName])) {
            $byCat[$cName] = [];
        }
        foreach ($cat['types'] as $t) {
            $byCat[$cName][] = $t['label'];
        }
    }
    foreach ($byCat as &$arr) {
        $arr = array_values(array_unique($arr));
    }
    return $byCat;
}

// Helper to build the FALLAS object for the Frontend JS
function build_js_fallas_object(): string {
    global $CATALOGO_FALLAS;
    $jsObj = [];
    foreach ($CATALOGO_FALLAS as $cat) {
        $jsObj[$cat['label']] = $cat['types'];
    }
    // Encode keeping unicode and not escaping slashes
    return json_encode($jsObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Helper to build the TYPE_TO_CATEGORY object for Frontend JS and backend checking
function build_js_type_to_category_object(): string {
    global $CATALOGO_FALLAS;
    $obj = [];
    foreach ($CATALOGO_FALLAS as $cat) {
        // Here we map the CAT LABEL to the CATEGORY (e.g., 'Computer / PC' => 'Hardware')
        $obj[$cat['label']] = $cat['category'];
    }
    return json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

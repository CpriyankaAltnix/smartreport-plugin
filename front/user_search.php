<?php

/**
 * SmartReport - AJAX user/group search (Select2)
 *
 * Provides paginated users and groups for email selection.
 * Returns data in Select2 format with infinite scroll support.
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/glpiversion.class.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=utf-8');

// Request params
$term     = trim($_GET['q']    ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;                          // items per page for both users and groups
$offset   = ($page - 1) * $per_page;

global $DB;

$results       = [];
$users_more    = false;
$groups_more   = false;

// Users
// Fetch extra row to detect more pages
$user_criteria = [
    'SELECT'    => [
        'glpi_users.id',
        'glpi_users.name',
        'glpi_users.realname',
        'glpi_users.firstname',
        'glpi_useremails.email',
    ],
    'FROM'      => 'glpi_users',
    'LEFT JOIN' => [
        'glpi_useremails' => [
            'ON' => [
                'glpi_users'      => 'id',
                'glpi_useremails' => 'users_id',
                ['AND' => ['glpi_useremails.is_default' => 1]],
            ],
        ],
    ],
    'WHERE'  => ['glpi_users.is_active' => 1],
    'ORDER'  => ['glpi_users.realname', 'glpi_users.firstname', 'glpi_users.name'],
    'START'  => $offset,
    'LIMIT'  => $per_page + 1,
];

if ($term !== '') {
    $like = '%' . $DB->escape($term) . '%';
    $user_criteria['WHERE'][] = [
        'OR' => [
            ['glpi_users.name'      => ['LIKE', $like]],
            ['glpi_users.realname'  => ['LIKE', $like]],
            ['glpi_users.firstname' => ['LIKE', $like]],
        ],
    ];
}

$user_rows = iterator_to_array($DB->request($user_criteria));

if (count($user_rows) > $per_page) {
    $users_more = true;
    array_pop($user_rows);      // discard the sentinel row
}

$user_children = [];
foreach ($user_rows as $row) {
    $display = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
    if ($display === '') {
        $display = $row['name'];
    }
    if (!empty($row['email'])) {
        $display .= ' &lt;' . htmlspecialchars($row['email']) . '&gt;';
    }
    $user_children[] = [
        'id'   => 'user_' . $row['id'],
        'text' => $display,
    ];
}

if (!empty($user_children)) {
    $results[] = ['text' => __('Users'), 'children' => $user_children];
}

// Groups
// Same pagination logic as users
$group_criteria = [
    'SELECT' => ['id', 'name'],
    'FROM'   => 'glpi_groups',
    'ORDER'  => 'name',
    'START'  => $offset,
    'LIMIT'  => $per_page + 1,
];

if ($term !== '') {
    $group_criteria['WHERE'] = [
        ['glpi_groups.name' => ['LIKE', '%' . $DB->escape($term) . '%']],
    ];
}

$group_rows = iterator_to_array($DB->request($group_criteria));

if (count($group_rows) > $per_page) {
    $groups_more = true;
    array_pop($group_rows);
}

$group_children = [];
foreach ($group_rows as $row) {
    $group_children[] = [
        'id'   => 'group_' . $row['id'],
        'text' => htmlspecialchars($row['name']),
    ];
}

if (!empty($group_children)) {
    $results[] = ['text' => __('Groups'), 'children' => $group_children];
}

// Response
// "more" enables infinite scroll
echo json_encode([
    'results'    => $results,
    'pagination' => ['more' => $users_more || $groups_more],
]);
exit;

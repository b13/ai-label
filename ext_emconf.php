<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Label',
    'description' => 'Adds AI-created / AI-modified checkboxes to every backend-editable record and stores them in a dedicated table.',
    'category' => 'be',
    'author' => 'b13 GmbH',
    'author_email' => 'typo3@b13.com',
    'author_company' => 'b13 GmbH',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];

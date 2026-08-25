<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Label',
    'description' => 'Disclose AI-generated and AI-modified content as required by Article 50 of the EU AI Act: flag records in the backend, track editorial review, and mark flagged content in the frontend.',
    'category' => 'be',
    'author' => 'b13 GmbH',
    'author_email' => 'typo3@b13.com',
    'author_company' => 'b13 GmbH',
    'state' => 'stable',
    'version' => '1.1.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'fluid_styled_content' => '13.4.0-14.99.99',
            'filelist' => '13.4.0-14.99.99',
        ],
    ],
];

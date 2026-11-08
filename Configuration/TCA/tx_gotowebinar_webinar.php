<?php

$lll = 'LLL:EXT:goto_webinar/Resources/Private/Language/locallang_db.xlf:tx_gotowebinar_webinar';

return [
    'ctrl' => [
        'title' => $lll,
        'label' => 'title',
        'label_alt' => 'label',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'sortby' => 'sorting',
        'delete' => 'deleted',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:goto_webinar/Resources/Public/Icons/Extension.svg',
        'searchFields' => 'title,label,host_name',
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;core.form.tabs:general,
                    webinar_key, label, title, description, image,
                --div--;' . $lll . '.tab.host,
                    host_name, host_image,
                --div--;core.form.tabs:language,
                    sys_language_uid, l10n_parent,
                --div--;core.form.tabs:access,
                    hidden,
            ',
        ],
    ],
    'columns' => [
        'webinar_key' => [
            'label' => $lll . '.webinar_key',
            'description' => $lll . '.webinar_key.description',
            'l10n_mode' => 'exclude',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => ''],
                ],
                'itemsProcFunc' => \WapplerSystems\GotoWebinar\Tca\WebinarItems::class . '->addItems',
                'required' => true,
            ],
        ],
        'label' => [
            'label' => $lll . '.label',
            'description' => $lll . '.label.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
            ],
        ],
        'title' => [
            'label' => $lll . '.title',
            'description' => $lll . '.title.description',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'max' => 255,
            ],
        ],
        'description' => [
            'label' => $lll . '.description',
            'config' => [
                'type' => 'text',
                'enableRichtext' => true,
            ],
        ],
        'image' => [
            'label' => $lll . '.image',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'common-image-types',
            ],
        ],
        'host_name' => [
            'label' => $lll . '.host_name',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
            ],
        ],
        'host_image' => [
            'label' => $lll . '.host_image',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'common-image-types',
            ],
        ],
    ],
];

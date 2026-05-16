<?php

return [
    'container' => [
        'singletons' => [
            \yii\mail\MailerInterface::class => [
                'class' => \yii\symfonymailer\Mailer::class,
                'viewPath' => '@common/mail',
            ],
        ],
    ],
    'components' => [
        'db' => [
            'class' => \yii\db\Connection::class,
            'dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=crypto',
            'username' => 'postgres',
            'password' => '',
            'charset' => 'utf8',
            'schemaMap' => [
                'pgsql' => [
                    'class' => \yii\db\pgsql\Schema::class,
                    'defaultSchema' => 'public',
                ],
            ],
        ],
        'redis' => [
            'class' => \yii\redis\Connection::class,
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ],
        'mailer' => \yii\mail\MailerInterface::class,
    ],
];

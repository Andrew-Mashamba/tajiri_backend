<?php
/**
 * @see https://github.com/pionl/laravel-chunk-upload
 */

return [
    /*
     * The storage config
     */
    'storage' => [
        /*
         * Returns the folder name of the chunks. The location is in storage/app/{folder_name}
         */
        'chunks' => 'chunks',
        'disk' => 'local',
    ],
    'clear' => [
        /*
         * How old chunks we should delete
         */
        'timestamp' => '-1 HOURS',
        'schedule' => [
            'enabled' => true,
            'cron' => '*/30 * * * *', // run every 30 minutes
        ],
    ],
    'chunk' => [
        // setup for the chunk naming setup to ensure same name upload at same time
        'name' => [
            'use' => [
                'session' => false, // disabled for API usage (no cookies)
                'browser' => true, // use IP and browser fingerprint instead
            ],
        ],
    ],
    'handlers' => [
        // A list of handlers/providers that will be appended to existing list of handlers
        'custom' => [],
        // Overrides the list of handlers - use only what you really want
        'override' => [
            // \Pion\Laravel\ChunkUpload\Handler\DropZoneUploadHandler::class
        ],
    ],
];

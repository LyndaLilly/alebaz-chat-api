 <?php

     return [

         'paths' => ['api/*', 'sanctum/csrf-cookie'],

         'allowed_methods'   => ['*'],

         'allowed_origins'  => [
             'https://alebaz.com',
             'https://www.alebaz.com',
              'https://osita.com.ng',
             'https://www.osita.com.ng',

         ],

         'allowed_origins_patterns' => [
             '/^http:\/\/localhost(:[0-9]+)?$/',
             '/^http:\/\/127\.0\.0\.1(:[0-9]+)?$/',
         ],

         'allowed_headers'          => ['*'],

         'exposed_headers'          => [],

         'max_age'                  => 0,

         'supports_credentials'     => true,

 ];